<?php
namespace Homeward;

defined( 'ABSPATH' ) || exit;

/**
 * Schedule one-shot jobs with Action Scheduler or WP-Cron.
 */
class Scheduler {
	const HOOKS = array(
		'homeward_sync_listings',
		'homeward_sync_details',
		'homeward_daily_refresh',
		'homeward_cleanup',
		'homeward_refresh_derived',
	);
	/**
	 * Return the earliest scheduled run or an active-action marker.
	 *
	 * @param string $hook Scheduled hook name.
	 * @return int|bool
	 */
	public static function next( $hook ) {
		$cron = wp_next_scheduled( $hook );
		$action = function_exists( 'as_next_scheduled_action' )
			? as_next_scheduled_action( $hook, array(), 'homeward' ) : false;
		return true === $action ? ( $cron ?: true ) : ( $action && $cron ? min( $action, $cron ) : ( $action ?: $cron ) );
	}

	/**
	 * Schedule a successor only when no pending run exists.
	 *
	 * @param string $hook Scheduled hook name.
	 * @param int    $when Unix timestamp.
	 * @return void
	 */
	public static function once( $hook, $when ) {
		$next = self::next( $hook );
		if ( $next && true !== $next ) {
			return;
		}
		// Action Scheduler reports true for an in-progress action with no pending successor.
		if ( function_exists( 'as_schedule_single_action' ) ) {
			as_schedule_single_action( $when, $hook, array(), 'homeward', true !== $next );
		} else {
			wp_schedule_single_event( $when, $hook );
		}
	}

	/**
	 * Ensure recurring sync and maintenance work has a next run.
	 *
	 * @return void
	 */
	public static function ensure() {
		$minute = get_option( 'homeward_schedule_minute', false );
		if ( false === $minute ) {
			$minute = random_int( 0, 59 );
			add_option( 'homeward_schedule_minute', $minute, '', false );
		}
		$hour = get_option( 'homeward_schedule_hour', false );
		if ( false === $hour ) {
			$hour = random_int( 0, 23 );
			add_option( 'homeward_schedule_hour', $hour, '', false );
		}
		$next = (int) ( floor( time() / 3600 ) * 3600 + $minute * 60 );
		self::once( 'homeward_sync_listings', $next > time() ? $next : $next + 3600 );
		$daily = (int) ( floor( time() / 86400 ) * 86400 + $hour * 3600 + $minute * 60 );
		$daily = $daily > time() ? $daily : $daily + 86400;
		self::once( 'homeward_daily_refresh', $daily );
		self::once( 'homeward_cleanup', $daily + 1800 );
		if ( get_option( 'homeward_derived_queue', array() ) ) {
			self::once( 'homeward_refresh_derived', time() + 10 );
		}
		if ( Sync::queue() ) {
			self::once( 'homeward_sync_details', max( time() + 90, Sync::paused_until() ) );
		}
	}

	/**
	 * Remove all kit jobs from both scheduling backends.
	 *
	 * @return void
	 */
	public static function clear() {
		foreach ( self::HOOKS as $hook ) {
			wp_clear_scheduled_hook( $hook );
			if ( function_exists( 'as_unschedule_all_actions' ) ) {
				as_unschedule_all_actions( $hook, array(), 'homeward' );
			}
		}
	}
}
