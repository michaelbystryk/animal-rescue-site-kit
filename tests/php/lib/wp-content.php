<?php

/**
 * WordPress doubles, content area: posts, meta, queries, attachments, thumbnails and the
 * database object. In memory only.
 */

#[AllowDynamicProperties]
class WP_Post {
	public $post_content = '', $post_title = '', $post_name = '', $post_password = '', $post_status = 'publish';

	public function __construct($values) {
		foreach ($values as $k => $v) {
			$this->$k = $v;
		}
	}
}

function get_post_status($id) {
	return get_post($id)->post_status ?? false;
}

function wp_get_attachment_url($id) {
	return 'https://example.invalid/uploads/' . $id . '.jpg';
}

function wp_delete_attachment($id, $force = false) {
	$GLOBALS['deleted_attachments'][] = $id;
	unset($GLOBALS['meta'][$id], $GLOBALS['posts'][$id]);
	return true;
}

function get_post($id) {
	return $id instanceof WP_Post ? $id :($GLOBALS['posts'][$id] ?? null);
}

function get_the_ID() {
	return $GLOBALS['current_id'];
}

function post_type_exists($type) {
	return $type === 'homeward_animal';
}

function get_post_type($id = null) {
	return get_post($id ?? get_the_ID())->post_type ?? '';
}

function get_queried_object_id() {
	return get_the_ID();
}

function get_queried_object() {
	return get_post(get_the_ID());
}

function get_post_field($key, $id) {
	return get_post($id)->$key ?? '';
}

function is_singular($type = null) {
	return get_the_ID() && (null === $type || in_array(get_post_type(), (array) $type, true));
}

function is_post_type_archive($type) {
	return is_string($GLOBALS['archive']) ? $GLOBALS['archive'] === $type : $GLOBALS['archive'];
}

function in_the_loop() {
	return true;
}

function is_main_query() {
	return true;
}

function get_post_meta($id, $k = '', $single = true) {
	return '' === $k ?($GLOBALS['meta'][$id] ?? array()) :($GLOBALS['meta'][$id][$k] ?? '');
}

function update_post_meta($id, $k, $v) {
	$GLOBALS['meta'][$id][$k] = wp_unslash($v);
}

function delete_post_meta($id, $k) {
	unset($GLOBALS['meta'][$id][$k]);
}

if (!function_exists('wp_insert_post')) {
	function wp_insert_post($v, $err = false) {
		$v = wp_unslash($v);
		$v['ID'] = max(array_merge(array(0), array_keys($GLOBALS['posts']))) + 1;
		$v['post_name'] = $v['post_name'] ?? sanitize_title($v['post_title']);
		$GLOBALS['posts'][$v['ID']] = new WP_Post($v);
		foreach ($v['meta_input'] ?? array() as $k => $value) {
			$GLOBALS['meta'][$v['ID']][$k] = $value;
		}
		do_action('save_post_' . $v['post_type'], $v['ID'], $GLOBALS['posts'][$v['ID']]);
		return $v['ID'];
	}
}

function wp_update_post($v, $err = false) {
	$v = wp_unslash($v);
	foreach ($v as $k => $val) {
		$GLOBALS['posts'][$v['ID']]->$k = $val;
	}
	do_action('save_post_' . $GLOBALS['posts'][$v['ID']]->post_type, $v['ID'], $GLOBALS['posts'][$v['ID']]);
	return $v['ID'];
}

function get_the_title($id = null) {
	return get_post($id ?? get_the_ID())->post_title ?? '';
}

if (!function_exists('get_permalink')) {
	function get_permalink($id = 0) {
		$id = $id ?: get_the_ID();
		return home_url('/animals/' . get_post($id)->post_name . '/');
	}
}

function get_post_type_archive_link($type) {
	return home_url(
		'/' . ($type === 'homeward_animal' ? Homeward\Settings::get('archive_slug') : $type) . '/',
	);
}

function get_post_time(...$a) {
	return time() - 100;
}

function meta_matches($id, $q) {
	if (isset($q['key'])) {
		$actual = get_post_meta($id, $q['key']);
		$value = $q['value'] ?? '';
		return match ($q['compare'] ?? '=') {
			'NOT EXISTS' => !isset($GLOBALS['meta'][$id][$q['key']]),
			'EXISTS' => isset($GLOBALS['meta'][$id][$q['key']]),
			'>=' => $actual >= $value,
			'<' => $actual < $value,
			'!=' => $actual != $value,
			default => $actual == $value,
		};
	}
	$matches = array();
	foreach ($q as $clause) {
		if (is_array($clause)) {
			$matches[] = meta_matches($id, $clause);
		}
	}
	return 'OR' === (
		$q['relation'] ?? 'AND'
	) ? in_array(true, $matches, true) : !in_array(false, $matches, true);
}

