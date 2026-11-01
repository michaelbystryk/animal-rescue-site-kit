<?php

define('WP_CLI', true);

class WP_CLI {
	public static $commands = array(), $success = '';

	public static function add_command($name, $callback) {
		self::$commands[$name] = $callback;
	}

	public static function error($message) {
		throw new RuntimeException($message);
	}

	public static function success($message) {
		self::$success = $message;
	}
}

require __DIR__ . '/lib/bootstrap.php';
use Homeward\{Config_Import, Settings, Settings_Page, Wizard, Sync, Sync_Lock};
Config_Import::init();
check('CLI registered', isset(WP_CLI::$commands['homeward import-config']));
$example = dirname(__DIR__, 2) . '/config.example.json';
$json = file_get_contents($example);
$config = json_decode($json, true);
check('example validates', !is_wp_error(Config_Import::decode($json)));
$before = $GLOBALS['options'];
foreach (array('bad', '[]', 'null', '{}', '{"version":1,"settings":[]}', str_repeat(' ', 65537)) as $bad) {
	check('malformed input rejected', is_wp_error(Config_Import::import_json($bad)));
	equals('malformed input writes nothing', $before, $GLOBALS['options']);
}
foreach (array('root', 'settings', 'finish') as $where) {
	$invalid = $config;
	if ('root' === $where) {
		$invalid['surprise'] = true;
	} else {
		$invalid[$where]['surprise'] = true;
	}
	$error = Config_Import::import_json(json_encode($invalid));
	check(
		'unknown key rejected ' . $where,
		is_wp_error($error) && str_contains($error->get_error_message(), 'surprise'),
	);
	equals('unknown key makes no writes', $before, $GLOBALS['options']);
}
foreach (
	array(
		'posthog_key' => 'bad',
		'posthog_region' => 'other',
		'org_name' => '',
		'logo_id' => -1,
		'source' => 'remote',
		'shelter_id' => 'abc',
		'contact_email' => 'not-email',
		'country' => 'Canada',
		'donate_paypal_url' => 'https://paypal.com.example.test/donate',
		'donate_card_url' => 'http://example.test/donate',
		'donate_etransfer_email' => 'bad',
		'color_ink' => '#12',
		'font_heading' => 'web-font',
		'font_body' => array('inter'),
	) as $key => $value
) {
	$invalid = $config;
	$invalid['settings'][$key] = $value;
	check('reject invalid ' . $key, is_wp_error(Config_Import::import_json(json_encode($invalid))));
	equals('validation atomic ' . $key, $before, $GLOBALS['options']);
}
$invalid = $config;
$invalid['finish']['import_demo'] = 'yes';
check('boolean finish required', is_wp_error(Config_Import::import_json(json_encode($invalid))));
$invalid = $config;
$invalid['version'] = 2;
check('version enforced', is_wp_error(Config_Import::import_json(json_encode($invalid))));
foreach (
	array(
		'https://paypal.com@evil.example.test/path',
		'https://paypal.com:9999/donate',
		'javascript:alert(1)',
		'http://paypal.me/example',
	) as $url
) {
	equals('unsafe PayPal URL rejected', '', Config_Import::paypal_url($url));
}
equals(
	'PayPal link accepted',
	'https://paypal.me/ExampleRescue',
	Config_Import::paypal_url('https://paypal.me/ExampleRescue'),
);
check(
	'remote config never fetched',
	is_wp_error(Config_Import::import_file('https://example.test/config.json')),
);
check(
	'missing local config rejected',
	is_wp_error(Config_Import::import_file(__DIR__ . '/missing.json')),
);
equals('no network used', array(), $GLOBALS['requests']);
Config_Import::cli(array($example), array());
check('CLI success explains review', str_contains(WP_CLI::$success, 'review'));
foreach ($config['settings'] as $key => $value) {
	equals('imported ' . $key, $value, Settings::get($key));
}
equals('CLI imports default region host', 'https://us.i.posthog.com', Settings::get('posthog_host'));
$stats_config = $config;
$stats_config['settings']['posthog_key'] = 'phc_' . str_repeat('x', 24);
$stats_config['settings']['posthog_region'] = 'eu';
$stats_file = tempnam(sys_get_temp_dir(), 'homeward-config-');
try {
	file_put_contents($stats_file, json_encode($stats_config));
	Config_Import::cli(array($stats_file), array());
	equals(
		'CLI saves fake key through validated path',
		$stats_config['settings']['posthog_key'],
		Settings::get('posthog_key'),
	);
	equals('CLI derives EU host', 'https://eu.i.posthog.com', Settings::get('posthog_host'));
	$stats_config['settings']['posthog_key'] = 'bad';
	file_put_contents($stats_file, json_encode($stats_config));
	$saved_options = $GLOBALS['options'];
	try {
		Config_Import::cli(array($stats_file), array());
		check('CLI rejects bad key', false);
	} catch (RuntimeException $error) {
		check('CLI explains invalid key', str_contains($error->getMessage(), 'phc_'));
	}
	equals('invalid CLI statistics makes no writes', $saved_options, $GLOBALS['options']);
} finally {
	unlink($stats_file);
}
check('import allows disabling statistics', !is_wp_error(Config_Import::import_json($json)));
equals('import clears statistics key', '', Settings::get('posthog_key'));
equals('finish choices saved', $config['finish'], Wizard::state()['finish']);
equals('import does not finish', 0, Wizard::state()['completed_at']);
equals('import does not publish pages', array(), $GLOBALS['posts']);
equals('import does not switch theme', 'other-theme', get_stylesheet());
equals('import does not sync', array(), $GLOBALS['requests']);
equals('site logo saved', 0, get_option('site_logo'));
equals('theme logo saved', 0, get_theme_mod('custom_logo'));
$logo = wp_insert_post(
	array('post_type' => 'attachment', 'post_title' => 'Example logo', 'post_mime_type' => 'image/png'),
);
check('existing image accepted', !is_wp_error(Config_Import::save_settings(array('logo_id' => $logo))));
equals('image applied to site logo', $logo, get_option('site_logo'));
check('non-image logo rejected', is_wp_error(Config_Import::save_settings(array('logo_id' => 999))));
update_option(
	'homeward_settings',
	array(
		'source' => 'petstablished',
		'shelter_id' => 100,
		'org_name' => 'Keep this name',
		'font_pair' => 'classic',
	),
);
$before = get_option('homeward_settings');
$state = Wizard::state();
check('source change requires confirmation', is_wp_error(Config_Import::import_json($json)));
equals('no partial name update', $before, get_option('homeward_settings'));
equals('no partial wizard update', $state, Wizard::state());
$lock = Sync_Lock::acquire();
check('busy source change rejected', is_wp_error(Config_Import::import_json($json, true)));
Sync_Lock::release($lock);
$GLOBALS['real_option_hooks'] = true;
add_filter('sanitize_option_homeward_settings', array(Settings_Page::class, 'sanitize_form'));
add_action('update_option_homeward_settings', array(Settings::class, 'saved'), 10, 2);
check(
	'confirmed import survives WordPress second sanitization',
	!is_wp_error(Config_Import::import_json($json, true)),
);
check(
	'EU statistics survives second sanitization',
	!is_wp_error(
		Config_Import::save_settings(
			array('posthog_key' => 'phc_' . str_repeat('x', 24), 'posthog_region' => 'eu'),
		),
	),
);
equals(
	'EU derived host survives second sanitization',
	'https://eu.i.posthog.com',
	Settings::get('posthog_host'),
);
equals('confirmed source saved', 'fixture', Settings::get('source'));
equals('source lock released', false, get_option('homeward_sync_lock'));
check('theme cache cleared', !empty($GLOBALS['theme_cleaned']));
check(
	'settings saved hook fired',
	in_array('homeward_settings_saved', array_column($GLOBALS['actions'], 0), true),
);
check(
	'control flag never persisted',
	!array_key_exists('confirm_source_change', get_option('homeward_settings')),
);
Sync::run_listings();
$fixture_ids = Homeward\Animal::all_ids();
check('fixture with shelter zero has animals', (bool) $fixture_ids);
check(
	'fixture replacement also requires confirmation',
	is_wp_error(Config_Import::save_settings(array('source' => 'petstablished', 'shelter_id' => 321))),
);
check(
	'confirmed fixture replacement saves',
	!is_wp_error(Config_Import::save_settings(array('source' => 'petstablished', 'shelter_id' => 321), true)),
);
foreach ($fixture_ids as $id) {
	equals('previous fixture animal moved to draft', 'draft', get_post_status($id));
}
// Every bundled font is a valid import value; unknown ones are not.
foreach (Homeward\Fonts::slugs() as $slug) {
	$ok = $config;
	$ok['settings']['font_heading'] = $slug;
	$ok['settings']['font_body'] = $slug;
	check(
		'import accepts font ' . $slug,
		!is_wp_error(Config_Import::validate_settings($ok['settings'])),
	);
}
$readme = file_get_contents(dirname(__DIR__, 2) . '/README.md');
foreach (Homeward\Fonts::slugs() as $slug) {
	check('README lists font ' . $slug, str_contains($readme, '`' . $slug . '`'));
}
check(
	'import rejects unknown font',
	is_wp_error(Config_Import::validate_settings(array('font_heading' => 'papyrus'))),
);
summary('config import');
