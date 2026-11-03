<?php

/** Profile features: switches, wording, previews, settings, config import and their optional extras. */
require __DIR__ . '/lib/bootstrap.php';
use Homeward\{Animal, Animal_Post_Type, Config_Import, Contact_Form, Features, Pet_Data, Render, Settings, Settings_Page};
set_error_handler(function ($severity, $message, $file, $line) {
	if (error_reporting() & $severity) {
		throw new ErrorException($message, 0, $severity, $file, $line);
	}
	return false;
});


// Settings page doubles, as in the site services suite.
function settings_errors() {}
function settings_fields(...$args) {}
function submit_button(...$args) {}

function fx_settings($values) {
	update_option('homeward_settings', Settings::sanitize($values));
	Animal::flush();
}

function fx_animal($overrides = array()) {
	return array_merge(
		array(
			'id' => 7,
			'name' => 'Pepper & "Co"',
			'url' => 'https://example.invalid/animals/pepper/',
			'status' => 'available',
			'listed' => true,
			'fee' => '250',
			'waiting_since' => time() - 40 * DAY_IN_SECONDS,
		),
		$overrides,
	);
}

function fx_profile($raw = array(), $status = 'available') {
	$data = Pet_Data::sanitize_pet(array_merge(array('name' => 'Juniper', 'animal' => 'cat', 'status' => 'Available', 'adoption_fee' => '175', 'date_aquired' => gmdate('Y-m-d', time() - 30 * DAY_IN_SECONDS)), $raw));
	$id = wp_insert_post(array('post_type' => Animal_Post_Type::TYPE, 'post_status' => 'publish', 'post_title' => 'Juniper', 'post_content' => '<p>Calm and curious.</p>'));
	update_post_meta($id, Animal::M_DATA, $data);
	update_post_meta($id, Animal::M_STATUS, $status);
	Animal::refresh_derived($id);
	update_post_meta($id, Animal::M_LISTED, '1');
	Animal::flush();
	return array(Animal::get($id), get_post($id));
}

$all_on = array();
foreach (Features::KEYS as $key) {
	$all_on[Features::option($key)] = true;
}

// Defaults: the two donate features start on; the others start off.
$off_by_default = array_values(array_diff(Features::KEYS, array('donate-card', 'adopted-donate')));
setup_reset();
$defaults = Settings::defaults();
equals('donate card defaults on', true, $defaults['feature_donate_card']);
equals('adopted donate defaults on', true, $defaults['feature_adopted_donate']);
foreach ($off_by_default as $key) {
	equals("$key defaults off", false, $defaults[Features::option($key)]);
}
equals('donate link defaults to the donate page', 'https://example.invalid/donate/', Features::donate_url());
equals('fee checklist has four generic items', array('Vet check', 'Vaccinations', 'Spay or neuter', 'Microchip'), $defaults['feature_fee_explainer_items']);
equals('adoption steps are neutral', array('Get to know', 'Apply', 'Review', 'Meet', 'Home'), array_map(fn($step) => explode(' {', $step[0])[0], Features::steps()));

// Each feature on and off.
$animal = fx_animal();
$gone = fx_animal(array('status' => 'gone'));
$renders = array(
	'donate-card' => fn() => Features::donate_card($animal),
	'adopted-donate' => fn() => Features::adopted_donate($gone),
);
$renders['fee-explainer'] = fn() => Features::fee_explainer($animal);
$renders['household-share'] = fn() => Features::household_share($animal);
$renders['adoption-path'] = fn() => Features::adoption_path($animal, true);
fx_settings(array_map(fn() => false, $all_on));
foreach ($renders as $key => $render) {
	equals("$key off renders nothing", '', $render());
}
fx_settings($all_on);
$GLOBALS['enqueued'] = array();
foreach ($renders as $key => $render) {
	$html = $render();
	contains("$key on renders its wrapper", $html, 'data-homeward-feature="' . $key . '"');
	check("$key on is visible at once", !str_contains($html, ' hidden>') || 'household-share' === $key);
	check("$key escapes the animal name", !str_contains($html, 'Pepper & "Co"'));
	check("$key has no em dash", !str_contains($html, "\u{2014}"));
}
check('features enqueue their script', in_array('homeward-features', $GLOBALS['enqueued'], true));

