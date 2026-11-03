<?php
namespace Homeward;

defined( 'ABSPATH' ) || exit;

require_once __DIR__ . '/class-features.php';

/**
 * Validate rescue settings and coordinate source configuration changes.
 */
class Settings {
	const PUBLIC_FIELDS = array(
		'fee', 'weight', 'age', 'compat', 'housebroken', 'special_needs_text', 'video', 'breed',
		'is_spayed', 'shots_up_to_date',
	);
	private static $source_lock = null;
	const PLACEHOLDER = '/^\s*a\s+(generic|foster)\b|\bapplication\b/i';

	/**
	 * Return the complete default settings in their stable order.
	 *
	 * @return array
	 */
	public static function defaults() {
		return array_merge(
			self::animal_defaults(),
			self::rescue_defaults(),
			self::service_defaults(),
			Features::defaults()
		);
	}

	private static function animal_defaults() {
		return array(
			'source'                 => 'petstablished',
			'shelter_id'             => 0,
			'species'                => array( 'dog' ),
			'noun_singular'          => 'animal',
			'noun_plural'            => 'animals',
			'archive_slug'           => 'dogs',
			'single_slug'            => 'dogs',
			'stories_slug'           => 'stories',
			'events_slug'            => 'events',
			'placeholder_pattern'    => self::PLACEHOLDER,
			'courtesy_mode'          => 'hide',
			'courtesy_pattern'       => '/courtesy(?:\s+post)?/i',
			'name_cleaning'          => true,
			'hidden_statuses'        => array( 'hold' ),
			'origin_enabled'         => false,
			'origin_rules'           => array(),
			'apply_mode'             => 'per_animal',
			'adopt_general_pet_ids'  => array(),
			'foster_pet_id'          => 0,
			'adopt_url'              => '',
			'foster_url'             => '',
			'volunteer_url'          => '',
			'donate_etransfer_email' => '',
			'donate_etransfer_note'  => '',
			'donate_card_url'        => '',
			'donate_card_label'      => 'Donate by card',
			'donate_order'           => array( 'card', 'paypal', 'etransfer' ),
		);
	}

	private static function rescue_defaults() {
		return array(
			'org_name'                 => (string) get_option( 'blogname', '' ),
			'contact_email'            => '',
			'phone'                    => '',
			'country'                  => '',
			'currency'                 => '',
			'region_label'             => 'Origin',
			'help_contact_name'        => '',
			'help_contact_email'       => '',
			'social'                   => array(),
			'color_primary'            => '#1F4E79',
			'color_accent'             => '#B58B35',
			'color_ink'                => '#2B2A28',
			'color_background'         => '#F7F1E3',
			'font_pair'                => 'system',
			'public_fields'            => self::PUBLIC_FIELDS,
			'badge_new_days'           => 14,
			'badge_long_wait_days'     => 0,
			'badge_special_needs'      => true,
			'badge_bonded'             => true,
			'badge_pending'            => true,
			'badge_labels'             => array(),
			'image_mode'               => 'hotlink',
			'gone_retention_days'      => 365,
			'import_stories_slug'      => '',
			'import_testimonials_slug' => '',
		);
	}

	private static function service_defaults() {
		return array(
			'email_guard'                 => true,
			'redirects'                   => '',
			'llms_txt'                    => true,
			'posthog_key'                 => '',
			'posthog_cookies'             => false,
			'trust_proxy'                 => false,
			'delete_on_uninstall'         => false,
			'delete_content_on_uninstall' => false,
			'posthog_region'              => 'us',
			'posthog_host'                => 'https://us.i.posthog.com',
			'logo_url'                    => '',
			'llms_pages'                  => array(),
			'message_recipients'          => array(),
			'turnstile_site_key'          => '',
			'turnstile_secret'            => '',
			'share_networks'              => array( 'facebook', 'x', 'email', 'whatsapp' ),
			'font_heading'                => 'fraunces',
			'font_body'                   => 'inter',
			'color_surface'               => '#FFFDF8',
			'logo_id'                     => 0,
			'donate_paypal_url'           => '',
		);
	}

