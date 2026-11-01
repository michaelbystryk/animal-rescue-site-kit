<?php

/** Behavioral regressions for setup, configuration and browser asset fixes. */
require __DIR__ . '/lib/bootstrap.php';
use Homeward\{
	Settings,
	Settings_Page,
	Config_Import,
	Wizard,
	Plugin,
	Pet_Data,
	Frontend,
	Animal_Post_Type,
	Sync,
};
set_error_handler(static function ($severity, $message, $file, $line) {
	if (error_reporting() & $severity) {
		throw new ErrorException($message, 0, $severity, $file, $line);
	}
	return false;
});

function get_theme_file_uri($path) {
	return home_url('/other-theme/' . $path);
}

class WP_Block_Type_Registry {
	public static function get_instance() {
		return new self();
	}

	public function is_registered($name) {
		return true;
	}
}

function wp_register_script_module(...$args) {
	throw new RuntimeException('Classic scripts must not be registered as modules');
}

function wp_enqueue_script_module(...$args) {
	throw new RuntimeException('Classic scripts must not be enqueued as modules');
}

// Zero and blank shelter numbers replace the default live adapter without a second choice.

foreach (array(0, '0', '') as $shelter) {
	setup_reset();
	$result = Config_Import::save_settings(array('shelter_id' => $shelter));
	check('sample shelter saves', !is_wp_error($result));
	equals('sample adapter', 'fixture', Settings::get('source'));
	check(
		'source check needs only shelter',
		!is_wp_error(Wizard::check_source(array('shelter_id' => $shelter))),
	);
}
equals(
	'positive shelter selects live adapter',
	'petstablished',
	Config_Import::validate_settings(array('shelter_id' => 123))['source'],
);
check(
	'nested species rejected',
	is_wp_error(Config_Import::validate_settings(array('species' => array(array('cat'))))),
);

// Imports record the steps they populate and can finish immediately after a full import.
setup_reset();
$config = json_decode(file_get_contents(dirname(__DIR__, 2) . '/config.example.json'), true);
$config['settings']['contact_email'] = '';
$config['settings']['donate_etransfer_email'] = '';
$config['settings']['donate_card_url'] = '';
check(
	'full configuration import succeeds',
	!is_wp_error(Config_Import::import_json(json_encode($config))),
);
equals('import fills first five steps', range(1, 5), array_keys(Wizard::state()['steps']));
check(
	'import can finish',
	!is_wp_error(Wizard::save_step(6, array('activate_theme' => false, 'import_demo' => false))),
);
equals('home selected', Wizard::state()['pages']['home'], get_option('page_on_front'));

// Real bundled patterns are materialized into page blocks even with an inactive theme.
$GLOBALS['theme_directory'] = dirname(__DIR__, 2) . '/theme/homeward';
$home = Wizard::home_content();
check(
	'all ten starter patterns expanded',
	substr_count($home, '<!-- wp:group') >= 10 && !str_contains($home, 'wp:pattern'),
);
check(
	'home uses rescue name',
	str_contains($home, Settings::get('org_name')) && !str_contains($home, 'Sample rescue website'),
);
check(
	'no invented donation address',
	!str_contains($home, 'example.test') && str_contains($home, 'wp:homeward/donate-panel'),
);
check(
	'inactive theme assets use Homeward URL',
	str_contains(
		$home,
		'/theme/homeward/assets/images/hero-placeholder.svg',
	) && !str_contains($home, '/other-theme/'),
);
check(
	'all core home sections present',
	str_contains(
		$home,
		'id="mission"',
	) && str_contains($home, 'id="give"') && str_contains($home, 'id="contact"'),
);
$before = $home;
Config_Import::save_settings(array('org_name' => 'Changed Rescue'));
check(
	'new starter content follows settings',
	str_contains(Wizard::home_content(), 'Changed Rescue') && $before !== Wizard::home_content(),
);
unset($GLOBALS['theme_directory']);

// No existing front page or page content is replaced by setup.
setup_reset();
$existing = wp_insert_post(
	array(
		'post_type' => 'page',
		'post_status' => 'publish',
		'post_name' => 'home',
		'post_title' => 'Our Home',
		'post_content' => 'Keep our home',
	),
);
update_option('show_on_front', 'page');
update_option('page_on_front', $existing);
Config_Import::import_json(json_encode($config));
Wizard::save_step(6, array('activate_theme' => false, 'import_demo' => false));
equals('existing Home unchanged', 'Keep our home', get_post($existing)->post_content);
equals('existing front page kept', $existing, get_option('page_on_front'));

setup_reset();
update_option('homeward_wizard', array('completed_at' => time(), 'pages' => array('adopt' => 123)));
$GLOBALS['stylesheet'] = 'homeward';
Wizard::upgrade_home();
$migrated = Wizard::state()['pages']['home'];
equals('completed legacy setup gets editable Home', $migrated, get_option('page_on_front'));
check(
	'migrated Home has real blocks',
	str_contains(get_post($migrated)->post_content, 'wp:homeward/animal-carousel'),
);
Wizard::upgrade_home();
equals('home upgrade is idempotent', 1, count(get_posts(array('post_type' => 'page'))));

