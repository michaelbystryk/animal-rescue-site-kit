<?php
namespace Homeward;

defined( 'ABSPATH' ) || exit;

/**
 * Resolve adoption, foster and volunteer application links.
 */
class Applications {
	/**
	 * Return an application URL for the requested animal or form.
	 *
	 * @param string $type Application type.
	 * @param int    $id   Animal post ID, or zero for a general form.
	 * @param array  $data Optional normalized animal data.
	 * @return string
	 */
	public static function url( $type = 'adopt', $id = 0, $data = array() ) {
		if ( ! in_array( $type, array( 'adopt', 'foster', 'volunteer' ), true ) ) {
			return '';
		}
		if ( $id && ! Animal::public_readable( $id ) ) {
			return '';
		}
		if (
			$id && 'adopt' === $type && in_array( get_post_meta( $id, Animal::M_STATUS, true ), array( 'gone', 'hold' ), true )
		) {
			return '';
		}
		if ( $url = Settings::get( $type . '_url' ) ) {
			return $url;
		}
		if ( 'volunteer' === $type ) {
			return '';
		}
		if ( 'adopt' === $type && 'per_animal' === Settings::get( 'apply_mode' ) && $id ) {
			$data = $data ?: Animal::data( $id );
			if ( ! empty( $data['adopt_url'] ) ) {
				return Pet_Data::petstablished_url( $data['adopt_url'] );
			}
			$pet = (int) get_post_meta( $id, Animal::M_PS_ID, true );
		} else {
			$pet = 'foster' === $type ? Settings::get( 'foster_pet_id' )
				: ( Settings::get( 'adopt_general_pet_ids' )[ 0 ]['pet_id'] ?? 0 );
		}
		return Source_Petstablished::application_url( $type, $pet );
	}

	/**
	 * Render an application link when a destination is available.
	 *
	 * @param string $type Application type.
	 * @param int    $id   Animal post ID, or zero for a general form.
	 * @return string
	 */
	public static function button( $type = 'adopt', $id = 0 ) {
		$url = self::url( $type, $id );
		if ( ! $url ) {
			return '';
		}
		$label = array(
			'adopt'     => __( 'Apply to adopt', 'homeward-rescue' ),
			'foster'    => __( 'Apply to foster', 'homeward-rescue' ),
			'volunteer' => __( 'Volunteer', 'homeward-rescue' ),
		)[ $type ];
		return '<a class="homeward-animal-btn homeward-animal-btn--primary" href="' . esc_url( $url ) . '">'
			. esc_html( $label ) . '</a>';
	}
}
