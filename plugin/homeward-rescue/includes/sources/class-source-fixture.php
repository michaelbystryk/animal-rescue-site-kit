<?php
namespace Homeward;

defined( 'ABSPATH' ) || exit;

/**
 * Read bundled JSON fixtures without a network connection.
 */
class Source_Fixture implements Source {
	private $directory;
	/**
	 * Choose the fixture directory.
	 *
	 * @param string|null $directory Fixture directory override.
	 * @return void
	 */
	public function __construct( $directory = null ) {
		$this->directory = $directory ?: HOMEWARD_DIR . 'fixtures/petstablished';
	}

	/**
	 * Return the fixture connection label.
	 *
	 * @return string
	 */
	public function label() {
		return __( 'Sample animals (offline)', 'homeward-rescue' );
	}

	private function read( $file ) {
		$path = $this->directory . '/' . $file . '.json';
		$data = is_readable( $path ) ? json_decode( file_get_contents( $path ), true ) : null;
		return is_array( $data ) ? $data : new \WP_Error( 'homeward_fixture', 'Missing or malformed synthetic fixture.' );
	}

	/**
	 * Read and validate the complete fixture listing.
	 *
	 * @return array|\WP_Error
	 */
	public function fetch_all() {
		$pets = array();
		$total = 1;
		for ( $page = 1; $page <= $total; $page++ ) {
			$data = $this->read( 'listing-' . $page );
			if ( is_wp_error( $data ) ) {
				return $data;
			}
			$declared = $data['shelter_pets_total_page'] ?? null;
			if ( ! is_int( $declared ) || $declared < 0 || $declared > 40 || ( $page > 1 && $declared !== $total )
				|| ! isset( $data['shelter_pets'] ) || ! is_array( $data['shelter_pets'] ) || ( $declared > 1
					&& ! $data['shelter_pets'] ) ) {
				return new \WP_Error( 'homeward_fixture', 'Incomplete synthetic fixture feed.' );
			}
			if ( 0 === $declared && $data['shelter_pets'] ) {
				return new \WP_Error( 'homeward_fixture', 'Inconsistent synthetic fixture pagination.' );
			}
			$total = max( 1, $declared );
			foreach ( $data['shelter_pets'] as $pet ) {
				if ( ! Source_Petstablished::valid_pet( $pet ) || isset( $pets[ $pet['id'] ] ) ) {
					return new \WP_Error( 'homeward_fixture', 'Malformed synthetic fixture row.' );
				}
				$pets[ $pet['id'] ] = $pet;
			}
		}
		return array_values( $pets );
	}

	/**
	 * Read the detail fixture for a source animal.
	 *
	 * @param int $id Source animal ID.
	 * @return array|\WP_Error
	 */
	public function fetch_detail( $id ) {
		$data = $this->read( 'detail-' . absint( $id ) );
		if ( is_wp_error( $data ) ) {
			return $data;
		}
		return isset( $data['pet'] ) && Source_Petstablished::valid_pet( $data['pet'] )
			&& (int) $data['pet']['id'] === (int) $id
			? $data['pet'] : new \WP_Error( 'homeward_fixture', 'Malformed synthetic detail.' );
	}
}
