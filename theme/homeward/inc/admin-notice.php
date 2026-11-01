<?php
/**
 * Per-user, persistent plugin reminder with a nonce-protected dismissal.
 *
 * @package Homeward
 */
defined( 'ABSPATH' ) || exit;

/**
 * Offer the rescue plugin to eligible administrators.
 *
 * @return void
 */
function homeward_theme_plugin_notice() {
	if ( homeward_theme_plugin_available() || ! current_user_can( 'manage_options' )
		|| get_user_meta( get_current_user_id(), 'homeward_theme_notice_dismissed', true ) ) {
		return;
	}
	$url = wp_nonce_url(
		admin_url( 'admin-post.php?action=homeward_theme_dismiss_notice' ),
		'homeward_theme_dismiss_notice'
	);
	echo '<div class="notice notice-info"><p>'
		. esc_html__( 'Install and activate Homeward Rescue to add animal listings, applications, stories, and donation tools. Pages and posts work without it.', 'homeward' )
		. '</p><p>';
	if ( current_user_can( 'install_plugins' ) ) {
		echo '<a class="button" href="' . esc_url( admin_url( 'plugin-install.php?tab=upload' ) ) . '">'
			. esc_html__( 'Install Homeward Rescue', 'homeward' ) . '</a> ';
	}
	echo '<a href="' . esc_url( admin_url( 'plugins.php' ) ) . '">' . esc_html__( 'Manage plugins', 'homeward' )
		. '</a> | ';
	echo '<a href="' . esc_url( $url ) . '">' . esc_html__( 'Dismiss this notice', 'homeward' ) . '</a></p></div>';
}
add_action( 'admin_notices', 'homeward_theme_plugin_notice' );
/**
 * Save an authorized per-user dismissal of the plugin reminder.
 *
 * @return void
 */
function homeward_theme_dismiss_notice() {
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die(
			esc_html__( 'You do not have permission to dismiss this notice.', 'homeward' ),
			'',
			array( 'response' => 403 )
		);
	}
	check_admin_referer( 'homeward_theme_dismiss_notice' );
	update_user_meta( get_current_user_id(), 'homeward_theme_notice_dismissed', 1 );
	wp_safe_redirect( admin_url( 'themes.php' ) );
	exit;
}
add_action( 'admin_post_homeward_theme_dismiss_notice', 'homeward_theme_dismiss_notice' );
