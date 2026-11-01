<?php
/**
 * A starting point for volunteers when they sign in.
 */
namespace Homeward;

defined( 'ABSPATH' ) || exit;

/**
 * Provide a dashboard starting point for volunteer tasks.
 */
class Dashboard_Help {
	/**
	 * Register the dashboard widget hook.
	 *
	 * @return void
	 */
	public static function init() {
		add_action( 'wp_dashboard_setup', array( __CLASS__, 'add' ) );
	}

	/**
	 * Add the help widget for content editors.
	 *
	 * @return void
	 */
	public static function add() {
		if ( current_user_can( 'edit_posts' ) ) {
			wp_add_dashboard_widget( 'homeward_site_help', __( 'Website help', 'homeward-rescue' ), array( __CLASS__, 'render' ) );
		}
	}

	/**
	 * Render guide and task links permitted for the current user.
	 *
	 * @return void
	 */
	public static function render() {
		if ( ! current_user_can( 'edit_posts' ) ) {
			return;
		}
		/* translators: %s: rescue organization name. */
		echo '<p>' . esc_html(
			sprintf(
				__( 'Helping with the %s website? The volunteer guide walks you through common tasks.', 'homeward-rescue' ),
				Settings::get( 'org_name' )
			)
		) . '</p>';
		echo '<p><a class="button" href="' . esc_url( Volunteer_Guide::url() ) . '">'
			. esc_html__( 'Open volunteer guide', 'homeward-rescue' ) . '</a></p><ul>';
		$links = array(
			'edit_homeward_stories' => array(
				'post-new.php?post_type=' . Content_Post_Types::STORY,
				__( 'Add a story', 'homeward-rescue' ),
			),
			'edit_homeward_events' => array(
				'post-new.php?post_type=' . Content_Post_Types::EVENT,
				__( 'Add an event', 'homeward-rescue' ),
			),
			'homeward_edit_overrides' => array(
				'edit.php?post_type=' . Animal_Post_Type::TYPE,
				__( 'Edit animal overrides', 'homeward-rescue' ),
			),
			Messages::CAP => array( 'edit.php?post_type=' . Messages::TYPE, __( 'Read messages', 'homeward-rescue' ) ),
			'manage_options' => array( 'options-general.php?page=homeward', __( 'Settings and sync', 'homeward-rescue' ) ),
		);
		foreach ( $links as $cap => $link ) {
			if ( current_user_can( $cap ) ) {
				echo '<li><a href="' . esc_url( admin_url( $link[0] ) ) . '">' . esc_html( $link[1] ) . '</a></li>';
			}
		}
		echo '</ul>';
		Volunteer_Guide::help();
	}
}
