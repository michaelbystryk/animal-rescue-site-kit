<?php

require __DIR__ . '/lib/bootstrap.php';
use Homeward\{
	Demo_Content,
	Wizard,
	Settings,
	Animal,
	Blocks,
	Testimonials,
	Content_Render,
	Content_Post_Types,
	Sync,
};


Demo_Content::init();
check(
	'wizard action listener registered',
	has_action('homeward_import_demo', array(Demo_Content::class, 'action')),
);
$GLOBALS['can'] = false;
check('import requires administrator', is_wp_error(Demo_Content::import()));
check('removal requires administrator', is_wp_error(Demo_Content::remove()));
equals('unauthorized writes nothing', array(), $GLOBALS['posts']);
$GLOBALS['can'] = true;
update_option(Demo_Content::LOCK, time());
check('concurrent import rejected', is_wp_error(Demo_Content::import()));
check('concurrent removal rejected', is_wp_error(Demo_Content::remove()));
delete_option(Demo_Content::LOCK);
update_option(Demo_Content::LOCK, time() - 3600);
$result = demo_import();
equals('all practice animals visible', 12, count(Animal::query()));
equals('default species includes demo cats', array('dog', 'cat'), Settings::get('species'));
check(
	'plain demo success message',
	str_contains($result['message'], 'Practice content is ready. The animals and stories are made up.'),
);
equals('six demo pages', 6, count(get_posts(array('post_type' => 'page'))));
equals('twelve practice animals', 12, count(get_posts(array('post_type' => 'homeward_animal'))));
equals('six story records', 6, count(get_posts(array('post_type' => 'homeward_story'))));
equals('twelve Media Library images', 12, count(get_posts(array('post_type' => 'attachment'))));
equals('demo selected without shelter', 'demo', Settings::get('source'));
check('source choice explained', str_contains($result['message'], 'Petstablished shelter number'));
equals('import uses no HTTP', array(), $GLOBALS['requests']);
equals('twelve uploads', 12, count($GLOBALS['demo_uploads']));
equals('all created IDs tracked', 36, count(get_option(Demo_Content::OPTION)['created']));
$practice = Animal::query();
equals('two practice cats', 2, count(array_filter($practice, fn($pet) => 'cat' === $pet['species'])));
check(
	'at least four breed families',
	count(array_unique(array_column($practice, 'breed_family'))) >= 4,
);
$practice_grid = Blocks::render('animal-grid');
check(
	'mixed demo grid displays dog breed filter',
	str_contains($practice_grid, 'name="breed"') && str_contains($practice_grid, 'Dog breed family'),
);
preg_match(
	'/<script type="application\/json" data-homeward-animals-json>(.*?)<\/script>/s',
	$practice_grid,
	$practice_json,
);
foreach (json_decode($practice_json[1], true) as $row) {
	if ('cat' === $row['species']) {
		equals('cat is not mapped to a dog breed family', '', $row['breed']);
	}
}
equals(
	'home carousel renders six practice cards',
	6,
	substr_count(Blocks::render('animal-carousel', array('limit' => 6)), 'class="homeward-animal-card"'),
);
foreach (array('ok_dogs', 'ok_cats', 'ok_kids') as $field) {
	$values = array_map(fn($pet) => $pet[$field], $practice);
	check(
		'tri-state ' . $field,
		in_array(true, $values, true) && in_array(false, $values, true) && in_array(null, $values, true),
	);
}
$badges = array_column(array_column($practice, 'badge'), 1);
foreach (array('new', 'special', 'bonded', 'pending') as $badge) {
	check('demo badge ' . $badge, in_array($badge, $badges, true));
}
foreach ($practice as $pet) {
	check(
		'fake animal copy',
		str_contains(get_post($pet['id'])->post_content, 'Fictional practice animal'),
	);
	check('practice fee populated', is_numeric(Animal::data($pet['id'])['adoption_fee']));
}
$demo_posts_snapshot = serialize($GLOBALS['posts']);
$demo_meta_snapshot = $GLOBALS['meta'];
$ownership = get_option(Demo_Content::OPTION);
demo_import();
equals('second run has identical posts', $demo_posts_snapshot, serialize($GLOBALS['posts']));
equals('second run has identical meta', $demo_meta_snapshot, $GLOBALS['meta']);
equals('second run has identical ownership', $ownership, get_option(Demo_Content::OPTION));
equals('second run does not upload again', 12, count($GLOBALS['demo_uploads']));
$quotes = get_posts(
	array('post_type' => 'homeward_story', 'meta_key' => 'homeward_kind', 'meta_value' => 'testimonial'),
);
equals('three testimonials', 3, count($quotes));
foreach ($quotes as $quote) {
	$animal = (int) get_post_meta($quote->ID, 'homeward_animal_id');
	$story = (int) get_post_meta($quote->ID, 'homeward_story_id');
	check('story target animal visible', Animal::public_readable($animal));
	equals(
		'testimonial animal target valid',
		(string) $animal,
		Content_Post_Types::sanitize_animal_id((string) $animal),
	);
	equals(
		'testimonial story target valid',
		(string) $story,
		Testimonials::sanitize_story_id((string) $story),
	);
	equals(
		'story and testimonial share animal',
		(string) $animal,
		get_post_meta($story, 'homeward_animal_id'),
	);
	check(
		'animal displays quote',
		str_contains(Testimonials::render_linked($animal), $quote->post_title),
	);
	check(
		'story displays quote',
		str_contains(Testimonials::render_linked($story), $quote->post_title),
	);
	check(
		'profile links to adoption story',
		str_contains(Content_Render::linked_stories($animal), get_permalink($story)),
	);
	equals('demo identity recorded', 'demo', get_post_meta($animal, Animal::M_ADAPTER));
	check(
		'fixture private fields excluded',
		!str_contains(json_encode(Animal::data($animal)), 'NEVER_STORE'),
	);
}
// The link is part of both active and adopted profiles, not just an unused helper.
$animal = demo_created('animal:920001');
$story = demo_created('story:demo-nimbus-routine');
$GLOBALS['current_id'] = $animal;
check(
	'profile block has story link',
	str_contains(Blocks::render('animal-profile'), get_permalink($story)),
);
update_post_meta($animal, Animal::M_STATUS, 'gone');
Animal::refresh_derived($animal);
check(
	'adopted profile has story link',
	str_contains(Blocks::render('animal-profile'), get_permalink($story)),
);
update_post_meta($animal, Animal::M_STATUS, 'available');
Animal::refresh_derived($animal);
foreach (array('draft', 'private') as $status) {
	wp_update_post(array('ID' => $story, 'post_status' => $status));
	equals('hidden stories not linked', '', Content_Render::linked_stories($animal));
}
wp_update_post(array('ID' => $story, 'post_status' => 'publish', 'post_password' => 'example'));
equals('password story not linked', '', Content_Render::linked_stories($animal));
wp_update_post(array('ID' => $story, 'post_password' => ''));
Sync::run_listings();
Sync::daily_refresh();
Sync::process_details();
equals(
	'practice sync reuses demo animals',
	12,
	count(get_posts(array('post_type' => 'homeward_animal'))),
);
check(
	'illustration survives detail refresh',
	str_contains(Animal::get($animal)['photos'][0]['full'], (string) get_post_thumbnail_id($animal)),
);
equals('demo sync makes no network calls', array(), $GLOBALS['requests']);
$before_fixture = array();
foreach (get_option(Demo_Content::OPTION)['created'] as $key => $id) {
	if (str_starts_with($key, 'animal:')) {
		$before_fixture[$id] = array(clone get_post($id), $GLOBALS['meta'][$id]);
	}
}
update_option(
	'homeward_settings',
	array_merge(get_option('homeward_settings'), array('source' => 'fixture')),
);
Sync::run_now();
Sync::daily_refresh();
Sync::process_details();
equals('fixture adapter has its own records', 6, count(Animal::ps_map()));
foreach ($before_fixture as $id => $snapshot) {
	equals('fixture sync preserves demo post', serialize($snapshot[0]), serialize(get_post($id)));
	equals('fixture sync preserves all demo meta', $snapshot[1], $GLOBALS['meta'][$id]);
}
$fixture_ids = array_values(Animal::ps_map());
check('removal succeeds', !is_wp_error(Demo_Content::remove()));
foreach ($fixture_ids as $id) {
	check('fixture record remains after removal', (bool) get_post($id));
}
foreach ($before_fixture as $id => $snapshot) {
	check(
		'modified demo retained or unchanged demo trashed',
		serialize(get_post($id)) === serialize($snapshot[0]) || 'trash' === get_post_status($id),
	);
}
check('retained media remains tracked', (bool) get_option(Demo_Content::OPTION));
equals('referenced attachments preserved', 0, count($GLOBALS['deleted_attachments']));
check('second removal succeeds', !is_wp_error(Demo_Content::remove()));