// Wording, placeholders and status rules.
$card = Features::donate_card($animal);
contains('donate card names the animal', $card, 'Can’t adopt Pepper &amp; &quot;Co&quot; right now?');
contains('donate card links to the donate page', $card, 'href="https://example.invalid/donate/"');
contains('donate card link is tagged for events', $card, 'data-homeward-donate-link="profile"');
equals('donate card skips gone animals', '', Features::donate_card($gone));
equals('donate card skips pending animals', '', Features::donate_card(fx_animal(array('status' => 'pending'))));
$adopted = Features::adopted_donate($gone);
contains('adopted prompt heading', $adopted, 'found a home. The next one is still waiting.');
contains('adopted prompt main button', $adopted, '>Help the next one</a>');
contains('adopted prompt uses the species noun', $adopted, '>Meet the animals waiting</a>');
contains('adopted prompt links to the animal list', $adopted, 'href="https://example.invalid/dogs/"');
equals('adopted prompt skips available animals', '', Features::adopted_donate($animal));
fx_settings(array_merge($all_on, array('noun_plural' => 'cats', 'noun_singular' => 'cat')));
contains('species noun follows settings', Features::adopted_donate($gone), 'Meet the cats waiting');
contains('singular species noun follows settings', Features::adopted_donate($gone), 'the next cat get ready');
fx_settings(array_merge($all_on, array('feature_donate_url' => '/give/', 'feature_donate_card_heading' => '<b>Help {name}</b>')));
contains('site path donate link', Features::donate_card($animal), 'href="https://example.invalid/give/"');
contains('custom heading is stripped of tags and filled', Features::donate_card($animal), '>Help Pepper &amp; &quot;Co&quot;</h2>');
fx_settings(array('feature_donate_url' => 'javascript:alert(1)'));
equals('unsafe donate link rejected', '', Settings::get('feature_donate_url'));
fx_settings(array('feature_donate_url' => '//evil.invalid/x'));
equals('protocol-relative link rejected', '', Settings::get('feature_donate_url'));
fx_settings(array('feature_donate_url' => 'https://give.example.invalid/now'));
equals('secure external donate link kept', 'https://give.example.invalid/now', Features::donate_url());
fx_settings(array_merge($all_on, array('feature_donate_card_heading' => '')));
contains('empty wording falls back to the default', Features::donate_card($animal), 'right now? You can still help.');

$fee = Features::fee_explainer($animal);
contains('fee explainer is a native disclosure', $fee, '<details class="homeward-fee-explainer" data-homeward-fee-explainer>');
contains('fee explainer button', $fee, 'What’s included?');
foreach (array('Vet check', 'Vaccinations', 'Spay or neuter', 'Microchip') as $item) {
	contains("fee explainer lists $item", $fee, '<span>' . $item . '</span>');
}
equals('no fee, no explainer', '', Features::fee_explainer(fx_animal(array('fee' => ''))));
equals('zero fee, no explainer', '', Features::fee_explainer(fx_animal(array('fee' => '0'))));
fx_settings(array_merge($all_on, array('feature_fee_explainer_items' => array('Food <i>bag</i>', '', 'Collar'), 'feature_fee_explainer_closing' => '')));
$fee = Features::fee_explainer($animal);
check('fee items are cleaned and blank lines dropped', str_contains($fee, '<span>Food bag</span>') && 2 === substr_count($fee, '<li>'));
check('empty closing line is left out', !str_contains($fee, 'homeward-fee-explainer__closing'));
$form = Settings_Page::sanitize_form(array('feature_fee_explainer_items' => "One\n\nTwo\r\nThree"));
equals('settings form splits checklist lines', array('One', 'Two', 'Three'), $form['feature_fee_explainer_items']);

