<?php
/**
 * Optional profile features, each with an on/off switch in Settings.
 *
 * A feature renders on the server when its switch is on, so visitors never wait for a network call.
 * Logged-in editors can preview features with ?homeward_preview=all or ?homeward_preview=key,key.
 *
 * @package Homeward
 */
namespace Homeward;

defined( 'ABSPATH' ) || exit;

/**
 * Render optional profile features and decide how each one is shown.
 */
class Features {
	const KEYS = array(
		'donate-card',
		'adopted-donate',
		'fee-explainer',
		'household-share',
		'adoption-path',
		'waiting-counter',
	);

	/**
	 * Settings that hold one line per entry.
	 */
	const LINE_FIELDS = array(
		'feature_fee_explainer_items',
		'feature_adoption_path_steps',
	);

	/**
	 * Counter for heading ids when a page renders several profiles.
	 *
	 * @var int
	 */
	private static $uid = 0;

	private static function uid( $prefix ) {
		return $prefix . '-' . ( ++self::$uid );
	}

	/**
	 * Return the default settings for every feature.
	 *
	 * @return array
	 */
	public static function defaults() {
		return array(
			'feature_donate_card'              => true,
			'feature_donate_card_heading'      => __( 'Can’t adopt {name} right now? You can still help.', 'homeward-rescue' ),
			'feature_donate_card_text'         => __( 'Donations pay for food, vet care and foster supplies while {name} waits for a home.', 'homeward-rescue' ),
			'feature_donate_card_button'       => __( 'Donate', 'homeward-rescue' ),
			'feature_adopted_donate'           => true,
			'feature_adopted_donate_heading'   => __( '{name} found a home. The next one is still waiting.', 'homeward-rescue' ),
			'feature_adopted_donate_text'      => __( 'Every adoption starts with care that donations pay for. A gift today helps the next {animal} get ready for a home.', 'homeward-rescue' ),
			'feature_adopted_donate_button'    => __( 'Help the next one', 'homeward-rescue' ),
			'feature_adopted_donate_secondary' => __( 'Meet the {animals} waiting', 'homeward-rescue' ),
			'feature_donate_url'               => '',
			'feature_fee_explainer'            => false,
			'feature_fee_explainer_button'     => __( 'What’s included?', 'homeward-rescue' ),
			'feature_fee_explainer_items'      => array(
				__( 'Vet check', 'homeward-rescue' ),
				__( 'Vaccinations', 'homeward-rescue' ),
				__( 'Spay or neuter', 'homeward-rescue' ),
				__( 'Microchip', 'homeward-rescue' ),
			),
			'feature_fee_explainer_closing'    => __( 'The fee covers part of the cost of care. Donations make up the rest.', 'homeward-rescue' ),
			'feature_household_share'          => false,
			'feature_household_share_text'     => __( 'Deciding with someone?', 'homeward-rescue' ),
			'feature_household_share_button'   => __( 'Share {name}’s page', 'homeward-rescue' ),
			'feature_adoption_path'            => false,
			'feature_adoption_path_heading'    => __( 'How adopting {name} works', 'homeward-rescue' ),
			'feature_adoption_path_steps'      => array(
				__( 'Get to know {name} | Read the profile and ask us anything.', 'homeward-rescue' ),
				__( 'Apply | Send in the adoption application.', 'homeward-rescue' ),
				__( 'Review | We read your application and get in touch.', 'homeward-rescue' ),
				__( 'Meet | Meet {name} and ask your last questions.', 'homeward-rescue' ),
				__( 'Home | {name} comes home with you.', 'homeward-rescue' ),
			),
			'feature_waiting_counter'          => false,
			'feature_waiting_counter_text'     => __( 'Waiting for a home: {days} days', 'homeward-rescue' ),
			'feature_waiting_counter_min_days' => 14,
			'ask_hint'                         => __( 'What would you like to know about {name}?', 'homeward-rescue' ),
		);
	}

	/**
	 * Return the setting name of a feature's switch.
	 *
	 * @param string $key Feature key.
	 * @return string
	 */
	public static function option( $key ) {
		return 'feature_' . str_replace( '-', '_', $key );
	}

	/**
	 * Sanitize the feature settings that the generic scalar rules do not cover.
	 *
	 * @param array $input Submitted settings.
	 * @param array $out   Settings being saved.
	 * @return void
	 */
	public static function sanitize( $input, &$out ) {
		foreach ( self::LINE_FIELDS as $key ) {
			if ( array_key_exists( $key, $input ) ) {
				$lines = is_array( $input[ $key ] ) ? $input[ $key ] : preg_split( '/\R/', (string) ( is_scalar( $input[ $key ] ) ? $input[ $key ] : '' ) );
				$lines = array_map( array( Settings::class, 'text' ), array_filter( $lines, 'is_scalar' ) );
				$out[ $key ] = array_slice( array_values( array_filter( $lines, 'strlen' ) ), 0, 12 );
			}
		}
		$out['feature_donate_url'] = self::sanitize_url( $out['feature_donate_url'] ?? '' );
		$out['feature_waiting_counter_min_days'] = min( 3650, max( 0, (int) ( $out['feature_waiting_counter_min_days'] ?? 14 ) ) );
	}