// Fill wizard pages, preserve identities and titles, and restore only untouched copy.
demo_reset();
$original = array();
$demo_original_content = array();
foreach (Wizard::starter_pages() as $slug => $page) {
	$demo_original_content[$slug] = $page[1];
	$original[$slug] = wp_insert_post(
		array(
			'post_type' => 'page',
			'post_status' => 'publish',
			'post_name' => $slug,
			'post_title' => $page[0] . ' existing',
			'post_content' => $page[1],
		),
	);
	Demo_Content::remember_wizard($original[$slug]);
}
$unrelated = wp_insert_post(
	array('post_type' => 'post', 'post_title' => 'Real volunteer update', 'post_content' => 'Keep this'),
);
// A real-source animal can have the same numeric source ID without being adopted.
$real = wp_insert_post(
	array(
		'post_type' => 'homeward_animal',
		'post_title' => 'Real animal',
		'meta_input' => array(
			Animal::M_PS_ID => 910001,
			Animal::M_SOURCE => 123,
			Animal::M_ADAPTER => 'petstablished',
		),
	),
);
update_option(
	'homeward_settings',
	array(
		'source' => 'petstablished',
		'shelter_id' => 123,
		'org_name' => 'Existing rescue',
		'species' => array('dog', 'cat'),
	),
);
$settings = get_option('homeward_settings');
$result = demo_import();
equals('shelter configuration unchanged', $settings, get_option('homeward_settings'));
check('live source choice explained', str_contains($result['message'], 'were kept'));
check('demo animal distinct from live ID', $real !== demo_created('animal:920001'));
equals('wizard pages reused', 7, count(get_posts(array('post_type' => 'page'))));
foreach ($original as $slug => $id) {
	equals('page ID reused ' . $slug, $id, get_page_by_path($slug)->ID);
	check(
		'page filled ' . $slug,
		'home' === $slug || str_contains(get_post($id)->post_content, 'Demo page.'),
	);
	check('existing title kept ' . $slug, str_ends_with(get_the_title($id), ' existing'));
	check(
		'existing page not owned ' . $slug,
		!in_array($id, get_option(Demo_Content::OPTION)['created'], true),
	);
}
wp_update_post(array('ID' => $original['adopt'], 'post_content' => 'Our real adoption information'));
wp_update_post(
	array(
		'ID' => $original['contact'],
		'post_content' => get_post($original['contact'])->post_content . '<p>Our team edits remain.</p>',
	),
);
$owned_story = demo_created('story:demo-nimbus-routine');
update_post_meta($owned_story, 'homeward_text', 'Our edited story');
wp_update_post(array('ID' => $original['volunteer'], 'post_content' => 'Edited volunteer page'));
demo_import();
equals(
	'repeat preserves page edits',
	'Our real adoption information',
	get_post($original['adopt'])->post_content,
);
equals('repeat preserves story edits', 'Our edited story', get_post_meta($owned_story, 'homeward_text'));
// Removal checks both its ledger and marker before deleting anything.
$state = get_option(Demo_Content::OPTION);
$state['created']['untrusted:post'] = $unrelated;
update_option(Demo_Content::OPTION, $state);
check('remove mixed site succeeds', !is_wp_error(Demo_Content::remove()));
check('unrelated record kept', (bool) get_post($unrelated));
check('real animal kept', (bool) get_post($real));
equals(
	'all original pages kept',
	7,
	count(get_posts(array('post_type' => 'page', 'post_status' => 'publish'))),
);
equals(
	'changed existing page kept',
	'Our real adoption information',
	get_post($original['adopt'])->post_content,
);
foreach (
	array_diff_key(
		Wizard::starter_pages(),
		array('adopt' => true, 'volunteer' => true, 'contact' => true),
	) as $slug => $page
) {
	equals(
		'original content restored ' . $slug,
		$demo_original_content[$slug],
		get_post($original[$slug])->post_content,
	);
}
check(
	'modified practice page kept verbatim',
	str_contains(
		get_post($original['contact'])->post_content,
		'Our team edits remain.',
	) && str_contains(get_post($original['contact'])->post_content, 'example.test'),
);
equals('remove keeps source configuration', $settings, get_option('homeward_settings'));