$share = Features::household_share($animal);
contains('household card text', $share, 'Deciding with someone?');
contains('household card button names the page', $share, 'Share Pepper &amp; &quot;Co&quot;’s page');
contains('household card waits for the Share button', $share, 'data-homeward-household-share hidden');
check('household button is a real button', str_contains($share, '<button type="button" class="homeward-animal-btn homeward-animal-btn--secondary" data-homeward-household-open>'));
equals('household card skips gone animals', '', Features::household_share($gone));

$path = Features::adoption_path($animal, true);
check('adoption path lists five steps', 5 === substr_count($path, '<li class="homeward-adoption-path__step'));
contains('adoption path marks the first step', $path, 'aria-current="step"');
contains('adoption path fills the name', $path, 'Get to know Pepper &amp; &quot;Co&quot;');
equals('adoption path needs applications', '', Features::adoption_path($animal, false));
$form = Settings_Page::sanitize_form(array('feature_adoption_path_steps' => "Look | Read\nApply"));
equals('settings form splits step lines', array('Look | Read', 'Apply'), $form['feature_adoption_path_steps']);



// Editors preview everything, or listed keys, without PostHog deciding.
setup_reset();
fx_settings(array_map(fn() => false, $all_on));
$_GET['homeward_preview'] = 'all';
foreach ($renders as $key => $render) {
	$html = $render();
	check("preview all shows $key", str_contains($html, 'data-homeward-feature="' . $key . '"') && !str_contains($html, 'data-homeward-flag'));
}
$_GET['homeward_preview'] = 'adopted-donate,other';
check('preview by key shows the listed feature', str_contains(Features::adopted_donate($gone), 'data-homeward-feature="adopted-donate">'));
check('preview by key leaves others alone', !str_contains(Features::donate_card($animal), 'data-homeward-feature="donate-card">'));
$GLOBALS['can'] = false;
check('visitors cannot preview', !str_contains(Features::adopted_donate($gone), 'data-homeward-feature="adopted-donate">'));
$GLOBALS['can'] = true;
unset($_GET['homeward_preview']);

// Profiles place the features; the default site shows the donate card only.
setup_reset();
list($profile_animal, $post) = fx_profile();
$page = Render::animal_page($profile_animal, $post);
contains('default profile shows the donate card', $page, 'data-homeward-feature="donate-card"');
foreach ($off_by_default as $key) {
	check("default profile has no $key", !str_contains($page, 'data-homeward-feature="' . $key . '"'));
}
check('donate card comes after the story', strpos($page, 'homeward-animal-story') < strpos($page, 'data-homeward-feature="donate-card"'));
fx_settings(array_merge($all_on, array('feature_waiting_counter_min_days' => 0, 'adopt_url' => 'https://apply.example.invalid/form')));
$page = Render::animal_page($profile_animal, $post);
check('fee explainer follows the facts', strpos($page, 'homeward-animal-facts') < strpos($page, 'data-homeward-feature="fee-explainer"'));
check('household card follows the Share button', strpos($page, 'data-homeward-share ') < strpos($page, 'data-homeward-feature="household-share"'));
check('normal Share button stays', str_contains($page, 'data-homeward-share '));
check('adoption steps follow the story', strpos($page, 'homeward-animal-story') < strpos($page, 'data-homeward-feature="adoption-path"'));
list($gone_animal, $gone_post) = fx_profile(array('name' => 'Juniper'), 'gone');
$gone_page = Render::animal_page($gone_animal, $gone_post);
contains('adopted profile shows the prompt', $gone_page, 'data-homeward-feature="adopted-donate"');
check('adopted profile has no donate card', !str_contains($gone_page, 'data-homeward-feature="donate-card"'));

