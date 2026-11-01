<?php

/** Offline regressions for every review safety boundary. */
require __DIR__ . '/lib/bootstrap.php';
use Homeward\{
	Animal,
	Animal_Post_Type,
	Blocks,
	Brand,
	Content_Post_Types,
	Content_Render,
	Frontend,
	Pet_Data,
	Plugin,
	Settings,
	Source,
	Source_Fixture,
	Source_Petstablished,
	Sync,
	Sync_Lock,
	Seo,
};

function review_settings($values) {
	$old = Settings::get();
	update_option('homeward_settings', Settings::sanitize($values));
	Settings::saved($old, Settings::get());
}

class Review_Source implements Source {
	public function __construct(public $rows, public $effect = null) {}

	public function label() {
		return 'Offline review fixture';
	}

	public function fetch_all() {
		if ($this->effect) {
			($this->effect)();
		}
		return $this->rows;
	}

	public function fetch_detail($id) {
		return $this->rows[$id - 1];
	}
}

function review_roster() {
	test_reset();
	review_settings(array('shelter_id' => 123));
	$rows = array_map(
		fn($id) => array(
			'id' => $id,
			'name' => 'Synthetic ' . $id,
			'status' => 'Available',
			'animal' => 'Dog',
		),
		range(1, 4),
	);
	Sync::run_listings(new Review_Source($rows));
	return $rows;
}

check('PHP 8.2 false date diagnostics', Pet_Data::date_errors_clear(false));
check(
	'PHP 8.1 array date diagnostics',
	Pet_Data::date_errors_clear(
		array('warning_count' => 0, 'warnings' => array(), 'error_count' => 0, 'errors' => array()),
	),
);
check(
	'date warnings rejected',
	!Pet_Data::date_errors_clear(array('warning_count' => 1, 'error_count' => 0)),
);
check(
	'date errors rejected',
	!Pet_Data::date_errors_clear(array('warning_count' => 0, 'error_count' => 1)),
);
check(
	'valid dates retained in whitelist',
	'2020-01-01' === Pet_Data::sanitize_pet(array('created_at' => '2020-01-01'))['created_at'],
);
$log = __DIR__ . '/review-status.log';
$old_log = ini_set('error_log', $log);
if (file_exists($log)) {
	unlink($log);
}
check('unknown status held', 'hold' === Pet_Data::status_key('Adoptable'));
check('unknown status does not log raw source values', !file_exists($log));
foreach (array('Adopted', 'Transferred', 'no_longer_available', 'No longer available') as $status) {
	check('explicit gone: ' . $status, 'gone' === Pet_Data::status_key($status));
}
$rows = review_roster();
$before = serialize(array($GLOBALS['posts'], $GLOBALS['meta'], Sync::queue()));
$changed = $rows;
foreach (array(0, 1, 2) as $i) {
	$changed[$i]['status'] = 'Available for adoption';
}
check('mass status drift rejected', !Sync::run_listings(new Review_Source($changed))['ok']);
check(
	'failure logged without raw source status',
	str_contains(file_get_contents($log), 'Homeward:') && !str_contains(file_get_contents($log), 'Adoptable'),
);
$logged = file_get_contents($log);
check(
	'guard leaves posts/meta/detail queue intact',
	$before === serialize(array($GLOBALS['posts'], $GLOBALS['meta'], Sync::queue())),
);
$changed = $rows;
$changed[0]['status'] = 'Adopted';
$changed[1]['status'] = 'Hold';
check(
	'exactly half availability transition allowed',
	Sync::run_listings(new Review_Source($changed))['ok'],
);
check('successful sync produces no log', $logged === file_get_contents($log));
$rows = review_roster();
$changed = $rows;
foreach (array(0, 1, 2) as $i) {
	$changed[$i]['no_longer_available'] = true;
}
check('mass explicit retirement guarded', !Sync::run_listings(new Review_Source($changed))['ok']);
ini_set('error_log', $old_log);
unlink($log);
// Public HTML, REST collections/singles, and sitemaps share the visibility policy.
$rows = review_roster();
update_post_meta(2, Animal::M_SHOW, 'hide');
Animal::refresh_derived(2);
update_post_meta(3, Animal::M_STATUS, 'hold');
Animal::refresh_derived(3);
update_post_meta(4, Animal::M_STATUS, 'gone');
Animal::refresh_derived(4);
$GLOBALS['can'] = false;
foreach (array(2, 3) as $id) {
	$GLOBALS['current_id'] = $id;
	$GLOBALS['is_404'] = false;
	Frontend::placeholder_404();
	check('private HTML 404 ' . $id, $GLOBALS['is_404'] && 404 === $GLOBALS['http_status']);
	check('private profile empty ' . $id, '' === Blocks::render('animal-profile'));
}

