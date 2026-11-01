<?php

/** Emit actual analytics scripts for the JavaScript privacy-signal tests. */
require __DIR__ . '/lib/bootstrap.php';

function is_admin() {
	return false;
}

function is_user_logged_in() {
	return false;
}

function is_preview() {
	return false;
}

function is_feed() {
	return false;
}

function wp_doing_ajax() {
	return false;
}

function wp_is_json_request() {
	return false;
}

function wp_get_environment_type() {
	return 'test';
}

update_option(
	'homeward_settings',
	array(
		'posthog_key' => 'phc_' . str_repeat('x', 24),
		'posthog_cookies' => in_array('--cookies', $_SERVER['argv'], true),
	),
);
Homeward\Analytics::snippet();
Homeward\Analytics::events();
