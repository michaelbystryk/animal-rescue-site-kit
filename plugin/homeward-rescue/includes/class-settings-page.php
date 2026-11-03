<?php
namespace Homeward;

defined( 'ABSPATH' ) || exit;

require_once __DIR__ . '/class-settings-fields.php';
require_once __DIR__ . '/class-scheduler.php';

/**
 * Render the rescue settings and handle administrator actions.
 */
class Settings_Page {
	/**
	 * Register the option with the WordPress Settings API.
	 *
	 * @return void
	 */
	public static function register() {
		register_setting(
			'homeward',
			'homeward_settings',
			array(
				'type'              => 'array',
				'show_in_rest'      => false,
				'sanitize_callback' => array( __CLASS__, 'sanitize_form' ),
			)
		);
	}

	/**
	 * Translate form controls into the stored settings structure.
	 *
	 * @param mixed $input Submitted fields.
	 * @return array
	 */
	public static function sanitize_form( $input ) {
		$input = is_array( $input ) ? $input : array();
		foreach ( Settings::defaults() as $key => $default ) {
			if ( ! is_array( $default ) || ! isset( $input[ $key ] ) || ! is_string( $input[ $key ] ) ) {
				continue;
			}
			$value = trim( $input[ $key ] );
			if ( '' === $value ) {
				$input[ $key ] = array();
			} elseif ( ( 'donate_order' === $key || in_array( $key, Features::LINE_FIELDS, true ) )
				&& ! in_array( $value[0], array( '[', '{' ), true ) ) {
				$input[ $key ] = self::lines( $value );
			} else {
				// Accept saved forms from before the structured controls were introduced.
				$decoded = json_decode( $value, true );
				if ( is_array( $decoded ) ) {
					$input[ $key ] = $decoded;
				} else {
					unset( $input[ $key ] );
					add_settings_error(
						'homeward_settings',
						$key,
						sprintf(
							/* translators: %s: setting name. */
							__( 'Invalid JSON for %s; the previous value was kept.', 'homeward-rescue' ),
							$key
						)
					);
				}
			}
		}
		if ( isset( $input['origin_rules'] ) && is_array( $input['origin_rules'] ) ) {
			foreach ( $input['origin_rules'] as &$rule ) {
				if ( is_array( $rule ) && is_string( $rule['patterns'] ?? null ) ) {
					$rule['patterns'] = self::lines( $rule['patterns'] );
				}
			}
			unset( $rule );
		}
		if ( isset( $input['badge_labels_enabled'] ) ) {
			$enabled = array_filter( (array) $input['badge_labels_enabled'], 'is_string' );
			$labels = is_array( $input['badge_labels'] ?? null ) ? $input['badge_labels'] : array();
			$input['badge_labels'] = array_intersect_key( $labels, array_flip( $enabled ) );
		}
		return Settings::sanitize( $input );
	}

	private static function lines( $value ) {
		return array_values( array_filter( array_map( 'trim', preg_split( '/\R/', $value ) ), 'strlen' ) );
	}

	/**
	 * Return readable labels indexed by option name.
	 *
	 * @return array
	 */
	public static function labels() {
		return array_map( static fn ( $field ) => $field[0], Settings_Fields::all() );
	}

	/**
	 * Display settings, sync status and maintenance actions.
	 *
	 * @return void
	 */
	public static function screen() {
		self::authorize();
		echo '<div class="wrap"><h1>' . esc_html__( 'Homeward Rescue', 'homeward-rescue' ) . '</h1>';
		echo '<p><a href="' . esc_url( admin_url( 'options-general.php?page=homeward-setup' ) ) . '">'
			. esc_html__( 'Open the setup guide', 'homeward-rescue' ) . '</a></p>';
		settings_errors();
		self::sync_status();
		echo '<form action="options.php" method="post">';
		settings_fields( 'homeward' );
		echo '<p>'
			. esc_html__( 'Save your changes before updating animals. The setup guide can help with your first choices.', 'homeward-rescue' )
			. '</p>';
		$values = Settings::get();
		$values['currency'] = get_option( 'homeward_settings', array() )['currency'] ?? '';
		foreach ( Settings_Fields::sections() as $slug => $section ) {
			self::section( $slug, $section, $values );
		}
		submit_button();
		echo '</form>';
		self::practice_content();
		self::api_key_form();
		echo '</div>';
	}

