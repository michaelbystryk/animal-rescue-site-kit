<?php

require __DIR__ . '/lib/bootstrap.php';
use Homeward\{
	Settings,
	Pet_Data,
	Description,
	Source,
	Source_Fixture,
	Source_Petstablished,
	Sync,
	Sync_Lock,
	Animal,
	Animal_Post_Type,
	Content_Post_Types,
	Meta_Boxes,
	Content_Render,
	Render,
	Blocks,
	Donations,
	Applications,
	Scheduler,
	Seo,
	Brand,
	Parser,
	Importer,
	Plugin,
	Templates,
	Site_Health,
};
set_error_handler(function ($severity, $message, $file, $line) {
	if (error_reporting() & $severity) {
		throw new ErrorException($message, 0, $severity, $file, $line);
	}
	return false;
});

function settings($values) {
	$old = Settings::get();
	$values['confirm_source_change'] = '1';
	update_option('homeward_settings', Settings::sanitize($values));
	Settings::saved($old, Settings::get());
}

function start_fixture() {
	test_reset();
	settings(array('source' => 'fixture', 'shelter_id' => 123, 'species' => array('dog', 'cat')));
	return Sync::run_listings();
}

function clear_pause() {
	delete_option('homeward_sync_paused_until');
	delete_option('homeward_backoff_until');
}

class Test_Source implements Source {
	public function __construct(public $rows, public $details = array()) {}

	public function label() {
		return 'Synthetic injected source';
	}

	public function fetch_all() {
		return $this->rows;
	}

	public function fetch_detail($id) {
		return $this->details[$id] ?? new WP_Error('missing', 'FAKE_PRIVATE_ERROR');
	}
}