// Existing fixture records keep their separate identity and are never owned or edited.
demo_reset();
update_option('homeward_settings', array('source' => 'fixture', 'shelter_id' => 0));
Sync::run_listings();
$existing_animals = Animal::all_ids();
$old_meta = $GLOBALS['meta'];
demo_import();
foreach ($existing_animals as $id) {
	check(
		'existing animal excluded from ledger',
		!in_array($id, get_option(Demo_Content::OPTION)['created'], true),
	);
	equals('existing animal meta untouched', $old_meta[$id], $GLOBALS['meta'][$id]);
}
Demo_Content::remove();
foreach ($existing_animals as $id) {
	check('existing fixture animal survives removal', 'trash' !== get_post_status($id));
}
foreach (array(array('cat'), array('dog')) as $selected) {
	demo_reset();
	update_option('homeward_settings', array('species' => $selected));
	update_option('homeward_species_customized', true);
	demo_import();
	equals('chosen species preserved', $selected, Settings::get('species'));
	foreach (get_posts(array('post_type' => 'homeward_story')) as $story) {
		check(
			'story remaps to visible practice animal',
			Animal::public_readable((int) get_post_meta($story->ID, 'homeward_animal_id')),
		);
	}
	Demo_Content::remove();
}
demo_reset();
update_option('homeward_settings', array('country' => 'CA', 'species' => array('dog', 'cat')));
demo_import();
check(
	'demo fees use site currency',
	str_contains(
		Blocks::render(
			'animal-profile',
			array(),
			(object) array('context' => array('postId' => demo_created('animal:920001'))),
		),
		'CAD',
	),
);
Demo_Content::remove();
equals('removed practice feed stays empty', array(), (new Homeward\Source_Demo())->fetch_all());
// Interrupted imports resume, failed removal retains a ledger for retry.
demo_reset();
$GLOBALS['upload_error'] = true;
check('upload error reported', is_wp_error(Demo_Content::import()));
equals('no content after upload error', array(), $GLOBALS['posts']);
equals('failure releases lock', false, get_option(Demo_Content::LOCK));
$GLOBALS['upload_error'] = false;
demo_import();
$state = get_option(Demo_Content::OPTION);
$first = array_key_first($state['created']);
unset($state['created'][$first]);
update_option(Demo_Content::OPTION, $state);
demo_import();
equals('interrupted insert recovered by marker', 36, count($GLOBALS['posts']));
equals('recovered attachment tracked', 36, count(get_option(Demo_Content::OPTION)['created']));
$GLOBALS['delete_error'] = true;
check('delete failure reported', is_wp_error(Demo_Content::remove()));
check('failed deletion retains ownership', (bool) get_option(Demo_Content::OPTION));
$GLOBALS['delete_error'] = false;
Demo_Content::remove();
equals(
	'deletion resumes with all demo posts in trash',
	24,
	count(
		get_posts(
			array('post_type' => array('page', 'homeward_story', 'homeward_animal'), 'post_status' => 'trash'),
		),
	),
);