	private static function section( $slug, $section, $values ) {
		list( $title, $step, $fields ) = $section;
		echo '<section aria-labelledby="homeward-section-' . esc_attr( $slug ) . '">';
		echo '<h2 id="homeward-section-' . esc_attr( $slug ) . '">' . esc_html( $title ) . '</h2>';
		if ( $step ) {
			echo '<p><a href="' . esc_url( admin_url( 'options-general.php?page=homeward-setup&step=' . $step ) ) . '">'
				. esc_html__( 'Review these choices in the setup guide', 'homeward-rescue' ) . '</a></p>';
		}
		if ( 'features' === $slug ) {
			self::features_intro();
		}
		echo '<table class="form-table" role="presentation"><tbody>';
		foreach ( $fields as $key => $field ) {
			$id = 'homeward-' . $key;
			$name = 'homeward_settings[' . $key . ']';
			$group = in_array( $field[2], array( 'checks', 'rows', 'badges' ), true ) || 'message_recipients' === $key;
			echo '<tr><th scope="row">';
			if ( $group ) {
				echo '<span id="' . esc_attr( $id ) . '-label">' . esc_html( $field[0] ) . '</span>';
			} else {
				echo '<label for="' . esc_attr( $id ) . '">' . esc_html( $field[0] ) . '</label>';
			}
			echo '</th><td>';
			self::control( $key, $values[ $key ], $name, $field[2] );
			echo '<p class="description" id="' . esc_attr( $id ) . '-help">' . esc_html( $field[1] ) . '</p>';
			echo '</td></tr>';
		}
		echo '</tbody></table>';
		if ( 'animals' === $slug ) {
			echo '<p><label><input type="checkbox" name="homeward_settings[confirm_source_change]" value="1"> '
				. esc_html__( 'Confirm changing the shelter or animal connection and hiding the previous animals.', 'homeward-rescue' )
				. '</label></p>';
		}
		echo '</section>';
	}

	private static function features_intro() {
		echo '<p>' . esc_html__( 'Each feature appears on animal profiles as soon as it is switched on. No statistics account is needed.', 'homeward-rescue' ) . '</p>';
		echo '<p>' . esc_html__( 'Logged-in editors can preview every feature on a profile by adding ?homeward_preview=all to its address.', 'homeward-rescue' ) . '</p>';
	}

	private static function control( $key, $value, $name, $type ) {
		if ( self::contact_field( $key, $value, $name ) ) {
			return;
		}
		switch ( $type ) {
			case 'checks':
				self::checkbox_list( $key, $value, $name );
				break;
			case 'select':
				self::select( $key, $value, $name, self::choices( $key ) );
				break;
			case 'font':
				self::select( $key, $value, $name, Fonts::grouped(), true );
				break;
			case 'rows':
				self::rows( $key, $value, $name );
				break;
			case 'badges':
				self::badge_fields( $value, $name );
				break;
			case 'checkbox':
				echo '<input type="hidden" name="' . esc_attr( $name ) . '" value="0">';
				echo '<input type="checkbox" id="homeward-' . esc_attr( $key ) . '" name="' . esc_attr( $name )
					. '" value="1" ' . checked( $value, true, false ) . ' aria-describedby="homeward-' . esc_attr( $key ) . '-help">';
				break;
			case 'lines':
			case 'textarea':
				self::textarea( $key, $name, is_array( $value ) ? implode( "\n", $value ) : $value );
				break;
			default:
				if ( 'posthog_host' === $key ) {
					$cloud = array( Analytics::region_host( 'us' ), Analytics::region_host( 'eu' ) );
					$value = in_array( $value, $cloud, true ) ? '' : $value;
				}
				self::input( 'homeward-' . $key, $name, $value, $type );
		}
	}