if (!function_exists('get_posts')) {
	function get_posts($args) {
		$out = array();
		foreach ($GLOBALS['posts'] as $id => $p) {
			if (isset($args['has_password']) && (bool) $p->post_password !== $args['has_password']) {
				continue;
			}
			if (
				isset($args['post_type']) && 'any' !== $args['post_type'] && !in_array(
					$p->post_type,
					(array) $args['post_type'],
					true,
				)
			) {
				continue;
			}
			if (isset($args['post_status']) && !in_array($p->post_status, (array) $args['post_status'], true)) {
				continue;
			}
			if (isset($args['name']) && $args['name'] !== $p->post_name) {
				continue;
			}
			if (in_array($id, $args['post__not_in'] ?? array(), true)) {
				continue;
			}
			if (
				isset(
					$args['meta_key'],
					$args['meta_value'],
				) && get_post_meta($id, $args['meta_key']) != $args['meta_value']
			) {
				continue;
			}
			if (!meta_matches($id, $args['meta_query'] ?? array())) {
				continue;
			}
			$out[] = $p;
		}
		if (($args['orderby'] ?? '') === 'meta_value') {
			usort(
				$out,
				fn($a, $b) => strcmp(
					get_post_meta($a->ID, $args['meta_key']),
					get_post_meta($b->ID, $args['meta_key']),
				),
			);
			if (($args['order'] ?? '') === 'DESC') {
				$out = array_reverse($out);
			}
		} elseif (isset($args['orderby'])) {
			usort($out, fn($a, $b) => $b->ID <=> $a->ID);
		}
		if (($args['posts_per_page'] ?? -1) > 0) {
			$out = array_slice($out, 0, $args['posts_per_page']);
		}
		return ($args['fields'] ?? '') === 'ids' ? array_column($out, 'ID') : $out;
	}
}

if (!class_exists('WP_Query')) {
	class WP_Query {
		public $posts;
		public $query_vars;
		public $main = true;

		public function __construct($args) {
			$this->query_vars = $args;
			$this->posts = get_posts($args);
		}

		public function get($key) {
			return $this->query_vars[$key] ?? null;
		}

		public function set($key, $value) {
			$this->query_vars[$key] = $value;
		}

		public function is_main_query() {
			return $this->main;
		}

		public function have_posts() {
			return (bool) $this->posts;
		}

		public function set_404() {
			$GLOBALS['is_404'] = true;
		}
	}
}

function get_page_by_path($slug, ...$a) {
	foreach ($GLOBALS['posts'] as $p) {
		if ('page' === $p->post_type && $slug === $p->post_name) {
			return $p;
		}
	}
	return null;
}

function update_meta_cache(...$a) {
	$GLOBALS['meta_primes'][] = $a;
}

function wp_reset_postdata() {}

if (!function_exists('get_the_post_thumbnail_url')) {
	function get_the_post_thumbnail_url($id, $size = '') {
		return get_post_thumbnail_id($id) ? wp_get_attachment_url(get_post_thumbnail_id($id)) : '';
	}
}

function get_the_post_thumbnail(...$a) {
	return '';
}

function get_post_thumbnail_id($id) {
	return $GLOBALS['thumbs'][$id] ?? 0;
}

function set_post_thumbnail($id, $attachment) {
	$GLOBALS['thumbs'][$id] = $attachment;
}

function wp_is_post_revision($id) {
	return false;
}

function wp_get_upload_dir() {
	return array('baseurl' => home_url('/wp-content/uploads/'));
}

function attachment_url_to_postid($url) {
	return 0;
}

class Test_DB {
	public $options = 'options', $posts = 'posts', $postmeta = 'postmeta';

	public function prepare($q, ...$args) {
		return array($q, $args);
	}

	public function query($query) {
		[$sql, $args] = $query;
		if (str_starts_with($sql, 'INSERT')) {
			return add_option($args[0], unserialize($args[1])) ? 1 : 0;
		}
		if (maybe_serialize(get_option($args[0])) === $args[1]) {
			delete_option($args[0]);
			return 1;
		}
		return 0;
	}

	public function esc_like($s) {
		return addcslashes($s, '_%');
	}

	public function get_var($query) {
		[$sql, $args] = $query;
		if (str_starts_with($sql, 'SELECT option_value')) {
			return isset(
				$GLOBALS['options'][$args[0]],
			) ? maybe_serialize($GLOBALS['options'][$args[0]]) : null;
		}
		if (str_starts_with($sql, 'SELECT post_id')) {
			foreach ($GLOBALS['meta'] as $id => $meta) {
				if ($id != $args[1] && in_array($args[0], $meta, true)) {
					return $id;
				}
			}
			foreach ($GLOBALS['thumbs'] as $id => $thumb) {
				if ($id != $args[1] && (string) $thumb === $args[0]) {
					return $id;
				}
			}
			return null;
		}
		if (str_starts_with($sql, 'SELECT ID')) {
			foreach ($GLOBALS['posts'] as $post) {
				if (str_contains($post->post_content, trim($args[0], '%'))) {
					return $post->ID;
				}
			}
		}
		return null;
	}

	public function get_col($query) {
		$rows = array();
		foreach ($GLOBALS['meta'] as $m) {
			if (isset($m[$query[1][0]])) {
				$rows[] = $m[$query[1][0]];
			}
		}
		return $rows;
	}
}

if (!function_exists('is_page')) {
	function is_page($slug = null) {
		return false;
	}
}

function post_password_required($post = null) {
	return (bool)(get_post($post ?? get_the_ID())->post_password ?? '');
}

function get_the_password_form($post = null) {
	return '<form>Protected <input name=post_password></form>';
}

function wp_trash_post($id) {
	if (!empty($GLOBALS['delete_error'])) {
		return false;
	}
	$post = get_post($id);
	if ($post) {
		wp_update_post(array('ID' => $id, 'post_status' => 'trash'));
	}
	return $post;
}

function get_post_types($args = array(), $output = 'names') {
	return array_values(
		array_unique(
			array_merge(
				array(
					'post',
					'page',
					'attachment',
					'homeward_story',
					'homeward_event',
					'homeward_animal',
					'wp_block',
					'wp_navigation',
				),
				array_column($GLOBALS['posts'], 'post_type'),
			),
		),
	);
}