$fixture = new Source_Fixture(dirname(__DIR__) . '/fixtures/petstablished');
$rows = $fixture->fetch_all();
// The defaults have no account, country, origin rules, or rescue-specific contact.
equals('org defaults to blogname', 'Example Rescue', Settings::get('org_name'));
equals('no shelter default', 0, Settings::get('shelter_id'));
equals('origin disabled', false, Settings::get('origin_enabled'));
equals('no region defaults', array(), Pet_Data::regions());
$clean = Settings::sanitize(
	array(
		'org_name' => '<b>Cloud Rescue</b>',
		'untrusted_key' => 'FAKE',
		'api_key' => 'FAKE',
		'source' => 'bad',
		'adopt_url' => 'http://bad.invalid',
		'contact_email' => 'bad',
		'donate_paypal_me' => 'bad/name',
		'country' => 'ca',
		'currency' => 'cad',
		'placeholder_pattern' => '/[',
		'species' => array('cat', 'lizard'),
		'public_fields' => array('fee', 'internal_notes'),
		'badge_new_days' => 500,
		'origin_rules' => array(array('region' => 'Coast', 'patterns' => array('/coast/i', '/['))),
	),
);
equals('setting strip HTML', 'Cloud Rescue', $clean['org_name']);
check('unknown and secret keys discarded', !isset($clean['untrusted_key'], $clean['api_key']));
equals('invalid enum fallback', 'petstablished', $clean['source']);
equals('https only', '', $clean['adopt_url']);
equals('invalid email', '', $clean['contact_email']);
equals('paypal validation', '', $clean['donate_paypal_url']);
equals('country normalized', 'CA', $clean['country']);
equals('currency normalized', 'CAD', $clean['currency']);
equals('regex fallback', Settings::PLACEHOLDER, $clean['placeholder_pattern']);
equals('species enum', array('cat'), $clean['species']);
update_option('homeward_settings', $clean);
equals('cat-only slug', 'cats', Settings::get('archive_slug'));
equals('public field whitelist', array('fee'), $clean['public_fields']);
equals('day clamp', 365, $clean['badge_new_days']);
equals('bad origin regex discarded', array('/coast/i'), $clean['origin_rules'][0]['patterns']);
settings(
	array(
		'origin_enabled' => true,
		'origin_rules' => array(array('region' => 'Coast', 'patterns' => array('/coast/i'))),
		'name_cleaning' => true,
	),
);
equals('origin parsed from suffix', 'Coast', Pet_Data::map_origin('Puff (Coast)'));
equals('name stripped', 'Puff', Pet_Data::clean_name('PUFF (Coast)'));
settings(array('name_cleaning' => false));
equals('cleaning can be disabled', 'PUFF (Coast)', Pet_Data::clean_name('PUFF (Coast)'));
settings(array('origin_enabled' => false));
equals('origin opt out', '', Pet_Data::map_origin('Puff (Coast)'));
settings(array('placeholder_pattern' => '/^Demo /i'));
check('custom placeholder', Pet_Data::is_placeholder('Demo application'));
check('custom placeholder replaces default', !Pet_Data::is_placeholder('Application'));
add_filter('homeward_is_placeholder', fn($value, $name) => $value || $name === 'Reserved', 10, 2);
check('placeholder filter', Pet_Data::is_placeholder('Reserved'));
settings(array('public_fields' => array('fee')));
add_filter('homeward_public_fields', fn($fields) => array('breed', 'fee', 'internal_notes'));
equals('public filter cannot add even a hidden approved field', array('fee'), Settings::public_fields());
foreach ($rows as $raw) {
	$data = Pet_Data::sanitize_pet($raw);
	equals('whitelist row ' . $raw['id'], array(), array_diff(array_keys($data), Pet_Data::WHITELIST));
	check('sensitive values rejected ' . $raw['id'], !str_contains(serialize($data), 'FAKE_'));
}
$nested = Pet_Data::sanitize_pet(
	array(
		'id' => 88,
		'name' => 'Synthetic',
		'status' => 'Available',
		'photos' => array(
			array(
				'main_photo_url' => 'https://petstablished.com/fake.jpg',
				'internal_notes' => 'FAKE_IN_PHOTO',
				'position' => 1,
			),
		),
	),
);
equals('photo nested whitelist', array('full', 'thumb'), array_keys($nested['photos'][0]));
foreach (
	array(
		'http://petstablished.com/a.jpg',
		'https://petstablished.com.bad.invalid/a.jpg',
		'javascript:alert(1)',
		'https://bad.invalid/a.jpg',
	) as $url
) {
	equals('photo reject ' . $url, '', Pet_Data::photo_url($url));
}
check(
	'array pet ID fails safely',
	!Source_Petstablished::valid_pet(array('id' => array(1), 'name' => 'Fake', 'status' => 'Available')),
);
check('photo allowlist', Pet_Data::photo_url('https://petstablished.s3.amazonaws.com/fake.jpg') !== '');
foreach (array('tomorrow', '2020-02-31', '2099-01-01', 'private text') as $date) {
	equals('date rejects ' . $date, 0, Pet_Data::date_timestamp($date));
}
check('date ISO accepted', Pet_Data::date_timestamp('2020-01-01') > 0);
foreach (
	array(
		'Available' => 'available',
		'Pending Adoption' => 'pending',
		'Hold' => 'hold',
		'Adopted' => 'gone',
	) as $from => $to
) {
	equals('status ' . $from, $to, Pet_Data::status_key($from));
}
foreach (array('yes' => true, 'no' => false, 'unknown' => null) as $raw => $expected) {
	equals(
		'tri-state ' . $raw,
		$expected,
		Pet_Data::sanitize_pet(array('is_ok_with_other_cats' => $raw))['ok_cats'],
	);
}
$html = Description::clean(
	'<script>FAKE_ATTACK</script><p style="color:red" onclick="bad()">ENERGY LEVEL: Calm</p>'
		. '<p><a href="javascript:bad()">Hello</a> <strong>gentle</strong>.</p>',
);
check(
	'description strips active markup',
	!str_contains(
		$html,
		'FAKE_ATTACK',
	) && !str_contains($html, 'onclick') && !str_contains(
		$html,
		'javascript',
	) && !str_contains($html, 'style='),
);
check('description labels normalized', str_contains($html, '<strong>Energy level:</strong>'));
$long = Description::clean(
	'<p>' . str_repeat('A gentle animal enjoys long walks with friends. ', 100) . '</p>',
);
[$visible, $more] = Description::split($long);
check(
	'long description splits without losing text',
	$more !== '' && Description::plain_text($visible . ' ' . $more) === Description::plain_text($long),
);
check(
	'personality extraction',
	str_contains(
		Description::personality('', '<p>I am playful and curious and enjoy gentle walks.</p>'),
		'playful',
	),
);
// Full sync, detail ownership and storage privacy.
$status = start_fixture();
equals('fixture created count', 6, $status['counts']['created']);
equals('three listed', 3, count(Animal::query()));
equals('fixture makes zero HTTP calls', 0, count($GLOBALS['requests']));
$map = Animal::ps_map();
$first = $map[910001];
$special = $map[910002];
$cat = $map[910003];
$placeholder = $map[910004];
$courtesy = $map[910005];
$hold = $map[910006];
equals('source stored', 123, get_post_meta($first, Animal::M_SOURCE, true));
equals('species stored', 'cat', get_post_meta($cat, Animal::M_SPECIES, true));
check('placeholder hidden', !Animal::get($placeholder)['listed']);
check('hold hidden', !Animal::get($hold)['listed']);
check('courtesy hidden', !Animal::get($courtesy)['listed']);
$stored = serialize(array($GLOBALS['posts'], $GLOBALS['meta'], $GLOBALS['options'], $GLOBALS['transients']));
foreach (
	array(
		'internal_notes',
		'latitude',
		'foster_postal_code',
		'FAKE_PRIVATE_NOTE_NEVER_STORE',
		'FAKE_LATITUDE_NEVER_STORE',
		'FAKE_POSTAL_NEVER_STORE',
		'fake-sensitive@example.test',
	) as $secret
) {
	check('storage privacy ' . $secret, !str_contains($stored, $secret));
}
update_post_meta($first, Animal::M_NAME, 'Volunteer Name');
update_post_meta($first, Animal::M_BLURB, 'An editorial blurb');
update_post_meta($first, Animal::M_WEIGHT, 7);
Animal::refresh_derived($first);
equals('first detail batch', 5, Sync::process_details());
equals('second detail batch', 1, Sync::process_details());
equals('queue drains', array(), Sync::queue());
$content = get_post($first)->post_content;
check('full detail description', str_contains($content, 'made-up sample story'));
$initial = get_post_meta($first, Animal::M_FIRST_SYNCED, true);
Sync::run_listings();
equals('listing preserves detail', $content, get_post($first)->post_content);
equals('override remains', 'Volunteer Name', Animal::get($first)['name']);
equals('first sync immutable', $initial, get_post_meta($first, Animal::M_FIRST_SYNCED, true));
equals('blurb preserved', 'An editorial blurb', get_post_meta($first, Animal::M_BLURB, true));
equals('sort derived', 7, get_post_meta($first, Animal::M_SORT, true));
// Bad feeds change neither posts nor meta nor the detail queue.
foreach (
	array(
		'zero' => array(),
		'partial' => array($rows[0]),
		'malformed' => array_merge(array_slice($rows, 0, 5), array(array('id' => 3))),
		'duplicate' => array_merge($rows, array($rows[0])),
		'transport' => new WP_Error('http', 'FAKE_PRIVATE_ERROR'),
	) as $label => $feed
) {
	clear_pause();
	$before = serialize(array($GLOBALS['posts'], $GLOBALS['meta'], Sync::queue()));
	$result = Sync::run_listings(new Test_Source($feed));
	check('bad feed fails ' . $label, !$result['ok']);
	equals(
		'no animal writes ' . $label,
		$before,
		serialize(array($GLOBALS['posts'], $GLOBALS['meta'], Sync::queue())),
	);
	check(
		'errors never persist arbitrary source text',
		!str_contains(serialize($GLOBALS['options']), 'FAKE_PRIVATE_ERROR'),
	);
}
clear_pause();
$remaining = array_slice($rows, 0, 3);
$result = Sync::run_listings(new Test_Source($remaining));
check('exactly half feed allowed', $result['ok']);
equals('missing count', 3, $result['counts']['gone']);
equals('missing becomes gone', 'gone', get_post_meta($courtesy, Animal::M_STATUS, true));
check('gone timestamp', (int) get_post_meta($courtesy, Animal::M_GONE, true) > 0);
$GLOBALS['current_id'] = $courtesy;
$gone = Blocks::render('animal-profile');
check(
	'gone page remains live',
	str_contains($gone, 'has found a home') && str_contains($gone, 'More animals'),
);
equals('gone noindex', true, Seo::robots(array('index' => true))['noindex']);
equals('gone Rank Math', 'noindex', Seo::rank_robots(array())['index']);
equals('gone Yoast', 'noindex, follow', Seo::yoast_robots('index, follow'));
equals('gone sitemap excluded', false, Seo::sitemap_entry(array(), 'post', get_post($courtesy)));
Sync::daily_refresh();
$stale = new Test_Source(
	$remaining,
	array(910001 => array_merge($rows[0], array('status' => 'Adopted', 'description' => 'Stale detail'))),
);
Sync::process_details($stale);
equals('stale detail cannot change listing status', 'available', get_post_meta($first, Animal::M_STATUS, true));
clear_pause();
Sync::run_listings();
equals('reappeared revived status', 'pending', get_post_meta($courtesy, Animal::M_STATUS, true));
equals('reappeared gone timestamp cleared', '', get_post_meta($courtesy, Animal::M_GONE, true));
settings(array('shelter_id' => 456));
equals('shelter isolation map', array(), Animal::ps_map());
equals('shelter isolation queue', array(), Sync::queue());
clear_pause();
Sync::run_listings();
equals('same source IDs in another shelter', 12, count($GLOBALS['posts']));
// Arrival baseline, ranking, priority and settings.
start_fixture();
$map = Animal::ps_map();
$first = $map[910001];
$special = $map[910002];
$cat = $map[910003];
equals('old arrival longest waiting', 'longest-waiting', Animal::get($first)['badge'][1]);
equals('special needs wins', 'special', Animal::get($special)['badge'][1]);
check('first-import fallback is not New', 'new' !== Animal::get($cat)['badge'][1]);
$data = Animal::data($cat);
$data['date_received'] = gmdate('Y-m-d', time() - 2 * DAY_IN_SECONDS);
update_post_meta($cat, Animal::M_DATA, $data);
Animal::flush();
equals('explicit recent arrival New', 'new', Animal::get($cat)['badge'][1]);
settings(
	array(
		'badge_new_days' => 0,
		'badge_labels' => array('special' => 'Extra care'),
		'badge_long_wait_days' => 4000,
	),
);
equals('badge label setting', 'Extra care', Animal::get($special)['badge'][0]);
check('new days zero disables', 'new' !== Animal::get($cat)['badge'][1]);
check('long-wait threshold', 'longest-waiting' !== Animal::get($first)['badge'][1]);
update_post_meta($special, Animal::M_BADGE, 'Editorial badge');
Animal::flush();
equals('editorial badge wins', 'Editorial badge', Animal::get($special)['badge'][0]);
settings(array('courtesy_mode' => 'section'));
foreach (Animal::all_ids() as $id) {
	Animal::refresh_derived($id);
}
equals('section main query excludes courtesy', 3, count(Animal::query()));
equals('section courtesy query', 1, count(Animal::query(array('courtesy' => true))));
settings(array('courtesy_mode' => 'show'));
foreach (Animal::all_ids() as $id) {
	Animal::refresh_derived($id);
}
equals('show merges courtesy', 4, count(Animal::query()));
settings(array('public_fields' => array()));
$a = Animal::get($first);
foreach (array('fee', 'weight', 'age', 'breed', 'special_needs', 'youtube') as $key) {
	equals('hidden field ' . $key, '', $a[$key]);
}
equals('compat hidden', null, $a['ok_dogs']);
$GLOBALS['current_id'] = $first;
$markup = Blocks::render('animal-grid');
check('private compatibility omitted from filter markup', !str_contains($markup, 'name="dogs"'));
check('hidden age filter omitted', !str_contains($markup, 'name="age"'));
settings(array('public_fields' => Settings::PUBLIC_FIELDS));
Animal::all_listed();
add_filter('homeward_public_fields', fn($fields) => array());
foreach (Animal::all_listed() as $animal) {
	equals('privacy filter applies on cached reads', '', $animal['fee']);
}
$GLOBALS['hooks']['homeward_public_fields'] = array();
$profiles = Blocks::render('animal-profile') . Blocks::render('animal-profile');
preg_match_all('/(?<![\w-])id="([^"]+)"/', $profiles, $ids);
equals('multiple profile IDs unique', count($ids[1]), count(array_unique($ids[1])));
// Save handlers require capability and nonce and never write source data.
$_POST = array('homeward_animal_nonce' => 'wrong', 'homeward_display_name' => 'Nope');
Animal_Post_Type::save($first);
check('bad nonce refuses overrides', Animal::name($first) !== 'Nope');
$_POST['homeward_animal_nonce'] = 'valid';
$GLOBALS['can'] = false;
Animal_Post_Type::save($first);
check('unauthorized refuses overrides', Animal::name($first) !== 'Nope');
$GLOBALS['can'] = true;
Animal_Post_Type::save($first);
equals('valid override save', 'Nope', Animal::name($first));
// Atomic locks: stale owner cannot release a newer lock.
$lock = Sync_Lock::acquire();
check('lock acquired', is_array($lock));
equals('contender blocked', false, Sync_Lock::acquire());
Sync_Lock::release(array('token' => 'stale', 'expires' => 0));
check('stale release rejected', get_option(Sync_Lock::OPTION) !== false);
Sync_Lock::release($lock);
equals('owner releases', false, get_option(Sync_Lock::OPTION));
// Retention preserves story-linked animals and never deletes.
start_fixture();
$map = Animal::ps_map();
Sync::run_listings(new Test_Source(array_slice($rows, 0, 3)));
$retired = $map[910006];
$linked = $map[910005];
foreach (array($retired, $linked) as $id) {
	update_post_meta($id, Animal::M_GONE, time() - 400 * DAY_IN_SECONDS);
}
$story = wp_insert_post(
	array(
		'post_type' => Content_Post_Types::STORY,
		'post_title' => 'Synthetic ending',
		'post_status' => 'draft',
	),
);
update_post_meta($story, 'homeward_animal_id', $linked);
Sync::cleanup();
equals('old gone drafted', 'draft', get_post($retired)->post_status);
equals('story linked stays live', 'publish', get_post($linked)->post_status);
equals('never deletes', 7, count($GLOBALS['posts']));
Sync::run_listings();
equals('retired draft republishes on return', 'publish', get_post($retired)->post_status);
// Configuration-driven applications and donations.
settings(
	array(
		'apply_mode' => 'general',
		'adopt_general_pet_ids' => array(array('label' => 'Adoption', 'pet_id' => 777)),
		'foster_pet_id' => 888,
	),
);
check('general application ID', str_contains(Applications::url('adopt'), 'pet_id=777'));
check('foster ID', str_contains(Applications::url('foster'), 'pet_id=888'));
settings(
	array(
		'adopt_url' => 'https://forms.example.invalid/adopt',
		'country' => 'US',
		'donate_etransfer_email' => 'donate@example.test',
		'donate_card_url' => 'https://donate.example.invalid/',
		'donate_paypal_me' => 'ExampleRescue',
		'donate_order' => array('etransfer', 'card', 'paypal'),
	),
);
equals('manual application override', 'https://forms.example.invalid/adopt', Applications::url());
$donate = Donations::render();
check('US no e-transfer', !str_contains($donate, 'donate@example.test'));
check('card configured', str_contains($donate, 'https://donate.example.invalid/'));
settings(array('country' => 'CA'));
$donate = Donations::render();
check('CA e-transfer', str_contains($donate, 'data-homeward-copy="donate@example.test"'));
check('donation ordering', strpos($donate, 'e-Transfer') < strpos($donate, 'Donate by card'));
check(
	'method attribute',
	!str_contains(Donations::render(array('methods' => array('paypal'))), 'e-Transfer'),
);
// Registration, content data and importer.
Animal_Post_Type::register();
Content_Post_Types::register();
Blocks::register();
Blocks::shortcodes();
Templates::register();
Plugin::grant_caps();
equals('ten blocks', 10, count($GLOBALS['blocks']));
equals('six templates', 6, count($GLOBALS['templates']));
equals('five shortcodes', 5, count($GLOBALS['shortcodes']));
check('edit caps installed', isset(get_role('editor')->caps['edit_homeward_animals']));
equals(
	'staff can create animals',
	'edit_homeward_animals',
	$GLOBALS['registered']['types']['homeward_animal']['capabilities']['create_posts'],
);
foreach (
	array(
		'homeward_animal' => 'animals',
		'homeward_story' => 'stories',
		'homeward_event' => 'events',
	) as $type => $base
) {
	equals('REST base ' . $type, $base, $GLOBALS['registered']['types'][$type]['rest_base']);
}
check(
	'raw data not in REST',
	!$GLOBALS['registered']['meta']['homeward_animal']['_homeward_data']['show_in_rest'],
);
check(
	'volunteer override in REST',
	$GLOBALS['registered']['meta']['homeward_animal']['homeward_display_name']['show_in_rest'],
);
equals('invalid event date', '', Content_Post_Types::sanitize_datetime('2026-02-31T10:00'));
equals('valid event date', '2026-03-02 10:30', Content_Post_Types::sanitize_datetime('2026-03-02T10:30'));
equals('invalid month', '', Content_Post_Types::sanitize_month('2026-13'));
equals('linked animal validation', '', Content_Post_Types::sanitize_animal_id($story));
$_POST = array(
	'homeward_meta_nonce' => 'valid',
	'homeward_kind' => 'story',
	'homeward_text' => 'Synthetic family story',
	'homeward_animal_id' => $map[910001],
);
Meta_Boxes::save($story, get_post($story));
equals('story animal link', (string) $map[910001], get_post_meta($story, 'homeward_animal_id', true));
wp_update_post(array('ID' => $story, 'post_status' => 'publish'));
update_post_meta($story, 'homeward_pull_quote', '[[Unwritten]]');
check(
	'editorial placeholders hidden',
	!str_contains(Content_Render::story_card($story, true), '[[Unwritten]]'),
);
$tabs = Content_Render::tabs() . Content_Render::tabs();
preg_match_all('/(?<![\w-])id="([^"]+)"/', $tabs, $ids);
equals('multiple tabs IDs unique', count($ids[1]), count(array_unique($ids[1])));
check('no JS story tabs still show panels', !preg_match('/data-homeward-panel="[^"]+"[^>]*hidden/', $tabs));
$parsed = Parser::parse_happy_tails(
	'<h3>Fable Spark</h3><p>Our fictional family enjoys quiet walks together.</p><p>Update received May 2021</p>',
);
equals('legacy parser synthetic count', 1, count($parsed));
equals('legacy month', '2021-05', $parsed[0]['received']);
$page = wp_insert_post(
	array(
		'post_type' => 'page',
		'post_status' => 'publish',
		'post_title' => 'Old stories',
		'post_name' => 'old-stories',
		'post_content' => '<h3>Fable Spark</h3><p>Our fictional family enjoys quiet walks together.</p>'
			. '<p>Update received May 2021</p>',
	),
);
settings(array('import_stories_slug' => 'old-stories'));
$import = Importer::run();
equals('import creates draft', 1, count($import['created']));
equals('import draft state', 'draft', get_post($import['created'][0])->post_status);
equals('import idempotent', 0, count(Importer::run()['created']));
foreach (Blocks::NAMES as $name) {
	$GLOBALS['current_id'] = $map[910001];
	check('block renders ' . $name, is_string(Blocks::render($name)));
}
// Source failures and redaction; the HTTP transport below cannot access a network.
test_reset();
settings(array('shelter_id' => 123));
$source = new Source_Petstablished();
$GLOBALS['http'] = function ($url) {
	parse_str(parse_url($url, PHP_URL_QUERY) ?: '', $query);
	$page = (int)($query['page'] ?? 1);
	return array(
		'code' => 200,
		'body' => json_encode(
			array(
				'shelter_pets_total_page' => 2,
				'shelter_pets' => $page === 1 ? array(
					array('id' => 1, 'name' => 'Fake A', 'status' => 'Available'),
				) : array(array('id' => 2, 'name' => 'Fake B', 'status' => 'Available')),
			),
		),
	);
};
$start = microtime(true);
equals('public pagination', 2, count($source->fetch_all()));
check('rate limited between requests', microtime(true) - $start >= 0.98);
$GLOBALS['http'] = fn() => array(
	'code' => 200,
	'body' => json_encode(
		array(
			'shelter_pets_total_page' => 2,
			'shelter_pets' => array(array('id' => 1, 'name' => 'Fake A', 'status' => 'Available')),
		),
	),
);
check('duplicate public pages rejected', is_wp_error($source->fetch_all()));
$GLOBALS['http'] = fn() => array(
	'code' => 200,
	'body' => '{"shelter_pets_total_page":1.5,"shelter_pets":[]}',
);
check('fractional page count rejected', is_wp_error($source->fetch_all()));
update_option('homeward_api_key', 'FAKE_SYNTHETIC_TEST_KEY');
$statuses = array();
$GLOBALS['http'] = function ($url) use (& $statuses) {
	parse_str(parse_url($url, PHP_URL_QUERY), $q);
	$status = rawurldecode($q['search']['status']);
	$statuses[] = $status;
	$i = array_search($status, array('Available', 'Pending Adoption', 'Hold'), true) + 1;
	return array(
		'code' => 200,
		'body' => json_encode(
			array(
				'pets' => array(
					array('id' => $i, 'name' => 'Synthetic ' . $i, 'status' => $status, 'animal' => 'Cat'),
				),
				'pagination' => array('total_pages' => 1),
			),
		),
	);
};
equals('keyed fetch count', 3, count($source->fetch_all()));
equals('keyed includes all statuses', array('Available', 'Pending Adoption', 'Hold'), $statuses);
$GLOBALS['http'] = fn() => new WP_Error('transport', 'FAKE_SYNTHETIC_TEST_KEY body private');
$error = $source->fetch_all();
check(
	'transport key redaction',
	is_wp_error($error) && !str_contains($error->get_error_message(), 'FAKE_SYNTHETIC_TEST_KEY'),
);
check(
	'URL redaction',
	!str_contains(
		Source_Petstablished::redact('https://example.invalid/?public_key=FAKE_TEST&x=1'),
		'FAKE_TEST',
	),
);
$GLOBALS['http'] = fn() => array(
	'code' => 429,
	'headers' => array('retry-after' => '900'),
	'body' => 'FAKE_PRIVATE_BODY',
);
$source->fetch_all();
check('Retry-After backoff', get_option('homeward_backoff_until') >= time() + 899);
$before = count($GLOBALS['requests']);
$source->fetch_all();
equals('backoff prevents HTTP', $before, count($GLOBALS['requests']));
check('HTTP bodies never persisted', !str_contains(serialize($GLOBALS['options']), 'FAKE_PRIVATE_BODY'));
test_reset();
settings(array('source' => 'fixture', 'shelter_id' => 123));
for ($i = 0; $i < 3; $i++) {
	Sync::run_listings(new Test_Source(new WP_Error('offline', 'Unavailable')));
}
check('three failures pause six hours', Sync::paused_until() >= time() + 6 * HOUR_IN_SECONDS - 1);
equals('health detects pause', 'recommended', Site_Health::result()['status']);
Scheduler::ensure();
$cron = $GLOBALS['cron'];
Scheduler::ensure();
equals('schedule idempotent', $cron, $GLOBALS['cron']);
check(
	'jitter persisted',
	get_option('homeward_schedule_minute') >= 0 && get_option('homeward_schedule_minute') <= 59,
);
Scheduler::clear();
equals('deactivate clears jobs', array(), $GLOBALS['cron']);
settings(array('color_primary' => '#ffffff', 'color_accent' => '#aabbcc'));
check('brand safe foreground', str_contains(Brand::css(), '--homeward-primary-foreground:#111111'));
check('AA contrast', Brand::contrast('#111111', '#ffffff') >= 4.5);
check('brand only scoped variables', !str_contains(Brand::css(), '--wp--preset'));
Settings::saved(array(), Settings::get());
check('settings clear theme cache', !empty($GLOBALS['theme_cleaned']));
update_option('homeward_sync_paused_until', time() + 21600);
update_option('homeward_backoff_until', time() + 900);
Settings::saved(
	array('source' => 'petstablished', 'shelter_id' => 1),
	array('source' => 'fixture', 'shelter_id' => 123),
);
equals('changing source clears stale pause', 0, Sync::paused_until());
equals('changing source resets old diagnostics', array(), Sync::status());
// Runtime and test fixtures are the same synthetic records.
foreach (glob(dirname(__DIR__) . '/fixtures/petstablished/*.json') as $file) {
	equals(
		'packaged fixture parity ' . basename($file),
		file_get_contents($file),
		file_get_contents(HOMEWARD_DIR . 'fixtures/petstablished/' . basename($file)),
	);
}
summary('PHP');