	private static function input( $id, $name, $value, $type = 'text', $help_id = '' ) {
		$attributes = '';
		if ( 'color' === $type ) {
			$type = 'text';
			$attributes = ' pattern="#[0-9a-fA-F]{3}([0-9a-fA-F]{3})?" placeholder="#123456"';
		}
		echo '<input class="regular-text" type="' . esc_attr( $type ) . '" id="' . esc_attr( $id )
			. '" name="' . esc_attr( $name ) . '" value="' . esc_attr( $value )
			. '" aria-describedby="' . esc_attr( $help_id ?: $id . '-help' ) . '"'
			. ( 'number' === $type ? ' min="0" step="1"' : '' ) . $attributes . '>';
	}

	private static function textarea( $key, $name, $value, $help_id = '' ) {
		$rows = 'redirects' === $key ? 5 : 4;
		echo '<textarea class="large-text" rows="' . esc_attr( $rows ) . '" id="homeward-' . esc_attr( $key )
			. '" name="' . esc_attr( $name ) . '" aria-describedby="'
			. esc_attr( $help_id ?: 'homeward-' . $key . '-help' ) . '">' . esc_textarea( $value ) . '</textarea>';
	}

	private static function select( $key, $value, $name, $choices, $grouped = false ) {
		echo '<select id="homeward-' . esc_attr( $key ) . '" name="' . esc_attr( $name )
			. '" aria-describedby="homeward-' . esc_attr( $key ) . '-help">';
		foreach ( $choices as $slug => $label ) {
			if ( $grouped ) {
				echo '<optgroup label="' . esc_attr( Fonts::category_label( $slug ) ) . '">';
				foreach ( $label as $font => $font_label ) {
					self::option( $font, $font_label, $value );
				}
				echo '</optgroup>';
			} else {
				self::option( $slug, $label, $value );
			}
		}
		echo '</select>';
	}

	private static function option( $key, $label, $value ) {
		echo '<option value="' . esc_attr( $key ) . '" ' . selected( $value, $key, false ) . '>'
			. esc_html( $label ) . '</option>';
	}

	private static function choices( $key ) {
		$choices = array(
			'source' => array(
				'petstablished' => __( 'Petstablished', 'homeward-rescue' ),
				'demo'          => __( 'Sample animals', 'homeward-rescue' ),
				'fixture'       => __( 'Local fixture', 'homeward-rescue' ),
			),
			'courtesy_mode' => array(
				'hide'    => __( 'Hide', 'homeward-rescue' ),
				'section' => __( 'Separate section', 'homeward-rescue' ),
				'show'    => __( 'Include in the animal list', 'homeward-rescue' ),
			),
			'apply_mode' => array(
				'per_animal' => __( 'Per animal', 'homeward-rescue' ),
				'general'    => __( 'General application', 'homeward-rescue' ),
			),
			'image_mode' => array(
				'hotlink'      => __( 'Load from the source', 'homeward-rescue' ),
				'import_cover' => __( 'Import cover images', 'homeward-rescue' ),
			),
			'posthog_region' => Analytics::regions(),
		);
		return $choices[ $key ] ?? array();
	}