// Validate all serialized block comments, including nested core blocks. The core
// schemas below are the strict subset used by the sample, with block supports.
Blocks::register();
$schemas = array();
foreach ($GLOBALS['blocks'] as $demo_block) {
	$schemas[$demo_block['name']] = $demo_block['attributes'];
}
$common = array(
	'className' => array('type' => 'string'),
	'anchor' => array('type' => 'string'),
	'align' => array('type' => 'string', 'enum' => array('wide', 'full', 'left', 'right', 'center')),
);
$schemas['core/group'] = $common + array(
	'tagName' => array('type' => 'string', 'enum' => array('div', 'section')),
	'layout' => array('type' => 'object'),
);
$schemas['core/heading'] = $common + array(
	'level' => array('type' => 'number'),
	'fontSize' => array('type' => 'string'),
);
$schemas['core/paragraph'] = $common + array('fontSize' => array('type' => 'string'));
$schemas['core/list'] = $common + array('ordered' => array('type' => 'boolean'));
$schemas['core/list-item'] = $common;
$schemas['core/html'] = array();

function demo_value_valid($value, $schema) {
	$ok = match ($schema['type']) {
		'string' => is_string($value),
		'number' => is_int($value) || is_float($value),
		'boolean' => is_bool($value),
		'object' => is_object($value),
		'array' => is_array($value),
		default => false,
	};
	if (!$ok || isset($schema['enum']) && !in_array($value, $schema['enum'], true)) {
		return false;
	}
	if (isset($schema['items'])) {
		foreach ($value as $item) {
			if (!demo_value_valid($item, $schema['items'])) {
				return false;
			}
		}
	}
	return true;
}