	/**
	 * Read effective settings with legacy and automatic fallbacks.
	 *
	 * @param string|null $key Option key, or null for all settings.
	 * @return mixed
	 */
	public static function get( $key = null ) {
		$saved = get_option( 'homeward_settings', array() );
		$all = array_merge(
			self::defaults(),
			is_array( $saved ) ? array_intersect_key( $saved, self::defaults() ) : array()
		);
		if ( ! is_array( $saved ) || ! array_key_exists( 'archive_slug', $saved ) ) {
			$all['archive_slug'] = self::species_slug( $all['species'] );
		}
		if ( ! is_array( $saved ) || ! array_key_exists( 'single_slug', $saved ) ) {
			$all['single_slug'] = $all['archive_slug'];
		}
		if ( '' === $all['donate_paypal_url'] ) {
			$all['donate_paypal_url'] = self::legacy_paypal( (array) $saved );
		}
		if ( ! isset( $saved['posthog_region'] ) && Analytics::region_host( 'eu' ) === $all['posthog_host'] ) {
			$all['posthog_region'] = 'eu';
		}
		if ( empty( $all['currency'] ) ) {
			$all['currency'] = self::country_currency( $all['country'] );
		}
		return null === $key ? $all : ( $all[ $key ] ?? null );
	}

	/**
	 * Choose the default address for the selected species.
	 *
	 * @param array $species Selected species.
	 * @return string
	 */
	public static function species_slug( $species ) {
		return array( 'dog' ) === $species ? 'dogs' : ( array( 'cat' ) === $species ? 'cats' : 'animals' );
	}

	/**
	 * Convert a legacy PayPal handle or button into a donation URL.
	 *
	 * @param array $saved Previously stored settings.
	 * @return string
	 */
	public static function legacy_paypal( $saved ) {
		$handle = $saved['donate_paypal_me'] ?? '';
		$button = $saved['donate_paypal_button_id'] ?? '';
		if ( is_string( $handle ) && preg_match( '/^[A-Za-z0-9]{1,20}$/D', $handle ) ) {
			return 'https://www.paypal.com/paypalme/' . rawurlencode( $handle );
		}
		return is_string( $button ) && preg_match( '/^[A-Za-z0-9]{1,40}$/D', $button )
			? 'https://www.paypal.com/donate/?hosted_button_id=' . rawurlencode( $button ) : '';
	}

	/**
	 * Choose the default currency for a country code.
	 *
	 * @param string $country Two-letter country code.
	 * @return string
	 */
	public static function country_currency( $country ) {
		$country = strtoupper( (string) $country );
		$currencies = array( 'CA' => 'CAD', 'US' => 'USD', 'GB' => 'GBP', 'AU' => 'AUD', 'NZ' => 'NZD' );
		$eu = array(
			'IE',
			'AT',
			'BE',
			'BG',
			'HR',
			'CY',
			'CZ',
			'DK',
			'EE',
			'FI',
			'FR',
			'DE',
			'GR',
			'HU',
			'IT',
			'LV',
			'LT',
			'LU',
			'MT',
			'NL',
			'PL',
			'PT',
			'RO',
			'SK',
			'SI',
			'ES',
			'SE',
		);
		return $currencies[ $country ] ?? ( in_array( $country, $eu, true ) ? 'EUR' : 'USD' );
	}

	/**
	 * Sanitize a scalar value as plain text.
	 *
	 * @param mixed $v Submitted value.
	 * @return string
	 */
	public static function text( $v ) {
		return sanitize_text_field( is_scalar( $v ) ? (string) $v : '' );
	}

	/**
	 * Accept only a sanitized HTTPS address.
	 *
	 * @param mixed $v Submitted URL.
	 * @return string
	 */
	public static function https( $v ) {
		$v = self::text( $v );
		return 'https' === strtolower( (string) wp_parse_url( $v, PHP_URL_SCHEME ) )
			? esc_url_raw( $v, array( 'https' ) ) : '';
	}

	/**
	 * Validate a supported PayPal address without credentials or a custom port.
	 *
	 * @param mixed $value Submitted URL.
	 * @return string
	 */
	public static function paypal_url( $value ) {
		$url = self::https( $value );
		$host = strtolower( (string) wp_parse_url( $url, PHP_URL_HOST ) );
		return in_array(
			$host,
			array( 'paypal.me', 'www.paypal.me', 'paypal.com', 'www.paypal.com' ),
			true
		) && ! wp_parse_url( $url, PHP_URL_USER ) && ! wp_parse_url( $url, PHP_URL_PASS )
			&& ! wp_parse_url( $url, PHP_URL_PORT ) ? $url : '';
	}