	private static function checkbox_choices( $key ) {
		$choices = array(
			'species' => array(
				'dog'   => __( 'Dogs', 'homeward-rescue' ),
				'cat'   => __( 'Cats', 'homeward-rescue' ),
				'other' => __( 'Other animals', 'homeward-rescue' ),
			),
			'hidden_statuses' => array(
				'hold'      => __( 'On hold', 'homeward-rescue' ),
				'pending'   => __( 'Adoption pending', 'homeward-rescue' ),
				'available' => __( 'Available', 'homeward-rescue' ),
			),
			'public_fields' => array(
				'fee'                => __( 'Adoption fee', 'homeward-rescue' ),
				'weight'             => __( 'Weight', 'homeward-rescue' ),
				'age'                => __( 'Age', 'homeward-rescue' ),
				'is_spayed'          => __( 'Spayed or neutered', 'homeward-rescue' ),
				'shots_up_to_date'   => __( 'Vaccinations up to date', 'homeward-rescue' ),
				'compat'             => __( 'Compatibility', 'homeward-rescue' ),
				'housebroken'        => __( 'House training', 'homeward-rescue' ),
				'special_needs_text' => __( 'Special needs', 'homeward-rescue' ),
				'video'              => __( 'Video', 'homeward-rescue' ),
				'breed'              => __( 'Breed', 'homeward-rescue' ),
			),
			'share_networks' => array(
				'facebook' => __( 'Facebook', 'homeward-rescue' ),
				'x'        => __( 'X', 'homeward-rescue' ),
				'email'    => __( 'Email', 'homeward-rescue' ),
				'whatsapp' => __( 'WhatsApp', 'homeward-rescue' ),
			),
		);
		return 'llms_pages' === $key ? self::public_pages() : ( $choices[ $key ] ?? array() );
	}

	private static function public_pages() {
		$choices = array();
		$pages = get_posts(
			array(
				'post_type'      => 'page',
				'post_status'    => 'publish',
				'posts_per_page' => -1,
				'orderby'        => 'title',
				'order'          => 'ASC',
			)
		);
		foreach ( $pages as $page ) {
			if ( empty( $page->post_password ) ) {
				$choices[ $page->ID ] = $page->post_title;
			}
		}
		return $choices;
	}

	/**
	 * Render a labeled group of checkbox choices.
	 *
	 * @param string $key   Option key.
	 * @param array  $value Selected values.
	 * @param string $name  Form field name.
	 * @return void
	 */
	public static function checkbox_list( $key, $value, $name ) {
		echo '<fieldset id="homeward-' . esc_attr( $key ) . '" aria-describedby="homeward-'
			. esc_attr( $key ) . '-help"><legend class="screen-reader-text">' . esc_html( self::labels()[ $key ] ) . '</legend>';
		echo '<input type="hidden" name="' . esc_attr( $name ) . '" value="">';
		foreach ( self::checkbox_choices( $key ) as $id => $label ) {
			echo '<p><label><input type="checkbox" name="' . esc_attr( $name ) . '[]" value="'
				. esc_attr( $id ) . '" ' . checked( in_array( $id, (array) $value, true ), true, false ) . '> '
				. esc_html( $label ) . '</label></p>';
		}
		echo '</fieldset>';
	}

