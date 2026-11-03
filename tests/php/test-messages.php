<?php

/** Validation, delivery, privacy, access and rendering with WordPress doubles. */
define('HOMEWARD_TEST_PROFILE', 'messages');
require __DIR__ . '/lib/bootstrap.php';
use Homeward\{Contact_Form, Messages, Plugin, Settings, Settings_Page, Turnstile};
set_error_handler(function ($severity, $message, $file, $line) {
	throw new ErrorException($message, 0, $severity, $file, $line);
});

function valid_input($context = array(), $time = null) {
	return array(
		'context' => Contact_Form::token(
			array_merge(
				array('topic' => 'general', 'animal' => 0, 'return' => home_url('/contact/')),
				$context,
			),
			$time ?? time() - 3,
		),
		'contact_name' => 'Taylor',
		'email' => 'taylor@example.test',
		'phone' => '',
		'message' => 'Can you tell me about adoption?',
		'website' => '',
	);
}

function validate($changes = array(), $base = null, $now = null) {
	return Contact_Form::validate(array_merge($base ?? valid_input(), $changes), '192.0.2.10', $now);
}

function fresh_rate() {
	$GLOBALS['transients'] = array();
}

Messages::init();
Messages::register();
Contact_Form::register();
Plugin::grant_caps();
foreach (
	array(
		'general' => 'General question',
		'animal' => 'About one of our animals',
		'volunteer' => 'Volunteering',
		'foster' => 'Fostering',
		'adopt' => 'Adopting',
		'transport' => 'Transport volunteering',
		'website' => 'Website feedback',
	) as $topic => $label
) {
	check('friendly topic helper: ' . $topic, Homeward\Message_Email::topic_label($topic) === $label);
	update_post_meta(999, '_homeward_topic', $topic);
	ob_start();
	Messages::column('topic', 999);
	$column = ob_get_clean();
	check('friendly topic column: ' . $topic, $column === $label);
}
check(
	'unknown topics use ucfirst and are escaped',
	Homeward\Message_Email::topic_label('other topic') === 'Other topic',
);
update_post_meta(999, '_homeward_topic', '<script>bad</script>');
ob_start();
Messages::column('topic', 999);
$column = ob_get_clean();
check(
	'unknown topic output escaped',
	!str_contains($column, '<script>') && str_contains($column, '&lt;script&gt;'),
);
check(
	'anonymous and logged-in POST hooks use the same handler',
	$GLOBALS['hooks']['admin_post_homeward_contact'] === $GLOBALS['hooks'][
		'admin_post_nopriv_homeward_contact'
	],
);
$cpt = $GLOBALS['cpts'][Messages::TYPE];
check(
	'private CPT has no REST, rewrite or public queries',
	!$cpt['public'] && !$cpt['show_in_rest'] && !$cpt['publicly_queryable'] && !$cpt['rewrite'],
);
check(
	'creation and editing denied, inbox uses dedicated cap',
	$cpt[
		'capabilities'
	][
		'create_posts'
	] === 'do_not_allow' && $cpt[
		'capabilities'
	]['edit_post'] === 'do_not_allow' && $cpt['capabilities']['edit_posts'] === Messages::CAP,
);
foreach ($GLOBALS['roles'] as $name => $role) {
	check(
		$name . ' has correct inbox access',
		!empty($role->caps[Messages::CAP]) === in_array($name, array('administrator', 'editor'), true),
	);
}
check('daily retention scheduled', $GLOBALS['cron'][Messages::CRON][1] === 'daily');
check(
	'shortcode renderer registered',
	$GLOBALS['shortcodes']['homeward_contact_form'] === array(Contact_Form::class, 'render'),
);
check('valid required fields and optional phone accepted', !validate()['errors']);
foreach (
	array(
		'contact_name' => '',
		'email' => 'bad@',
		'message' => 'too short',
		'phone' => str_repeat('1', 101),
	) as $field => $bad
) {
	check($field . ' validation is inline', isset(validate(array($field => $bad))['errors'][$field]));
}
check(
	'email header injection rejected',
	isset(validate(array('email' => "a@example.test\r\nBcc: other@example.test"))['errors']['email']),
);
foreach (array(9 => false, 10 => true, 5000 => true, 5001 => false) as $length => $ok) {
	fresh_rate();
	check(
		'Unicode message boundary ' . $length,
		empty(validate(array('message' => str_repeat('犬', $length)))['errors']) === $ok,
	);
}
foreach (array('context', 'contact_name', 'email', 'phone', 'message', 'website') as $field) {
	check(
		$field . ' rejects array input without PHP warnings',
		(bool) validate(array($field => array('bad')))['errors'] || 'phone' === $field,
	);
}
check('honeypot rejects spam', isset(validate(array('website' => 'spam.test'))['errors']['form']));
check(
	'tampered signature rejected',
	isset(validate(array('context' => valid_input()['context'] . 'x'))['errors']['form']),
);
foreach (
	array(-1 => false, 0 => false, 2 => false, 3 => true, 86400 => true, 86401 => false) as $age => $ok
) {
	fresh_rate();
	check(
		'signed timestamp age ' . $age,
		empty(validate(array(), valid_input(array(), time() - $age))['errors']) === $ok,
	);
}
fresh_rate();
for ($i = 1; $i <= 6; ++$i) {
	check('rate attempt ' . $i, empty(validate()['errors']) === ($i <= 5));
}
$key = array_key_first($GLOBALS['transients']);
check(
	'rate keys never store raw IPs',
	!str_contains(serialize($GLOBALS['transients']), '192.0.2.10') && strlen($key) === 77,
);
check('rate expiration stays at one hour', $GLOBALS['transients'][$key]['ttl'] === 3600);
check('a different IP is independent', !Contact_Form::validate(valid_input(), '192.0.2.11')['errors']);
check('rate resets after one hour', !validate(array(), null, time() + 3600)['errors']);
fresh_rate();
check(
	'three mixed links accepted',
	!validate(array('message' => 'https://one.test http://two.test www.three.test'))['errors'],
);
check(
	'four links rejected',
	isset(
		validate(
			array('message' => 'https://one.test http://two.test www.three.test https://four.test'),
		)['errors']['message'],
	),
);
check(
	'HTML link destinations counted before sanitization',
	isset(
		validate(
			array('message' => str_repeat('<a href="https://spam.test">click me</a>', 4)),
		)['errors']['message'],
	),
);
$GLOBALS['posts'][1] = (object) array(
	'ID' => 1,
	'post_type' => 'homeward_animal',
	'post_status' => 'publish',
	'post_title' => 'Scout <script>alert(1)</script>',
	'post_password' => '',
);
update_post_meta(1, '_homeward_listed', '1');
$GLOBALS['posts'][2] = (object) array(
	'ID' => 2,
	'post_type' => 'page',
	'post_status' => 'publish',
	'post_title' => 'Page',
	'post_password' => '',
);
fresh_rate();
$animal = valid_input(array('topic' => 'animal', 'animal' => 1));
check(
	'published animal bound by signed ID',
	validate(
		array('animal' => '2', 'topic' => 'transport', 'animal_name' => 'Forged'),
		$animal,
	)['data']['animal'] === 1,
);
foreach (array(0, 2, 999) as $id) {
	check(
		'invalid animal ID ' . $id . ' rejected',
		isset(validate(array(), valid_input(array('topic' => 'animal', 'animal' => $id)))['errors']['form']),
	);
}
foreach (array('draft', 'private', 'trash') as $status) {
	$GLOBALS['posts'][1]->post_status = $status;
	check($status . ' animal rejected', isset(validate(array(), $animal)['errors']['form']));
}
$GLOBALS['posts'][1]->post_status = 'publish';
$GLOBALS['posts'][1]->post_password = 'secret';
check('password-protected animal rejected', isset(validate(array(), $animal)['errors']['form']));
$GLOBALS['posts'][1]->post_password = '';
check('no recipient is invented', Messages::recipient('transport') === '');
update_option(
	'homeward_settings',
	Settings::sanitize(
		array(
			'contact_email' => 'main@example.test',
			'message_recipients' => array(
				'transport' => 'transport@example.test',
				'website' => 'web@example.test',
				'injected' => 'evil@example.test',
			),
		),
	),
);
check(
	'topic override and contact fallback',
	Messages::recipient('transport') === 'transport@example.test'
		&& Messages::recipient('animal') === 'main@example.test'
		&& Messages::recipient('website') === 'web@example.test',
);
check('recipient settings allow-list', !isset(Settings::get('message_recipients')['injected']));
$clean = Settings::sanitize(
	array(
		'message_recipients' => array('website' => array('bad'), 'general' => "bad\r\nBcc: evil@example.test"),
	),
);
check(
	'recipient settings reject arrays and header injection',
	'' === $clean['message_recipients']['website'] && '' === $clean['message_recipients']['general'],
);
fresh_rate();
$GLOBALS['mail_ok'] = false;
$result = Contact_Form::submit(
	array_merge($animal, array('message' => "A question with a backslash \\ and apostrophe ' kept.")),
	'192.0.2.20',
);
$id = array_key_last($GLOBALS['posts']);
check(
	'mail failure still returns success with a saved private message',
	!$result['errors']
		&& get_post($id)->post_type === Messages::TYPE
		&& get_post($id)->post_status === 'private'
		&& get_post_meta($id, '_homeward_email_failed') === '1',
);
check(
	'message body backslashes survive WP slashing',
	str_contains(get_post($id)->post_content, "backslash \\ and apostrophe '"),
);
check(
	'saved before sending and Reply-To is the validated sender',
	$GLOBALS['saved_before_mail'] === count($GLOBALS['posts']) && (bool) preg_grep(
		'/^Reply-To: "[^"]*" <taylor@example\.test>$/',
		end($GLOBALS['mail'])[3],
	),
);
$last = end($GLOBALS['mail']);
check(
	'email is HTML with a clear subject naming the sender',
	in_array(
		'Content-Type: text/html; charset=UTF-8',
		$last[3],
		true,
	) && str_contains($last[1], ' from ') && str_contains($last[2], 'New website message'),
);
check('email body never contains raw script tags', !str_contains($last[2], '<script'));
check(
	'mail animal name and URL come from stored animal',
	str_contains(
		end($GLOBALS['mail'])[2],
		get_permalink(1),
	) && !str_contains(end($GLOBALS['mail'])[1], '<script>'),
);
ob_start();
Messages::notice();
$notice = ob_get_clean();
check(
	'quiet admin mail failure notice shown',
	str_contains($notice, 'notice notice-info')
		&& str_contains($notice, 'email')
		&& str_contains($notice, 'Messages')
		&& str_contains($notice, 'edit.php?post_type=' . Messages::TYPE),
);
$menu = array(array('Messages', Messages::CAP, 'edit.php?post_type=' . Messages::TYPE));
Messages::menu();
check('unread bubble shown', str_contains($menu[0][0], 'pending-count">1'));
$_GET = array('message' => (string) $id);
ob_start();
Messages::view();
$view = ob_get_clean();
check(
	'GET keeps unread and shows staff reply with subject',
	get_post_meta($id, '_homeward_read') === '0' && str_contains($view, 'Reply by email') && str_contains(
		$view,
		'mailto:taylor@example.test?subject=Re%3A',
	),
);
check(
	'staff screen escapes stored subject and body',
	!str_contains($view, '<script>') && str_contains($view, '&lt;script&gt;'),
);
ob_start();
Messages::notice();
check('GET keeps failed email warning', ob_get_clean() !== '');
$_SERVER['REQUEST_METHOD'] = 'POST';
$_POST = array('homeward_message_action' => 'mark_read', '_wpnonce' => 'bad');
try {
	Messages::view();
	check('read action requires nonce', false);
} catch (RuntimeException $e) {
	check('read action requires nonce', $e->getMessage() === 'nonce');
}
check('bad nonce keeps unread', get_post_meta($id, '_homeward_read') === '0');
$_POST['_wpnonce'] = 'valid';
ob_start();
Messages::view();
ob_end_clean();
check('explicit POST marks read', get_post_meta($id, '_homeward_read') === '1');
ob_start();
Messages::notice();
check('POST clears warning', ob_get_clean() === '');
$_SERVER['REQUEST_METHOD'] = 'GET';
$_POST = array();
$GLOBALS['can'] = array();
try {
	Messages::view();
	check('unauthorized reader denied', false);
} catch (RuntimeException $e) {
	check('unauthorized reader denied', $e->getMessage() === 'Not allowed.');
}