	/**
	 * Check a bounded regular expression without exposing parser warnings.
	 *
	 * @param mixed $v Candidate expression.
	 * @return bool
	 */
	public static function regex( $v ) {
		return is_string( $v ) && strlen( $v ) <= 500 && null !== self::match_pattern( $v, '' );
	}

	/**
	 * Match a bounded pattern and restore the process backtracking limit.
	 *
	 * @param mixed       $pattern  Candidate expression.
	 * @param string      $subject  Text to match.
	 * @param string|null $fallback Expression used after a parser failure.
	 * @return bool|null
	 */
	public static function match_pattern( $pattern, $subject, $fallback = null ) {
		$limit = ini_get( 'pcre.backtrack_limit' );
		ini_set( 'pcre.backtrack_limit', (string) min( (int) $limit, 10000 ) );
		try {
			$result = is_string( $pattern ) && strlen( $pattern ) <= 500 ? @preg_match( $pattern, $subject ) : false;
			if ( false === $result && null !== $fallback ) {
				$result = @preg_match( $fallback, $subject );
			}
			return false === $result ? null : (bool) $result;
		} finally {
			ini_set( 'pcre.backtrack_limit', $limit );
		}
	}

	/**
	 * Replace a bounded pattern and restore the process backtracking limit.
	 *
	 * @param mixed  $pattern     Candidate expression.
	 * @param string $replacement Replacement text.
	 * @param string $subject     Text to transform.
	 * @param string $fallback    Expression used after a parser failure.
	 * @return string
	 */
	public static function replace_pattern( $pattern, $replacement, $subject, $fallback ) {
		$limit = ini_get( 'pcre.backtrack_limit' );
		ini_set( 'pcre.backtrack_limit', (string) min( (int) $limit, 10000 ) );
		try {
			$result = is_string( $pattern ) && strlen( $pattern ) <= 500
				? @preg_replace( $pattern, $replacement, $subject ) : null;
			return $result ?? @preg_replace( $fallback, $replacement, $subject ) ?? $subject;
		} finally {
			ini_set( 'pcre.backtrack_limit', $limit );
		}
	}

	/**
	 * Sanitize submitted fields while preserving omitted settings.
	 *
	 * @param mixed $input Submitted settings.
	 * @return array
	 */
	public static function sanitize( $input ) {
		$input = is_array( $input ) ? $input : array();
		$out = self::get();
		$saved = (array) get_option( 'homeward_settings', array() );
		$out['currency'] = $saved['currency'] ?? '';
		self::sanitize_scalars( $input, $out, $saved );
		self::sanitize_public_values( $input, $out );
		self::sanitize_choices( $input, $out, $saved );
		self::sanitize_rows( $input, $out );
		self::sanitize_services( $input, $out );
		self::sanitize_brand( $input, $out );
		self::confirm_source( $input, $out );
		self::sanitize_contact( $input, $out );
		Features::sanitize( $input, $out );
		return $out;
	}

	private static function sanitize_scalars( $input, &$out, $saved ) {
		$enums = array(
			'posthog_region' => array( 'us', 'eu' ),
			'source'         => array( 'petstablished', 'fixture', 'demo' ),
			'courtesy_mode'  => array( 'hide', 'section', 'show' ),
			'apply_mode'     => array( 'per_animal', 'general' ),
			'image_mode'     => array( 'hotlink', 'import_cover' ),
			'font_pair'      => array( 'warm', 'classic', 'friendly', 'modern', 'system' ),
			'font_heading'   => Fonts::slugs(),
			'font_body'      => Fonts::slugs(),
		);
		foreach ( self::defaults() as $key => $default ) {
			if ( ! array_key_exists( $key, $input ) ) {
				continue;
			}
			$v = $input[ $key ];
			if ( isset( $enums[ $key ] ) ) {
				$out[ $key ] = in_array( $v, $enums[ $key ], true ) ? $v : $default;
			} elseif ( is_bool( $default ) ) {
				$out[ $key ] = filter_var( $v, FILTER_VALIDATE_BOOLEAN );
			} elseif ( is_int( $default ) ) {
				$out[ $key ] = max( 0, (int) ( is_scalar( $v ) ? $v : 0 ) );
			} elseif ( ! is_array( $default ) ) {
				$out[ $key ] = self::text( $v );
			}
		}
	}