	private static function rows( $key, $value, $name ) {
		$columns = array(
			'social' => array(
				'service' => __( 'Service name', 'homeward-rescue' ),
				'url'     => __( 'Profile link', 'homeward-rescue' ),
			),
			'adopt_general_pet_ids' => array(
				'label'  => __( 'Form label', 'homeward-rescue' ),
				'pet_id' => __( 'Animal number', 'homeward-rescue' ),
			),
			'origin_rules' => array(
				'region'   => __( 'Region', 'homeward-rescue' ),
				'patterns' => __( 'Patterns, one per line', 'homeward-rescue' ),
			),
		);
		$rows = array_values( (array) $value );
		if ( count( $rows ) < 50 ) {
			$rows[] = array();
		}
		echo '<fieldset id="homeward-' . esc_attr( $key ) . '"><legend class="screen-reader-text">'
			. esc_html( self::labels()[ $key ] ) . '</legend>';
		foreach ( $rows as $index => $row ) {
			echo '<fieldset><legend>';
			/* translators: %d: row number. */
			echo esc_html( sprintf( __( 'Entry %d', 'homeward-rescue' ), $index + 1 ) );
			echo '</legend>';
			foreach ( $columns[ $key ] as $column => $label ) {
				$id = $key . '-' . $index . '-' . $column;
				$field_name = $name . '[' . $index . '][' . $column . ']';
				$help = 'homeward-' . $key . '-help';
				echo '<p><label for="homeward-' . esc_attr( $id ) . '">' . esc_html( $label ) . '</label><br>';
				if ( 'patterns' === $column ) {
					self::textarea( $id, $field_name, implode( "\n", $row[ $column ] ?? array() ), $help );
				} else {
					$type = 'url' === $column ? 'url' : ( 'pet_id' === $column ? 'number' : 'text' );
					self::input( 'homeward-' . $id, $field_name, $row[ $column ] ?? '', $type, $help );
				}
				echo '</p>';
			}
			echo '</fieldset>';
		}
		echo '<p class="description">'
			. esc_html__( 'Fill the empty entry and save to add another. Clear an entry to remove it.', 'homeward-rescue' )
			. '</p></fieldset>';
	}

	private static function badge_fields( $values, $name ) {
		$labels = array(
			'new'             => __( 'New', 'homeward-rescue' ),
			'longest-waiting' => __( 'Longest waiting', 'homeward-rescue' ),
			'special'         => __( 'Special needs', 'homeward-rescue' ),
			'bonded'          => __( 'Bonded pair', 'homeward-rescue' ),
			'pending'         => __( 'Adoption pending', 'homeward-rescue' ),
			'available'       => __( 'Available', 'homeward-rescue' ),
			'hold'            => __( 'On hold', 'homeward-rescue' ),
			'gone'            => __( 'No longer listed', 'homeward-rescue' ),
		);
		echo '<fieldset id="homeward-badge_labels"><legend class="screen-reader-text">'
			. esc_html__( 'Badge wording', 'homeward-rescue' ) . '</legend>';
		echo '<input type="hidden" name="homeward_settings[badge_labels_enabled]" value="">';
		foreach ( $labels as $key => $label ) {
			$id = 'homeward-badge-' . $key;
			echo '<p><label for="' . esc_attr( $id ) . '">' . esc_html( $label ) . '</label><br>';
			self::input( $id, $name . '[' . $key . ']', $values[ $key ] ?? '', 'text', 'homeward-badge_labels-help' );
			echo ' <label><input type="checkbox" name="homeward_settings[badge_labels_enabled][]" value="'
				. esc_attr( $key ) . '" ' . checked( array_key_exists( $key, $values ), true, false ) . '> '
				. esc_html__( 'Use custom wording', 'homeward-rescue' ) . '</label>';
			echo '</p>';
		}
		echo '</fieldset>';
	}

	/**
	 * Render contact controls without disclosing stored secrets.
	 *
	 * @param string $key   Option key.
	 * @param mixed  $value Current value.
	 * @param string $name  Form field name.
	 * @return bool Whether the control was rendered.
	 */
	public static function contact_field( $key, $value, $name ) {
		if ( 'message_recipients' === $key ) {
			echo '<fieldset id="homeward-message_recipients"><legend class="screen-reader-text">'
				. esc_html__( 'Topic inboxes', 'homeward-rescue' ) . '</legend>';
			foreach ( Messages::TOPICS as $topic ) {
				$id = 'homeward-recipient-' . $topic;
				echo '<p><label for="' . esc_attr( $id ) . '">' . esc_html( Message_Email::topic_label( $topic ) ) . '</label><br>';
				self::input(
					$id,
					$name . '[' . $topic . ']',
					$value[ $topic ] ?? '',
					'email',
					'homeward-message_recipients-help'
				);
				echo '</p>';
			}
			echo '</fieldset>';
		} elseif ( 'turnstile_site_key' === $key ) {
			echo '<input class="regular-text code" type="text" id="homeward-turnstile_site_key" name="'
				. esc_attr( $name ) . '" value="' . esc_attr( $value )
				. '" autocomplete="off" aria-describedby="homeward-turnstile_site_key-help">';
		} elseif ( 'turnstile_secret' === $key ) {
			echo '<input class="regular-text code" type="password" id="homeward-turnstile_secret" name="'
				. esc_attr( $name ) . '" value="" autocomplete="new-password"'
				. ' aria-describedby="homeward-turnstile_secret-help">';
			echo '<p><label><input type="checkbox" name="homeward_settings[clear_turnstile_secret]" value="1"> '
				. esc_html__( 'Remove the saved secret', 'homeward-rescue' ) . '</label></p>';
		} else {
			return false;
		}
		return true;
	}