function demo_blocks_valid($content, $schemas) {
	preg_match_all(
		'/<!--\s*(\/?)wp:([a-z0-9-]+(?:\/[a-z0-9-]+)?)(.*?)-->/s',
		$content,
		$matches,
		PREG_SET_ORDER,
	);
	$stack = array();
	foreach ($matches as $match) {
		$name = str_contains($match[2], '/') ? $match[2] : 'core/' . $match[2];
		if (!isset($schemas[$name])) {
			return false;
		}
		if ($match[1]) {
			if (trim($match[3]) || array_pop($stack) !== $name) {
				return false;
			}
			continue;
		}
		$attrs = trim($match[3]);
		$self = str_ends_with($attrs, '/');
		if ($self) {
			$attrs = rtrim(substr($attrs, 0, -1));
		}
		$values = $attrs ? json_decode($attrs) : new stdClass();
		if (!is_object($values) || JSON_ERROR_NONE !== json_last_error() && $attrs) {
			return false;
		}
		foreach ($values as $key => $value) {
			if (!isset($schemas[$name][$key]) || !demo_value_valid($value, $schemas[$name][$key])) {
				return false;
			}
		}
		if (
			isset($values->layout) && (
				!isset($values->layout->type) || !in_array(
					$values->layout->type,
					array('constrained', 'default', 'flex', 'grid'),
					true,
				)
			)
		) {
			return false;
		}
		if (
			isset($values->level) && (
				$values->level < 1 || $values->level > 6 || floor($values->level) != $values->level
			)
		) {
			return false;
		}
		if (!$self) {
			$stack[] = $name;
		}
	}
	return !$stack;
}

