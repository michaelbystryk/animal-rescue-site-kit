<?php
namespace Homeward;

defined( 'ABSPATH' ) || exit;

/**
 * Validate setup values shared by uploads, the command line and the wizard.
 */
class Config_Import {
	const MAX_BYTES = 65536;
	const FIELDS = array(
		'posthog_key',
		'posthog_region',
		'species',
		'org_name',
		'logo_id',
		'contact_email',
		'country',
		'currency',
		'source',
		'shelter_id',
		'donate_paypal_url',
		'donate_card_url',
		'donate_etransfer_email',
		'donate_etransfer_note',
		'color_primary',
		'color_accent',
		'color_ink',
		'color_background',
		'color_surface',
		'font_heading',
		'font_body',
	);

	/**
	 * Accepted settings: the fixed setup fields plus every feature setting.
	 *
	 * @return array
	 */
	public static function fields() {
		return array_merge( self::FIELDS, array_keys( Features::defaults() ) );
	}
	/**
	 * Register the configuration import command when WP-CLI is available.
	 *
	 * @return void
	 */
	public static function init() {
		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			\WP_CLI::add_command( 'homeward import-config', array( __CLASS__, 'cli' ) );
		}
	}

	/**
	 * Validate a supported secure PayPal donation URL.
	 *
	 * @param mixed $value Candidate URL.
	 * @return string
	 */
	public static function paypal_url( $value ) {
		return Settings::paypal_url( $value );
	}

	/**
	 * Reject keys outside the accepted configuration fields.
	 *
	 * @param array  $input   Submitted fields.
	 * @param array  $allowed Accepted keys.
	 * @param string $where   Configuration section name.
	 * @return true|\WP_Error
	 */
	public static function unknown( $input, $allowed, $where ) {
		$unknown = array_diff( array_keys( $input ), $allowed );
		return $unknown ? new \WP_Error( 'homeward_config_unknown', sprintf(
			__( 'Unknown keys in %1$s: %2$s. Remove them and try again.', 'homeward-rescue' ),
			$where,
			implode( ', ', $unknown )
		) ) : true;
	}

	/**
	 * Validate a partial setup configuration without saving it.
	 *
	 * @param mixed $input Submitted configuration.
	 * @return array|\WP_Error
	 */
	public static function validate_settings( $input ) {
		if ( ! is_array( $input ) ) {
			return new \WP_Error( 'homeward_config', __( 'Settings must be a group of named fields.', 'homeward-rescue' ) );
		}
		$known = self::unknown( $input, self::fields(), 'settings' );
		if ( is_wp_error( $known ) ) {
			return $known;
		}
		$out = array();
		foreach ( $input as $key => $value ) {
			$value = self::validate_field( $key, $value );
			if ( is_wp_error( $value ) ) {
				return $value;
			}
			$out[ $key ] = $value;
		}
		if ( array_key_exists( 'shelter_id', $out ) ) {
			$out['source'] = 0 === $out['shelter_id'] ? 'fixture' : ( $out['source'] ?? 'petstablished' );
		} elseif ( isset( $out['source'] ) && 'petstablished' === $out['source'] && ! Settings::get( 'shelter_id' ) ) {
			$out['source'] = 'fixture';
		}

		return $out;
	}

	/**
	 * Defaults of the settings validated by type: the feature settings and on/off choices.
	 *
	 * @return array
	 */
	private static function typed() {
		$typed = Features::defaults();
		return $typed;
	}

	private static function validate_field( $key, $value ) {
		$defaults = self::typed();
		if ( is_bool( $defaults[ $key ] ?? null ) ) {
			return is_bool( $value ) ? $value : self::field_error( $key );
		}
		if ( is_int( $defaults[ $key ] ?? null ) ) {
			return is_int( $value ) && $value >= 0 && $value <= 3650 ? $value : self::field_error( $key );
		}
		if ( is_array( $defaults[ $key ] ?? null ) ) {
			$valid = is_array( $value ) && count( $value ) <= 12
				&& count( array_filter( $value, static fn ( $line ) => is_string( $line ) && strlen( $line ) <= 500 ) ) === count( $value );
			return $valid ? array_values( array_map( array( Settings::class, 'text' ), $value ) ) : self::field_error( $key );
		}
		if ( 'feature_donate_url' === $key ) {
			$valid = is_string( $value ) && ( '' === trim( $value ) || '' !== Features::sanitize_url( $value ) );
			return $valid ? Features::sanitize_url( $value ) : self::field_error( $key );
		}
		$valid = is_string( $value );
		if ( 'species' === $key ) {
			$valid = is_array( $value ) && $value && count( array_filter( $value, 'is_string' ) ) === count( $value )
				&& ! array_diff( $value, array( 'dog', 'cat', 'other' ) );
			$value = $valid ? array_values( array_unique( $value ) ) : array();
		} elseif ( in_array( $key, array( 'logo_id', 'shelter_id' ), true ) ) {
			if ( 'shelter_id' === $key && '' === $value ) {
				$value = 0;
			}
			$valid = ( is_int( $value ) || is_string( $value ) ) && ctype_digit( (string) $value ) && false !== filter_var(
				$value,
				FILTER_VALIDATE_INT,
				array( 'options' => array( 'min_range' => 0 ) )
			);
			$value = $valid ? (int) $value : 0;
			if ( $valid && 'logo_id' === $key && $value ) {
				$valid = wp_attachment_is_image( $value );
			}
		} elseif ( $valid ) {
			$value = trim( $value );
			$valid = strlen( $value ) <= 2000;
			if ( 'posthog_key' === $key ) {
				$valid = '' === $value || '' !== Analytics::sanitize_key( $value );
			} elseif ( 'posthog_region' === $key ) {
				$valid = isset( Analytics::regions()[ $value ] );
			} elseif ( 'org_name' === $key ) {
				$valid = $valid && '' !== Settings::text( $value ) && strlen( $value ) <= 200;
			} elseif ( in_array( $key, array( 'contact_email', 'donate_etransfer_email' ), true ) ) {
				$valid = '' === $value || (bool) is_email( $value );
			} elseif ( 'country' === $key ) {
				$value = strtoupper( $value );
				$valid = '' === $value || (bool) preg_match( '/^[A-Z]{2}$/D', $value );
			} elseif ( 'currency' === $key ) {
				$value = strtoupper( $value );
				$valid = '' === $value || (bool) preg_match( '/^[A-Z]{3}$/D', $value );
			} elseif ( 'source' === $key ) {
				$valid = in_array( $value, array( 'fixture', 'petstablished', 'demo' ), true );
			} elseif ( 'donate_paypal_url' === $key ) {
				$valid = '' === $value || '' !== self::paypal_url( $value );
			} elseif ( 'donate_card_url' === $key ) {
				$valid = '' === $value || ( '' !== Settings::https( $value ) && (bool) wp_parse_url( $value, PHP_URL_HOST )
					&& ! wp_parse_url( $value, PHP_URL_USER ) );
			} elseif ( str_starts_with( $key, 'color_' ) ) {
				$valid = (bool) preg_match( '/^#[a-f0-9]{6}$/iD', $value );
			} elseif ( str_starts_with( $key, 'font_' ) ) {
				$valid = Fonts::is_valid( $value );
			}
			$value = Settings::text( $value );
		}
		return $valid ? $value : self::field_error( $key );
	}

	private static function field_error( $key ) {
		$messages = array(
			'posthog_key'            => Analytics::key_error(),
			'posthog_region'         => __( 'Choose United States or European Union for your PostHog region. Settings files use us or eu.', 'homeward-rescue' ),
			'org_name'               => __( 'Enter your rescue name using up to 200 characters.', 'homeward-rescue' ),
			'logo_id'                => __( 'Choose an image from your media library for the logo.', 'homeward-rescue' ),
			'contact_email'          => __( 'Enter a complete contact email address, or leave it blank.', 'homeward-rescue' ),
			'currency'               => __( 'Enter a three-letter currency code, such as CAD or USD, or leave it blank to use your country.', 'homeward-rescue' ),
			'country' => __( 'Choose your country. Settings files use a two-letter country code.', 'homeward-rescue' ),
			'species'                => __( 'Choose dogs, cats or other animals.', 'homeward-rescue' ),
			'source'                 => __( 'Choose Petstablished or sample animals.', 'homeward-rescue' ),
			'shelter_id'             => __( 'Enter a whole shelter number. Use 0 for sample animals.', 'homeward-rescue' ),
			'donate_paypal_url'      => __( 'Use a secure https://paypal.me or paypal.com donation link, or leave it blank.', 'homeward-rescue' ),
			'donate_card_url' => __( 'Use a complete https:// card donation link, or leave it blank.', 'homeward-rescue' ),
			'donate_etransfer_email' => __( 'Enter a complete e-Transfer email address, or leave it blank.', 'homeward-rescue' ),
			'donate_etransfer_note'  => __( 'Keep the e-Transfer instructions under 2,000 characters.', 'homeward-rescue' ),
			'feature_donate_url'     => __( 'Use a complete https:// donate link or a path on this site such as /donate/, or leave it blank.', 'homeward-rescue' ),
		);
		$default = self::typed()[ $key ] ?? null;
		if ( is_bool( $default ) ) {
			return new \WP_Error( 'homeward_config_value', __( 'Use true or false for on/off choices.', 'homeward-rescue' ) );
		} elseif ( is_int( $default ) ) {
			return new \WP_Error( 'homeward_config_value', __( 'Use a whole number of days from 0 to 3650.', 'homeward-rescue' ) );
		} elseif ( is_array( $default ) ) {
			return new \WP_Error( 'homeward_config_value', __( 'Use a list of up to 12 short lines of text.', 'homeward-rescue' ) );
		} elseif ( null !== $default && 'feature_donate_url' !== $key ) {
			return new \WP_Error( 'homeward_config_value', __( 'Keep feature wording under 2,000 characters.', 'homeward-rescue' ) );
		}
		$message = $messages[ $key ] ?? ( str_starts_with( $key, 'font_' )
			? __( 'Choose one of the included fonts. The list is in config.example.json.', 'homeward-rescue' ) : __( 'Choose a colour. Settings files use a # followed by six colour digits.', 'homeward-rescue' ) );
		return new \WP_Error( 'homeward_config_value', $message );
	}

	/**
	 * Persist validated setup values and apply the selected logo.
	 *
	 * @param mixed $input                 Submitted configuration.
	 * @param bool  $confirm_source_change Whether source replacement was confirmed.
	 * @return array|\WP_Error
	 */
	public static function save_settings( $input, $confirm_source_change = false ) {
		$values = self::validate_settings( $input );
		if ( is_wp_error( $values ) ) {
			return $values;
		}
		$sanitized = Settings::sanitize(
			array_merge(
				$values,
				array( 'confirm_source_change' => $confirm_source_change ? '1' : '0' )
			)
		);
		foreach ( array( 'source', 'shelter_id' ) as $key ) {
			if ( isset( $values[ $key ] ) && $values[ $key ] !== $sanitized[ $key ] ) {
				Settings::release_source_lock();
				return new \WP_Error( 'homeward_source_change', __( 'Confirm the shelter change before saving. If a sync is running, wait for it to finish and try again.', 'homeward-rescue' ) );
			}
		}
		try {
			update_option( 'homeward_settings', $sanitized );
			$stored = (array) get_option( 'homeward_settings', array() );
			foreach ( $values as $key => $value ) {
				if ( ! array_key_exists( $key, $stored ) || $stored[ $key ] !== $sanitized[ $key ] ) {
					return new \WP_Error( 'homeward_config_save', __( 'Your settings could not be saved. Please try again.', 'homeward-rescue' ) );
				}
			}
		} finally {
			Settings::release_source_lock();
		}
		if ( array_key_exists( 'org_name', $values ) ) {
			update_option( 'blogname', $sanitized['org_name'] );
		}
		if ( array_key_exists( 'logo_id', $values ) ) {
			self::apply_logo( $values['logo_id'] );
		}
		return $values;
	}

	/**
	 * Apply the selected logo to the active theme.
	 *
	 * @param int $id Logo attachment ID.
	 * @return void
	 */
	public static function apply_logo( $id ) {
		update_option( 'site_logo', (int) $id );
		set_theme_mod( 'custom_logo', (int) $id );
	}

	/**
	 * Decode and validate a versioned configuration document.
	 *
	 * @param string $json Configuration document.
	 * @return array|\WP_Error
	 */
	public static function decode( $json ) {
		if ( ! is_string( $json ) || strlen( $json ) > self::MAX_BYTES ) {
			return new \WP_Error( 'homeward_config_size', __( 'Choose a settings file smaller than 64 KB.', 'homeward-rescue' ) );
		}
		$object = json_decode( $json );
		if ( ! $object instanceof \stdClass || ! isset( $object->settings ) || ! $object->settings instanceof \stdClass
			|| ( property_exists( $object, 'finish' ) && ! $object->finish instanceof \stdClass ) ) {
			return new \WP_Error( 'homeward_config_shape', __( 'Use the JSON format in config.example.json, with version, settings and optional finish fields.', 'homeward-rescue' ) );
		}
		$config = (array) $object;
		$known = self::unknown( $config, array( 'version', 'settings', 'finish' ), 'file' );
		if ( is_wp_error( $known ) ) {
			return $known;
		}
		if ( 1 !== ( $config['version'] ?? null ) ) {
			return new \WP_Error( 'homeward_config_version', __( 'This settings file must use version 1.', 'homeward-rescue' ) );
		}
		$values = self::validate_settings( (array) $object->settings );
		if ( is_wp_error( $values ) ) {
			return $values;
		}
		$finish = (array) ( $object->finish ?? new \stdClass() );
		$known = self::unknown( $finish, array( 'activate_theme', 'import_demo' ), 'finish' );
		if ( is_wp_error( $known ) ) {
			return $known;
		}
		foreach ( $finish as $value ) {
			if ( ! is_bool( $value ) ) {
				return new \WP_Error( 'homeward_config_finish', __( 'Use true or false for the finish choices.', 'homeward-rescue' ) );
			}
		}
		return array( 'settings' => $values, 'finish' => $finish );
	}

	/**
	 * Import a versioned configuration document.
	 *
	 * @param string $json                  Configuration document.
	 * @param bool   $confirm_source_change Whether source replacement was confirmed.
	 * @return array|\WP_Error
	 */
	public static function import_json( $json, $confirm_source_change = false ) {
		$config = self::decode( $json );
		if ( is_wp_error( $config ) ) {
			return $config;
		}
		$saved = self::save_settings( $config['settings'], $confirm_source_change );
		if ( is_wp_error( $saved ) ) {
			return $saved;
		}
		$state = Wizard::state();
		$state['finish'] = array_merge( $state['finish'], $config['finish'] );
		// Imported choices are reviewed before publishing pages or switching themes.
		$state['step'] = 1;
		$state['completed_at'] = 0;
		foreach ( range( 1, 5 ) as $step ) {
			$fields = array_intersect_key( $saved, array_flip( Wizard::STEPS[ $step ] ) );
			if ( $fields ) {
				$state['steps'][ $step ] = array_merge( $state['steps'][ $step ] ?? array(), $fields );
			}
		}
		update_option( 'homeward_wizard', $state, false );
		return $config;
	}

	/**
	 * Read and import a bounded configuration file.
	 *
	 * @param string $file                  Configuration file path.
	 * @param bool   $confirm_source_change Whether source replacement was confirmed.
	 * @return array|\WP_Error
	 */
	public static function import_file( $file, $confirm_source_change = false ) {
		if ( ! is_string( $file ) || str_contains( $file, '://' ) || ! is_file( $file ) || ! is_readable( $file )
			|| filesize( $file ) > self::MAX_BYTES ) {
			return new \WP_Error( 'homeward_config_file', __( 'Choose a readable local JSON file smaller than 64 KB.', 'homeward-rescue' ) );
		}
		return self::import_json( file_get_contents( $file ), $confirm_source_change );
	}

	/**
	 * Handle configuration imports from WP-CLI.
	 *
	 * @param array $args       Positional command arguments.
	 * @param array $assoc_args Named command arguments.
	 * @return void
	 */
	public static function cli( $args, $assoc_args ) {
		$result = self::import_file( $args[0] ?? '', isset( $assoc_args['confirm-source-change'] ) );
		if ( is_wp_error( $result ) ) {
			\WP_CLI::error( $result->get_error_message() );
			return;
		}
		\WP_CLI::success( __( 'Settings imported. Open Settings > Homeward Setup to review your choices and finish.', 'homeward-rescue' ) );
	}
}
