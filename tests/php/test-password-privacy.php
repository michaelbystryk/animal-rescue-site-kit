<?php

require __DIR__ . '/lib/bootstrap.php';
use Homeward\{
	Animal,
	Blocks,
	Content_Render,
	Content_Post_Types,
	Frontend,
	Render,
	Llms_Txt,
	Seo,
	Testimonials,
	Plugin,
};

function is_home() {
	return false;
}

function is_front_page() {
	return false;
}

function is_archive() {
	return false;
}

function get_page_uri($post) {
	return get_post($post)->post_name;
}

class Privacy_Response {
	public function __construct(public $data) {}

	public $links = array('https://api.w.org/featuredmedia' => 'PRIVATE_IMAGE');

	public function remove_link($rel) {
		unset($this->links[$rel]);
	}

	public function get_data() {
		return $this->data;
	}

	public function set_data($data) {
		$this->data = $data;
	}
}

function privacy_clean($label, $value) {
	check($label, !str_contains((string) $value, 'PRIVATE_'));
}

Plugin::init();
do_action('rest_api_init');
foreach (array('post', 'page', 'homeward_story', 'homeward_event', 'homeward_animal') as $type) {
	$id = wp_insert_post(
		array(
			'post_type' => $type,
			'post_title' => 'Protected title',
			'post_password' => 'secret',
			'post_content' => 'PRIVATE_CONTENT',
			'post_excerpt' => 'PRIVATE_EXCERPT',
			'meta_input' => array(
				'homeward_text' => 'PRIVATE_STORY',
				'homeward_description' => 'PRIVATE_EVENT',
				'homeward_location' => 'PRIVATE_LOCATION',
				Animal::M_DATA => array(
					'raw_name' => 'Animal',
					'species' => 'dog',
					'photos' => array(array('full' => 'https://example.test/PRIVATE_IMAGE')),
				),
				Animal::M_STATUS => 'available',
				Animal::M_LISTED => '1',
			),
		),
	);
	$GLOBALS['current_id'] = $id;
	set_post_thumbnail($id, 500);
	$GLOBALS['can'] = false;
	privacy_clean('single content keeps password form ' . $type, Frontend::content('PRIVATE_CONTENT'));
	check(
		'password form rendered ' . $type,
		str_contains(Frontend::content('PRIVATE_CONTENT'), 'post_password'),
	);
	foreach (array(false, true) as $feature) {
		equals('protected card and data island excluded', '', Content_Render::story_card($id, $feature));
	}
	equals('protected story text excluded', '', Content_Render::story_text_html($id));
	equals('protected event card excluded', '', Content_Render::event_card($id));
	equals('protected event dates excluded', '', Content_Render::event_when($id));
	equals('protected linked quotes excluded', '', Testimonials::render_linked($id));
	equals('protected model excluded', null, Animal::get($id));
	equals('classic thumbnail hidden', '', apply_filters('post_thumbnail_html', 'PRIVATE_IMAGE', $id));
	equals(
		'block featured image hidden',
		'',
		apply_filters(
			'render_block_core/post-featured-image',
			'PRIVATE_IMAGE',
			array(),
			(object) array('context' => array('postId' => $id)),
		),
	);
	equals(
		'oembed denied',
		false,
		Homeward\Animal_Post_Type::oembed(array('thumbnail_url' => 'PRIVATE_IMAGE'), get_post($id)),
	);
	$stale = array(
		'id' => $id,
		'name' => 'PRIVATE_NAME',
		'thumb' => 'PRIVATE_IMAGE',
		'personality' => 'PRIVATE_EXCERPT',
	);
	equals('stale card model guarded', '', Render::card($stale));
	privacy_clean(
		'stale listing JSON guarded',
		Render::listing(array($stale), array('filters' => true, 'courtesy' => array($stale))),
	);
	privacy_clean('stale carousel guarded', Render::carousel(array($stale)));
	privacy_clean('stale profile and gallery guarded', Render::animal_page($stale, get_post($id)));
	equals('direct animal description guarded', '', Render::description(get_post($id)));
	foreach (
		array(
			'animal-grid',
			'animal-carousel',
			'animal-profile',
			'story-cards',
			'story-feature',
			'upcoming-events',
		) as $block
	) {
		privacy_clean('block ' . $block, Blocks::render($block));
	}
	$response = new Privacy_Response(
		array(
			'content' => array('rendered' => 'PRIVATE_CONTENT'),
			'excerpt' => array('rendered' => 'PRIVATE_EXCERPT'),
			'meta' => array('homeward_text' => 'PRIVATE_META'),
			'featured_media' => 500,
		),
	);
	$response = apply_filters('rest_prepare_' . $type, $response, get_post($id));
	privacy_clean('REST strips content excerpt meta ' . $type, json_encode($response->get_data()));
	equals('REST strips image', 0, $response->get_data()['featured_media']);
	equals('REST removes image embed link', array(), $response->links);
	equals('SEO descriptions protected', 'Protected', Seo::plugin_description('PRIVATE_EXCERPT'));
	equals('SEO images protected', '', Seo::plugin_image('PRIVATE_IMAGE'));
	privacy_clean(
		'SEO structured data',
		json_encode(
			Seo::plugin_schema(
				array(
					'page' => array(
						'@type' => 'WebPage',
						'description' => 'PRIVATE_EXCERPT',
						'image' => 'PRIVATE_IMAGE',
					),
				),
			),
		),
	);
	ob_start();
	Seo::head();
	privacy_clean('SEO fallback head', ob_get_clean());
	equals(
		'sitemap entry excluded',
		false,
		Seo::sitemap_entry(array('loc' => 'url'), 'post', get_post($id)),
	);
	check(
		'sitemap exclusions include protected post',
		in_array($id, Seo::sitemap_exclusions(array()), true),
	);
	update_option('homeward_settings', array('llms_pages' => array($id)));
	privacy_clean('llms never includes protected copy', Llms_Txt::text());
	check('llms omits protected title', !str_contains(Llms_Txt::text(), 'Protected title'));
}
equals(
	'story query excludes passwords',
	false,
	Content_Render::story_query()->query_vars['has_password'],
);
equals(
	'event query excludes passwords',
	false,
	Content_Render::event_query()->query_vars['has_password'],
);
equals('sitemap query excludes passwords', false, Seo::sitemap_args(array(), 'post')['has_password']);
// A cached ID must be rechecked after a password is added.
equals('cached protected animals absent', array(), Animal::all_listed());
// Public view responses stay redacted for staff; authenticated edit context stays editable.
$story = get_posts(array('post_type' => 'homeward_story'))[0];
$GLOBALS['can'] = true;
$response = new Privacy_Response(
	array(
		'content' => array('raw' => 'PRIVATE_EDIT_CONTENT'),
		'meta' => array('homeward_text' => 'PRIVATE_EDIT_META'),
	),
);
$edit_request = new class {
	public function get_param($key) {
		return 'context' === $key ? 'edit' : null;
	}
};
check(
	'authorized REST edit preserves saved fields',
	str_contains(
		json_encode(Content_Render::rest_prepare($response, $story, $edit_request)->get_data()),
		'PRIVATE_EDIT_CONTENT',
	),
);
$GLOBALS['can'] = false;
$response = Content_Render::rest_prepare($response, $story, $edit_request);
privacy_clean('unauthorized edit context stays redacted', json_encode($response->get_data()));
summary('password privacy');
