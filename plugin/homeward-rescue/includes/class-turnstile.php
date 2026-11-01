<?php
/**
 * Optional Cloudflare spam check, configured in Homeward Rescue settings.
 */

namespace Homeward;

defined( 'ABSPATH' ) || exit;

/**
 * Verify contact submissions with optional Turnstile protection.
 */
class Turnstile {
	const VERIFY_URL = 'https://challenges.cloudflare.com/turnstile/v0/siteverify';
	const SCRIPT_URL = 'https://challenges.cloudflare.com/turnstile/v0/api.js';

	/**
	 * Return the configured public site key.
	 *
	 * @return string
	 */
	public static function site_key() {
		$key = Settings::get( 'turnstile_site_key' );
		return is_string( $key ) ? trim( $key ) : '';
	}

	/**
	 * Return the configured verification secret.
	 *
	 * @return string
	 */
	public static function secret() {
		$key = Settings::get( 'turnstile_secret' );
		return is_string( $key ) ? trim( $key ) : '';
	}

	/**
	 * Check that both verification keys are configured.
	 *
	 * @return bool
	 */
	public static function enabled() {
		return '' !== self::site_key() && '' !== self::secret();
	}

	/**
	 * Render the challenge and enqueue its script.
	 *
	 * @return string
	 */
	public static function widget() {
		if ( ! self::enabled() ) {
			return '';
		}
		// phpcs:ignore WordPress.WP.EnqueuedResourceParameters.MissingVersion -- The endpoint serves the current build.
		wp_enqueue_script(
			'homeward-turnstile',
			self::SCRIPT_URL,
			array(),
			null,
			array( 'strategy' => 'defer', 'in_footer' => true )
		);
		return '<div class="homeward-contact__turnstile cf-turnstile" data-sitekey="' . esc_attr( self::site_key() )
			. '" data-appearance="interaction-only" data-theme="light" data-size="flexible"></div>';
	}

	/**
	 * Verify a token with Cloudflare.
	 *
	 * @param string $token Token from the cf-turnstile-response field.
	 * @param string $ip    Visitor IP (sent to Cloudflare only, never stored).
	 * @return true|string True when verified, else a visitor-facing error.
	 */
	public static function verify( $token, $ip ) {
		if ( ! self::enabled() ) {
			return true;
		}
		$retry = __( 'Please confirm you’re human (the check above the Send button), then try again.', 'homeward-rescue' );
		if ( ! is_string( $token ) || '' === $token || strlen( $token ) > 2048 ) {
			return $retry;
		}
		$response = wp_remote_post(
			self::VERIFY_URL,
			array(
				'timeout' => 8,
				'body'    => array(
					'secret'   => self::secret(),
					'response' => $token,
					'remoteip' => $ip,
				),
			)
		);
		if ( is_wp_error( $response ) || 200 !== (int) wp_remote_retrieve_response_code( $response ) ) {
			return __( 'We couldn’t check your message for spam just now. Please try again in a minute.', 'homeward-rescue' );
		}
		$body = json_decode( (string) wp_remote_retrieve_body( $response ), true );
		return is_array( $body ) && ! empty( $body['success'] ) ? true : $retry;
	}
}
