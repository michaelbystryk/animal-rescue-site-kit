<?php
namespace Homeward;

defined( 'ABSPATH' ) || exit;

/**
 * Render the configured donation methods in their chosen order.
 */
class Donations {
	/**
	 * Render available donation methods for a block.
	 *
	 * @param array $attrs Donation block attributes.
	 * @return string
	 */
	public static function render( $attrs = array() ) {
		$methods = array_intersect(
			Settings::get( 'donate_order' ),
			$attrs['methods'] ?? array( 'card', 'paypal', 'etransfer' )
		);
		$out = '';
		foreach ( $methods as $method ) {
			if ( 'card' === $method && Settings::get( 'donate_card_url' ) ) {
				$out .= self::link( Settings::get( 'donate_card_url' ), Settings::get( 'donate_card_label' ) );
			}
			if ( 'paypal' === $method ) {
				if ( Settings::get( 'donate_paypal_url' ) ) {
					$out .= self::link( Settings::get( 'donate_paypal_url' ), __( 'Donate with PayPal', 'homeward-rescue' ) );
				}
			}
			if ( 'etransfer' === $method && 'CA' === Settings::get( 'country' ) && Settings::get( 'donate_etransfer_email' ) ) {
				$email = Settings::get( 'donate_etransfer_email' );
				$out .= '<div class="homeward-donate-method"><h3>' . esc_html__( 'e-Transfer', 'homeward-rescue' )
					. '</h3><p><a href="' . esc_url( 'mailto:' . antispambot( $email ) ) . '">' . esc_html( $email )
					. '</a></p><button type="button" class="homeward-animal-btn homeward-js-only" data-homeward-copy="'
					. esc_attr( $email ) . '">' . esc_html__( 'Copy email', 'homeward-rescue' ) . '</button><p role="status"></p><p>'
					. esc_html( Settings::get( 'donate_etransfer_note' ) ) . '</p></div>';
			}
		}
		return $out ? '<section class="homeward-donate"><h2>' . esc_html(
			! empty( $attrs['heading'] ) ? $attrs['heading'] : __( 'Help them find a home', 'homeward-rescue' )
		) . '</h2>' . $out . '</section>' : '';
	}

	private static function link( $url, $label ) {
		return '<p><a class="homeward-animal-btn homeward-animal-btn--primary" href="' . esc_url( $url ) . '">'
			. esc_html( $label ) . '</a></p>';
	}
}