// Settings page: a section with every switch.
$sections = \Homeward\Settings_Fields::sections();
check('features section exists', isset($sections['features']));
foreach (Features::KEYS as $key) {
	check("settings switch for $key", isset($sections['features'][2][Features::option($key)]));
}
ob_start();
Settings_Page::screen();
$screen = ob_get_clean();
contains('settings render the features section', $screen, 'id="homeward-section-features"');
contains('settings explain previews', $screen, '?homeward_preview=all');
check('settings page has no em dash', !str_contains($screen, "\u{2014}"));

// Contact form: the hint shows only when asking about one animal, and can be cleared.
setup_reset();
list($ask_animal) = fx_profile(array('name' => 'Juniper'));
$form = Contact_Form::render(array('topic' => 'animal', 'animal' => $ask_animal['id'], '_return' => 'https://example.invalid/contact/'));
contains('animal question shows the hint', $form, 'placeholder="What would you like to know about Juniper?"');
check('general form has no hint', !str_contains(Contact_Form::render(array('_return' => 'https://example.invalid/contact/')), 'placeholder='));
fx_settings(array('ask_hint' => 'Ask about {name} <b>now</b>'));
contains('custom hint is cleaned and filled', Contact_Form::render(array('topic' => 'animal', 'animal' => $ask_animal['id'], '_return' => 'https://example.invalid/contact/')), 'placeholder="Ask about Juniper now"');
fx_settings(array('ask_hint' => ''));
check('empty hint shows none', !str_contains(Contact_Form::render(array('topic' => 'animal', 'animal' => $ask_animal['id'], '_return' => 'https://example.invalid/contact/')), 'placeholder='));
$css = file_get_contents(HOMEWARD_DIR . 'assets/css/contact-form.css');
check('Send has a subtle hover', str_contains($css, '.homeward-contact__submit:not(:disabled):hover'));

// Config import accepts the feature settings, and the example uses them.
$example = json_decode(file_get_contents(dirname(__DIR__, 2) . '/config.example.json'), true)['settings'];
foreach (Features::KEYS as $key) {
	check("example config lists the $key switch", isset($example[Features::option($key)]));
}
$valid = Config_Import::validate_settings(array('feature_donate_card' => false, 'feature_donate_url' => '/donate/', 'feature_donate_card_button' => 'Give'));
check('feature config validates', is_array($valid) && false === $valid['feature_donate_card'] && '/donate/' === $valid['feature_donate_url']);
check('feature switch must be true or false', is_wp_error(Config_Import::validate_settings(array('feature_donate_card' => 'yes'))));
check('feature donate link must be safe', is_wp_error(Config_Import::validate_settings(array('feature_donate_url' => 'http://x.invalid'))));
check('ask hint imports', is_array(Config_Import::validate_settings(array('ask_hint' => 'Questions about {name}?'))));
equals('fee checklist imports', array('Vet check', 'Microchip'), Config_Import::validate_settings(array('feature_fee_explainer_items' => array('Vet check', 'Microchip')))['feature_fee_explainer_items']);
check('feature lines must be text', is_wp_error(Config_Import::validate_settings(array('feature_fee_explainer_items' => array(1, 2)))));

// No reference rescue names, no em dashes and no dog-only wording in the new files.
foreach (array(
	HOMEWARD_DIR . 'includes/class-features.php',
	HOMEWARD_DIR . 'assets/js/features.js',
) as $file) {
	$text = file_get_contents($file);
	check('no em dash in ' . basename($file), !str_contains($text, "\u{2014}"));
	check('no reference brand in ' . basename($file), !str_contains(strtolower($text), implode('', array('la', 'ldr'))));
}
check('feature wording is species neutral', !preg_match('/\bdogs?\b/i', implode(' ', array_filter(Features::defaults(), 'is_string'))));

summary('profile feature');