	private static function sanitize_public_values( $input, &$out ) {
		foreach ( array( 'adopt_url', 'foster_url', 'volunteer_url', 'donate_card_url' ) as $k ) {
			$out[ $k ] = self::https( $out[ $k ] );
		}
		foreach ( array( 'contact_email', 'help_contact_email', 'donate_etransfer_email' ) as $k ) {
			$out[ $k ] = sanitize_email( $out[ $k ] );
		}
		foreach ( array(
			'archive_slug',
			'single_slug',
			'stories_slug',
			'events_slug',
			'import_stories_slug',
			'import_testimonials_slug',
			) as $k ) {
			$out[ $k ] = sanitize_title( $out[ $k ] );
		}
		foreach ( array( 'color_primary', 'color_accent', 'color_ink', 'color_background', 'color_surface' ) as $k ) {
			$out[ $k ] = sanitize_hex_color( $out[ $k ] ) ?: '';
		}
		$out['country'] = preg_match( '/^[A-Z]{2}$/', strtoupper( $out['country'] ) ) ? strtoupper( $out['country'] ) : '';
		$out['currency'] = preg_match( '/^[A-Z]{3}$/', strtoupper( $out['currency'] ) ) ? strtoupper( $out['currency'] ) : '';
		$out['placeholder_pattern'] = self::regex( $out['placeholder_pattern'] )
			? $out['placeholder_pattern'] : self::PLACEHOLDER;
	}

	private static function sanitize_choices( $input, &$out, $saved ) {
		foreach ( array(
			'species'         => array( 'dog', 'cat', 'other' ),
			'public_fields'   => self::PUBLIC_FIELDS,
			'hidden_statuses' => array( 'hold', 'pending', 'available' ),
			'donate_order'    => array( 'card', 'paypal', 'etransfer' ),
			'share_networks'  => array( 'facebook', 'x', 'email', 'whatsapp' ),
			) as $key => $allowed ) {
			if ( isset( $input[ $key ] ) ) {
				$values = array_filter( (array) $input[ $key ], 'is_string' );
				$out[ $key ] = array_values(
					array_unique(
						'donate_order' === $key ? array_intersect( $values, $allowed ) : array_intersect( $allowed, $values )
					)
				);
			}
		}
		$out['species'] = $out['species'] ?: array( 'dog' );
		foreach ( array( 'archive_slug', 'single_slug' ) as $key ) {
			// Absence means automatic. Previously saved slugs remain stable until explicitly changed.
			if ( ! array_key_exists( $key, $input ) && ! array_key_exists( $key, $saved ) ) {
				unset( $out[ $key ] );
			} elseif ( array_key_exists( $key, $input ) && '' === $out[ $key ] ) {
				unset( $out[ $key ] );
			}
		}
		$out['stories_slug'] = $out['stories_slug'] ?: 'stories';
		$out['events_slug'] = $out['events_slug'] ?: 'events';
		$out['noun_singular'] = $out['noun_singular'] ?: 'animal';
		$out['noun_plural'] = $out['noun_plural'] ?: 'animals';
		$out['badge_new_days'] = min( 365, $out['badge_new_days'] );
	}

	private static function sanitize_rows( $input, &$out ) {
		foreach ( array( 'origin_rules', 'adopt_general_pet_ids', 'social', 'badge_labels' ) as $key ) {
			if ( ! array_key_exists( $key, $input ) ) {
				continue;
			}
			$out[ $key ] = array();
			foreach ( array_slice( (array) $input[ $key ], 0, 50, true ) as $k => $row ) {
				if ( 'badge_labels' === $key ) {
					if ( in_array(
						$k,
						array(
							'new',
							'longest-waiting',
							'special',
							'bonded',
							'pending',
							'available',
							'hold',
							'gone',
						),
						true
					) ) {
						$out[ $key ][ $k ] = self::text( $row );
					}
				} elseif ( is_array( $row ) && 'origin_rules' === $key ) {
					$patterns = array_values( array_filter( (array) ( $row['patterns'] ?? array() ), array( __CLASS__, 'regex' ) ) );
					if ( $patterns && self::text( $row['region'] ?? '' ) ) {
						$out[ $key ][] = array( 'region' => self::text( $row['region'] ), 'patterns' => $patterns );
					}
				} elseif ( is_array( $row ) && 'social' === $key ) {
					if ( $url = self::https( $row['url'] ?? '' ) ) {
						$out[ $key ][] = array( 'service' => self::text( $row['service'] ?? '' ), 'url' => $url );
					}
				} elseif ( is_array( $row ) && is_scalar( $row['pet_id'] ?? null ) && absint( $row['pet_id'] ) ) {
					$out[ $key ][] = array( 'label' => self::text( $row['label'] ?? '' ), 'pet_id' => absint( $row['pet_id'] ) );
				}
			}
		}
	}

