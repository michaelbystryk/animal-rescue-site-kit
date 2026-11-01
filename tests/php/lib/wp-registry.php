<?php

/**
 * WordPress doubles, registration area: scripts, styles, post types, settings, blocks and
 * shortcodes. Registrations are recorded in $GLOBALS for assertions.
 */

function wp_set_script_translations(...$args) {}

function wp_enqueue_style(...$a) {
	$GLOBALS['enqueued'][] = $a[0];
}

function wp_enqueue_script(...$a) {
	$GLOBALS['enqueued'][] = $a[0];
}

function wp_register_style(...$a) {}

function wp_register_script(...$a) {
	$GLOBALS['scripts'][$a[0]] = $a;
}

function wp_localize_script(...$a) {
	$GLOBALS['script_config'][$a[0]][$a[1]] = $a[2];
}

function wp_add_inline_style(...$a) {}

function wp_clean_theme_json_cache() {
	$GLOBALS['theme_cleaned'] = true;
}

if (!function_exists('register_post_type')) {
	function register_post_type($t, $args) {
		$GLOBALS['registered']['types'][$t] = $args;
	}
}

function register_post_meta($t, $k, $a) {
	$GLOBALS['registered']['meta'][$t][$k] = $a;
}

function register_setting($g, $k, $a) {
	$GLOBALS['registered']['settings'][$k] = $a;
}

function register_block_type($path) {
	$GLOBALS['blocks'][$path] = json_decode(file_get_contents($path . '/block.json'), true);
}

if (!function_exists('add_shortcode')) {
	function add_shortcode($name, $cb) {
		$GLOBALS['shortcodes'][$name] = $cb;
	}
}

function register_block_template($name, $args) {
	$GLOBALS['templates'][$name] = $args;
}

function add_settings_error(...$a) {
	$GLOBALS['settings_errors'][] = $a;
}

function parse_blocks($s) {
	return array();
}