$GLOBALS['can'] = array(Messages::CAP, 'manage_options', 'read');
$GLOBALS['save_fail'] = true;
$mail_count = count($GLOBALS['mail']);
check(
	'database error reports failure and sends no email',
	isset(
		Contact_Form::submit(valid_input(), '192.0.2.21')['errors']['form'],
	) && count($GLOBALS['mail']) === $mail_count,
);
$GLOBALS['save_fail'] = false;
$_GET = array('topic' => 'animal', 'animal' => '1');
$html = Contact_Form::render(
	array(
		'heading' => '<script>bad()</script>',
		'intro' => '<img src=x onerror=bad()>',
		'button' => 'Send "now"',
		'privacy' => '<b>Private</b>',
	),
);
check(
	'renderer escapes editable copy and animal name',
	!str_contains($html, '<script>') && !str_contains($html, '<img src=x') && str_contains(
		$html,
		'&lt;script&gt;',
	),
);
check(
	'animal photo and server-derived label shown',
	str_contains($html, 'About Scout') && str_contains($html, 'width="64"'),
);
preg_match('/name="context" value="([^"]+)"/', $html, $m);
$context = Contact_Form::context(html_entity_decode($m[1], ENT_QUOTES));
check(
	'contact query binds allow-listed topic and animal',
	$context['topic'] === 'animal' && $context['animal'] === 1,
);
$_GET = array('topic' => 'hacked', 'animal' => '2');
$html = Contact_Form::render();
preg_match('/name="context" value="([^"]+)"/', $html, $m);
check(
	'invalid query ignored',
	Contact_Form::context(html_entity_decode($m[1], ENT_QUOTES))['topic'] === 'general',
);
$_GET = array('topic' => 'adopt');
$html = Contact_Form::render(array('topic' => 'transport'));
preg_match('/name="context" value="([^"]+)"/', $html, $m);
check(
	'inline transport topic cannot be overridden by query',
	Contact_Form::context(html_entity_decode($m[1], ENT_QUOTES))['topic'] === 'transport',
);
$entity_copy = Contact_Form::render(
	array('heading' => 'Questions &amp; answers', 'button' => 'Send &amp; reply'),
);
check(
	'editable RichText entities are not double escaped',
	str_contains($entity_copy, 'Questions &amp; answers') && !str_contains($entity_copy, '&amp;amp;'),
);
check(
	'normal POST, visible labels, live status and public email-link ban',
	str_contains($html, 'method="post"')
		&& str_contains($html, 'admin-post.php')
		&& substr_count($html, '<label ') === 5
		&& str_contains($html, 'aria-live="polite"')
		&& !str_contains($html, 'mailto:'),
);
$errors = Contact_Form::render_form(
	array(),
	array('email' => 'Invalid <email>'),
	array(
		'name' => '"><script>bad</script>',
		'email' => 'bad',
		'message' => '</textarea><script>bad</script>',
	),
);
check(
	'no-JS field errors and retained values are escaped',
	str_contains($errors, 'aria-invalid="true"') && str_contains(
		$errors,
		'Invalid &lt;email&gt;',
	) && !str_contains($errors, '<script>'),
);
get_post($id)->post_date_gmt = gmdate('Y-m-d H:i:s', strtotime('-25 months'));
$GLOBALS['mail_ok'] = true;
$recent = Messages::save($result['data']);
Messages::prune();
check(
	'retention permanently deletes old message and metadata only',
	!get_post($id) && !isset(
		$GLOBALS['meta'][$id],
	) && get_post($recent) && get_post(1) && $GLOBALS['deleted_force'],
);
check(
	'retention uses UTC calendar-month cutoff',
	$GLOBALS['last_query']['date_query'][0]['column'] === 'post_date_gmt' && substr(
		$GLOBALS['last_query']['date_query'][0]['before'],
		0,
		10,
	) === gmdate('Y-m-d', strtotime('-24 months')),
);
$_GET = array();
$_SERVER['REQUEST_METHOD'] = 'POST';
$_SERVER['REMOTE_ADDR'] = '192.0.2.33';
$_POST = valid_input();
try {
	Contact_Form::handle();
} catch (Test_Response $e) {}
check(
	'native POST returns a safe 303 receipt URL with form anchor',
	$GLOBALS['response']['status'] === 303 && $GLOBALS['response']['url'] === home_url(
		'/contact/?sent=1#form',
	) && $GLOBALS['nocache'],
);
$_POST = valid_input(array('return' => 'https://evil.example/path'));
try {
	Contact_Form::handle();
} catch (Test_Response $e) {}
check(
	'redirect is validated even with signed external URL',
	$GLOBALS['response']['url'] === home_url('/contact/?sent=1#form'),
);
$_POST = array_merge(valid_input(), array('homeward_fetch' => '1', 'email' => 'bad'));
try {
	Contact_Form::handle();
} catch (Test_Response $e) {}
check(
	'fetch errors use JSON 422 and field errors',
	$GLOBALS['response']['status'] === 422 && !$GLOBALS['response']['data']['success'] && isset(
		$GLOBALS['response']['data']['errors']['email'],
	),
);
$_POST = array_merge(valid_input(), array('homeward_fetch' => '1'));
try {
	Contact_Form::handle();
} catch (Test_Response $e) {}
check(
	'fetch success returns JSON receipt',
	$GLOBALS['response']['status'] === 200 && $GLOBALS['response']['data']['success'],
);
$_SERVER['REQUEST_METHOD'] = 'GET';
try {
	Contact_Form::handle();
	check('GET submission denied', false);
} catch (RuntimeException $e) {
	check('GET submission denied', $e->getMessage() === 'Please submit the contact form.');
}
$_POST = array();
$_SERVER['REQUEST_METHOD'] = 'POST';
$_POST = array_merge(valid_input(), array('email' => 'not-an-email', 'message' => 'Question kept intact'));
try {
	Contact_Form::handle();
} catch (Test_Response $e) {}
check(
	'invalid native POST redirects to form page',
	$GLOBALS['response']['status'] === 303 && str_contains(
		$GLOBALS['response']['url'],
		'/contact/?homeward_form=',
	),
);
parse_str(parse_url($GLOBALS['response']['url'], PHP_URL_QUERY), $_GET);
$ticket = $_GET['homeward_form'];
check(
	'recovery URL contains no personal details',
	!str_contains(
		$GLOBALS['response']['url'],
		'Question',
	) && !str_contains($GLOBALS['response']['url'], 'not-an-email'),
);
check(
	'tampered ticket rejected',
	false === Contact_Form::recover_errors($ticket . 'x', home_url('/contact/')),
);
check('ticket bound to return page', false === Contact_Form::recover_errors($ticket, home_url('/other/')));
$key = 'homeward_form_' . explode('.', $ticket)[0];
check('error data has ten minute lifetime', $GLOBALS['transients'][$key]['ttl'] === 600);
$_SERVER['REQUEST_METHOD'] = 'GET';
$post_html = Contact_Form::render();
check(
	'redirected form keeps values and inline errors',
	str_contains($post_html, 'Question kept intact') && str_contains(
		$post_html,
		'Enter a valid email address.',
	) && str_contains($post_html, 'aria-invalid="true"'),
);
check('ticket consumed once', false === Contact_Form::recover_errors($ticket, home_url('/contact/')));
$expired = Contact_Form::store_errors(array('errors' => array(), 'data' => array()), home_url('/contact/'));
$key = 'homeward_form_' . explode('.', $expired)[0];
$GLOBALS['transients'][$key]['value']['expires'] = time() - 1;
check('expired recovery rejected', false === Contact_Form::recover_errors($expired, home_url('/contact/')));
if (in_array('--preview', $_SERVER['argv'], true)) {
	$_GET = array('topic' => 'animal', 'animal' => '1');
	$GLOBALS['posts'][1]->post_title = 'Scout';
	echo '<!doctype html><html lang="en"><meta charset="utf-8">';
	echo '<meta name="viewport" content="width=device-width"><title>Contact fixture</title>';
	echo '<style>body{margin:0;padding:16px;background:#fef9f5}';
	echo file_get_contents(HOMEWARD_DIR . 'assets/css/contact-form.css');
	echo '</style><main>' . Contact_Form::render() . '</main></html>';
} else {
	summary('message');
}