	private static function sanitize_services( $input, &$out ) {
		$out['courtesy_pattern'] = self::regex( $out['courtesy_pattern'] )
			? $out['courtesy_pattern'] : self::defaults()['courtesy_pattern'];
		if ( array_key_exists( 'redirects', $input ) ) {
			$out['redirects'] = Redirects::sanitize( $input['redirects'] );
		}
		if ( array_key_exists( 'posthog_key', $input ) && '' !== $input['posthog_key']
			&& '' === Analytics::sanitize_key( $input['posthog_key'] ) ) {
			add_settings_error( 'homeward_settings', 'posthog_key', Analytics::key_error() );
			$out['posthog_key'] = self::get( 'posthog_key' );
		}
		$out['posthog_key'] = Analytics::sanitize_key( $out['posthog_key'] );
		// A submitted region derives the cloud host unless an explicit custom host overrides it.
		$host = array_key_exists( 'posthog_host', $input ) ? Analytics::sanitize_host( $input['posthog_host'] ) : '';
		if ( array_key_exists( 'posthog_region', $input ) || array_key_exists( 'posthog_host', $input ) ) {
			$out['posthog_host'] = $host ?: Analytics::region_host( $out['posthog_region'] );
		} else {
			$out['posthog_host'] = Analytics::sanitize_host( $out['posthog_host'] )
				?: Analytics::region_host( $out['posthog_region'] );
		}
	}

	private static function sanitize_brand( $input, &$out ) {
		$out['logo_url'] = self::https( $out['logo_url'] );
		$out['logo_id'] = $out['logo_id'] && wp_attachment_is_image( $out['logo_id'] ) ? $out['logo_id'] : 0;
		if ( ! array_key_exists( 'donate_paypal_url', $input ) && ( isset( $input['donate_paypal_me'] )
			|| isset( $input['donate_paypal_button_id'] ) ) ) {
			$out['donate_paypal_url'] = self::legacy_paypal( $input );
		}
		$out['donate_paypal_url'] = self::paypal_url( $out['donate_paypal_url'] );
		// Preserve legacy font pairs until a rescue explicitly chooses individual fonts.
		foreach ( array( 'font_heading', 'font_body' ) as $key ) {
			if ( ! array_key_exists( $key, $input )
				&& ! array_key_exists( $key, (array) get_option( 'homeward_settings', array() ) ) ) {
				unset( $out[ $key ] );
			}
		}
		if ( array_key_exists( 'llms_pages', $input ) ) {
			$out['llms_pages'] = array_values(
				array_unique(
					array_filter(
						array_map(
							'absint',
							array_filter( (array) $input['llms_pages'], 'is_scalar' )
						)
					)
				)
			);
		}
	}

	private static function confirm_source( $input, &$out ) {
		$old = self::get();
		if ( ( $old['shelter_id'] !== $out['shelter_id'] || $old['source'] !== $out['source'] )
			&& self::source_change_needs_confirmation() ) {
			if ( ! self::$source_lock && ( ! is_scalar( $input['confirm_source_change'] ?? null )
				|| '1' !== (string) $input['confirm_source_change'] ) ) {
				$out['shelter_id'] = $old['shelter_id'];
				$out['source'] = $old['source'];
				add_settings_error(
					'homeward_settings',
					'source_confirmation',
					__( 'Confirm the source change. Animals from the old source will be moved to draft.', 'homeward-rescue' )
				);
			} elseif ( ! self::$source_lock && ! ( self::$source_lock = Sync_Lock::acquire() ) ) {
				$out['shelter_id'] = $old['shelter_id'];
				$out['source'] = $old['source'];
				add_settings_error(
					'homeward_settings',
					'source_busy',
					__( 'A sync is running. Save the source change again after it finishes.', 'homeward-rescue' )
				);
			} else {
				// Release even if another settings filter vetoes this update.
				register_shutdown_function( array( __CLASS__, 'release_source_lock' ) );
			}
		}
	}