class Review_Request {
	public function __construct(public $id) {}

	public function get_method() {
		return 'GET';
	}

	public function get_route() {
		return '/wp/v2/animals/' . $this->id;
	}
}

check(
	'REST collection public IDs',
	array(1, 4) === get_posts(
		Animal_Post_Type::rest_query(array('post_type' => Animal_Post_Type::TYPE, 'fields' => 'ids')),
	),
);
check(
	'REST hidden single 404',
	404 === Animal_Post_Type::rest_read(null, null, new Review_Request(2))->data['status'],
);
check(
	'REST gone single allowed',
	null === Animal_Post_Type::rest_read(null, null, new Review_Request(4)),
);
$GLOBALS['can'] = true;
check('REST editor sees collection', array() === Animal_Post_Type::rest_query(array()));
check(
	'REST editor sees hidden single',
	null === Animal_Post_Type::rest_read(null, null, new Review_Request(2)),
);
check(
	'sitemap only listed',
	array(1) === get_posts(
		Seo::sitemap_args(
			array('post_type' => Animal_Post_Type::TYPE, 'fields' => 'ids'),
			Animal_Post_Type::TYPE,
		),
	),
);
check(
	'sitemap respects existing OR constraints',
	array() === get_posts(
		Seo::sitemap_args(
			array(
				'post_type' => Animal_Post_Type::TYPE,
				'fields' => 'ids',
				'meta_query' => array('relation' => 'OR', array('key' => Animal::M_STATUS, 'value' => 'gone')),
			),
			Animal_Post_Type::TYPE,
		),
	),
);
$GLOBALS['current_id'] = 4;
check('gone page still live', str_contains(Blocks::render('animal-profile'), 'has found a home'));
check('gone remains noindex', !empty(Seo::robots(array())['noindex']));
// Changed derived settings are queued, then recomputed in bounded batches.
$rows = review_roster();
$d = Animal::data(1);
$d['raw_name'] = 'Synthetic (Partner listing)';
update_post_meta(1, Animal::M_DATA, $d);
review_settings(array('courtesy_pattern' => '/partner listing/i', 'courtesy_mode' => 'hide'));
check(
	'small settings changes finish derivation immediately',
	array() === get_option('homeward_derived_queue'),
);
Settings::refresh_batch();
check('custom courtesy hidden after queued refresh', '0' === get_post_meta(1, Animal::M_LISTED, true));
review_settings(array('courtesy_mode' => 'section'));
Settings::refresh_batch();
check(
	'courtesy section change applies without sync',
	'1' === get_post_meta(1, Animal::M_LISTED, true),
);
$sanitized = Settings::sanitize(array('shelter_id' => 456));
check('shelter change requires checkbox', 123 === $sanitized['shelter_id']);
$lock = Sync_Lock::acquire();
$sanitized = Settings::sanitize(array('shelter_id' => 456, 'confirm_source_change' => '1'));
check('source change refuses sync race', 123 === $sanitized['shelter_id']);
Sync_Lock::release($lock);
review_settings(array('shelter_id' => 456, 'confirm_source_change' => '1'));
check('confirmed source change persists', 456 === Settings::get('shelter_id'));
check(
	'old source animals drafted after save',
	array('draft') === array_values(array_unique(array_map(fn($p) => $p->post_status, $GLOBALS['posts']))),
);
check('source settings lease released', false === get_option(Sync_Lock::OPTION));
check('source map isolated', array() === Animal::ps_map());
// Migrated source-less posts are claimed instead of duplicated.
$rows = review_roster();
delete_post_meta(1, Animal::M_SOURCE);
delete_post_meta(1, Animal::M_ADAPTER);
Sync::run_listings(new Review_Source($rows));
check(
	'source-less migration matches ID',
	count($GLOBALS['posts']) === 4 && 123 === get_post_meta(1, Animal::M_SOURCE, true),
);
// A real Source_Fixture batch of 150 animals, including update hooks and detail work.
test_reset();
review_settings(array('source' => 'fixture', 'shelter_id' => 123));
Animal::init();
$directory = __DIR__ . '/review-fixture-' . bin2hex(random_bytes(4));
mkdir($directory);
try {
	$large = array_map(
		fn($id) => array('id' => $id, 'name' => 'Synthetic ' . $id, 'status' => 'Available'),
		range(1, 150),
	);
	foreach (array_chunk($large, 75) as $page => $pets) {
		file_put_contents(
			$directory . '/listing-' . ($page + 1) . '.json',
			json_encode(array('shelter_pets_total_page' => 2, 'shelter_pets' => $pets)),
		);
	}
	foreach (array_slice($large, 0, 5) as $pet) {
		file_put_contents($directory . '/detail-' . $pet['id'] . '.json', json_encode(array('pet' => $pet)));
	}
	$builds = 0;
	add_action('homeward_view_model_build', function () use (& $builds) {
		++$builds;
	});
	$source = new Source_Fixture($directory);
	foreach (array('insert', 'update') as $mode) {
		$GLOBALS['flushes'] = 0;
		$GLOBALS['meta_primes'] = array();
		$builds = 0;
		check('150 fixture sync ' . $mode, Sync::run_listings($source)['ok']);
		check('zero sync view builds ' . $mode, 0 === $builds);
		check('one map/meta prime per run ' . $mode, count($GLOBALS['meta_primes']) === 1);
		check('one transient flush per run ' . $mode, 1 === $GLOBALS['flushes']);
		$listed = Animal::query();
		check('O(N) view builds after batch ' . $mode, count($listed) === 150 && $builds === 150);
	}
	$GLOBALS['meta_primes'] = array();
	$GLOBALS['flushes'] = 0;
	$builds = 0;
	check('detail fixture batch completes', 5 === Sync::process_details($source));
	check('detail map built once', count($GLOBALS['meta_primes']) === 1);
	check('detail zero builds/one flush', 0 === $builds && 1 === $GLOBALS['flushes']);
	review_settings(array('name_cleaning' => false));
	Settings::refresh_batch();
	check('derived refresh bounded to 50', 100 === count(get_option('homeward_derived_queue')));
} finally {
	foreach (glob($directory . '/*') as $file) {
		unlink($file);
	}
	rmdir($directory);
}
// Cat-only feed with no species field, plus multi-species fallback.
test_reset();
review_settings(array('shelter_id' => 123, 'species' => array('cat')));
Sync::run_listings(
	new Review_Source(array(array('id' => 1, 'name' => 'Synthetic cat', 'status' => 'Available'))),
);
check(
	'missing species uses configured cat',
	'cat' === get_post_meta(
		1,
		Animal::M_SPECIES,
		true,
	) && 'cat' === Animal::get(1)['species'] && count(Animal::query()) === 1,
);
review_settings(array('species' => array('dog', 'cat')));
check('multiple species fallback is other', 'other' === Animal::default_species());
// Block wrappers, empty output, modules and defensive CSS sanitizing.
test_reset();
check('empty block emits no wrapper', '' === Blocks::render('donate-panel'));
check('empty block enqueues nothing', array() === $GLOBALS['enqueued']);
check(
	'wrapper attributes preserved',
	str_contains(
		Blocks::render(
			'animal-grid',
			array(),
			null,
			'id="example" class="alignwide custom" style="margin-top:2px"',
		),
		'<div id="example" class="alignwide custom" style="margin-top:2px">',
	),
);
$GLOBALS['enqueued'] = array();
Blocks::render('upcoming-events');
check(
	'events enqueue no modules',
	!in_array(
		'homeward-animals',
		$GLOBALS['enqueued'],
		true,
	) && !in_array('homeward-donate', $GLOBALS['enqueued'], true),
);
$GLOBALS['enqueued'] = array();
Frontend::block_assets('story-cards');
check(
	'stories enqueue only own module',
	in_array(
		'homeward-stories',
		$GLOBALS['enqueued'],
		true,
	) && !in_array(
		'homeward-animals',
		$GLOBALS['enqueued'],
		true,
	) && !in_array('homeward-donate', $GLOBALS['enqueued'], true),
);
update_option('homeward_settings', array('color_accent' => '</style><script>alert(1)</script>'));
check('CSS rejects unsanitized stored accent', !str_contains(Brand::css(), '<'));
check(
	'unknown S3 hosts rejected',
	'' === Pet_Data::photo_url('https://unrelated.s3.amazonaws.com/animal.jpg'),
);
add_filter('homeward_photo_hosts', fn($hosts) => array_merge($hosts, array('images.example.invalid')));
check(
	'photo host allowlist filter',
	'' !== Pet_Data::photo_url('https://images.example.invalid/animal.jpg'),
);
// Multi-day events stay upcoming until their end date; no-end events use start.
test_reset();
$event = wp_insert_post(array('post_type' => Content_Post_Types::EVENT, 'post_title' => 'Ongoing'));
update_post_meta($event, 'homeward_start', wp_date('Y-m-d H:i', time() - DAY_IN_SECONDS));
update_post_meta($event, 'homeward_end', wp_date('Y-m-d H:i', time() + DAY_IN_SECONDS));
check('ongoing event upcoming', count(Content_Render::event_query()->posts) === 1);
check('ongoing event not past', count(Content_Render::event_query('past')->posts) === 0);
update_option('date_format', 'Y/m/d');
update_option('time_format', 'H:i');
check(
	'event site date format',
	str_contains(Content_Render::event_when($event), wp_date('Y/m/d', time() - DAY_IN_SECONDS)),
);
delete_post_meta($event, 'homeward_end');
check('no-end event uses start', count(Content_Render::event_query('past')->posts) === 1);
// Sync cannot write after losing its lease or changing configuration during fetch.
foreach (array('lease', 'configuration') as $case) {
	$rows = review_roster();
	$before = serialize(array($GLOBALS['posts'], $GLOBALS['meta'], Sync::queue()));
	$effect = 'lease' === $case ? function () {
		update_option(Sync_Lock::OPTION, array('token' => 'new owner', 'expires' => time() + 1800));
	} : function () {
		update_option('homeward_settings', array('shelter_id' => 999));
	};
	check(
		'sync aborts changed ' . $case,
		!Sync::run_listings(new Review_Source($rows, $effect))['ok'],
	);
	check(
		'no writes after changed ' . $case,
		serialize(
			array($GLOBALS['posts'], $GLOBALS['meta']),
		) === serialize(array_slice(unserialize($before), 0, 2)),
	);
	if ('lease' === $case) {
		check(
			'old owner cannot remove new lease',
			'new owner' === get_option(Sync_Lock::OPTION)['token'],
		);
	}
}
// Pagination must prove completion; all HTTP calls remain intercepted.
test_reset();
review_settings(array('shelter_id' => 123));
update_option('homeward_api_key', 'SYNTHETIC_KEY');
$source = new Source_Petstablished();
$GLOBALS['http'] = fn() => array(
	'code' => 200,
	'body' => json_encode(
		array('pets' => array(array('id' => 1, 'name' => 'Synthetic', 'status' => 'Available'))),
	),
);
check('ambiguous keyed pagination rejected', is_wp_error($source->fetch_all()));
$GLOBALS['http'] = function ($url) {
	parse_str(parse_url($url, PHP_URL_QUERY), $q);
	return array(
		'code' => 200,
		'body' => json_encode(
			array(
				'pets' => 1 === (int) $q['pagination']['page'] ? array(
					array('id' => 1, 'name' => 'Synthetic', 'status' => 'Available'),
				) : array(),
				'pagination' => array('total_pages' => 2),
			),
		),
	);
};
check('empty final keyed page rejected', is_wp_error($source->fetch_all()));
check('key-bearing requests never follow redirects', 0 === $GLOBALS['requests'][0][1]['redirection']);
$deadline = new ReflectionProperty(Source_Petstablished::class, 'deadline');
$deadline->setValue(null, microtime(true) - 1);
$request = new ReflectionMethod(Source_Petstablished::class, 'get_json');
$count = count($GLOBALS['requests']);
check(
	'total HTTP deadline aborts before request',
	is_wp_error(
		$request->invoke(null, Source_Petstablished::BASE . 'pets'),
	) && count($GLOBALS['requests']) === $count,
);
// Authorization is enforced before either admin-post handler can mutate or request data.
foreach (array('sync_now', 'save_key') as $handler) {
	foreach (array('permission', 'nonce') as $denial) {
		test_reset();
		$GLOBALS['can'] = 'nonce' === $denial;
		$_POST = array('homeward_key' => 'UNAUTHORIZED_KEY', '_wpnonce' => 'invalid');
		try {
			Homeward\Settings_Page::$handler();
			check($handler . ' should reject ' . $denial, false);
		} catch (RuntimeException $error) {
			check($handler . ' rejects ' . $denial, $denial === $error->getMessage());
		}
		check(
			$handler . ' rejection has no side effects',
			false === get_option('homeward_api_key') && array() === $GLOBALS['requests'],
		);
	}
}
$rows = review_roster();
$invalid = Settings::sanitize(array('shelter_id' => 999, 'confirm_source_change' => array('1')));
check('array confirmation is rejected', 123 === $invalid['shelter_id']);
$invalid = Settings::sanitize(array('adopt_general_pet_ids' => array(array('pet_id' => array(1)))));
check('array application ID rejected', array() === $invalid['adopt_general_pet_ids']);
update_post_meta(2, Animal::M_SHOW, 'hide');
Animal::refresh_derived(2);
$GLOBALS['can'] = false;
check(
	'REST search excludes hidden animals',
	array(1, 3, 4) === get_posts(
		Animal_Post_Type::rest_search(array('post_type' => Animal_Post_Type::TYPE, 'fields' => 'ids')),
	),
);
check(
	'oEmbed does not expose hidden animal',
	false === Animal_Post_Type::oembed(array('title' => 'Private'), get_post(2)),
);

