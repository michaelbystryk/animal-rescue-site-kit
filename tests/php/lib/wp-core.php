<?php

/**
 * WordPress doubles, core area: constants, errors, translation, escaping, sanitising, hooks,
 * options, transients, URLs, HTTP, time, cron, capabilities and nonces. In memory only, no network.
 */

function define_once($name, $value) {
	if (!defined($name)) {
		define($name, $value);
	}
}

define_once('ABSPATH', dirname(__DIR__) . '/');
define_once('HOMEWARD_DIR', dirname(__DIR__, 3) . '/plugin/homeward-rescue/');
define_once('HOMEWARD_URL', 'https://example.invalid/plugins/homeward-rescue/');
define_once('HOMEWARD_VERSION', '0.3.0');
define_once('MINUTE_IN_SECONDS', 60);
define_once('HOUR_IN_SECONDS', 3600);
define_once('DAY_IN_SECONDS', 86400);
define_once('OBJECT', 'OBJECT');

class WP_Error {
	public function __construct(public $code, public $message, public $data = array()) {}

	public function get_error_message() {
		return $this->message;
	}

	public function get_error_code() {
		return $this->code;
	}
}

if (!class_exists('Test_Role')) {
	class Test_Role {
		public $caps = array();

		public function add_cap($key, $grant = true) {
			$this->caps[$key] = $grant;
		}

		public function remove_cap($key) {
			unset($this->caps[$key]);
		}
	}
}

function test_reset() {
	foreach (
		array(
			'posts',
			'meta',
			'options',
			'transients',
			'cron',
			'requests',
			'hooks',
			'registered',
			'actions',
			'blocks',
			'roles',
			'enqueued',
			'meta_primes',
			'deleted_attachments',
			'thumbs',
			'settings_errors',
		) as $key
	) {
		$GLOBALS[$key] = array();
	}
	$GLOBALS['flushes'] = 0;
	$GLOBALS['wp_query'] = new WP_Query(array());
	$GLOBALS['options']['blogname'] = 'Example Rescue';
	$GLOBALS['can'] = true;
	$GLOBALS['current_id'] = 0;
	$GLOBALS['archive'] = false;
	$GLOBALS['http'] = fn() => new WP_Error('offline', 'FAKE_ERROR_SECRET');
	$_POST = array();
}

function __($s, $domain = '') {
	return $s;
}

function _n($one, $many, $n, $d = '') {
	return 1 === $n ? $one : $many;
}

function esc_html($s) {
	return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
}

function esc_attr($s) {
	return esc_html($s);
}

function esc_textarea($s) {
	return esc_html($s);
}

function esc_html__($s, $d = '') {
	return esc_html($s);
}

function esc_attr__($s, $d = '') {
	return esc_attr($s);
}

function esc_html_e($s, $d = '') {
	echo esc_html($s);
}

function esc_attr_e($s, $d = '') {
	echo esc_attr($s);
}

function esc_url($s, $p = null) {
	return esc_attr(esc_url_raw($s, $p));
}

if (!function_exists('esc_url_raw')) {
	function esc_url_raw($s, $p = null) {
		return preg_match('~^(https?://|mailto:|#)~', (string) $s) ? $s : '';
	}
}

function sanitize_text_field($s) {
	return is_scalar($s) ? trim(strip_tags((string) $s)) : '';
}

function sanitize_textarea_field($s) {
	return sanitize_text_field($s);
}

function sanitize_title($s) {
	return trim(preg_replace('/[^a-z0-9]+/', '-', strtolower($s)), '-');
}

function sanitize_key($s) {
	return preg_replace('/[^a-z0-9_-]/', '', strtolower($s));
}

function sanitize_email($s) {
	return filter_var($s, FILTER_VALIDATE_EMAIL) ? $s : '';
}

function sanitize_hex_color($s) {
	return preg_match('/^#(?:[a-f0-9]{3}|[a-f0-9]{6})$/i', $s) ? $s : '';
}

function wp_kses($s, $tags) {
	return $s;
}

function wp_kses_post($s) {
	return $s;
}

function wpautop($s) {
	return '<p>' . $s . '</p>';
}

function wp_strip_all_tags($s) {
	return strip_tags($s);
}

function wptexturize($s) {
	return $s;
}

function wp_html_excerpt($s, $n, $more = '') {
	return mb_substr($s, 0, $n) . (mb_strlen($s) > $n ? $more : '');
}

function wp_trim_words($s, $n, $more = '…') {
	$w = preg_split('/\s+/', strip_tags($s));
	return implode(' ', array_slice($w, 0, $n)) . (count($w) > $n ? $more : '');
}

function absint($n) {
	return abs((int) $n);
}

function wp_slash($v) {
	return is_array($v) ? array_map('wp_slash', $v) :(is_string($v) ? addslashes($v) : $v);
}

function wp_unslash($v) {
	return is_array($v) ? array_map('wp_unslash', $v) :(is_string($v) ? stripslashes($v) : $v);
}

function wp_parse_args($a, $b) {
	return array_merge($b, $a);
}

function wp_json_encode($v, $flags = 0) {
	return json_encode($v, $flags);
}

function is_wp_error($v) {
	return $v instanceof WP_Error;
}

if (!function_exists('add_filter')) {
	function add_filter($h, $cb, $priority = 10, $argc = 1) {
		$GLOBALS['hooks'][$h][] = array($cb, $argc);
	}
}

if (!function_exists('add_action')) {
	function add_action(...$a) {
		add_filter(...$a);
	}
}

function has_action($h, $cb = false) {
	foreach ($GLOBALS['hooks'][$h] ?? array() as $hook) {
		if (false === $cb || $hook[0] === $cb) {
			return true;
		}
	}
	return false;
}

