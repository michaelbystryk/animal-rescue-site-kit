<?php

require __DIR__ . '/lib/bootstrap.php';
use Homeward\{Wizard, Config_Import, Brand, Settings, Sync, Plugin};
Wizard::init();
Wizard::activate();
equals('wizard keeps six steps', 6, count(Wizard::STEPS));
equals('new wizard starts at first step', 1, Wizard::state()['step']);
check('activation sets redirect', (bool) get_option('homeward_wizard_redirect'));
$GLOBALS['can'] = false;
check('unauthorized does not consume redirect', !Wizard::consume_redirect());
$GLOBALS['can'] = true;
$GLOBALS['ajax'] = true;
check('AJAX does not consume redirect', !Wizard::consume_redirect());
$GLOBALS['ajax'] = false;
check('first admin visit consumes redirect', Wizard::consume_redirect());
check('redirect happens only once', !Wizard::consume_redirect());
Wizard::activate();
check('reactivation does not redirect again', !Wizard::consume_redirect());
Wizard::menu();
equals('setup in settings', 'homeward-setup', $GLOBALS['options_pages'][0][3]);
$state = Wizard::state();
$settings = get_option('homeward_settings');
foreach (array(0, 7) as $step) {
	check('invalid step rejected', is_wp_error(Wizard::save_step($step, array())));
}
check(
	'unknown step keys rejected',
	is_wp_error(Wizard::save_step(1, array('org_name' => 'Example', 'bad' => 'value'))),
);
check('blank rescue rejected', is_wp_error(Wizard::save_step(1, array('org_name' => ''))));
equals('invalid saves preserve progress', $state, Wizard::state());
equals('invalid saves preserve settings', $settings, get_option('homeward_settings'));
$GLOBALS['can'] = false;
check(
	'step save requires capability',
	is_wp_error(Wizard::save_step(1, array('org_name' => 'Example'))),
);
$GLOBALS['can'] = true;
check(
	'step 1 saved',
	!is_wp_error(
		Wizard::save_step(
			1,
			array(
				'org_name' => 'Example Rescue',
				'contact_email' => 'hello@example.test',
				'logo_id' => 0,
				'country' => 'CA',
			),
		),
	),
);
equals('progress resumes at animals', 2, Wizard::state()['step']);
equals('first step snapshot saved', 'Example Rescue', Wizard::state()['steps'][1]['org_name']);
check(
	'premature finish rejected',
	is_wp_error(Wizard::save_step(6, array('activate_theme' => true, 'import_demo' => false))),
);
check(
	'invalid shelter rejected',
	is_wp_error(Wizard::save_step(2, array('source' => 'petstablished', 'shelter_id' => 'bad'))),
);
check(
	'zero shelter selects samples',
	!is_wp_error(Wizard::save_step(2, array('source' => 'petstablished', 'shelter_id' => 0))),
);
equals('zero picks fixture', 'fixture', Settings::get('source'));
$settings = get_option('homeward_settings');
$result = Wizard::check_source(array('source' => 'fixture', 'shelter_id' => 0));
check(
	'fixture live check counts animals',
	!is_wp_error($result) && $result['count'] > 0 && str_contains($result['message'], 'animals found'),
);
equals('fixture check makes no network calls', array(), $GLOBALS['requests']);
equals('check does not save settings', $settings, get_option('homeward_settings'));
$GLOBALS['http'] = function ($url) {
	return array(
		'code' => 200,
		'body' => json_encode(
			array(
				'shelter_pets_total_page' => 1,
				'shelter_pets' => array(array('id' => 71, 'name' => 'Example Pet', 'status' => 'Available')),
			),
		),
	);
};
$result = Wizard::check_source(array('source' => 'petstablished', 'shelter_id' => 123));
equals('live check source adapter used', 1, $result['count']);
check('requested supplied shelter', str_contains($GLOBALS['requests'][0][0], 'shelter_show/123'));
equals('live probe does not change saved settings', $settings, get_option('homeward_settings'));
$GLOBALS['http'] = function () {
	return array('code' => 200, 'body' => '{"unexpected":true}');
};
check(
	'wrong response shape rejected',
	is_wp_error(Wizard::check_source(array('source' => 'petstablished', 'shelter_id' => 123))),
);
check(
	'fixture step with zero accepted',
	!is_wp_error(Wizard::save_step(2, array('source' => 'fixture', 'shelter_id' => 0))),
);
check(
	'invalid payment link rejected',
	is_wp_error(Wizard::save_step(3, array('donate_paypal_url' => 'https://example.test/pay'))),
);
check('donations can be skipped', !is_wp_error(Wizard::save_step(3, array())));
$colors = array_intersect_key(Settings::defaults(), array_flip(Wizard::STEPS[4]));
check(
	'incomplete colors rejected',
	is_wp_error(Wizard::save_step(4, array('color_primary' => '#123456'))),
);
check('palette saved', !is_wp_error(Wizard::save_step(4, $colors)));
$bad_colors = $colors;
$bad_colors['color_ink'] = $bad_colors['color_background'];
check('low contrast warned', !Wizard::color_feedback($bad_colors)[0]['passes']);
check(
	'warning contains threshold',
	str_contains(Wizard::color_feedback($bad_colors)[0]['message'], '4.5:1'),
);
check(
	'invalid font rejected',
	is_wp_error(Wizard::save_step(5, array('font_heading' => 'remote', 'font_body' => 'inter'))),
);
check(
	'fonts saved',
	!is_wp_error(
		Wizard::save_step(5, array('font_heading' => 'lora', 'font_body' => 'atkinson-hyperlegible')),
	),
);
equals('resumes at finish', 6, Wizard::state()['step']);
$existing = wp_insert_post(
	array(
		'post_type' => 'page',
		'post_title' => 'Our existing adoption page',
		'post_name' => 'adopt',
		'post_content' => 'Keep our copy',
		'post_status' => 'publish',
	),
);
$before_stats = $GLOBALS['options'];
foreach (array('bad', 'phc_' . str_repeat('x', 19), 'phc_' . str_repeat('x', 20) . '!') as $bad_key) {
	$error = Wizard::save_step(
		6,
		array(
			'activate_theme' => true,
			'import_demo' => true,
			'posthog_key' => $bad_key,
			'posthog_region' => 'eu',
		),
	);
	check(
		'wizard rejects bad project key with friendly error',
		is_wp_error($error) && str_contains($error->get_error_message(), 'phc_'),
	);
	equals('invalid statistics makes no writes', $before_stats, $GLOBALS['options']);
}
check(
	'wizard rejects unknown region',
	is_wp_error(
		Wizard::save_step(
			6,
			array(
				'activate_theme' => true,
				'import_demo' => true,
				'posthog_key' => '',
				'posthog_region' => 'other',
			),
		),
	),
);
$result = Wizard::save_step(
	6,
	array('activate_theme' => true, 'import_demo' => true, 'posthog_key' => '', 'posthog_region' => 'us'),
);
equals('wizard accepts empty statistics key', '', Settings::get('posthog_key'));
equals('wizard maps US region', 'https://us.i.posthog.com', Settings::get('posthog_host'));
$fake_key = 'phc_' . str_repeat('x', 24);
check(
	'wizard accepts fake project key',
	!is_wp_error(
		Wizard::save_step(
			6,
			array(
				'activate_theme' => true,
				'import_demo' => true,
				'posthog_key' => $fake_key,
				'posthog_region' => 'eu',
			),
		),
	),
);
equals('wizard saves project key', $fake_key, Settings::get('posthog_key'));
equals('wizard maps EU region', 'https://eu.i.posthog.com', Settings::get('posthog_host'));
equals(
	'statistics stay out of boolean finish choices',
	array('activate_theme' => true, 'import_demo' => true),
	Wizard::state()['finish'],
);
check('finish succeeds offline', !is_wp_error($result) && $result['completed_at'] > 0);
equals('seven starter pages', 7, count(get_posts(array('post_type' => 'page'))));
equals('existing page content preserved', 'Keep our copy', get_post($existing)->post_content);
equals('primary menu assigned', 1, get_theme_mod('nav_menu_locations')['primary']);
equals('seven menu links', 7, count($GLOBALS['menu_items'][1]));
equals('block navigation created', 1, count(get_posts(array('post_type' => 'wp_navigation'))));
$navigation = get_post(get_option('homeward_navigation_id'));
equals('saved navigation is published', 'publish', $navigation->post_status);
equals(
	'block navigation has archive and six page links',
	7,
	substr_count($navigation->post_content, 'wp:navigation-link'),
);
preg_match_all('/<!-- wp:navigation-link (.*?) \/-->/', $navigation->post_content, $nav_matches);
$nav_links = array_map(fn($json) => json_decode($json, true), $nav_matches[1]);
foreach (array_diff_key(Wizard::state()['pages'], array('home' => true)) as $slug => $id) {
	$link = array_values(array_filter($nav_links, fn($link) => ($link['id'] ?? 0) === $id));
	check('navigation links to ' . $slug, count($link) === 1 && $link[0]['url'] === get_permalink($id));
}
equals(
	'navigation order',
	array('Animals', 'Our existing adoption page', 'Foster', 'Volunteer', 'Donate', 'About', 'Contact'),
	array_column($nav_links, 'label'),
);
equals('first navigation link is archive', Homeward\Render::archive_url(), $nav_links[0]['url']);
equals('donate has theme button class', 'homeward-navigation-donate', $nav_links[4]['className']);
// Exercise the actual header pattern inside its parent group, before block instances cache attributes.
require dirname(__DIR__, 2) . '/theme/homeward/inc/plugin-support.php';
$header = file_get_contents(dirname(__DIR__, 2) . '/theme/homeward/patterns/header.php');
preg_match('/<!-- wp:navigation (.*?) -->/', $header, $header_match);
$header_nav = array(
	'blockName' => 'core/navigation',
	'attrs' => json_decode($header_match[1], true),
	'innerBlocks' => array(array('blockName' => 'core/page-list', 'attrs' => array())),
	'innerContent' => array(null),
	'innerHTML' => '',
);
$header_tree = array(
	'blockName' => 'core/group',
	'attrs' => array(),
	'innerBlocks' => array(array('blockName' => 'core/group', 'innerBlocks' => array($header_nav))),
);
$bound = apply_filters('render_block_data', $header_tree)['innerBlocks'][0]['innerBlocks'][0];
equals('nested header references wizard post', $navigation->ID, $bound['attrs']['ref']);
equals('header has no page-list to override reference', array(), $bound['innerBlocks']);
wp_insert_post(
	array(
		'post_type' => 'page',
		'post_name' => 'sample-page',
		'post_title' => 'Sample Page',
		'post_status' => 'publish',
	),
);
$nav_id = get_option('homeward_navigation_id');
delete_option('homeward_navigation_id');
$fallback = apply_filters('render_block_data', $header_tree)['innerBlocks'][0]['innerBlocks'][0];
equals(
	'fallback ordered public links',
	array_column($nav_links, 'label'),
	array_column(array_column($fallback['innerBlocks'], 'attrs'), 'label'),
);
check(
	'fallback excludes default page',
	!str_contains(json_encode($fallback), 'Sample Page') && !str_contains(
		json_encode($fallback),
		'core/page-list',
	),
);
update_option('homeward_navigation_id', $nav_id);
unset($GLOBALS['posts'][array_key_last($GLOBALS['posts'])]);
equals('Home is selected front page', Wizard::state()['pages']['home'], get_option('page_on_front'));
equals('front page is static', 'page', get_option('show_on_front'));
equals('first wizard sync completes detail queue', array(), Sync::queue());
equals('wizard saves site title', 'Example Rescue', get_option('blogname'));
equals('wizard country sets currency', 'CAD', Settings::get('currency'));
check('first sync succeeded', !empty(Sync::status()['ok']));
check('missing theme explained', str_contains(implode(' ', $result['messages']), 'Upload Theme'));
check('missing demo listener skipped', str_contains(implode(' ', $result['messages']), 'skipped'));
$posts = $GLOBALS['posts'];
$menu_items = $GLOBALS['menu_items'];
Wizard::save_step(6, array('activate_theme' => true, 'import_demo' => true));
equals('finish repeat no posts duplicated', $posts, $GLOBALS['posts']);
equals('finish repeat no menu duplicates', $menu_items, $GLOBALS['menu_items']);
check(
	'demo hook not fired without listener',
	!in_array('homeward_import_demo', array_column($GLOBALS['actions'], 0), true),
);
$other_navigation = wp_insert_post(
	array(
		'post_type' => 'wp_navigation',
		'post_status' => 'publish',
		'post_name' => 'other-menu',
		'post_title' => 'Other menu',
		'post_content' => 'Keep these links',
	),
);
wp_update_post(array('ID' => $navigation->ID, 'post_content' => 'Outdated links'));
Wizard::save_step(5, array('font_heading' => 'fraunces', 'font_body' => 'inter'));
$GLOBALS['theme_exists'] = true;
add_action('homeward_import_demo', function () {
	$GLOBALS['demo_calls'] = ($GLOBALS['demo_calls'] ?? 0) + 1;
});
$result = Wizard::save_step(6, array('activate_theme' => true, 'import_demo' => true));
check('finish with theme succeeds', !is_wp_error($result));
equals('companion theme activated', 'homeward', get_stylesheet());
equals('theme switched once', 1, $GLOBALS['switch_count']);
equals('demo listener called', 1, $GLOBALS['demo_calls']);
equals('wizard navigation updated in place', $navigation->ID, get_option('homeward_navigation_id'));
equals(
	'modified wizard navigation preserved',
	'Outdated links',
	get_post($navigation->ID)->post_content,
);
equals('other navigation preserved', 'Keep these links', get_post($other_navigation)->post_content);
Wizard::save_step(5, array('font_heading' => 'lora', 'font_body' => 'inter'));
Wizard::save_step(6, array('activate_theme' => true, 'import_demo' => true));
equals('demo not duplicated on retry', 1, $GLOBALS['demo_calls']);
equals('menu still has seven links', 7, count($GLOBALS['menu_items'][1]));
// AJAX routes check both capability and nonce before querying anything.
foreach (array(array(false, 'valid'), array(true, 'invalid'), array(true, 'valid')) as [$can, $nonce]) {
	$GLOBALS['can'] = $can;
	$_POST = array('nonce' => $nonce, 'source' => 'fixture', 'shelter_id' => '0');
	try {
		Wizard::ajax_source();
		check('AJAX exits', false);
	} catch (Setup_Response $response) {
		equals('AJAX permission and nonce', $can && 'valid' === $nonce, $response->payload['success']);
	}
}
$GLOBALS['can'] = true;
$_POST = array('nonce' => 'valid', 'colors' => $bad_colors);
try {
	Wizard::ajax_colors();
} catch (Setup_Response $response) {
	check('AJAX returns contrast warning', !$response->payload['data']['checks'][0]['passes']);
}
// Render every step and check labels plus helper references on all visible fields.
$_POST = array();
Wizard::save_step(1, array('org_name' => 'Example Rescue'));
foreach (range(1, 6) as $step) {
	$_GET = array('step' => $step);
	ob_start();
	Wizard::screen();
	$html = ob_get_clean();
	$doc = new DOMDocument();
	libxml_use_internal_errors(true);
	$doc->loadHTML('<html><body>' . $html . '</body></html>');
	libxml_clear_errors();
	$xp = new DOMXPath($doc);
	foreach ($xp->query('//input[not(@type="hidden")] | //select') as $node) {
		$id = $node->getAttribute('id');
		$label = $node->parentNode->nodeName === 'label' || (
			$id && $xp->query('//label[@for="' . $id . '"]')->length
		);
		check('field label on step ' . $step, (bool) $label);
		$help = $node->getAttribute('aria-describedby');
		check(
			'field helper on step ' . $step,
			$help && $xp->query('//*[@id="' . $help . '"]')->length > 0,
		);
	}
	if (6 === $step) {
		check(
			'finish renders optional statistics',
			str_contains($html, 'Visitor statistics (optional)'),
		);
		check(
			'finish includes both regions',
			str_contains($html, 'United States') && str_contains($html, 'European Union'),
		);
		check(
			'finish explains visitor-only tracking',
			str_contains($html, 'Logged-in users are never tracked.'),
		);
	}
	check('wizard plain copy has no em dash', !str_contains($html, "\u{2014}"));
}
$_SERVER['REQUEST_METHOD'] = 'POST';
$_POST = array('_wpnonce' => 'bad', 'step' => 1, 'fields' => array('org_name' => 'Overwrite'));
try {
	Wizard::screen();
	check('form requires nonce', false);
} catch (RuntimeException $e) {
	equals('form nonce rejected', 'nonce', $e->getMessage());
}
$_SERVER['REQUEST_METHOD'] = 'GET';
// A failed first sync keeps created content and can be retried without duplication.
setup_reset();
foreach (
	array(
		1 => array('org_name' => 'Example Rescue'),
		2 => array('source' => 'petstablished', 'shelter_id' => 123),
		3 => array(),
		4 => $colors,
		5 => array('font_heading' => 'fraunces', 'font_body' => 'inter'),
	) as $step => $fields
) {
	Wizard::save_step($step, $fields);
}
$result = Wizard::save_step(6, array('activate_theme' => false, 'import_demo' => false));
check('failed first sync is reported', is_wp_error($result));
equals('failed sync leaves setup unfinished', 0, Wizard::state()['completed_at']);
equals('failed sync keeps pages', 7, count(get_posts(array('post_type' => 'page'))));
$menu_items = $GLOBALS['menu_items'];
Wizard::save_step(2, array('source' => 'fixture', 'shelter_id' => 0), true);
$result = Wizard::save_step(6, array('activate_theme' => false, 'import_demo' => false));
check('retry finishes with fixture source', !is_wp_error($result) && $result['completed_at'] > 0);
equals('retry does not duplicate pages', 7, count(get_posts(array('post_type' => 'page'))));
equals('retry does not duplicate menu items', $menu_items, $GLOBALS['menu_items']);
equals('unselected theme activation skipped', 'other-theme', get_stylesheet());
// Fonts step: pairing cards, mix your own, and the shared font list.
use Homeward\Fonts;
equals('twelve shared fonts', 12, count(Fonts::slugs()));
equals('default heading font', 'fraunces', Fonts::DEFAULT_HEADING);
equals('default body font', 'inter', Fonts::DEFAULT_BODY);
foreach (Fonts::pairings() as $key => $pair) {
	check(
		'pairing fonts are in the list ' . $key,
		Fonts::is_valid($pair['heading']) && Fonts::is_valid($pair['body']),
	);
}
equals('eight pairings', 8, count(Fonts::pairings()));
equals('first pairing is the default', 'warm-classic', array_key_first(Fonts::pairings()));
check(
	'easy to read uses Atkinson for both',
	Fonts::pairings()[
		'easy-to-read'
	]['heading'] === 'atkinson-hyperlegible' && Fonts::pairings()[
		'easy-to-read'
	]['body'] === 'atkinson-hyperlegible',
);
check(
	'a pairing uses Nunito',
	in_array(
		'nunito',
		array_merge(array_column(Fonts::pairings(), 'heading'), array_column(Fonts::pairings(), 'body')),
		true,
	),
);
equals('grouped lists serif and sans', array('serif', 'sans'), array_keys(Fonts::grouped()));
update_option(
	'homeward_settings',
	array_merge(Settings::get(), array('font_heading' => 'fraunces', 'font_body' => 'inter')),
);
$_POST = array();
$_GET = array('step' => 5);
$_SERVER['REQUEST_METHOD'] = 'GET';
ob_start();
Wizard::screen();
$html = ob_get_clean();
$doc = new DOMDocument();
libxml_use_internal_errors(true);
$doc->loadHTML('<html><body>' . $html . '</body></html>');
libxml_clear_errors();
$xp = new DOMXPath($doc);
$cards = $xp->query('//label[contains(@class,"homeward-fontcard")]');
equals('eight pairing cards render', 8, $cards->length);
equals(
	'cards are radios in one group',
	8,
	$xp->query(
		'//label[contains(@class,"homeward-fontcard")]/input[@type="radio"][@name="fields[font_pairing]"]',
	)->length,
);
equals(
	'default pairing preselected',
	'warm-classic',
	$xp->query('//input[@name="fields[font_pairing]"][@checked]')->item(0)->getAttribute('value'),
);
check(
	'default card is marked',
	str_contains($cards->item(0)->textContent, 'Default') && str_contains(
		$cards->item(0)->textContent,
		'Warm and classic',
	),
);
foreach (Fonts::pairings() as $key => $pair) {
	$card = $xp->query('//input[@value="' . $key . '"]/following-sibling::span')->item(0);
	check('card exists ' . $key, (bool) $card);
	$heading = $xp->query('.//span[contains(@class,"homeward-fontcard-heading")]', $card)->item(0);
	$text = $xp->query('.//span[contains(@class,"homeward-fontcard-text")]', $card)->item(0);
	check(
		'card heading uses heading font ' . $key,
		str_contains(
			$heading->getAttribute('style'),
			Fonts::all()[$pair['heading']]['stack'],
		) && str_contains($heading->textContent, 'Meet Biscuit'),
	);
	check(
		'card text uses body font ' . $key,
		str_contains($text->getAttribute('style'), Fonts::all()[$pair['body']]['stack']),
	);
}
check(
	'mix your own disclosure',
	(bool) $xp->query('//details[contains(@class,"homeward-fontmix")]/summary[text()="Mix your own"]')->length,
);
check(
	'mix selects grouped by serif and sans',
	$xp->query(
		'//details//select[@id="homeward-font_heading"]/optgroup',
	)->length === 2 && $xp->query('//details//select[@id="homeward-font_body"]/optgroup')->length === 2,
);
equals(
	'mix select offers every font',
	12,
	$xp->query('//select[@id="homeward-font_heading"]//option')->length,
);
check('similar font note removed', !str_contains($html, 'similar font'));
check('no Google font requests on the fonts step', !preg_match('~googleapis|gstatic~', $html));
$css = Fonts::preview_css('https://example.test/wp-content/plugins/homeward-rescue/assets/fonts/');
foreach (Fonts::slugs() as $slug) {
	check(
		'preview face for ' . $slug,
		str_contains($css, 'font-family:"' . Fonts::all()[$slug]['name'] . '"'),
	);
	foreach (Fonts::preview_faces($slug) as $face) {
		check(
			'preview file ships ' . $face['file'],
			is_file(HOMEWARD_DIR . 'assets/fonts/' . $face['file']),
		);
	}
}
check('preview css is local only', !preg_match('~googleapis|gstatic|jsdelivr~', $css));
// Saving: a pairing sets both slugs, custom keeps the selects, bad values are rejected.
check(
	'pairing saves',
	!is_wp_error(
		Wizard::save_step(
			5,
			array('font_pairing' => 'elegant', 'font_heading' => 'inter', 'font_body' => 'inter'),
		),
	),
);
equals('pairing saved heading', 'playfair-display', Settings::get('font_heading'));
equals('pairing saved body', 'source-serif-4', Settings::get('font_body'));
check(
	'pairing alone is enough',
	!is_wp_error(Wizard::save_step(5, array('font_pairing' => 'storybook'))),
);
equals('storybook heading', 'dm-serif-display', Settings::get('font_heading'));
equals('storybook body', 'figtree', Settings::get('font_body'));
check(
	'mix your own saves',
	!is_wp_error(
		Wizard::save_step(
			5,
			array('font_pairing' => 'custom', 'font_heading' => 'merriweather', 'font_body' => 'work-sans'),
		),
	),
);
equals('custom heading', 'merriweather', Settings::get('font_heading'));
equals('custom body', 'work-sans', Settings::get('font_body'));
check(
	'selects work without a pairing field',
	!is_wp_error(Wizard::save_step(5, array('font_heading' => 'nunito', 'font_body' => 'nunito-sans'))),
);
equals('plain selects saved', 'nunito', Settings::get('font_heading'));
$before = get_option('homeward_settings');
check(
	'unknown pairing rejected',
	is_wp_error(
		Wizard::save_step(
			5,
			array('font_pairing' => 'comic', 'font_heading' => 'inter', 'font_body' => 'inter'),
		),
	),
);
check(
	'unknown heading slug rejected',
	is_wp_error(
		Wizard::save_step(
			5,
			array('font_pairing' => 'custom', 'font_heading' => 'comic-sans', 'font_body' => 'inter'),
		),
	),
);
check(
	'array pairing rejected',
	is_wp_error(
		Wizard::save_step(
			5,
			array('font_pairing' => array('elegant'), 'font_heading' => 'inter', 'font_body' => 'inter'),
		),
	),
);
equals('rejected fonts leave settings alone', $before, get_option('homeward_settings'));
check(
	'matching pairing is detected',
	Fonts::pairing_for(
		'atkinson-hyperlegible',
		'atkinson-hyperlegible',
	) === 'easy-to-read' && '' === Fonts::pairing_for('lora', 'lora'),
);
Wizard::save_step(5, array('font_pairing' => 'custom', 'font_heading' => 'lora', 'font_body' => 'lora'));
ob_start();
Wizard::screen();
$html = ob_get_clean();
check(
	'custom mix opens the disclosure and selects Use my own mix',
	str_contains(
		$html,
		'<details class="homeward-fontmix" open>',
	) && (bool) preg_match('~value="custom"[^>]*checked~', $html),
);
Wizard::save_step(5, array('font_pairing' => 'warm-classic'));
summary('wizard');
