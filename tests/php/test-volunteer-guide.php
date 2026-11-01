<?php

/** Dashboard help stays generic, settings-driven and permission checked. */
define('HOMEWARD_TEST_PROFILE', 'messages');
require __DIR__ . '/lib/bootstrap.php';
require HOMEWARD_DIR . 'includes/class-volunteer-guide.php';
require HOMEWARD_DIR . 'includes/class-dashboard-help.php';
use Homeward\{Volunteer_Guide, Dashboard_Help};

function add_dashboard_page(...$args) {
	$GLOBALS['guide_page'] = $args;
}

function wp_add_dashboard_widget(...$args) {
	$GLOBALS['guide_widget'] = $args;
}

Volunteer_Guide::init();
Dashboard_Help::init();
Volunteer_Guide::menu();
check(
	'guide is registered inside Dashboard for editors',
	$GLOBALS['guide_page'][2] === 'edit_posts' && $GLOBALS['guide_page'][3] === Volunteer_Guide::PAGE,
);
check('no public guide route', !isset($GLOBALS['hooks']['template_redirect']));
$GLOBALS['options']['homeward_settings'] = array(
	'org_name' => 'Example <Rescue>',
	'help_contact_name' => 'Website helper',
	'help_contact_email' => 'helper@example.test',
);
$GLOBALS['can'] = array('read');
Dashboard_Help::add();
check('subscriber gets no staff widget', !isset($GLOBALS['guide_widget']));
ob_start();
Dashboard_Help::render();
check('subscriber cannot render staff contacts', '' === ob_get_clean());
try {
	Volunteer_Guide::render();
	check('subscriber denied guide', false);
} catch (RuntimeException $e) {
	check('subscriber denied guide', $e->getMessage() === 'Permission denied.');
}
$GLOBALS['can'] = array('read', 'edit_posts');
ob_start();
Volunteer_Guide::render();
$html = ob_get_clean();
foreach (array('stories', 'events', 'animals', 'sync', 'donations', 'messages') as $topic) {
	check('guide covers ' . $topic, str_contains($html, 'id="homeward-guide-' . $topic . '"'));
}
check(
	'organization name escaped from settings',
	str_contains($html, 'Example &lt;Rescue&gt;') && !str_contains($html, '<Rescue>'),
);
check(
	'help contact comes from settings',
	str_contains($html, 'Website helper') && str_contains($html, 'mailto:helper@example.test'),
);
check(
	'administrator-only work is explained',
	str_contains($html, 'Only administrators can change payment details'),
);
Dashboard_Help::add();
check('volunteers get the dashboard widget', $GLOBALS['guide_widget'][0] === 'homeward_site_help');
ob_start();
Dashboard_Help::render();
$widget = ob_get_clean();
check('widget links to the dashboard guide', str_contains($widget, esc_url(Volunteer_Guide::url())));
check(
	'reader sees no unauthorized task links',
	!str_contains($widget, 'post-new.php') && !str_contains($widget, 'options-general.php'),
);
$GLOBALS['can'][] = 'edit_homeward_stories';
ob_start();
Dashboard_Help::render();
$widget = ob_get_clean();
check(
	'story editing shortcut uses capability',
	str_contains($widget, 'post-new.php?post_type=homeward_story'),
);
$GLOBALS['options']['homeward_settings'] = array('org_name' => 'Another Rescue');
ob_start();
Volunteer_Guide::render();
$html = ob_get_clean();
check(
	'guide updates when rescue settings change',
	str_contains($html, 'Another Rescue') && !str_contains($html, 'helper@example.test'),
);
$GLOBALS['can'] = array();
try {
	Volunteer_Guide::render();
	check('unauthorized guide denied', false);
} catch (RuntimeException $e) {
	check('unauthorized guide denied', 'Permission denied.' === $e->getMessage());
}
foreach (
	array(
		'guide/volunteer-guide.php',
		'includes/class-volunteer-guide.php',
		'includes/class-dashboard-help.php',
	) as $file
) {
	$source = file_get_contents(HOMEWARD_DIR . $file);
	check('no fixed external guide links: ' . $file, !preg_match('~https?://~', $source));
	check('no long dash: ' . $file, !str_contains($source, "\u{2014}"));
}
summary('volunteer-guide');
