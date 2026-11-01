<?php

/** Execute the real uninstall entrypoint with isolated options, posts and object-cache doubles. */
define('ABSPATH', __DIR__ . '/');
define('WP_UNINSTALL_PLUGIN', true);
set_error_handler(static function ($severity, $message, $file, $line) {
	throw new ErrorException($message, 0, $severity, $file, $line);
});

function get_option($key, $default = false) {
	return $GLOBALS['options'][$key] ?? $default;
}

function delete_option($key) {
	unset($GLOBALS['options'][$key]);
}

function delete_transient($key) {
	unset(
		$GLOBALS['transients'][$key],
		$GLOBALS['options']['_transient_' . $key],
		$GLOBALS['options']['_transient_timeout_' . $key],
	);
}

function delete_site_transient($key) {
	unset(
		$GLOBALS['options']['_site_transient_' . $key],
		$GLOBALS['options']['_site_transient_timeout_' . $key],
	);
}

function wp_clear_scheduled_hook($hook) {
	unset($GLOBALS['cron'][$hook]);
}

function as_unschedule_all_actions($hook, $args, $group) {
	unset($GLOBALS['scheduled'][$hook]);
}

function maybe_serialize($value) {
	return $value;
}

function wp_cache_delete($key, $group) {
	$GLOBALS['cache_deleted'][] = array($key, $group);
}

function wp_delete_post($id, $force) {
	unset($GLOBALS['posts'][$id], $GLOBALS['meta'][$id]);
	return true;
}

class Uninstall_DB {
	public $options = 'wp_options';
	public $posts = 'wp_posts';

	public function update($table, $data, $where) {
		$GLOBALS['options'][$where['option_name']] = $data['option_value'];
	}

	public function esc_like($value) {
		return addcslashes($value, '_%');
	}

	public function prepare($sql, $value) {
		return array($sql, $value);
	}

	public function get_col($query) {
		[$sql, $value] = $query;
		if (str_contains($sql, 'post_type')) {
			return array_keys(array_filter($GLOBALS['posts'], static fn($type) => $type === $value));
		}
		$prefix = stripslashes(substr($value, 0, -1));
		return array_values(
			array_filter(array_keys($GLOBALS['options']), static fn($key) => str_starts_with($key, $prefix)),
		);
	}
}

$wpdb = new Uninstall_DB();
require __DIR__ . '/lib/assert.php';


function reset_uninstall($settings) {
	$GLOBALS['options'] = array(
		'homeward_settings' => $settings,
		'homeward_api_key' => 'synthetic-key',
		'homeward_wizard' => array('step' => 2),
		'homeward_sync_lock' => 'locked',
		'homeward_transient_keys' => array('homeward_form_nonce' => time() + 600),
		'_transient_homeward_msg_test' => 1,
		'_transient_timeout_homeward_msg_test' => time() + 600,
		'_site_transient_homeward_test' => 1,
		'_site_transient_timeout_homeward_test' => time() + 600,
		'unrelated' => 'keep',
		'_transient_unrelated' => 'keep',
	);
	$GLOBALS['posts'] = array(
		1 => 'homeward_message',
		2 => 'homeward_animal',
		3 => 'homeward_story',
		4 => 'homeward_event',
		5 => 'page',
		6 => 'attachment',
	);
	$GLOBALS['meta'] = array_fill_keys(array_keys($GLOBALS['posts']), array('test' => 'data'));
	$GLOBALS['transients'] = array(
		'homeward_form_nonce' => array('message' => 'Temporary contact values'),
		'unrelated' => 'keep',
	);
	$hooks = array(
		'homeward_sync_listings',
		'homeward_sync_details',
		'homeward_daily_refresh',
		'homeward_cleanup',
		'homeward_refresh_derived',
		'homeward_messages_retention',
		'unrelated',
	);
	$GLOBALS['cron'] = $GLOBALS['scheduled'] = array_fill_keys($hooks, true);
	$GLOBALS['cache_deleted'] = array();
}

$settings = array(
	'org_name' => 'Keep Rescue',
	'posthog_key' => 'synthetic-project',
	'turnstile_secret' => 'synthetic-secret',
	'source' => 'fixture',
	'shelter_id' => 0,
);
reset_uninstall($settings);
require dirname(__DIR__, 2) . '/plugin/homeward-rescue/uninstall.php';
check('default retains posts and messages', count($GLOBALS['posts']) === 6);
check('default retains ordinary settings', get_option('homeward_settings')['org_name'] === 'Keep Rescue');
check(
	'default clears every stored secret',
	!get_option('homeward_api_key') && !isset(
		get_option('homeward_settings')['posthog_key'],
		get_option('homeward_settings')['turnstile_secret'],
	),
);
check(
	'settings cache invalidated',
	in_array(array('alloptions', 'options'), $GLOBALS['cache_deleted'], true),
);
check(
	'scheduled plugin jobs cleared',
	$GLOBALS['cron'] === array('unrelated' => true) && $GLOBALS['scheduled'] === array('unrelated' => true),
);
check('default retains temporary data', isset($GLOBALS['transients']['homeward_form_nonce']));
foreach (array(false, true) as $content) {
	reset_uninstall(
		array_merge(
			$settings,
			array('delete_on_uninstall' => true, 'delete_content_on_uninstall' => $content),
		),
	);
	require dirname(__DIR__, 2) . '/plugin/homeward-rescue/uninstall.php';
	check(
		'opt in removes all plugin options',
		!array_filter(array_keys($GLOBALS['options']), static fn($key) => str_starts_with($key, 'homeward_')),
	);
	check(
		'opt in removes plugin transients and timeouts',
		!array_filter(array_keys($GLOBALS['options']), static fn($key) => str_contains($key, 'homeward_')),
	);
	check('opt in removes cached form data', !isset($GLOBALS['transients']['homeward_form_nonce']));
	check(
		'unrelated options and cached data kept',
		get_option('unrelated') === 'keep' && get_option(
			'_transient_unrelated',
		) === 'keep' && $GLOBALS['transients']['unrelated'] === 'keep',
	);
	check('messages and their metadata deleted', !isset($GLOBALS['posts'][1], $GLOBALS['meta'][1]));
	check(
		'content deletion requires separate choice',
		isset(
			$GLOBALS['posts'][2],
		) === !$content && isset(
			$GLOBALS['posts'][3],
		) === !$content && isset($GLOBALS['posts'][4]) === !$content,
	);
	check('pages and media retained', isset($GLOBALS['posts'][5], $GLOBALS['posts'][6]));
}
summary('uninstall');