	/**
	 * Accept a secure link or a path on this site, such as /donate/.
	 *
	 * @param mixed $value Submitted link.
	 * @return string
	 */
	public static function sanitize_url( $value ) {
		$value = Settings::text( $value );
		// A site path is resolved with home_url() when rendered, so only plain path characters are kept.
		if ( preg_match( '#^/(?!/)[A-Za-z0-9/_.~%+=&?\#-]*$#D', $value ) ) {
			return $value;
		}
		return Settings::https( $value );
	}

	/**
	 * Read a feature text, falling back to the default when the setting is empty.
	 *
	 * @param string $key Setting name.
	 * @return string|array
	 */
	public static function text( $key ) {
		$value = Settings::get( $key );
		$default = self::defaults()[ $key ] ?? '';
		if ( is_array( $default ) ) {
			return is_array( $value ) && $value ? array_values( $value ) : $default;
		}
		$value = is_scalar( $value ) ? trim( (string) $value ) : '';
		return '' === $value ? $default : $value;
	}

	/**
	 * Replace {name}, {animal} and {animals} in visitor wording.
	 *
	 * @param string $text Wording.
	 * @param string $name Animal name.
	 * @return string
	 */
	public static function fill( $text, $name = '' ) {
		return strtr(
			(string) $text,
			array(
				'{name}'    => (string) $name,
				'{animal}'  => (string) Settings::get( 'noun_singular' ),
				'{animals}' => (string) Settings::get( 'noun_plural' ),
			)
		);
	}

	/**
	 * Return the donate link used by both donate features.
	 *
	 * @return string
	 */
	public static function donate_url() {
		$url = (string) Settings::get( 'feature_donate_url' );
		if ( '' === $url ) {
			return home_url( '/donate/' );
		}
		return str_starts_with( $url, '/' ) ? home_url( $url ) : $url;
	}

	/**
	 * Check whether a logged-in editor asked to preview a feature with ?homeward_preview=all or =key,key.
	 *
	 * @param string $key Feature key.
	 * @return bool
	 */
	public static function previewing( $key ) {
		$raw = isset( $_GET['homeward_preview'] ) && is_string( $_GET['homeward_preview'] )
			? sanitize_text_field( wp_unslash( $_GET['homeward_preview'] ) ) : '';
		if ( '' === $raw || ! current_user_can( 'edit_posts' ) ) {
			return false;
		}
		$keys = array_map( 'trim', explode( ',', $raw ) );
		return in_array( 'all', $keys, true ) || in_array( $key, $keys, true );
	}


	/**
	 * Decide how a feature renders: off or on.
	 *
	 * @param string $key Feature key.
	 * @return string
	 */
	public static function mode( $key ) {
		if ( self::previewing( $key ) ) {
			return 'on';
		}
		return Settings::get( self::option( $key ) ) ? 'on' : 'off';
	}

	/**
	 * Wrap feature markup with the attributes features.js reads.
	 *
	 * @param string $key  Feature key.
	 * @param string $html Escaped feature markup.
	 * @param string $tag  Wrapper element.
	 * @return string
	 */
	public static function wrap( $key, $html, $tag = 'div' ) {
		$mode = self::mode( $key );
		if ( 'off' === $mode || '' === $html ) {
			return '';
		}
		Frontend::script( 'features' );
		$attrs = ' class="homeward-feature homeward-feature--' . esc_attr( $key ) . '" data-homeward-feature="'
			. esc_attr( $key ) . '"';
		return '<' . $tag . $attrs . '>' . $html . '</' . $tag . '>';
	}

	/**
	 * Donate card for available animal profiles.
	 *
	 * @param array $animal Public animal view model.
	 * @return string
	 */
	public static function donate_card( $animal ) {
		if ( 'available' !== ( $animal['status'] ?? '' ) || '' === (string) ( $animal['name'] ?? '' ) ) {
			return '';
		}
		$name = (string) $animal['name'];
		$hid = self::uid( 'homeward-donate-card' );
		$html = '<section class="homeward-feature-card homeward-feature-donate" aria-labelledby="' . esc_attr( $hid ) . '">'
			. '<h2 id="' . esc_attr( $hid ) . '" class="homeward-feature-card__heading">'
			. esc_html( self::fill( self::text( 'feature_donate_card_heading' ), $name ) ) . '</h2>'
			. '<p>' . esc_html( self::fill( self::text( 'feature_donate_card_text' ), $name ) ) . '</p>'
			. '<p class="homeward-feature-card__actions"><a class="homeward-animal-btn homeward-animal-btn--primary" href="'
			. esc_url( self::donate_url() ) . '" data-homeward-donate-link="profile">'
			. esc_html( self::fill( self::text( 'feature_donate_card_button' ), $name ) ) . '</a></p></section>';
		return self::wrap( 'donate-card', $html );
	}

