<?php
namespace Homeward;

defined( 'ABSPATH' ) || exit;

/**
 * Report sync health and conflicting public page addresses.
 */
class Site_Health {
	/**
	 * Register the rescue sync Site Health test.
	 *
	 * @param array $tests Existing Site Health tests.
	 * @return array
	 */
	public static function tests( $tests ) {
		$tests['direct']['homeward_sync'] = array(
			'label' => __( 'Homeward sync', 'homeward-rescue' ),
			'test'  => array( __CLASS__, 'result' ),
		);
		return $tests;
	}

	/**
	 * Build the Site Health result from sync and routing state.
	 *
	 * @return array
	 */
	public static function result() {
		$s = Sync::status();
		$next = Scheduler::next( 'homeward_sync_listings' );
		$healthy = ! Sync::pending() && ! empty( $s['last_success'] ) && $s['last_success'] > time() - 2 * DAY_IN_SECONDS
			&& Sync::paused_until() <= time() && $next && ( true === $next || $next > time() - 2 * HOUR_IN_SECONDS );
		$conflicts = array();
		foreach ( array( 'archive_slug', 'stories_slug', 'events_slug' ) as $key ) {
			if ( get_page_by_path( Settings::get( $key ) ) ) {
				$conflicts[] = Settings::get( $key );
			}
		}
		return array(
			'label' => $healthy && ! $conflicts
			? __( 'Homeward sync is healthy', 'homeward-rescue' ) : __( 'Homeward sync needs attention', 'homeward-rescue' ),
			'status' => $healthy && ! $conflicts ? 'good' : 'recommended',
			'badge'  => array( 'label' => 'Homeward', 'color' => 'blue' ),
			/* translators: %s: comma-separated page slugs. */
			'description' => '<p>' . esc_html(
				Sync::pending_message() ?: ( $s['message'] ?? __( 'No successful sync recorded.', 'homeward-rescue' ) )
			) . '</p>' . ( $conflicts ? '<p>' . esc_html(
					sprintf(
						__( 'Page slug conflicts: %s', 'homeward-rescue' ),
						implode( ', ', $conflicts )
					)
				) . '</p>' : '' ),
			'actions' => Sync::confirm_form() . '<p><a href="' . esc_url( admin_url( 'options-general.php?page=homeward' ) )
			. '">' . esc_html__( 'Review settings and sync status', 'homeward-rescue' ) . '</a></p>',
			'test' => 'homeward_sync',
		);
	}
}