if (!function_exists('apply_filters')) {
	function apply_filters($h, $v, ...$args) {
		foreach ($GLOBALS['hooks'][$h] ?? array() as [$cb, $argc]) {
			$v = $cb(...array_slice(array_merge(array($v), $args), 0, $argc));
		}
		return $v;
	}
}

function do_action($h, ...$args) {
	$GLOBALS['actions'][] = array($h, $args);
	foreach ($GLOBALS['hooks'][$h] ?? array() as [$cb, $argc]) {
		$cb(...array_slice($args, 0, $argc));
	}
}

function get_option($k, $d = false) {
	return $GLOBALS['options'][$k] ?? $d;
}

function update_option($k, $v, $autoload = null) {
	$old = get_option($k);
	if (!empty($GLOBALS['real_option_hooks'])) {
		$v = apply_filters('sanitize_option_' . $k, $v);
	}
	if ($old === $v) {
		return false;
	}
	$GLOBALS['options'][$k] = $v;
	if (!empty($GLOBALS['real_option_hooks'])) {
		do_action('update_option_' . $k, $old, $v);
	}
	return true;
}

function add_option($k, $v, ...$a) {
	if (array_key_exists($k, $GLOBALS['options'])) {
		return false;
	}
	$GLOBALS['options'][$k] = $v;
	return true;
}

function delete_option($k) {
	unset($GLOBALS['options'][$k]);
}

function wp_parse_url($url, $component = -1) {
	return parse_url($url, $component);
}

function antispambot($email) {
	return $email;
}

function status_header($code) {
	$GLOBALS['http_status'] = $code;
}

if (!function_exists('nocache_headers')) {
	function nocache_headers() {}
}

if (!function_exists('get_transient')) {
	function get_transient($k) {
		return $GLOBALS['transients'][$k] ?? false;
	}
}

if (!function_exists('set_transient')) {
	function set_transient($k, $v, $ttl = 0) {
		$GLOBALS['transients'][$k] = $v;
	}
}

function delete_transient($k) {
	$GLOBALS['flushes'] = ($GLOBALS['flushes'] ?? 0) + 1;
	unset($GLOBALS['transients'][$k]);
}

function home_url($p = '') {
	return ($GLOBALS['home_url'] ?? 'https://example.invalid') . $p;
}

function admin_url($p = '') {
	return home_url('/wp-admin/' . $p);
}

function get_bloginfo($s) {
	return 'Example Rescue';
}

function wp_cache_delete(...$a) {}

function maybe_serialize($v) {
	return serialize($v);
}

function wp_generate_uuid4() {
	return bin2hex(random_bytes(16));
}

function wp_next_scheduled($h) {
	return $GLOBALS['cron'][$h] ?? false;
}

function wp_schedule_single_event($t, $h) {
	$GLOBALS['cron'][$h] = $t;
}

function wp_clear_scheduled_hook($h) {
	unset($GLOBALS['cron'][$h]);
}

function wp_remote_get($url, $args) {
	$GLOBALS['requests'][] = array($url, $args);
	return ($GLOBALS['http'])($url, $args);
}

function wp_remote_retrieve_response_code($r) {
	return $r['code'];
}

function wp_remote_retrieve_body($r) {
	return $r['body'];
}

function wp_remote_retrieve_header($r, $h) {
	return $r['headers'][$h] ?? '';
}

if (!function_exists('add_query_arg')) {
	function add_query_arg($args, $url) {
		return $url . '?' . http_build_query($args);
	}
}

function wp_timezone() {
	return new DateTimeZone('America/Vancouver');
}

if (!function_exists('wp_date')) {
	function wp_date($f, $t = null) {
		return (new DateTimeImmutable('@' . ($t ?? time())))->setTimezone(wp_timezone())->format($f);
	}
}

function get_gmt_from_date($s) {
	return $s;
}

function number_format_i18n($n, $d = 0) {
	return number_format($n, $d);
}

function wp_unique_id($p = '') {
	static $i = 0;
	return $p . (++$i);
}

function current_user_can(...$a) {
	return is_array($GLOBALS['can']) ? in_array($a[0], $GLOBALS['can'], true) : $GLOBALS['can'];
}

function wp_verify_nonce($n, $a) {
	$GLOBALS['last_nonce_action'] = $a;
	return $n === 'valid';
}

function get_role($name) {
	return $GLOBALS['roles'][$name] ?? ($GLOBALS['roles'][$name] = new Test_Role());
}

function flush_rewrite_rules(...$a) {}

function selected($a, $b, $echo = true) {
	$s = $a == $b ? 'selected="selected"' : '';
	if ($echo) {
		echo $s;
	}
	return $s;
}

function checked($a, $b, $echo = true) {
	$s = $a == $b ? 'checked="checked"' : '';
	if ($echo) {
		echo $s;
	}
	return $s;
}

if (!function_exists('wp_die')) {
	function wp_die($message) {
		throw new RuntimeException('permission');
	}
}

function check_admin_referer($action) {
	if (($_POST['_wpnonce'] ?? '') !== 'valid') {
		throw new RuntimeException('nonce');
	}
}

if (!function_exists('wp_nonce_field')) {
	function wp_nonce_field(...$a) {}
}

function __return_false() {
	return false;
}

function trailingslashit($s) {
	return rtrim($s, '/') . '/';
}

function wp_salt($scheme) {
	return 'test-secret-' . $scheme;
}

function wp_validate_redirect($url, $fallback) {
	return str_starts_with($url, home_url('/')) ? $url : $fallback;
}

function remove_query_arg($keys, $url) {
	$parts = explode('?', $url, 2);
	parse_str($parts[1] ?? '', $args);
	foreach ((array) $keys as $key) {
		unset($args[$key]);
	}
	return $parts[0] . ($args ? '?' . http_build_query($args) : '');
}
