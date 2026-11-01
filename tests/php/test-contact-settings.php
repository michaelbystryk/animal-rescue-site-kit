<?php

/** Exercise the real settings screen and optional verification without network calls. */
namespace Homeward {
	class Sync {
		public static function status() {
			return array();
		}

		public static function paused_until() {
			return 0;
		}
	}
}
namespace {
	define('HOMEWARD_TEST_PROFILE', 'messages');
	require __DIR__ . '/lib/bootstrap.php';
	use Homeward\{Settings, Settings_Page, Turnstile, Messages, Contact_Form, Plugin};
	set_error_handler(function ($severity, $message, $file, $line) {
		throw new ErrorException($message, 0, $severity, $file, $line);
	});

	function save_settings($values) {
		update_option('homeward_settings', Settings_Page::sanitize_form($values));
	}

	save_settings(array('org_name' => 'Example Rescue', 'contact_email' => 'team@example.test'));
	check(
		'Turnstile defaults off',
		!Turnstile::enabled() && true === Turnstile::verify('', '192.0.2.1') && !isset(
			$GLOBALS['verify_request'],
		) && '' === Turnstile::widget(),
	);
	save_settings(array('turnstile_site_key' => 'test-site-key'));
	check('one key leaves verification off', !Turnstile::enabled());
	save_settings(array('turnstile_secret' => 'test-secret-key'));
	check('both keys enable verification', Turnstile::enabled());
	$widget = Turnstile::widget();
	check(
		'widget contains the public site key only',
		str_contains($widget, 'data-sitekey="test-site-key"') && !str_contains($widget, 'test-secret-key'),
	);
	foreach (array('', str_repeat('x', 2049), array('invalid')) as $token) {
		check(
			'invalid tokens fail without HTTP',
			true !== Turnstile::verify($token, '192.0.2.1') && !isset($GLOBALS['verify_request']),
		);
	}
	$GLOBALS['verify_response'] = array('code' => 200, 'body' => '{"success":true}');
	check('successful remote check accepted', true === Turnstile::verify('test-response-token', '192.0.2.1'));
	check(
		'remote check sends secret and token to Cloudflare',
		$GLOBALS[
			'verify_request'
		][0] === Turnstile::VERIFY_URL && $GLOBALS[
			'verify_request'
		][1]['body']['secret'] === 'test-secret-key' && $GLOBALS[
			'verify_request'
		][1]['body']['response'] === 'test-response-token',
	);
	foreach (
		array(
			new WP_Error('offline', 'offline'),
			array('code' => 500, 'body' => '{"success":true}'),
			array('code' => 200, 'body' => '{"success":false}'),
			array('code' => 200, 'body' => 'invalid JSON'),
		) as $response
	) {
		$GLOBALS['verify_response'] = $response;
		check(
			'verification failure fails closed',
			true !== Turnstile::verify('test-response-token', '192.0.2.1'),
		);
	}
	$input = array(
		'context' => Contact_Form::token(
			array('topic' => 'website', 'animal' => 0, 'return' => home_url('/contact/')),
			time() - 3,
		),
		'contact_name' => 'Visitor',
		'email' => 'visitor@example.test',
		'message' => 'A question about the website',
		'website' => '',
	);
	$before = count($GLOBALS['posts']);
	check(
		'enabled verification blocks persistence without a token',
		isset(
			Contact_Form::submit($input, '192.0.2.1')['errors']['form'],
		) && count($GLOBALS['posts']) === $before,
	);
	save_settings(array('turnstile_secret' => ''));
	check('blank password keeps saved secret', Settings::get('turnstile_secret') === 'test-secret-key');
	save_settings(array('turnstile_secret' => array('bad'), 'turnstile_site_key' => '<invalid key>'));
	check(
		'malformed keys preserve saved values',
		Turnstile::secret() === 'test-secret-key' && Turnstile::site_key() === 'test-site-key',
	);
	ob_start();
	Settings_Page::screen();
	$screen = ob_get_clean();
	check(
		'settings screen never echoes saved secret',
		!str_contains($screen, 'test-secret-key') && str_contains(
			$screen,
			'type="password" id="homeward-turnstile_secret" name="homeward_settings[turnstile_secret]" value=""',
		),
	);
	check(
		'settings page exposes every topic with readable labels',
		str_contains($screen, 'Website feedback') && str_contains(
			$screen,
			'homeward_settings[message_recipients][website]',
		) && substr_count($screen, 'id="homeward-recipient-') === count(Messages::TOPICS),
	);
	check(
		'new settings have helper copy',
		str_contains($screen, 'blank addresses use Contact email') && str_contains(
			$screen,
			'add both Cloudflare Turnstile keys',
		) && str_contains($screen, 'leave this blank to keep it'),
	);
	save_settings(array('clear_turnstile_secret' => '1'));
	check(
		'explicit secret removal disables verification',
		'' === Turnstile::secret() && !Turnstile::enabled() && !array_key_exists(
			'clear_turnstile_secret',
			Settings::get(),
		),
	);
	save_settings(array('turnstile_secret' => 'replacement-secret', 'turnstile_site_key' => ''));
	check('empty site key disables verification too', !Turnstile::enabled());
	save_settings(array('contact_email' => ''));
	check(
		'missing recipient still stores the message',
		empty(Contact_Form::submit($input, '192.0.2.2')['errors']),
	);
	$id = array_key_last($GLOBALS['posts']);
	check(
		'missing recipient marks notification failure',
		get_post_meta($id, '_homeward_email_failed') === '1' && !$GLOBALS['mail'],
	);
	check(
		'stored message has no raw IP or verification token',
		!str_contains(serialize($GLOBALS['meta']), '192.0.2.2') && !str_contains(
			serialize($GLOBALS['meta']),
			'test-response-token',
		),
	);
	$GLOBALS['options']['homeward_db_version'] = HOMEWARD_VERSION;
	Plugin::maybe_upgrade();
	check(
		'existing installations get message capabilities without a version bump',
		isset(
			$GLOBALS['roles']['editor']->caps[Messages::CAP],
			$GLOBALS['roles']['administrator']->caps['read_private_homeward_messages'],
		),
	);
	$_SERVER['REMOTE_ADDR'] = '192.0.2.10';
	$_SERVER['HTTP_CF_CONNECTING_IP'] = '198.51.100.2';
	$_SERVER['HTTP_X_FORWARDED_FOR'] = '203.0.113.5, 203.0.113.6';
	check('proxy headers ignored by default', Contact_Form::client_ip() === '192.0.2.10');
	save_settings(array('trust_proxy' => true));
	check('opt in trusts Cloudflare', Contact_Form::client_ip() === '198.51.100.2');
	$_SERVER['HTTP_CF_CONNECTING_IP'] = 'invalid';
	check('first forwarded address used', Contact_Form::client_ip() === '203.0.113.5');
	$_SERVER['HTTP_X_FORWARDED_FOR'] = 'invalid, 203.0.113.5';
	check('invalid forwarding uses remote', Contact_Form::client_ip() === '192.0.2.10');
	add_filter('homeward_client_ip', static fn($ip) => '198.51.100.42');
	check('custom client filter applied', Contact_Form::client_ip() === '198.51.100.42');
	add_filter('homeward_client_ip', static fn($ip) => array('bad'));
	check('invalid filter fails back to remote', Contact_Form::client_ip() === '192.0.2.10');
	check(
		'proxy and privacy helper copy present',
		str_contains($screen, 'homeward_client_ip') && str_contains($screen, 'memory only') && str_contains(
			$screen,
			'Delete all Homeward data',
		),
	);
	summary('contact-settings and Turnstile');
}
