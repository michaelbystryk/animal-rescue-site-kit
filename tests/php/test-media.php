<?php

require __DIR__ . '/lib/bootstrap.php';
$GLOBALS['sideloads'] = array();

function media_sideload_image($url, $post, $desc, $return) {
	$GLOBALS['sideloads'][] = array($url, $post, $return);
	return 1000 + count($GLOBALS['sideloads']);
}

$id = wp_insert_post(array('post_type' => 'homeward_animal', 'post_title' => 'Synthetic Photo Animal'));
$data = array(
	'photos' => array(
		array('full' => 'https://petstablished.com/synthetic-first.jpg'),
		array('full' => 'https://petstablished.com/synthetic-second.jpg'),
	),
);
$checks = array();
Homeward\Media::cover($id, $data);
check('without the import setting no image is sideloaded', count($GLOBALS['sideloads']) === 0);
update_option('homeward_settings', array('image_mode' => 'import_cover'));
Homeward\Media::cover($id, $data);
check('cover import sideloads exactly one image', count($GLOBALS['sideloads']) === 1);
check('cover import uses the first provider photo', $GLOBALS['sideloads'][0][0] === $data['photos'][0]['full']);
check('imported cover becomes the featured image', get_post_thumbnail_id($id) === 1001);
check(
	'imported cover remembers its source photo',
	get_post_meta(1001, '_homeward_ps_photo', true) === $data['photos'][0]['full'],
);
Homeward\Media::cover($id, $data);
check('a repeat sync does not import the cover again', count($GLOBALS['sideloads']) === 1);
set_post_thumbnail($id, 999);
$data['photos'][0]['full'] = 'https://petstablished.com/changed.jpg';
Homeward\Media::cover($id, $data);
check(
	'an editor-chosen featured image is never replaced',
	get_post_thumbnail_id($id) === 999 && count($GLOBALS['sideloads']) === 1,
);
set_post_thumbnail($id, 0);
$data['photos'][0]['full'] = 'https://unapproved.example.invalid/image.jpg';
Homeward\Media::cover($id, $data);
check('unapproved image hosts are never imported', count($GLOBALS['sideloads']) === 1);
// Signed URL refreshes reuse the imported cover.
set_post_thumbnail($id, 1001);
$data['photos'][0]['full'] = 'https://petstablished.com/synthetic-first.jpg?signature=second';
Homeward\Media::cover($id, $data);
check('a signed URL refresh reuses the imported cover', count($GLOBALS['sideloads']) === 1);
// Replacing an unused tagged import removes it.
$data['photos'][0]['full'] = 'https://petstablished.com/replacement.jpg';
Homeward\Media::cover($id, $data);
check('a changed photo imports a replacement', count($GLOBALS['sideloads']) === 2);
check(
	'replacing an unused tagged import deletes the old attachment',
	in_array(1001, $GLOBALS['deleted_attachments'], true),
);
// A shared featured image is preserved.
$other = wp_insert_post(array('post_type' => 'page', 'post_title' => 'Shared image'));
set_post_thumbnail($other, 1002);
$data['photos'][0]['full'] = 'https://petstablished.com/third.jpg';
Homeward\Media::cover($id, $data);
check('a featured image shared with another post is preserved', !in_array(1002, $GLOBALS['deleted_attachments'], true));
// Content references (including gallery/block references) are preserved too.
wp_update_post(array('ID' => $other, 'post_content' => '<!-- wp:image {"id":1003} -->'));
$data['photos'][0]['full'] = 'https://petstablished.com/fourth.jpg';
Homeward\Media::cover($id, $data);
check('an image referenced from post content is preserved', !in_array(1003, $GLOBALS['deleted_attachments'], true));
summary('media');
