<?php

require __DIR__ . '/lib/bootstrap.php';
use Homeward\{
	Testimonials,
	Content_Post_Types,
	Meta_Boxes,
	Content_Render,
	Frontend,
	Blocks,
	Animal,
	Settings,
	Sync,
};

function quote_post($title, $kind = 'testimonial', $status = 'publish', $animal = 0, $story = 0) {
	return wp_insert_post(
		array(
			'post_type' => 'homeward_story',
			'post_status' => $status,
			'post_title' => $title,
			'meta_input' => array(
				'homeward_kind' => $kind,
				'homeward_text' => 'A happy family remembers their animal.',
				'homeward_family' => 'Example Family',
				'homeward_animal_id' => (string) $animal,
				'homeward_story_id' => (string) $story,
			),
		),
	);
}

Content_Post_Types::register();
Testimonials::init();
Testimonials::menu();
check(
	'filtered submenu registered',
	str_contains($GLOBALS['submenus'][0][4], 'homeward_kind=testimonial'),
);
check('create testimonial entry registered', str_contains($GLOBALS['submenus'][1][4], 'post-new.php'));
check(
	'story link registered',
	isset($GLOBALS['registered']['meta']['homeward_story']['homeward_story_id']),
);
update_option('homeward_settings', Settings::sanitize(array('source' => 'fixture', 'shelter_id' => 0)));
Sync::run_listings();
$animal = Animal::ps_map()[910001];
$story = quote_post('An adoption story', 'story');
$testimonial = quote_post('Linked family testimonial', 'testimonial', 'publish', $animal, $story);
$private = quote_post('Private quote', 'testimonial', 'private', $animal, $story);
$draft = quote_post('Draft quote', 'testimonial', 'draft', $animal, $story);
$password = quote_post('Protected quote', 'testimonial', 'publish', $animal, $story);
get_post($password)->post_password = 'secret';
$other = quote_post('Unrelated quote');
$event = wp_insert_post(array('post_type' => 'homeward_event', 'post_title' => 'Example event'));
equals('accept story link', (string) $story, Testimonials::sanitize_story_id($story));
foreach (array($animal, $event, $testimonial, 99999, -1, array($story), 'bad') as $invalid) {
	equals('reject wrong story target', '', Testimonials::sanitize_story_id($invalid));
}
equals('valid animal link', (string) $animal, Content_Post_Types::sanitize_animal_id($animal));
foreach (array($story, $event, 99999, -1, array($animal)) as $invalid) {
	equals('reject wrong animal target', '', Content_Post_Types::sanitize_animal_id($invalid));
}
$_POST = array('homeward_meta_nonce' => 'invalid', 'homeward_story_id' => (string) $other);
Meta_Boxes::save($testimonial, get_post($testimonial));
equals('nonce protects link', (string) $story, get_post_meta($testimonial, 'homeward_story_id'));
$_POST = array(
	'homeward_meta_nonce' => 'valid',
	'homeward_story_id' => (string) $story,
	'homeward_animal_id' => (string) $animal,
	'homeward_kind' => 'testimonial',
);
$GLOBALS['can'] = false;
Meta_Boxes::save($other, get_post($other));
equals('capability protects links', '0', get_post_meta($other, 'homeward_story_id'));
$GLOBALS['can'] = true;
Meta_Boxes::save($other, get_post($other));
equals('save links story', (string) $story, get_post_meta($other, 'homeward_story_id'));
equals('save links animal', (string) $animal, get_post_meta($other, 'homeward_animal_id'));
$_POST['homeward_story_id'] = (string) $story;
Meta_Boxes::save($story, get_post($story));
equals('self link cleared', '', get_post_meta($story, 'homeward_story_id'));
update_post_meta($story, 'homeward_kind', 'story');
$html = Testimonials::render_linked($animal);
check('linked quote appears on animal', str_contains($html, 'Linked family testimonial'));
check('private quote absent', !str_contains($html, 'Private quote'));
check('draft quote absent', !str_contains($html, 'Draft quote'));
check('password quote absent', !str_contains($html, 'Protected quote'));
check('dialog assets enqueued', in_array('homeward-stories', $GLOBALS['enqueued'], true));
$GLOBALS['current_id'] = $animal;
check(
	'profile block includes testimonial',
	str_contains(Blocks::render('animal-profile'), 'Linked family testimonial'),
);
update_post_meta($animal, Animal::M_STATUS, 'gone');
Animal::flush();
check(
	'adopted profile includes testimonial',
	str_contains(Blocks::render('animal-profile'), 'Linked family testimonial'),
);
$GLOBALS['current_id'] = $story;
check(
	'single story includes testimonial',
	str_contains(Frontend::content(''), 'Linked family testimonial'),
);
equals('testimonials do not recurse', '', Testimonials::render_linked($testimonial));
$grid = Blocks::render('story-cards', array('kind' => 'testimonial'));
check('existing cards include testimonials', str_contains($grid, 'Linked family testimonial'));
check(
	'story kind cards stay separate',
	!str_contains(Blocks::render('story-cards', array('kind' => 'story')), 'Linked family testimonial'),
);
$_GET['homeward_kind'] = 'testimonial';
$query = new WP_Query(
	array(
		'post_type' => 'homeward_story',
		'meta_query' => array(array('key' => 'homeward_featured', 'value' => '1')),
	),
);
Testimonials::filter_list($query);
equals('list combines filters with AND', 'AND', $query->get('meta_query')['relation']);
update_post_meta($testimonial, 'homeward_featured', '1');
check('list filter matches testimonial', meta_matches($testimonial, $query->get('meta_query')));
check('list filter excludes story', !meta_matches($story, $query->get('meta_query')));
$query = new WP_Query(array('post_type' => 'homeward_event'));
Testimonials::filter_list($query);
equals('other lists unaffected', null, $query->get('meta_query'));
$query = new WP_Query(array('post_type' => 'homeward_story'));
$query->main = false;
Testimonials::filter_list($query);
equals('secondary query unaffected', null, $query->get('meta_query'));
$GLOBALS['admin'] = false;
$query->main = true;
Testimonials::filter_list($query);
equals('frontend queries unaffected', null, $query->get('meta_query'));
summary('testimonial');