	/**
	 * Donate prompt for the profile of an animal who found a home.
	 *
	 * @param array $animal Public animal view model.
	 * @return string
	 */
	public static function adopted_donate( $animal ) {
		if ( 'gone' !== ( $animal['status'] ?? '' ) || '' === (string) ( $animal['name'] ?? '' ) ) {
			return '';
		}
		$name = (string) $animal['name'];
		$hid = self::uid( 'homeward-adopted-donate' );
		$html = '<section class="homeward-feature-card homeward-feature-donate" aria-labelledby="' . esc_attr( $hid ) . '">'
			. '<h2 id="' . esc_attr( $hid ) . '" class="homeward-feature-card__heading">'
			. esc_html( self::fill( self::text( 'feature_adopted_donate_heading' ), $name ) ) . '</h2>'
			. '<p>' . esc_html( self::fill( self::text( 'feature_adopted_donate_text' ), $name ) ) . '</p>'
			. '<p class="homeward-feature-card__actions"><a class="homeward-animal-btn homeward-animal-btn--primary" href="'
			. esc_url( self::donate_url() ) . '" data-homeward-donate-link="adopted">'
			. esc_html( self::fill( self::text( 'feature_adopted_donate_button' ), $name ) ) . '</a>'
			. '<a class="homeward-animal-btn homeward-animal-btn--secondary" href="' . esc_url( Render::archive_url() ) . '">'
			. esc_html( self::fill( self::text( 'feature_adopted_donate_secondary' ), $name ) ) . '</a></p></section>';
		return self::wrap( 'adopted-donate', $html );
	}

	/**
	 * "What's included?" disclosure under the adoption fee.
	 *
	 * @param array $animal Public animal view model.
	 * @return string
	 */
	public static function fee_explainer( $animal ) {
		$fee = $animal['fee'] ?? '';
		if ( ! is_numeric( $fee ) || (float) $fee <= 0 || 'gone' === ( $animal['status'] ?? '' ) ) {
			return '';
		}
		$items = '';
		foreach ( (array) self::text( 'feature_fee_explainer_items' ) as $item ) {
			$items .= '<li>' . Render::icon( 'check' ) . '<span>' . esc_html( self::fill( $item, $animal['name'] ?? '' ) ) . '</span></li>';
		}
		$closing = trim( (string) Settings::get( 'feature_fee_explainer_closing' ) );
		$html = '<details class="homeward-fee-explainer" data-homeward-fee-explainer><summary class="homeward-fee-explainer__toggle">'
			. Render::icon( 'info' ) . '<span>' . esc_html( self::text( 'feature_fee_explainer_button' ) ) . '</span></summary>'
			. '<div class="homeward-fee-explainer__panel"><ul class="homeward-fee-explainer__list" role="list">' . $items . '</ul>'
			. ( '' !== $closing ? '<p class="homeward-fee-explainer__closing">' . esc_html( self::fill( $closing, $animal['name'] ?? '' ) ) . '</p>' : '' )
			. '</div></details>';
		return self::wrap( 'fee-explainer', $html );
	}

	/**
	 * Card that opens the page's Share box for someone deciding with their household.
	 *
	 * @param array $animal Public animal view model.
	 * @return string
	 */
	public static function household_share( $animal ) {
		if ( 'gone' === ( $animal['status'] ?? '' ) || '' === (string) ( $animal['name'] ?? '' ) ) {
			return '';
		}
		$name = (string) $animal['name'];
		// Hidden until features.js finds the page's Share button, which needs JavaScript too.
		$html = '<div class="homeward-household-share" data-homeward-household-share hidden>'
			. '<p class="homeward-household-share__text">' . esc_html( self::fill( self::text( 'feature_household_share_text' ), $name ) ) . '</p>'
			. '<button type="button" class="homeward-animal-btn homeward-animal-btn--secondary" data-homeward-household-open>'
			. Render::icon( 'share' ) . '<span>' . esc_html( self::fill( self::text( 'feature_household_share_button' ), $name ) )
			. '</span></button></div>';
		return self::wrap( 'household-share', $html );
	}