	private static function sync_status() {
		$status = Sync::status();
		$next = Scheduler::next( 'homeward_sync_listings' );
		echo '<section aria-labelledby="homeward-sync-status"><h2 id="homeward-sync-status">'
			. esc_html__( 'Sync status', 'homeward-rescue' ) . '</h2><dl>';
		self::status_item( __( 'Last run', 'homeward-rescue' ), self::date_label( $status['time'] ?? 0 ) );
		self::status_item( __( 'Last successful run', 'homeward-rescue' ), self::date_label( $status['last_success'] ?? 0 ) );
		$next_label = true === $next ? __( 'Running now', 'homeward-rescue' ) : self::date_label( $next, true );
		self::status_item( __( 'Next run', 'homeward-rescue' ), $next_label );
		$labels = array(
			'received' => __( 'Animals received', 'homeward-rescue' ),
			'created'  => __( 'Added', 'homeward-rescue' ),
			'updated'  => __( 'Updated', 'homeward-rescue' ),
			'gone'     => __( 'No longer listed', 'homeward-rescue' ),
		);
		foreach ( $labels as $key => $label ) {
			if ( isset( $status['counts'][ $key ] ) ) {
				self::status_item( $label, number_format_i18n( (int) $status['counts'][ $key ] ) );
			}
		}
		echo '</dl><p role="status">'
			. esc_html( $status['message'] ?? __( 'No sync has run yet.', 'homeward-rescue' ) ) . '</p>';
		self::sync_problems( $status );
		self::action_form( 'homeward_sync_now', __( 'Sync now', 'homeward-rescue' ) );
		echo '</section>';
	}

	private static function status_item( $label, $value ) {
		echo '<dt><strong>' . esc_html( $label ) . '</strong></dt><dd>' . esc_html( $value ) . '</dd>';
	}

	private static function date_label( $time, $future = false ) {
		if ( ! $time ) {
			return $future ? __( 'Not scheduled', 'homeward-rescue' ) : __( 'Not yet run', 'homeward-rescue' );
		}
		$format = get_option( 'date_format', 'F j, Y' ) . ' ' . get_option( 'time_format', 'g:i a' );
		return wp_date( $format, (int) $time );
	}

	private static function sync_problems( $status ) {
		echo '<h3>' . esc_html__( 'Problems', 'homeward-rescue' ) . '</h3>';
		$paused = Sync::paused_until();
		if ( $paused > time() ) {
			/* translators: %s: localized date and time. */
			echo '<p>' . esc_html( sprintf( __( 'Sync paused until %s.', 'homeward-rescue' ), self::date_label( $paused ) ) )
				. '</p>';
		}
		if ( ! empty( $status['failures'] ) ) {
			/* translators: %d: number of consecutive failed runs. */
			echo '<p>' . esc_html( sprintf( __( 'Consecutive failed runs: %d.', 'homeward-rescue' ), $status['failures'] ) )
				. '</p>';
		}
		if ( get_option( 'homeward_sync_pending' ) && Sync::pending() ) {
			echo '<p>' . esc_html( Sync::pending_message() ) . '</p>';
			echo Sync::confirm_form();
		} elseif ( empty( $status['failures'] ) && $paused <= time() ) {
			echo '<p>' . esc_html__( 'No problems reported.', 'homeward-rescue' ) . '</p>';
		}
	}

