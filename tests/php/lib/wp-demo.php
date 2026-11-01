<?php

/**
 * WordPress doubles, demo content area: in-memory media upload, attachment and deletion.
 */

use Homeward\Demo_Content;

// Media operations stay in memory. Real PNG structure is inspected by the suites.
function wp_upload_bits($name, $deprecated, $bytes) {
	if (!empty($GLOBALS['upload_error'])) {
		return array('error' => 'Cannot write');
	}
	$GLOBALS['demo_uploads'][$name] = $bytes;
	return array(
		'file' => Demo_Content::directory() . '/images/' . $name,
		'url' => home_url('/uploads/' . $name),
		'error' => false,
	);
}

function wp_insert_attachment($post, $file, $parent = 0, $error = false) {
	$post['post_type'] = 'attachment';
	return wp_insert_post($post, $error);
}

function wp_generate_attachment_metadata($id, $file) {
	$size = getimagesize($file);
	return array('width' => $size[0], 'height' => $size[1]);
}

function wp_update_attachment_metadata($id, $meta) {
	update_post_meta($id, '_wp_attachment_metadata', $meta);
}

function wp_delete_file($file) {
	$GLOBALS['demo_deleted_files'][] = $file;
}

if (!function_exists('wp_delete_post')) {
	function wp_delete_post($id, $force = false) {
		if (!empty($GLOBALS['delete_error'])) {
			return false;
		}
		$post = get_post($id);
		unset($GLOBALS['posts'][$id], $GLOBALS['meta'][$id], $GLOBALS['thumbs'][$id]);
		return $post;
	}
}

function demo_reset() {
	setup_reset();
	$GLOBALS['demo_uploads'] = array();
	$GLOBALS['upload_error'] = false;
	$GLOBALS['delete_error'] = false;
}

function demo_created($key) {
	return get_option(Demo_Content::OPTION)['created'][$key] ?? 0;
}

function demo_import() {
	$result = Demo_Content::import();
	check('import succeeds', !is_wp_error($result));
	return $result;
}