	/**
	 * Split the adoption path settings into title and line pairs.
	 *
	 * @return array[]
	 */
	public static function steps() {
		$steps = array();
		foreach ( (array) self::text( 'feature_adoption_path_steps' ) as $row ) {
			$parts = array_map( 'trim', explode( '|', (string) $row, 2 ) );
			if ( '' !== $parts[0] ) {
				$steps[] = array( $parts[0], $parts[1] ?? '' );
			}
		}
		return $steps;
	}

	/**
	 * Numbered steps from first look to home.
	 *
	 * @param array $animal    Public animal view model.
	 * @param bool  $can_apply Whether the profile takes applications.
	 * @return string
	 */
	public static function adoption_path( $animal, $can_apply ) {
		$steps = self::steps();
		if ( ! $can_apply || ! $steps ) {
			return '';
		}
		$name = (string) $animal['name'];
		$hid = self::uid( 'homeward-adoption-path' );
		$html = '<section class="homeward-feature-card homeward-adoption-path" aria-labelledby="' . esc_attr( $hid ) . '">'
			. '<h2 id="' . esc_attr( $hid ) . '" class="homeward-feature-card__heading">'
			. esc_html( self::fill( self::text( 'feature_adoption_path_heading' ), $name ) ) . '</h2>'
			. '<ol class="homeward-adoption-path__steps" data-homeward-adoption-path>';
		foreach ( $steps as $i => $step ) {
			$html .= '<li class="homeward-adoption-path__step' . ( 0 === $i ? ' is-current' : '' ) . '"'
				. ( 0 === $i ? ' aria-current="step"' : '' ) . '><span class="homeward-adoption-path__number" aria-hidden="true">'
				. ( $i + 1 ) . '</span><span class="homeward-adoption-path__text"><strong>' . esc_html( self::fill( $step[0], $name ) )
				. '</strong>' . ( '' !== $step[1] ? '<span>' . esc_html( self::fill( $step[1], $name ) ) . '</span>' : '' )
				. '</span></li>';
		}
		return self::wrap( 'adoption-path', $html . '</ol></section>' );
	}

	/**
	 * Whole UTC calendar days between a wait start and now.
	 *
	 * @param int      $since Wait start timestamp.
	 * @param int|null $now   Current time override.
	 * @return int
	 */
	public static function days_waiting( $since, $now = null ) {
		$now = null === $now ? time() : (int) $now;
		$today = gmmktime( 0, 0, 0, (int) gmdate( 'n', $now ), (int) gmdate( 'j', $now ), (int) gmdate( 'Y', $now ) );
		$start = gmmktime( 0, 0, 0, (int) gmdate( 'n', $since ), (int) gmdate( 'j', $since ), (int) gmdate( 'Y', $since ) );
		return (int) floor( ( $today - $start ) / DAY_IN_SECONDS );
	}

	/**
	 * Fill the counter wording, writing "1 day" rather than "1 days".
	 *
	 * @param string $template Wording with {days}.
	 * @param int    $days     Days waiting.
	 * @return string
	 */
	public static function days_label( $template, $days ) {
		if ( 1 === $days ) {
			$template = preg_replace( '/\{days\}(\s+\S+?)s(?=$|[\s,.;:!?])/u', '{days}$1', $template );
		}
		return str_replace( '{days}', number_format_i18n( $days ), $template );
	}

	/**
	 * "Waiting for a home: N days" for available animals who have waited at least the minimum.
	 *
	 * @param array    $animal Public animal view model.
	 * @param int|null $now    Current time override.
	 * @return string
	 */
	public static function waiting_counter( $animal, $now = null ) {
		$since = (int) ( $animal['waiting_since'] ?? 0 );
		if ( 'available' !== ( $animal['status'] ?? '' ) || empty( $animal['listed'] ) || ! Animal::plausible_wait( $since ) ) {
			return '';
		}
		$days = self::days_waiting( $since, $now );
		if ( $days < (int) Settings::get( 'feature_waiting_counter_min_days' ) ) {
			return '';
		}
		$template = self::fill( self::text( 'feature_waiting_counter_text' ), $animal['name'] ?? '' );
		$html = '<span class="homeward-waiting-counter" data-homeward-waiting-counter data-since="'
			. esc_attr( gmdate( 'Y-m-d', $since ) ) . '" data-days="' . esc_attr( (string) $days ) . '" data-template="'
			. esc_attr( $template ) . '">' . esc_html( self::days_label( $template, $days ) ) . '</span>';
		return self::wrap( 'waiting-counter', $html, 'p' );
	}

	/**
	 * Placeholder for the message box when a visitor asks about one animal.
	 *
	 * @param string $name Animal name.
	 * @return string Empty when the rescue cleared the hint.
	 */
	public static function ask_hint( $name ) {
		$hint = trim( (string) Settings::get( 'ask_hint' ) );
		return '' === $hint ? '' : self::fill( $hint, $name );
	}
}
