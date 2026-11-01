<?php
namespace Homeward;

defined( 'ABSPATH' ) || exit;

/**
 * Supply complete source listings and individual animal records.
 */
interface Source {
	/**
	 * Return the complete feed, or an error instead of a partial result.
	 *
	 * @return array|\WP_Error
	 */
	public function fetch_all();

	/**
	 * Return the source details for an animal.
	 *
	 * @param int $id Source animal ID.
	 * @return array|\WP_Error
	 */
	public function fetch_detail( $id );

	/**
	 * Return the connection label shown to administrators.
	 *
	 * @return string
	 */
	public function label();
}