// Automatic slugs follow species. Explicit and legacy slugs remain stable.
setup_reset();
Plugin::activate();
check(
	'activation does not freeze default slugs',
	!isset(get_option('homeward_settings')['archive_slug']),
);
$GLOBALS['real_option_hooks'] = true;
add_action('update_option_homeward_settings', array(Settings::class, 'saved'), 10, 2);
foreach (
	array(
		array(array('cat'), 'cats'),
		array(array('dog', 'cat'), 'animals'),
		array(array('dog'), 'dogs'),
	) as [$species, $slug]
) {
	check(
		'wizard species saves',
		!is_wp_error(Wizard::save_step(2, array('shelter_id' => 0, 'species' => $species))),
	);
	equals('species archive slug', $slug, Settings::get('archive_slug'));
	equals('species single slug', $slug, Settings::get('single_slug'));
	check('species requests rewrite flush', (bool) get_option('homeward_flush_rewrites'));
	Plugin::rewrites();
	check('rewrite flush consumed', !get_option('homeward_flush_rewrites'));
}
update_option(
	'homeward_settings',
	Settings::sanitize(array('archive_slug' => 'companions', 'single_slug' => 'meet')),
);
Wizard::save_step(2, array('shelter_id' => 0, 'species' => array('cat')));
equals('custom archive kept', 'companions', Settings::get('archive_slug'));
equals('custom single kept', 'meet', Settings::get('single_slug'));

// Invalid and expensive patterns fail safely, with the process guard restored.
setup_reset();
$limit = ini_get('pcre.backtrack_limit');
$clean = Settings::sanitize(
	array(
		'placeholder_pattern' => '/[',
		'courtesy_pattern' => '/[',
		'origin_rules' => array(array('region' => 'Coast', 'patterns' => array('/[', '/coast/i'))),
	),
);
equals('invalid placeholder fallback', Settings::PLACEHOLDER, $clean['placeholder_pattern']);
equals(
	'invalid courtesy fallback',
	Settings::defaults()['courtesy_pattern'],
	$clean['courtesy_pattern'],
);
equals('invalid origin patterns removed', array('/coast/i'), $clean['origin_rules'][0]['patterns']);
$subject = 'a ' . str_repeat('a', 20000) . '! application';
equals(
	'bounded expensive regex falls back',
	true,
	Settings::match_pattern('/^(a|a )+$/', $subject, Settings::PLACEHOLDER),
);
equals('PCRE limit restored', $limit, ini_get('pcre.backtrack_limit'));
update_option(
	'homeward_settings',
	array(
		'placeholder_pattern' => '/[',
		'courtesy_pattern' => '/[',
		'origin_enabled' => true,
		'origin_rules' => array(array('region' => 'Coast', 'patterns' => array('/['))),
	),
);
check('legacy malformed placeholder safe', Pet_Data::is_placeholder('A generic application'));
check('legacy malformed courtesy safe', Pet_Data::is_courtesy('Courtesy post'));
equals('legacy malformed origin safe', '', Pet_Data::map_origin('Animal (Coast)'));

// Dead fields are ignored and both old PayPal formats migrate without breaking links.
foreach (array('brand_preset', 'radius', 'safe_mode', 'safe_mode_patterns', 'revision_limit') as $field) {
	check(
		'dead field absent ' . $field,
		!array_key_exists($field, Settings::sanitize(array($field => 'legacy'))),
	);
}
foreach (
	array(
		array('donate_paypal_me' => 'ExampleRescue'),
		array('donate_paypal_button_id' => 'EXAMPLE123'),
		array('donate_paypal_url' => '', 'donate_paypal_me' => 'ExampleRescue'),
	) as $legacy
) {
	update_option('homeward_settings', $legacy);
	$url = Settings::get('donate_paypal_url');
	check('legacy link converted', str_starts_with($url, 'https://www.paypal.com/'));
	update_option('homeward_settings', Settings::sanitize(array('org_name' => 'Example Rescue')));
	equals('legacy link persisted once', $url, Settings::get('donate_paypal_url'));
	check(
		'legacy fields removed',
		!isset(get_option('homeward_settings')['donate_paypal_me']) && !isset(
			get_option('homeward_settings')['donate_paypal_button_id'],
		),
	);
	update_option('homeward_settings', Settings::sanitize(array('donate_paypal_url' => '')));
	equals('canonical blank disables PayPal', '', Settings::get('donate_paypal_url'));
}

setup_reset();
$id = wp_insert_post(array('post_type' => 'homeward_animal', 'post_title' => 'Example'));
$_POST = array('homeward_animal_nonce' => 'valid', 'homeward_display_name' => 'Unauthorized');
$GLOBALS['can'] = array('edit_post');
Animal_Post_Type::save($id);
equals('override save needs dedicated cap', '', get_post_meta($id, 'homeward_display_name', true));
equals(
	'REST override needs dedicated cap',
	false,
	Animal_Post_Type::auth_overrides(false, 'homeward_display_name', $id),
);
$GLOBALS['can'][] = 'homeward_edit_overrides';
equals(
	'REST override accepts both caps',
	true,
	Animal_Post_Type::auth_overrides(false, 'homeward_display_name', $id),
);
Animal_Post_Type::save($id);
equals('authorized override saved', 'Unauthorized', get_post_meta($id, 'homeward_display_name', true));
$GLOBALS['can'] = array('homeward_edit_overrides');
equals(
	'REST also needs post permission',
	false,
	Animal_Post_Type::auth_overrides(false, 'homeward_display_name', $id),
);
$GLOBALS['can'] = true;
Frontend::register_assets();
foreach (array('animals', 'stories', 'donate') as $name) {
	equals(
		'classic script depends on config',
		'animals' === $name ? array('homeward-config', 'homeward-qr') : array('homeward-config'),
		$GLOBALS['scripts']['homeward-' . $name][2],
	);
	Frontend::script($name);
	check('classic script enqueued', in_array('homeward-' . $name, $GLOBALS['enqueued'], true));
}
check(
	'configuration attached to normal script',
	isset(
		$GLOBALS['script_config']['homeward-config']['homewardAnimalsL10n'],
		$GLOBALS['script_config']['homeward-config']['homewardDonateL10n'],
	),
);
summary('review fixes');
