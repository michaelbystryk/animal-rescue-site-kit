<?php

/** Regressions from the first WordPress run, without network or cron. */
require __DIR__ . '/lib/bootstrap.php';
use Homeward\{
	Settings,
	Settings_Page,
	Config_Import,
	Sync,
	Source,
	Source_Fixture,
	Animal,
	Content_Render,
	Brand,
};

// An omitted currency stays automatic across saves and country changes.
foreach (
	array(
		'CA' => 'CAD',
		'US' => 'USD',
		'GB' => 'GBP',
		'AU' => 'AUD',
		'NZ' => 'NZD',
		'IE' => 'EUR',
		'FR' => 'EUR',
		'DE' => 'EUR',
		'SE' => 'EUR',
		'' => 'USD',
	) as $country => $currency
) {
	setup_reset();
	update_option('homeward_settings', Settings::sanitize(array('country' => $country)));
	equals('automatic currency for ' . $country, $currency, Settings::get('currency'));
	equals('automatic choice stays unset', '', get_option('homeward_settings')['currency']);
}
setup_reset();
Config_Import::save_settings(array('org_name' => 'Cloud Rescue', 'country' => 'CA'));
equals('shared save sets WordPress title', 'Cloud Rescue', get_option('blogname'));
equals('importer infers Canadian currency', 'CAD', Settings::get('currency'));
Config_Import::save_settings(array('country' => 'GB'));
equals('automatic currency follows new country', 'GBP', Settings::get('currency'));
Config_Import::save_settings(array('currency' => 'NZD', 'country' => 'CA'));
equals('explicit currency overrides country', 'NZD', Settings::get('currency'));
Config_Import::save_settings(array('country' => 'AU'));
equals('explicit currency survives other saves', 'NZD', Settings::get('currency'));
Config_Import::save_settings(array('currency' => ''));
equals('blank restores automatic currency', 'AUD', Settings::get('currency'));
check(
	'invalid currency import rejected',
	is_wp_error(Config_Import::save_settings(array('currency' => 'money'))),
);
$logo = wp_insert_post(
	array('post_type' => 'attachment', 'post_title' => 'Sample logo', 'post_mime_type' => 'image/png'),
);
Config_Import::save_settings(array('logo_id' => $logo));
equals('shared save applies custom logo', $logo, get_theme_mod('custom_logo'));

// Listing meta exists before details. The default dog-only filter, hold status and
// courtesy settings explain why only the two sample dogs appear in the list.
setup_reset();
update_option('homeward_settings', Settings::sanitize(array('source' => 'fixture', 'shelter_id' => 0)));
Sync::run_listings();
equals('two eligible dogs visible before details', 2, count(Animal::query()));
$map = Animal::ps_map();
check(
	'listing computes derived visibility immediately',
	'1' === get_post_meta($map[910001], Animal::M_LISTED, true),
);
check('details still pending in scheduled path', (bool) Sync::queue());
equals('application placeholder stays draft', 'draft', get_post_status($map[910004]));
update_post_meta($map[910004], Animal::M_SHOW, 'show');
wp_update_post(array('ID' => $map[910004], 'post_status' => 'publish'));
Animal::refresh_derived($map[910004]);
equals('show override cannot publish a placeholder', 'draft', get_post_status($map[910004]));
check('placeholder cannot enter public listing', !Animal::get($map[910004])['listed']);
Sync::run_now();
equals('manual sync drains details without cron', array(), Sync::queue());
foreach (Animal::ps_map() as $id) {
	check('all small-feed details completed', (bool) get_post_meta($id, Animal::M_DETAIL, true));
}
check(
	'profile uses plain label',
	str_contains(
		Homeward\Blocks::render(
			'animal-profile',
			array(),
			(object) array('context' => array('postId' => $map[910001])),
		),
		'Adoption details',
	),
);

// Changing species also refreshes existing demo cats without cron.
$GLOBALS['real_option_hooks'] = true;
add_action('update_option_homeward_settings', array(Settings::class, 'saved'), 10, 2);
update_option('homeward_settings', Settings::sanitize(array('species' => array('dog', 'cat'))));
Sync::run_now();
equals('eligible cat joins dogs', 3, count(Animal::query()));
$map = Animal::ps_map();
update_option('homeward_settings', Settings::sanitize(array('species' => array('dog'))));
equals('cat hidden immediately', '0', get_post_meta($map[910003], Animal::M_LISTED, true));
update_option('homeward_settings', Settings::sanitize(array('species' => array('dog', 'cat'))));
equals('existing cat restored immediately', '1', get_post_meta($map[910003], Animal::M_LISTED, true));
equals('public list refreshed after settings change', 3, count(Animal::query()));

