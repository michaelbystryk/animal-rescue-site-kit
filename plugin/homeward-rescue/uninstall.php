<?php
/**
 * Secrets are always erased. Other data requires explicit deletion choices.
 */
defined( 'ABSPATH' ) || exit;

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;
global $wpdb;
$homeward_settings = (array) get_option( 'homeward_settings', array() );
foreach ( array(
	'homeward_sync_listings',
	'homeward_sync_details',
	'homeward_daily_refresh',
	'homeward_cleanup',
	'homeward_refresh_derived',
	'homeward_messages_retention',
	) as $homeward_hook ) {
	wp_clear_scheduled_hook( $homeward_hook );
	if ( function_exists( 'as_unschedule_all_actions' ) ) {
		as_unschedule_all_actions( $homeward_hook, array(), 'homeward' );
	}
}
delete_option( 'homeward_sync_lock' );
delete_option( 'homeward_api_key' );
if ( empty( $homeward_settings['delete_on_uninstall'] ) ) {
	unset( $homeward_settings['turnstile_secret'], $homeward_settings['posthog_key'], $homeward_settings['api_key'] );
	// Avoid the settings sanitizer, which deliberately preserves blank secrets on ordinary saves.
	$wpdb->update(
		$wpdb->options,
		array( 'option_value' => maybe_serialize( $homeward_settings ) ),
		array( 'option_name' => 'homeward_settings' )
	);
	wp_cache_delete( 'homeward_settings', 'options' );
	wp_cache_delete( 'alloptions', 'options' );
	return;
}

foreach ( array_keys( (array) get_option( 'homeward_transient_keys', array() ) ) as $homeward_key ) {
	if ( str_starts_with( $homeward_key, 'homeward_' ) ) {
		delete_transient( $homeward_key );
	}
}
$homeward_types = array( 'homeward_message' );
if ( ! empty( $homeward_settings['delete_content_on_uninstall'] ) ) {
	$homeward_types = array_merge( $homeward_types, array( 'homeward_animal', 'homeward_story', 'homeward_event' ) );
}
foreach ( $homeward_types as $homeward_type ) {
	// Query the table directly: CPT registration is not guaranteed during uninstall.
	$homeward_ids = $wpdb->get_col(
		$wpdb->prepare(
			"SELECT ID FROM {$wpdb->posts} WHERE post_type = %s",
			$homeward_type
		)
	);
	foreach ( $homeward_ids as $homeward_id ) {
		wp_delete_post( (int) $homeward_id, true );
	}
}

foreach ( array(
	'homeward_',
	'_transient_homeward_',
	'_transient_timeout_homeward_',
	'_site_transient_homeward_',
	'_site_transient_timeout_homeward_',
	) as $homeward_prefix ) {
	$homeward_names = $wpdb->get_col(
		$wpdb->prepare(
			"SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s",
			$wpdb->esc_like( $homeward_prefix ) . '%'
		)
	);
	foreach ( $homeward_names as $homeward_name ) {
		if ( str_starts_with( $homeward_name, '_transient_timeout_' )
			|| str_starts_with( $homeward_name, '_site_transient_timeout_' ) ) {
			delete_option( $homeward_name );
		} elseif ( str_starts_with( $homeward_name, '_transient_' ) ) {
			delete_transient( substr( $homeward_name, strlen( '_transient_' ) ) );
		} elseif ( str_starts_with( $homeward_name, '_site_transient_' ) ) {
			delete_site_transient( substr( $homeward_name, strlen( '_site_transient_' ) ) );
		} else {
			delete_option( $homeward_name );
		}
	}
}