$manifest = json_decode(
	file_get_contents(Demo_Content::directory() . '/sample.json'),
	true,
	512,
	JSON_THROW_ON_ERROR,
);
foreach ($manifest['pages'] as $page) {
	if ('donate' === $page['slug']) {
		equals(
			'donate panel appears once',
			1,
			substr_count($page['content'], 'wp:homeward/donate-panel'),
		);
		check(
			'donate panel uses wide space',
			str_contains($page['content'], 'alignwide') && !str_contains($page['content'], 'homeward-two-up'),
		);
		check(
			'donate panel has no duplicate heading or transfer card',
			!str_contains($page['content'], 'wp:heading') && !str_contains(
				$page['content'],
				'data-homeward-copy',
			),
		);
	}
	check(
		'real blocks and valid attributes on ' . $page['slug'],
		demo_blocks_valid($page['content'], $schemas),
	);
	foreach ($page['patterns'] as $pattern) {
		check(
			'pattern exists',
			is_file(dirname(__DIR__, 2) . '/theme/homeward/patterns/' . substr($pattern, 9) . '.php'),
		);
	}
}
check('unknown blocks fail validation', !demo_blocks_valid('<!-- wp:homeward/invented /-->', $schemas));
foreach (
	array(
		'<!-- wp:homeward/apply-button {"type":"invalid"} /-->',
		'<!-- wp:homeward/animal-grid {"columns":"three"} /-->',
		'<!-- wp:homeward/story-cards {"invented":true} /-->',
		'<!-- wp:group {"layout":"wide"} /-->',
		'<!-- wp:heading {"level":9} --><h9>Wrong</h9><!-- /wp:heading -->',
		'<!-- wp:group --><!-- /wp:paragraph -->',
	) as $bad
) {
	check('invalid attributes or structure rejected', !demo_blocks_valid($bad, $schemas));
}
equals(
	'twelve distinct illustrations',
	12,
	count(
		array_unique(
			array_map(
				fn($image) => hash_file('sha256', Demo_Content::directory() . '/images/' . $image['file']),
				$manifest['images'],
			),
		),
	),
);
// Inspect every PNG chunk and palette, rather than trusting a file extension.
foreach ($manifest['images'] as $image) {
	$png = file_get_contents(Demo_Content::directory() . '/images/' . $image['file']);
	equals('PNG signature', "\x89PNG\r\n\x1a\n", substr($png, 0, 8));
	$offset = 8;
	$types = array();
	$compressed = '';
	while ($offset < strlen($png)) {
		$length = unpack('N', substr($png, $offset, 4))[1];
		$type = substr($png, $offset + 4, 4);
		$payload = substr($png, $offset + 8, $length);
		$crc = unpack('N', substr($png, $offset + 8 + $length, 4))[1];
		equals('PNG chunk checksum', $crc, crc32($type . $payload));
		$types[] = $type;
		if ('IDAT' === $type) {
			$compressed .= $payload;
		}
		$offset += 12 + $length;
	}
	equals('no metadata chunks', array('IHDR', 'IDAT', 'IEND'), $types);
	$raw = zlib_decode($compressed);
	$palette = array_map('hex2bin', array('1F4E79', 'B58B35', 'F7F1E3', 'FFFDF8', '2B2A28'));
	$valid = true;
	for ($y = 0; $y < 480; $y++) {
		if ("\0" !== $raw[$y * 1921]) {
			$valid = false;
		}
		for ($x = 0; $x < 640; $x++) {
			if (!in_array(substr($raw, $y * 1921 + 1 + $x * 3, 3), $palette, true)) {
				$valid = false;
			}
		}
	}
	check('only kit palette pixels', $valid);
}
check(
	'sample has no forbidden punctuation',
	!str_contains(json_encode($manifest, JSON_UNESCAPED_UNICODE), "\u{2014}"),
);

