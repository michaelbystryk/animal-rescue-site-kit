<?php
namespace Homeward;

defined( 'ABSPATH' ) || exit;

/**
 * Register fallback block templates for rescue content.
 */
class Templates {
	/**
	 * Register templates when the installed WordPress version supports them.
	 *
	 * @return void
	 */
	public static function register() {
		if ( ! function_exists( 'register_block_template' ) ) {
			return;
		}
		foreach ( array(
			'single-homeward_animal'  => __( 'Animal profile', 'homeward-rescue' ),
			'archive-homeward_animal' => __( 'Animal archive', 'homeward-rescue' ),
			'single-homeward_story'   => __( 'Story', 'homeward-rescue' ),
			'archive-homeward_story'  => __( 'Story archive', 'homeward-rescue' ),
			'single-homeward_event'   => __( 'Event', 'homeward-rescue' ),
			'archive-homeward_event'  => __( 'Event archive', 'homeward-rescue' ),
			) as $name => $title ) {
			register_block_template(
				'homeward-rescue//' . $name,
				array(
					'title'       => $title,
					'description' => __( 'Homeward Rescue content template', 'homeward-rescue' ),
					'content'     => file_get_contents( HOMEWARD_DIR . 'templates/' . $name . '.html' ),
				)
			);
		}
	}
}