class Review_Response {
	public $data = array(
		'title' => 'Public',
		'meta' => array('homeward_blurb' => 'Private'),
		'content' => array('rendered' => 'Raw feed'),
		'excerpt' => 'Raw feed',
	);

	public function get_data() {
		return $this->data;
	}

	public function set_data($data) {
		$this->data = $data;
	}
}

check(
	'public REST strips source prose and editorial meta',
	array('title' => 'Public') === Animal_Post_Type::rest_prepare(new Review_Response(), get_post(1))->data,
);
$GLOBALS['can'] = true;
check(
	'editor REST retains source and editorial fields',
	isset(Animal_Post_Type::rest_prepare(new Review_Response(), get_post(1))->data['meta']),
);
check(
	'invalid event date renders safely',
	'' === Content_Render::event_when(
		wp_insert_post(
			array(
				'post_type' => Content_Post_Types::EVENT,
				'post_title' => 'Invalid',
				'meta_input' => array('homeward_start' => 'garbage'),
			),
		),
	),
);
$rows = review_roster();
$builds = 0;
add_action('homeward_view_model_build', function () use (& $builds) {
	++$builds;
});
Animal::get(1);
$first_builds = $builds;
Animal::get(2);
check('urgency rank cache prevents repeat roster builds', $first_builds === 5 && $builds === 6);
$duplicate = wp_insert_post(array('post_type' => Animal_Post_Type::TYPE, 'post_title' => 'Legacy duplicate'));
update_post_meta($duplicate, Animal::M_SOURCE, 123);
update_post_meta($duplicate, Animal::M_PS_ID, 1);
review_settings(array('shelter_id' => 456, 'confirm_source_change' => '1'));
check(
	'source retirement includes duplicate provider IDs',
	'draft' === get_post_status(1) && 'draft' === get_post_status($duplicate),
);
test_reset();
Plugin::init();
check('REST single gate is hooked', !empty($GLOBALS['hooks']['rest_pre_dispatch']));
check('REST query gate is hooked', !empty($GLOBALS['hooks']['rest_homeward_animal_query']));
check(
	'derived batches are scheduled and hooked',
	!empty($GLOBALS['hooks']['homeward_refresh_derived']) && in_array(
		'homeward_refresh_derived',
		Homeward\Scheduler::HOOKS,
		true,
	),
);
summary('review regression');