	private static function sanitize_contact( $input, &$out ) {
		if ( array_key_exists( 'message_recipients', $input ) ) {
			$out['message_recipients'] = array();
			foreach ( Messages::TOPICS as $topic ) {
				$value = is_array( $input['message_recipients'] ) ? ( $input['message_recipients'][ $topic ] ?? '' ) : '';
				$out['message_recipients'][ $topic ] = sanitize_email( self::text( $value ) );
			}
		}
		foreach ( array(
			'turnstile_site_key' => '/^[A-Za-z0-9_-]{0,100}$/D',
			'turnstile_secret'   => '/^[A-Za-z0-9_.-]{0,200}$/D',
			) as $key => $pattern ) {
			$value = $input[ $key ] ?? self::get( $key );
			$value = is_string( $value ) ? trim( $value ) : null;
			$out[ $key ] = null !== $value && preg_match( $pattern, $value ) ? $value : self::get( $key );
		}
		// A blank password field preserves the secret. Removal is an explicit settings choice.
		if ( empty( $input['turnstile_secret'] ) ) {
			$out['turnstile_secret'] = self::get( 'turnstile_secret' );
		}
		if ( '1' === ( $input['clear_turnstile_secret'] ?? null ) ) {
			$out['turnstile_secret'] = '';
		}
	}

	/**
	 * Store a transient and track it for eventual cleanup.
	 *
	 * @param string $key   Transient key.
	 * @param mixed  $value Transient value.
	 * @param int    $ttl   Lifetime in seconds.
	 * @return bool
	 */
	public static function temporary( $key, $value, $ttl ) {
		$keys = (array) get_option( 'homeward_transient_keys', array() );
		$keys = array_filter( $keys, static fn ( $expires ) => $expires > time() );
		$keys[ $key ] = time() + $ttl;
		update_option( 'homeward_transient_keys', $keys, false );
		return set_transient( $key, $value, $ttl );
	}

	/**
	 * Release the lease held by a settings source change.
	 *
	 * @return void
	 */
	public static function release_source_lock() {
		if ( self::$source_lock ) {
			Sync_Lock::release( self::$source_lock );
			self::$source_lock = null;
		}
	}

	/**
	 * Check whether replacing the source would affect existing animals.
	 *
	 * @return bool
	 */
	public static function source_change_needs_confirmation() {
		return (bool) self::get( 'shelter_id' ) || ( in_array( self::get( 'source' ), array( 'fixture', 'demo' ), true )
			&& (bool) Animal::all_ids() );
	}

	/**
	 * Resolve the public detail fields within the allowed set.
	 *
	 * @return array
	 */
	public static function public_fields() {
		$allowed = array_intersect( self::PUBLIC_FIELDS, (array) self::get( 'public_fields' ) );
		return array_values( array_intersect( $allowed, (array) apply_filters( 'homeward_public_fields', $allowed ) ) );
	}

	/**
	 * Resolve the source API key from a constant or private option.
	 *
	 * @return string
	 */
	public static function key() {
		return defined( 'HOMEWARD_PETSTABLISHED_KEY' )
			? (string) HOMEWARD_PETSTABLISHED_KEY : (string) get_option( 'homeward_api_key', '' );
	}

	/**
	 * Clear source backoff and consecutive failure state.
	 *
	 * @return void
	 */
	public static function reset_source_errors() {
		foreach ( array( 'homeward_backoff_until', 'homeward_http_failures', 'homeward_sync_paused_until' ) as $key ) {
			delete_option( $key );
		}
		$status = Sync::status();
		if ( $status ) {
			$status['failures'] = 0;
			update_option( Sync::OPT_STATUS, $status, false );
		}
	}

	private static function retire_previous_source( $old, $new ) {
		if ( ( $old['source'] ?? null ) !== ( $new['source'] ?? null ) || ( $old['shelter_id']
			?? null ) !== ( $new['shelter_id'] ?? null ) ) {
			self::reset_source_errors();
			delete_option( Sync::OPT_STATUS );
			delete_option( Sync::OPT_QUEUE );
			if ( ! empty( $old['shelter_id'] ) || in_array( $old['source'] ?? '', array( 'fixture', 'demo' ), true ) ) {
				$ids = Animal::all_ids();
				update_meta_cache( 'post', $ids );
				foreach ( $ids as $id ) {
					$source = get_post_meta( $id, Animal::M_SOURCE, true );
					$adapter = get_post_meta( $id, Animal::M_ADAPTER, true );
					if ( ( '' !== $source && (int) $source !== (int) $old['shelter_id'] ) || ( '' !== $adapter
						&& $adapter !== ( $old['source'] ?? 'petstablished' ) ) ) {
						continue;
					}
					// Trash/private posts keep their stronger existing protection.
					if ( in_array( get_post( $id )->post_status, array( 'publish', 'pending', 'future' ), true ) ) {
						wp_update_post( array( 'ID' => $id, 'post_status' => 'draft' ) );
					}
					update_post_meta( $id, Animal::M_LISTED, '0' );
					delete_post_meta( $id, '_homeward_retired' );
				}
			}
		}
	}

