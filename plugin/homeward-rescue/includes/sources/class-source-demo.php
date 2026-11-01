<?php
namespace Homeward;

defined( 'ABSPATH' ) || exit;

/**
 * Read the offline feed for records still owned by the practice importer.
 */
class Source_Demo implements Source {
	/**
	 * Return the practice connection label.
	 *
	 * @return string
	 */
	public function label() {
		return __( 'Fictional practice animals (offline)', 'homeward-rescue' );
	}

	/**
	 * Return the complete feed of remaining practice animals.
	 *
	 * @return array|\WP_Error
	 */
	public function fetch_all() {
		$file = Demo_Content::directory() . '/sample.json';
		$manifest = is_readable( $file ) ? json_decode( file_get_contents( $file ), true ) : null;
		if ( ! is_array( $manifest['animals'] ?? null ) ) {
			return new \WP_Error( 'homeward_demo_feed', __( 'The practice animal sample is unreadable.', 'homeward-rescue' ) );
		}
		$state = (array) get_option( Demo_Content::OPTION, array() );
		$pets = Demo_Content::pets( $manifest['animals'], $state['started'] ?? time() );
		return array_values(
			array_filter(
				$pets,
				static function ( $pet ) use ( $state ) {
					$key = 'animal:' . $pet['id'];
					$id = $state['created'][ $key ] ?? 0;
					return $id && get_post( $id ) && get_post_meta( $id, Demo_Content::META, true ) === $key;
				}
			)
		);
	}

	/**
	 * Return a practice animal still present in the feed.
	 *
	 * @param int $id Source animal ID.
	 * @return array|\WP_Error
	 */
	public function fetch_detail( $id ) {
		$pets = $this->fetch_all();
		if ( is_wp_error( $pets ) ) {
			return $pets;
		}
		foreach ( $pets as $pet ) {
			if ( (int) $pet['id'] === (int) $id ) {
				return $pet;
			}
		}
		return new \WP_Error( 'homeward_demo_pet', __( 'This practice animal was removed.', 'homeward-rescue' ) );
	}
}