	private static function practice_content() {
		$result = get_option( Demo_Content::RESULT );
		if ( is_array( $result ) ) {
			echo '<div class="notice notice-info"><p role="status">' . esc_html( $result['message'] ?? '' ) . '</p></div>';
		}
		if ( ! get_option( Demo_Content::OPTION ) ) {
			return;
		}
		echo '<h2>' . esc_html__( 'Practice content', 'homeward-rescue' ) . '</h2>';
		echo '<p>'
			. esc_html__( 'Trash unchanged sample animals and stories. Modified records and pictures used elsewhere are kept and listed after removal.', 'homeward-rescue' )
			. '</p>';
		self::action_form( 'homeward_remove_demo', __( 'Remove practice content', 'homeward-rescue' ) );
	}

	private static function action_form( $action, $label ) {
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		wp_nonce_field( $action );
		echo '<input type="hidden" name="action" value="' . esc_attr( $action ) . '">';
		submit_button( $label, 'secondary' );
		echo '</form>';
	}

	private static function api_key_form() {
		echo '<h2>' . esc_html__( 'Optional API key', 'homeward-rescue' ) . '</h2><p>'
			. esc_html__( 'Only add a key if Petstablished gave you one. Your saved key stays hidden. If your site manager has already set a key for the site, that key is used instead.', 'homeward-rescue' )
			. '</p><form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		wp_nonce_field( 'homeward_save_key' );
		echo '<input type="hidden" name="action" value="homeward_save_key"><label for="homeward-key">'
			. esc_html__( 'New key (blank keeps the saved key)', 'homeward-rescue' ) . '</label> '
			. '<input type="password" autocomplete="new-password" id="homeward-key" name="homeward_key" value="">'
			. '<p><label><input type="checkbox" name="remove_key" value="1">'
			. esc_html__( 'Remove saved key', 'homeward-rescue' ) . '</label></p>';
		submit_button( __( 'Save key', 'homeward-rescue' ), 'secondary' );
		echo '</form>';
	}

	private static function authorize() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Permission denied.', 'homeward-rescue' ) );
		}
	}

	/**
	 * Remove unchanged practice content after verifying the request.
	 *
	 * @return void
	 */
	public static function remove_demo() {
		self::authorize();
		check_admin_referer( 'homeward_remove_demo' );
		$result = Demo_Content::remove();
		if ( is_wp_error( $result ) ) {
			$result = array( 'ok' => false, 'message' => $result->get_error_message() );
		}
		update_option( Demo_Content::RESULT, $result, false );
		wp_safe_redirect( admin_url( 'options-general.php?page=homeward' ) );
		exit;
	}

	/**
	 * Run an administrator-requested sync.
	 *
	 * @return void
	 */
	public static function sync_now() {
		self::authorize();
		check_admin_referer( 'homeward_sync_now' );
		Sync::run_now();
		wp_safe_redirect( admin_url( 'options-general.php?page=homeward' ) );
		exit;
	}

	/**
	 * Replace or remove the separately stored API key.
	 *
	 * @return void
	 */
	public static function save_key() {
		self::authorize();
		check_admin_referer( 'homeward_save_key' );
		if ( ! empty( $_POST['remove_key'] ) ) {
			delete_option( 'homeward_api_key' );
		} elseif ( ! empty( $_POST['homeward_key'] ) ) {
			update_option( 'homeward_api_key', Settings::text( wp_unslash( $_POST['homeward_key'] ) ), false );
		}
		Settings::reset_source_errors();
		wp_safe_redirect( admin_url( 'options-general.php?page=homeward' ) );
		exit;
	}
}
