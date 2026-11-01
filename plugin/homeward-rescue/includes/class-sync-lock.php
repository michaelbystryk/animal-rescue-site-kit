<?php
namespace Homeward;

defined( 'ABSPATH' ) || exit;

/**
 * Coordinate sync and settings writes with a database lease.
 */
class Sync_Lock {
	const OPTION = 'homeward_sync_lock';

	/**
	 * Acquire a lease after removing an expired one.
	 *
	 * @return array|false
	 */
	public static function acquire() {
		global $wpdb;
		$old = get_option( self::OPTION );
		if ( is_array( $old ) && (int) ( $old['expires'] ?? 0 ) < time() ) {
			self::release( $old );
		}
		$lock = array( 'token' => wp_generate_uuid4(), 'expires' => time() + 1800 );
		$inserted = $wpdb->query(
			$wpdb->prepare(
				"INSERT IGNORE INTO {$wpdb->options} (option_name, option_value, autoload) VALUES (%s, %s, 'no')",
				self::OPTION,
				maybe_serialize( $lock )
			)
		);
		wp_cache_delete( self::OPTION, 'options' );
		wp_cache_delete( 'notoptions', 'options' );
		return 1 === $inserted ? $lock : false;
	}

	/**
	 * Check lease ownership directly in the database.
	 *
	 * @param array $lock Lease token and expiry.
	 * @return bool
	 */
	public static function owned( $lock ) {
		global $wpdb;
		// Read the database, not a potentially stale persistent options cache.
		$value = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT option_value FROM {$wpdb->options} WHERE option_name = %s",
				self::OPTION
			)
		);
		return $value === maybe_serialize( $lock ) && (int) $lock['expires'] > time();
	}

	/**
	 * Release only the matching lease.
	 *
	 * @param array $lock Lease token and expiry.
	 * @return void
	 */
	public static function release( $lock ) {
		global $wpdb;
		$wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$wpdb->options} WHERE option_name = %s AND option_value = %s",
				self::OPTION,
				maybe_serialize( $lock )
			)
		);
		wp_cache_delete( self::OPTION, 'options' );
	}
}