// Run the full import with the same sanitizer and settings hooks as WordPress.
demo_reset();
$GLOBALS['real_option_hooks'] = true;
add_filter('sanitize_option_homeward_settings', array(Settings::class, 'sanitize'));
add_action('update_option_homeward_settings', array(Settings::class, 'saved'), 10, 2);
Homeward\Config_Import::save_settings(
	array('org_name' => 'Example Rescue', 'source' => 'fixture', 'shelter_id' => 0, 'country' => 'CA'),
);
check('ordinary wizard saves do not customize species', !get_option('homeward_species_customized'));
demo_import();
equals('hooked import selects demo adapter', 'demo', Settings::get('source'));
equals('hooked import shows twelve animals', 12, count(Animal::query()));
Sync::run_now();
equals('hooked demo sync keeps twelve animals', 12, count(Animal::query()));
equals('country currency survives demo connection', 'CAD', Settings::get('currency'));
equals('automatic currency stays automatic', '', get_option('homeward_settings')['currency']);
update_option('homeward_settings', Settings::sanitize(array('species' => array('dog'))));
check('species changes are remembered', get_option('homeward_species_customized'));
demo_import();
equals('repeat import respects dogs-only choice', array('dog'), Settings::get('species'));
equals('chosen dogs-only set visible', 10, count(Animal::query()));
Demo_Content::remove();

// Exercise the real wizard action, reporting, menu reuse and CLI entry point.
demo_reset();
Demo_Content::init();
$colors = array_intersect_key(Settings::defaults(), array_flip(Wizard::STEPS[4]));
foreach (
	array(
		1 => array('org_name' => 'Example Rescue'),
		2 => array('source' => 'fixture', 'shelter_id' => 0),
		3 => array(),
		4 => $colors,
		5 => array('font_heading' => 'fraunces', 'font_body' => 'inter'),
	) as $step => $fields
) {
	Wizard::save_step($step, $fields);
}
$result = Wizard::save_step(6, array('activate_theme' => false, 'import_demo' => true));
check('wizard imports and completes', !is_wp_error($result) && $result['completed_at'] > 0);
equals('wizard with demo has seven pages', 7, count(get_posts(array('post_type' => 'page'))));
equals('wizard sync does not add animals', 12, count(Animal::all_ids()));
check(
	'wizard reports fixture choice',
	str_contains(implode(' ', $result['messages']), 'Petstablished shelter number'),
);
Demo_Content::remove();
equals('removal keeps seven wizard-created pages', 7, count(get_posts(array('post_type' => 'page'))));
demo_reset();
Demo_Content::init();
$GLOBALS['upload_error'] = true;
foreach (
	array(
		1 => array('org_name' => 'Example Rescue'),
		2 => array('source' => 'fixture', 'shelter_id' => 0),
		3 => array(),
		4 => $colors,
		5 => array('font_heading' => 'fraunces', 'font_body' => 'inter'),
	) as $step => $fields
) {
	Wizard::save_step($step, $fields);
}
check(
	'wizard reports demo upload failure',
	is_wp_error(Wizard::save_step(6, array('activate_theme' => false, 'import_demo' => true))),
);
equals('failed demo not marked imported', false, isset(Wizard::state()['demo_imported_at']));
$GLOBALS['upload_error'] = false;
check(
	'wizard retry succeeds',
	!is_wp_error(Wizard::save_step(6, array('activate_theme' => false, 'import_demo' => true))),
);

class WP_CLI {
	public static $commands = array(), $message = '';

	public static function add_command($name, $callback) {
		self::$commands[$name] = $callback;
	}

	public static function success($message) {
		self::$message = $message;
	}

	public static function error($message) {
		throw new RuntimeException($message);
	}
}

define('WP_CLI', true);
Demo_Content::init();
check('CLI command registered', isset(WP_CLI::$commands['homeward import-demo']));
call_user_func(WP_CLI::$commands['homeward import-demo'], array(), array());
check('CLI import reports success', str_contains(WP_CLI::$message, 'Practice content is ready'));
call_user_func(WP_CLI::$commands['homeward import-demo'], array(), array('remove' => true));
check('CLI removal reports success', str_contains(WP_CLI::$message, 'were removed'));
summary('demo content');
