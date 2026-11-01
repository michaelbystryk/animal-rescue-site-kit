<?php

require __DIR__ . '/lib/bootstrap.php';
use Homeward\{Animal, Demo_Content, Wizard};
// Matching slugs and even matching starter copy do not grant ownership.
$original = array();
foreach (Wizard::starter_pages() as $slug => $page) {
	$original[$slug] = wp_insert_post(
		array(
			'post_type' => 'page',
			'post_name' => $slug,
			'post_title' => 'Rescue ' . $slug,
			'post_content' => $page[1],
		),
	);
}
$before = serialize($GLOBALS['posts']);
$result = demo_import();
foreach ($original as $slug => $id) {
	equals(
		'existing page content untouched ' . $slug,
		Wizard::starter_pages()[$slug][1],
		get_post($id)->post_content,
	);
}
check(
	'skipped pages reported',
	str_contains(
		$result['message'],
		'Skipped existing pages:',
	) && str_contains($result['message'], 'Rescue adopt'),
);
// All modified record types are retained, including metadata-only changes.
$story = demo_created('story:demo-nimbus-routine');
$animal = demo_created('animal:920001');
$cover = get_post_thumbnail_id($animal);
update_post_meta($story, 'homeward_text', 'Staff story revision');
wp_update_post(array('ID' => $animal, 'post_content' => 'Staff animal revision'));
$other_cover = demo_created('image:demo-moss.png');
if (!$other_cover) {
	$other_cover = array_values(
		array_filter(
			get_option(Demo_Content::OPTION)['created'],
			fn($id) => 'attachment' === get_post_type($id),
		),
	)[1];
}
$non_demo = wp_insert_post(
	array(
		'post_type' => 'post',
		'post_title' => 'Rescue update',
		'post_content' => '<img class="wp-image-' . $other_cover . '">',
	),
);
set_post_thumbnail($non_demo, $cover);
$result = Demo_Content::remove();
equals('modified story kept', 'publish', get_post_status($story));
equals('story edits kept', 'Staff story revision', get_post_meta($story, 'homeward_text'));
equals('modified animal kept', 'publish', get_post_status($animal));
equals('animal edits kept', 'Staff animal revision', get_post($animal)->post_content);
check('featured image used elsewhere kept', (bool) get_post($cover));
check('content image used elsewhere kept', (bool) get_post($other_cover));
check(
	'notice lists modified records',
	str_contains($result['message'], '#' . $story) && str_contains($result['message'], '#' . $animal),
);
check('notice persisted', get_option(Demo_Content::RESULT) === $result);
check(
	'unchanged demo posts trashed',
	(bool) get_posts(array('post_type' => 'homeward_story', 'post_status' => 'trash')),
);
foreach ($original as $slug => $id) {
	equals(
		'non-owned pages preserved on removal ' . $slug,
		Wizard::starter_pages()[$slug][1],
		get_post($id)->post_content,
	);
}
// Wizard pages are eligible only with an ownership fingerprint from creation.
demo_reset();
$create = new ReflectionMethod(Wizard::class, 'create_pages');
$pages = $create->invoke(null);
check('wizard ownership fingerprint', Demo_Content::wizard_unchanged($pages['adopt']));
wp_update_post(array('ID' => $pages['contact'], 'post_content' => 'Staff contact revision'));
update_post_meta($pages['about'], 'custom_profile', 'Staff metadata revision');
$result = demo_import();
equals(
	'edited wizard page not filled',
	'Staff contact revision',
	get_post($pages['contact'])->post_content,
);
equals(
	'wizard metadata change keeps original copy',
	Wizard::starter_pages()['about'][1],
	get_post($pages['about'])->post_content,
);
check(
	'unchanged wizard page filled',
	str_contains(get_post($pages['adopt'])->post_content, 'Demo page.'),
);
wp_update_post(array('ID' => $pages['foster'], 'post_content' => 'Staff foster revision'));
$result = Demo_Content::remove();
equals(
	'unchanged filled page restored',
	Wizard::starter_pages()['adopt'][1],
	get_post($pages['adopt'])->post_content,
);
equals(
	'modified filled page retained',
	'Staff foster revision',
	get_post($pages['foster'])->post_content,
);
check('modified filled page reported', str_contains($result['message'], '#' . $pages['foster']));
// Missing legacy hashes are handled conservatively, not treated as unchanged.
demo_reset();
demo_import();
$state = get_option(Demo_Content::OPTION);
$story = $state['created']['story:demo-nimbus-routine'];
unset($state['hashes']['story:demo-nimbus-routine']);
update_option(Demo_Content::OPTION, $state);
Demo_Content::remove();
equals('unverifiable legacy record retained', 'publish', get_post_status($story));
// Wizard navigation also needs ownership, even when its slug matches.
demo_reset();
$pages = $create->invoke(null);
$navigation = wp_insert_post(
	array(
		'post_type' => 'wp_navigation',
		'post_name' => 'homeward-primary',
		'post_title' => 'Staff navigation',
		'post_content' => 'Staff navigation content',
	),
);
$menu = new ReflectionMethod(Wizard::class, 'create_menu');
$menu->invoke(null, $pages);
equals(
	'wizard preserves existing navigation',
	'Staff navigation content',
	get_post($navigation)->post_content,
);
Demo_Content::remember_wizard($navigation);
wp_update_post(array('ID' => $navigation, 'post_content' => 'Staff changed wizard navigation'));
$menu->invoke(null, $pages);
equals(
	'wizard preserves modified owned navigation',
	'Staff changed wizard navigation',
	get_post($navigation)->post_content,
);
// A demo attachment referenced only by a reusable block must also survive.
demo_reset();
demo_import();
$image = demo_created('image:demo-nimbus.png');
if (!$image) {
	$image = array_values(
		array_filter(
			get_option(Demo_Content::OPTION)['created'],
			fn($id) => 'attachment' === get_post_type($id),
		),
	)[0];
}
$block = wp_insert_post(
	array(
		'post_type' => 'wp_block',
		'post_title' => 'Shared photo',
		'post_content' => '<!-- wp:image {"id":' . $image . '} /-->',
	),
);
Demo_Content::remove();
check('reusable-block image retained', (bool) get_post($image));
// Isolate external uses: these owned attachments have no demo-post references.
foreach (array('featured', 'class', 'block', 'gallery', 'resized', 'unused') as $usage) {
	demo_reset();
	$key = 'image:isolated.png';
	$image = wp_insert_post(
		array(
			'post_type' => 'attachment',
			'post_title' => 'Owned illustration',
			'meta_input' => array(Demo_Content::META => $key),
		),
	);
	update_option(
		Demo_Content::OPTION,
		array(
			'created' => array($key => $image),
			'pages' => array(),
			'hashes' => array($key => Demo_Content::fingerprint($image)),
		),
	);
	$content = match ($usage) {
		'class' => '<img class="wp-image-' . $image . '">',
		'block' => '<!-- wp:image {"id":' . $image . '} /-->',
		'gallery' => '[gallery ids="' . $image . '"]',
		'resized' => '<img src="' . str_replace('.jpg', '-300x200.jpg', wp_get_attachment_url($image)) . '">',
		default => 'Rescue content',
	};
	if ('unused' !== $usage) {
		$external = wp_insert_post(
			array('post_type' => 'wp_block', 'post_title' => 'Rescue photo', 'post_content' => $content),
		);
		if ('featured' === $usage) {
			set_post_thumbnail($external, $image);
		}
	}
	$result = Demo_Content::remove();
	equals('external image use protected ' . $usage, 'unused' !== $usage, (bool) get_post($image));
	if ('unused' !== $usage) {
		check('retained image listed ' . $usage, str_contains($result['message'], '#' . $image));
	}
}
summary('demo safety');