class Runtime_Source implements Source {
	public $detail_calls = 0;

	public function __construct(public $count, public $fail = false) {}

	public function label() {
		return 'Practice animals';
	}

	public function fetch_all() {
		return array_map(
			fn($id) => array(
				'id' => $id,
				'name' => 'Sample ' . $id,
				'animal' => 'Dog',
				'status' => 'Available',
			),
			range(1, $this->count),
		);
	}

	public function fetch_detail($id) {
		++$this->detail_calls;
		return $this->fail ? new WP_Error('sample', 'Sample error') : array(
			'id' => $id,
			'name' => 'Sample ' . $id,
			'animal' => 'Dog',
			'status' => 'Available',
			'description' => '<p>This is a made-up sample animal.</p>',
		);
	}
}

foreach (array(50, 51) as $count) {
	setup_reset();
	update_option('homeward_settings', array('source' => 'fixture'));
	$source = new Runtime_Source($count);
	$status = Sync::run_now($source);
	check('manual sync succeeds at ' . $count, $status['ok']);
	equals('bounded synchronous details at ' . $count, 50 === $count ? 50 : 0, $source->detail_calls);
	equals('remaining detail queue at ' . $count, 50 === $count ? 0 : 51, count(Sync::queue()));
	equals('listing works before background details at ' . $count, $count, count(Animal::query()));
}
setup_reset();
update_option('homeward_settings', array('source' => 'fixture'));
$source = new Runtime_Source(6, true);
$status = Sync::run_now($source);
check('initial detail failure reported', !$status['ok']);
equals('failed details attempted once each', 6, $source->detail_calls);
equals('failed detail queue retained', 6, count(Sync::queue()));
equals('listing survives detail error', 6, count(Animal::query()));

// Only truncated excerpts gain an ellipsis, with no sentence punctuation before it.
setup_reset();
$story = wp_insert_post(
	array('post_type' => 'homeward_story', 'post_title' => 'Sample story', 'post_status' => 'publish'),
);
foreach (array('.', '...', '!', '?', ';', '…') as $punctuation) {
	update_post_meta($story, 'homeward_kind', 'story');
	update_post_meta(
		$story,
		'homeward_text',
		str_repeat('word ', 31) . 'ending' . $punctuation . ' more words',
	);
	$html = Content_Render::story_card($story);
	check('story trims punctuation ' . $punctuation, str_contains($html, 'ending…'));
}
update_post_meta($story, 'homeward_text', 'A complete short sentence.');
check(
	'complete story keeps its full stop',
	str_contains(Content_Render::story_card($story), 'A complete short sentence.'),
);
update_post_meta($story, 'homeward_kind', 'testimonial');
update_post_meta($story, 'homeward_text', str_repeat('a', 199) . '. more');
check(
	'character excerpt trims punctuation',
	str_contains(Content_Render::story_card($story), str_repeat('a', 199) . '…'),
);

// Page choices exclude private and protected pages and allow clearing every choice.
$public = wp_insert_post(
	array('post_type' => 'page', 'post_title' => 'Public choice', 'post_status' => 'publish'),
);
wp_insert_post(array('post_type' => 'page', 'post_title' => 'Private choice', 'post_status' => 'private'));
wp_insert_post(
	array(
		'post_type' => 'page',
		'post_title' => 'Protected choice',
		'post_status' => 'publish',
		'post_password' => 'sample',
	),
);
ob_start();
Settings_Page::checkbox_list('llms_pages', array($public), 'homeward_settings[llms_pages]');
$html = ob_get_clean();
check(
	'page picker is checkboxes',
	str_contains($html, 'type="checkbox"') && !str_contains($html, 'textarea'),
);
check('public choice selected', str_contains($html, 'checked="checked"'));
check('private page excluded', !str_contains($html, 'Private choice'));
check('protected page excluded', !str_contains($html, 'Protected choice'));
update_option('homeward_settings', array('llms_pages' => array($public)));
equals(
	'page choices can all be unchecked',
	array(),
	Settings_Page::sanitize_form(array('llms_pages' => ''))['llms_pages'],
);
foreach (array(array(false, 'valid'), array(true, 'invalid')) as [$can, $nonce]) {
	$GLOBALS['can'] = $can;
	$_POST = array('_wpnonce' => $nonce);
	try {
		Settings_Page::remove_demo();
		check('removal rejects bad authorization', false);
	} catch (RuntimeException $error) {
		equals(
			'removal checks permission and nonce',
			$can ? 'nonce' : 'permission',
			$error->getMessage(),
		);
	}
}
summary('runtime regression');
