<?php
/**
 * Plain-language help inside the WordPress dashboard.
 */
namespace Homeward;

defined( 'ABSPATH' ) || exit;

/**
 * Provide task-focused help for rescue volunteers.
 */
class Volunteer_Guide {
	const PAGE = 'homeward-volunteer-guide';

	/**
	 * Register the dashboard guide menu.
	 *
	 * @return void
	 */
	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ) );
	}

	/**
	 * Add the guide for users who can edit posts.
	 *
	 * @return void
	 */
	public static function menu() {
		add_dashboard_page(
			__( 'Volunteer guide', 'homeward-rescue' ),
			__( 'Volunteer guide', 'homeward-rescue' ),
			'edit_posts',
			self::PAGE,
			array( __CLASS__, 'render' )
		);
	}

	/**
	 * Return the dashboard guide address.
	 *
	 * @return string
	 */
	public static function url() {
		return admin_url( 'index.php?page=' . self::PAGE );
	}

	/**
	 * Display the guide after checking access.
	 *
	 * @return void
	 */
	public static function render() {
		if ( ! current_user_can( 'edit_posts' ) ) {
			wp_die( esc_html__( 'Permission denied.', 'homeward-rescue' ), '', array( 'response' => 403 ) );
		}
		$org = Settings::get( 'org_name' );
		require HOMEWARD_DIR . 'guide/volunteer-guide.php';
	}

	/**
	 * Display the rescue-configured website help contact.
	 *
	 * @return void
	 */
	public static function help() {
		if ( ! current_user_can( 'edit_posts' ) ) {
			return;
		}
		$name = Settings::get( 'help_contact_name' );
		$email = Settings::get( 'help_contact_email' );
		echo '<p>'
			. esc_html__( 'Need help? Send your website administrator the page address and a short description of what happened.', 'homeward-rescue' )
			. '</p>';
		if ( $name || is_email( $email ) ) {
			echo '<p>' . esc_html( $name );
			if ( is_email( $email ) ) {
				echo ' <a href="' . esc_url( 'mailto:' . $email ) . '">' . esc_html( $email ) . '</a>';
			}
			echo '</p>';
		}
	}
}
