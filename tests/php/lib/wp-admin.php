<?php

/**
 * WordPress doubles, admin area: themes, menus, admin screens, AJAX and redirects.
 * Functions that a suite replaces with its own behaviour are guarded with function_exists().
 */

function is_email($value) {
	return is_string($value) && filter_var($value, FILTER_VALIDATE_EMAIL);
}

function wp_attachment_is_image($id) {
	$post = get_post($id);
	return $post && 'attachment' === $post->post_type && str_starts_with(
		$post->post_mime_type ?? '',
		'image/',
	);
}

function set_theme_mod($key, $value) {
	$GLOBALS['theme_mods'][$key] = $value;
}

function get_theme_mod($key, $default = false) {
	return $GLOBALS['theme_mods'][$key] ?? $default;
}

function get_stylesheet() {
	return $GLOBALS['stylesheet'] ?? 'other-theme';
}

function switch_theme($slug) {
	$GLOBALS['stylesheet'] = $slug;
	$GLOBALS['theme_mods'] = array();
	$GLOBALS['switch_count'] = ($GLOBALS['switch_count'] ?? 0) + 1;
}

class Setup_Theme {
	public function exists() {
		return !empty($GLOBALS['theme_exists']);
	}

	public function get_stylesheet_directory() {
		return $GLOBALS['theme_directory'] ?? HOMEWARD_DIR . 'not-a-theme';
	}

	public function get_stylesheet_directory_uri() {
		return home_url('/theme/homeward');
	}
}

function wp_get_theme($slug = '') {
	return new Setup_Theme();
}

function wp_get_nav_menu_object($id) {
	foreach ($GLOBALS['menus'] ?? array() as $menu) {
		if ($id === $menu->slug || (int) $id === $menu->term_id) {
			return $menu;
		}
	}
	return false;
}

function wp_create_nav_menu($slug) {
	$id = count($GLOBALS['menus'] ?? array()) + 1;
	$GLOBALS['menus'][$id] = (object) array('term_id' => $id, 'slug' => $slug);
	return $id;
}

function wp_get_nav_menu_items($id) {
	return $GLOBALS['menu_items'][$id] ?? array();
}

function wp_update_nav_menu_item($menu, $id, $args) {
	$GLOBALS['menu_items'][$menu][] = (object) array(
		'object_id' => $args['menu-item-object-id'],
		'object' => $args['menu-item-object'],
		'type' => $args['menu-item-type'],
		'url' => $args['menu-item-url'] ?? '',
	);
	return count($GLOBALS['menu_items'][$menu]);
}

if (!function_exists('is_admin')) {
	function is_admin() {
		return $GLOBALS['admin'] ?? true;
	}
}

if (!function_exists('wp_doing_ajax')) {
	function wp_doing_ajax() {
		return $GLOBALS['ajax'] ?? false;
	}
}

function is_network_admin() {
	return $GLOBALS['network_admin'] ?? false;
}

if (!function_exists('wp_safe_redirect')) {
	function wp_safe_redirect($url) {
		$GLOBALS['redirect'] = $url;
	}
}

function add_options_page(...$args) {
	$GLOBALS['options_pages'][] = $args;
}

function add_submenu_page(...$args) {
	$GLOBALS['submenus'][] = $args;
}

function wp_enqueue_media() {
	$GLOBALS['media_enqueued'] = true;
}

function wp_create_nonce($action) {
	return 'valid';
}

class Setup_Response extends RuntimeException {
	public function __construct(public $payload, public $status) {
		parent::__construct('response');
	}
}

function wp_send_json_success($data, $code = 200) {
	throw new Setup_Response(array('success' => true, 'data' => $data), $code);
}

function wp_send_json_error($data, $code = 200) {
	throw new Setup_Response(array('success' => false, 'data' => $data), $code);
}

function check_ajax_referer($action, $key) {
	if ('valid' !== ($_POST[$key] ?? '')) {
		throw new Setup_Response(array('success' => false), 403);
	}
}

function setup_reset() {
	test_reset();
	foreach (array('theme_mods', 'menus', 'menu_items', 'submenus', 'options_pages') as $key) {
		$GLOBALS[$key] = array();
	}
	$GLOBALS['real_option_hooks'] = false;
	$GLOBALS['theme_exists'] = false;
	$GLOBALS['stylesheet'] = 'other-theme';
	$GLOBALS['admin'] = true;
	$GLOBALS['ajax'] = false;
	$GLOBALS['network_admin'] = false;
	$GLOBALS['switch_count'] = 0;
	$_GET = array();
	$_FILES = array();
	$_SERVER['REQUEST_METHOD'] = 'GET';
}
