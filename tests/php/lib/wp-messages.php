<?php

/**
 * WordPress doubles for the message inbox suites (HOMEWARD_TEST_PROFILE = messages).
 *
 * Loaded before the shared doubles. Each function here behaves differently from its shared
 * counterpart, which is guarded with function_exists(), or exists only for the inbox.
 */

/** Constants for the message suites, defined first so the core doubles keep them. */
define('HOMEWARD_URL', '../');
define('HOMEWARD_VERSION', '1.2.0');

function get_permalink($id = 0) {
	return home_url($id ? '/animals/' . $id . '/' : '/contact/');
}

function get_the_post_thumbnail_url($id, $size) {
	return 'https://example.test/animal.jpg';
}

function is_page($slug) {
	return $GLOBALS['is_contact'] ?? true;
}

function add_query_arg($args, $value, $url = null) {
	if (is_array($args)) {
		$url = $value;
	} else {
		$args = array($args => $value);
	}
	$parts = explode('?', $url, 2);
	parse_str($parts[1] ?? '', $old);
	return $parts[0] . '?' . http_build_query(array_merge($old, $args));
}

function wp_insert_post($v, $error = false) {
	if (!empty($GLOBALS['save_fail'])) {
		return new WP_Error('database', 'Not saved');
	}
	$v = wp_unslash($v);
	$id = count($GLOBALS['posts']) + 100;
	$GLOBALS['meta'][$id] = $v['meta_input'];
	unset($v['meta_input']);
	$GLOBALS['posts'][$id] = (object) array_merge(
		$v,
		array('ID' => $id, 'post_date_gmt' => gmdate('Y-m-d H:i:s')),
	);
	return $id;
}

function get_posts($args) {
	$GLOBALS['last_query'] = $args;
	$out = array();
	foreach ($GLOBALS['posts'] as $id => $post) {
		if (
			$post->post_type !== $args['post_type'] || !in_array(
				$post->post_status,
				(array) $args['post_status'],
				true,
			)
		) {
			continue;
		}
		if (isset($args['date_query']) && $post->post_date_gmt >= $args['date_query'][0]['before']) {
			continue;
		}
		$match = true;
		foreach ($args['meta_query'] ?? array() as $clause) {
			if (get_post_meta($id, $clause['key']) !== $clause['value']) {
				$match = false;
			}
		}
		if ($match) {
			$out[] = $id;
		}
	}
	return array_slice($out, 0, $args['posts_per_page'] ?? 100);
}

class WP_Query {
	public $found_posts;

	public function __construct($args) {
		$args['posts_per_page'] = 9999;
		$this->found_posts = count(get_posts($args));
	}
}

function wp_delete_post($id, $force) {
	$GLOBALS['deleted_force'] = $force;
	unset($GLOBALS['posts'][$id], $GLOBALS['meta'][$id]);
	return true;
}

function wp_mail(...$args) {
	$GLOBALS['mail'][] = $args;
	$GLOBALS['saved_before_mail'] = count($GLOBALS['posts']);
	return $GLOBALS['mail_ok'];
}

function get_transient($key) {
	return $GLOBALS['transients'][$key]['value'] ?? false;
}

function set_transient($key, $value, $ttl) {
	$GLOBALS['transients'][$key] = array('value' => $value, 'ttl' => $ttl);
}

function add_shortcode($name, $callback) {
	$GLOBALS['shortcodes'][$name] = $callback;
}

function add_action($name, $callback, ...$rest) {
	$GLOBALS['hooks'][$name][] = $callback;
}

function add_filter(...$args) {
	add_action(...$args);
}

function add_role($name, $label, $caps) {
	return $GLOBALS['roles'][$name] = new Test_Role();
}

function is_multisite() {
	return false;
}

function register_post_type($type, $args) {
	$GLOBALS['cpts'][$type] = $args;
}

function wp_schedule_event($time, $frequency, $hook) {
	$GLOBALS['cron'][$hook] = array($time, $frequency);
}

function wp_die($message, ...$rest) {
	throw new RuntimeException($message);
}

function get_the_date(...$args) {
	return 'Oct 31, 2026';
}

function get_the_time(...$args) {
	return '09:00';
}

function add_settings_section(...$args) {}

function add_settings_field(...$args) {
	$GLOBALS['settings_fields'][$args[0]] = $args[1];
}

function nocache_headers() {
	$GLOBALS['nocache'] = true;
}

function language_attributes() {
	echo 'lang="en"';
}

function wp_head() {}

function wp_footer() {}

class Test_Response extends RuntimeException {}

function wp_send_json($data, $status) {
	$GLOBALS['response'] = array('data' => $data, 'status' => $status);
	throw new Test_Response();
}

function wp_safe_redirect($url, $status) {
	$GLOBALS['response'] = array('url' => $url, 'status' => $status);
	throw new Test_Response();
}

function esc_url_raw($value, $protocols = null) {
	return $value;
}

function wp_remote_post($url, $args) {
	$GLOBALS['verify_request'] = array($url, $args);
	return $GLOBALS['verify_response'];
}

function settings_errors() {}

function settings_fields(...$args) {}

function wp_nonce_field(...$args) {}

function submit_button(...$args) {}

function apply_filters($hook, $value) {
	foreach ($GLOBALS['hooks'][$hook] ?? array() as $callback) {
		$value = $callback($value);
	}
	return $value;
}

function wp_date($format, $timestamp = null) {
	return date($format, $timestamp ?? 1793500000);
}

function wp_specialchars_decode($text, $quotes = 0) {
	return htmlspecialchars_decode((string) $text, ENT_QUOTES);
}

function remove_filter(...$args) {
	return true;
}

function remove_action(...$args) {
	return true;
}

/** Fresh in-memory state, then the plugin classes the inbox suites exercise. Called by bootstrap.php. */
function messages_boot() {
	$GLOBALS['posts'] = $GLOBALS['meta'] = $GLOBALS['options'] = $GLOBALS['transients'] = array();
	$GLOBALS['mail'] = $GLOBALS['hooks'] = $GLOBALS['cron'] = array();
	$GLOBALS['roles'] = array_fill_keys(
		array('administrator', 'editor', 'author', 'subscriber', 'contributor'),
		null,
	);
	foreach ($GLOBALS['roles'] as &$role) {
		$role = new Test_Role();
	}
	unset($role);
	$GLOBALS['can'] = array('edit_homeward_messages', 'manage_options');
	$GLOBALS['mail_ok'] = true;
	foreach (
		array(
			'messages', 'message-email', 'contact-form', 'content-post-types', 'animal-post-type', 'animal',
			'fonts', 'settings', 'demo-content', 'settings-page', 'plugin', 'turnstile', 'analytics', 'redirects',
		) as $file
	) {
		require_once HOMEWARD_DIR . 'includes/class-' . $file . '.php';
	}
}
