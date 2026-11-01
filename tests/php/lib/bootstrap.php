<?php

/**
 * The single bootstrap for the PHP suites.
 *
 * Loads the shared assertions, then the WordPress doubles by area, then the plugin. Doubles are
 * in memory only: no network, no database. A suite that needs different behaviour for a function
 * declares it before requiring this file; the doubles that suites replace are guarded with
 * function_exists().
 *
 * Set HOMEWARD_TEST_PROFILE to 'messages' before requiring this file for the suites that exercise
 * the private message inbox, which need a few doubles to behave differently (see wp-messages.php).
 */

require_once __DIR__ . '/assert.php';

$profile = defined('HOMEWARD_TEST_PROFILE') ? HOMEWARD_TEST_PROFILE : 'default';

if ('messages' === $profile) {
	require_once __DIR__ . '/wp-messages.php';
}
require_once __DIR__ . '/wp-core.php';
require_once __DIR__ . '/wp-content.php';
require_once __DIR__ . '/wp-registry.php';
require_once __DIR__ . '/wp-admin.php';
require_once __DIR__ . '/wp-demo.php';

if ('messages' === $profile) {
	messages_boot();
	return;
}

$GLOBALS['wpdb'] = new Test_DB();
test_reset();
require_once HOMEWARD_DIR . 'includes/class-plugin.php';
Homeward\Plugin::load();
setup_reset();
