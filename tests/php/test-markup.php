<?php

require __DIR__ . '/lib/bootstrap.php';
use Homeward\{Settings, Sync, Animal, Blocks, Content_Render};
update_option(
	'homeward_settings',
	Settings::sanitize(
		array(
			'source' => 'fixture',
			'shelter_id' => 123,
			'species' => array('dog', 'cat'),
			'country' => 'CA',
			'donate_etransfer_email' => 'fake@example.test',
		),
	),
);
Sync::run_listings();
$GLOBALS['current_id'] = Animal::ps_map()[910001];
$data = Animal::data($GLOBALS['current_id']);
$data['photos'] = array(
	array(
		'full' => 'https://petstablished.com/synthetic.jpg',
		'thumb' => 'https://petstablished.com/synthetic.jpg',
	),
);
update_post_meta($GLOBALS['current_id'], Animal::M_DATA, $data);
Animal::flush();
$story = wp_insert_post(
	array(
		'post_type' => 'homeward_story',
		'post_title' => 'Fictional Happy Tail',
		'post_content' => '',
		'post_status' => 'publish',
	),
);
update_post_meta($story, 'homeward_text', 'A synthetic family story.');
$html = '';
foreach (Blocks::NAMES as $name) {
	$html .= Blocks::render($name) . Blocks::render($name);
}
ob_start();
Content_Render::story_dialog();
$html .= ob_get_clean();
$doc = new DOMDocument();
libxml_use_internal_errors(true);
$doc->loadHTML(
	'<!doctype html><html><head><meta charset="utf-8"><title>Offline markup checks</title></head><body>'
		. $html
		. '</body></html>',
);
libxml_clear_errors();
$xp = new DOMXPath($doc);
$ids = array();

foreach ($xp->query('//*[@id]') as $node) {
	$id = $node->getAttribute('id');
	check('unique id ' . $id, !isset($ids[$id]));
	$ids[$id] = true;
}
foreach ($xp->query('//*[@aria-labelledby or @aria-describedby or @aria-controls or @for]') as $node) {
	foreach (array('aria-labelledby', 'aria-describedby', 'aria-controls', 'for') as $attr) {
		$value = $node->getAttribute($attr);
		foreach (array_filter(explode(' ', $value)) as $id) {
			check('reference target ' . $id, isset($ids[$id]));
		}
	}
}
foreach ($xp->query('//img') as $node) {
	check('image alt exists', $node->hasAttribute('alt'));
}
foreach ($xp->query('//button') as $node) {
	check(
		'button accessible name',
		trim($node->textContent) !== '' || $node->getAttribute('aria-label') !== '',
	);
}
foreach ($xp->query('//input[not(@type="hidden")] | //select') as $node) {
	$id = $node->getAttribute('id');
	check(
		'form control label',
		($id && $xp->query('//label[@for="' . $id . '"]')->length) || $node->parentNode->nodeName === 'label',
	);
}
foreach ($xp->query('//script[@type="application/json"]') as $node) {
	check('JSON data island decodes', is_array(json_decode($node->textContent, true)));
	check(
		'whitelist excludes source sensitive data',
		!str_contains($node->textContent, 'FAKE_PRIVATE'),
	);
}
check('no eager video iframe', $xp->query('//iframe')->length === 0);
summary('rendered markup');