	/**
	 * Retire replaced source records and refresh derived settings.
	 *
	 * @param array $old Previous stored settings.
	 * @param array $new New stored settings.
	 * @return void
	 */
	public static function saved( $old, $new ) {
		$old = (array) $old;
		$new = (array) $new;
		if ( ( $old['species'] ?? self::defaults()['species'] ) !== ( $new['species'] ?? self::defaults()['species'] ) ) {
			update_option( 'homeward_species_customized', true, false );
		}
		Animal::begin_batch();
		try {
			self::retire_previous_source( $old, $new );
			$derived = array(
				'courtesy_mode',
				'courtesy_pattern',
				'species',
				'hidden_statuses',
				'placeholder_pattern',
				'name_cleaning',
				'origin_enabled',
				'origin_rules',
			);
			foreach ( $derived as $key ) {
				if ( ( $old[ $key ] ?? null ) !== ( $new[ $key ] ?? null ) ) {
					update_option( 'homeward_derived_generation', wp_generate_uuid4(), false );
					update_option( 'homeward_derived_queue', Animal::all_ids(), false );
					Scheduler::once( 'homeward_refresh_derived', time() + 1 );
					break;
				}
			}
			if ( function_exists( 'wp_clean_theme_json_cache' ) ) {
				wp_clean_theme_json_cache();
			}
			foreach ( array( 'archive_slug', 'single_slug' ) as $key ) {
				$old[ $key ] = $old[ $key ] ?? self::species_slug( $old['species'] ?? array( 'dog' ) );
				$new[ $key ] = $new[ $key ] ?? self::species_slug( $new['species'] ?? array( 'dog' ) );
			}
			$slugs = array_flip( array( 'archive_slug', 'single_slug', 'stories_slug', 'events_slug' ) );
			if ( array_intersect_key( (array) $old, $slugs ) !== array_intersect_key( (array) $new, $slugs ) ) {
				update_option( 'homeward_flush_rewrites', 1, false );
			}
		} finally {
			Animal::end_batch();
			self::release_source_lock();
		}
		$pending = (array) get_option( 'homeward_derived_queue', array() );
		if ( $pending && count( $pending ) <= 50 ) {
			self::refresh_batch();
		}
		do_action( 'homeward_settings_saved', $old, $new );
	}

	/**
	 * Refresh a bounded group of animals under the shared sync lease.
	 *
	 * @return void
	 */
	public static function refresh_batch() {
		$lock = Sync_Lock::acquire();
		if ( ! $lock ) {
			Scheduler::once( 'homeward_refresh_derived', time() + 60 );
			return;
		}
		Animal::begin_batch();
		try {
			$generation = get_option( 'homeward_derived_generation', '' );
			$queue = (array) get_option( 'homeward_derived_queue', array() );
			$ids = array_splice( $queue, 0, 50 );
			update_meta_cache( 'post', $ids );
			foreach ( $ids as $id ) {
				$post = get_post( $id );
				if ( ! $post || Animal_Post_Type::TYPE !== $post->post_type ) {
					continue;
				}
				Animal::refresh_derived( $id );
				$name = Animal::name( $id );
				if ( $name !== $post->post_title ) {
					wp_update_post( wp_slash( array( 'ID' => $id, 'post_title' => $name ) ) );
				}
			}
			if ( $generation === get_option( 'homeward_derived_generation', '' ) ) {
				update_option( 'homeward_derived_queue', $queue, false );
			} else {
				$queue = (array) get_option( 'homeward_derived_queue', array() );
			}
			if ( $queue ) {
				Scheduler::once( 'homeward_refresh_derived', time() + 10 );
			}
		} finally {
			Animal::end_batch();
			Sync_Lock::release( $lock );
		}
	}
}
