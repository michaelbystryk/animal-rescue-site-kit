<?php
/**
 * Post types and registered meta.
 *
 * @package Homeward
 */
namespace Homeward;

defined( 'ABSPATH' ) || exit;

/**
 * Register story and event records with their editable metadata.
 */
class Content_Post_Types {
	const STORY = 'homeward_story';
	const EVENT = 'homeward_event';

	/**
	 * Register content types and metadata hooks.
	 *
	 * @return void
	 */
	public static function init() {
		add_action( 'init', array( __CLASS__, 'register' ) );
		add_filter( 'use_block_editor_for_post_type', array( __CLASS__, 'classic_editor' ), 100, 2 );
	}

	/**
	 * Turn the block editor off for our types.
	 *
	 * @param bool   $use       Whether to use the block editor.
	 * @param string $post_type Post type.
	 * @return bool
	 */
	public static function classic_editor( $use, $post_type ) {
		return in_array( $post_type, array( self::STORY, self::EVENT ), true ) ? false : $use;
	}

	/**
	 * Regions, shared with the dogs list.
	 *
	 * @return string[]
	 */
	public static function regions() {
		return Pet_Data::regions();
	}

	/**
	 * Story kinds.
	 *
	 * @return string[]
	 */
	public static function kinds() {
		return array(
			'story'       => __( 'Adoption story', 'homeward-rescue' ),
			'testimonial' => __( 'Testimonial', 'homeward-rescue' ),
		);
	}

	/**
	 * Capability names for a type.
	 *
	 * @param string $type Post type.
	 * @return string[]
	 */
	public static function caps_for( $type ) {
		$p = ( self::STORY === $type ) ? 'homeward_stories' : 'homeward_events';
		return array(
			"edit_{$p}",
			"edit_others_{$p}",
			"publish_{$p}",
			"read_private_{$p}",
			"delete_{$p}",
			"delete_private_{$p}",
			"delete_published_{$p}",
			"delete_others_{$p}",
			"edit_private_{$p}",
			"edit_published_{$p}",
		);
	}

	/**
	 * Register the story and event post types.
	 *
	 * @return void
	 */
	public static function register() {
		register_post_type(
			self::STORY,
			array(
				'labels' => array(
					'name'                  => __( 'Stories', 'homeward-rescue' ),
					'singular_name'         => __( 'Story', 'homeward-rescue' ),
					'add_new_item'          => __( 'Add new story', 'homeward-rescue' ),
					'edit_item'             => __( 'Edit story', 'homeward-rescue' ),
					'all_items'             => __( 'All stories', 'homeward-rescue' ),
					'menu_name'             => __( 'Stories', 'homeward-rescue' ),
					'featured_image'        => __( 'Photo', 'homeward-rescue' ),
					'set_featured_image'    => __( 'Set photo', 'homeward-rescue' ),
					'remove_featured_image' => __( 'Remove photo', 'homeward-rescue' ),
					'use_featured_image'    => __( 'Use as photo', 'homeward-rescue' ),
				),
				'public'          => true,
				'show_in_rest'    => true,
				'menu_icon'       => 'dashicons-heart',
				'menu_position'   => 21,
				'supports'        => array( 'title', 'thumbnail', 'revisions', 'custom-fields' ),
				'has_archive'     => Settings::get( 'stories_slug' ),
				'rest_base'       => 'stories',
				'rewrite'         => array( 'slug' => Settings::get( 'stories_slug' ), 'with_front' => false ),
				'capability_type' => array( 'homeward_story', 'homeward_stories' ),
				'map_meta_cap'    => true,
			)
		);
		register_post_type(
			self::EVENT,
			array(
				'labels' => array(
					'name'                  => __( 'Events', 'homeward-rescue' ),
					'singular_name'         => __( 'Event', 'homeward-rescue' ),
					'add_new_item'          => __( 'Add new event', 'homeward-rescue' ),
					'edit_item'             => __( 'Edit event', 'homeward-rescue' ),
					'all_items'             => __( 'All events', 'homeward-rescue' ),
					'menu_name'             => __( 'Events', 'homeward-rescue' ),
					'featured_image'        => __( 'Photo', 'homeward-rescue' ),
					'set_featured_image'    => __( 'Set photo', 'homeward-rescue' ),
					'remove_featured_image' => __( 'Remove photo', 'homeward-rescue' ),
					'use_featured_image'    => __( 'Use as photo', 'homeward-rescue' ),
				),
				'public'          => true,
				'show_in_rest'    => true,
				'menu_icon'       => 'dashicons-calendar-alt',
				'menu_position'   => 22,
				'supports'        => array( 'title', 'thumbnail', 'revisions', 'custom-fields' ),
				'has_archive'     => Settings::get( 'events_slug' ),
				'rest_base'       => 'events',
				'rewrite'         => array( 'slug' => Settings::get( 'events_slug' ), 'with_front' => false ),
				'capability_type' => array( 'homeward_event', 'homeward_events' ),
				'map_meta_cap'    => true,
			)
		);
		self::register_meta();
	}

	/**
	 * Allowed tags for story text.
	 *
	 * @return array
	 */
	public static function allowed_html() {
		return array(
			'p'      => array(),
			'br'     => array(),
			'em'     => array(),
			'strong' => array(),
			'a'      => array( 'href' => true, 'title' => true, 'rel' => true, 'target' => true, ),
		);
	}

	/**
	 * Sanitise story text.
	 *
	 * @param string $value Raw value.
	 * @return string
	 */
	public static function sanitize_text( $value ) {
		return wp_kses( (string) $value, self::allowed_html() );
	}

	/**
	 * Sanitise a datetime-local value to "Y-m-d H:i" (site time) or ''.
	 *
	 * @param string $value Raw value.
	 * @return string
	 */
	public static function sanitize_datetime( $value ) {
		$value = str_replace( 'T', ' ', sanitize_text_field( (string) $value ) );
		if ( ! preg_match( '/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}$/D', $value ) ) {
			return '';
		}
		$date = \DateTimeImmutable::createFromFormat( '!Y-m-d H:i', $value, wp_timezone() );
		return $date && $date->format( 'Y-m-d H:i' ) === $value ? $value : '';
	}

	/**
	 * Sanitise a month value to "Y-m" or ''.
	 *
	 * @param string $value Raw value.
	 * @return string
	 */
	public static function sanitize_month( $value ) {
		$value = sanitize_text_field( (string) $value );
		return preg_match( '/^\d{4}-(0[1-9]|1[0-2])$/', $value ) ? $value : '';
	}

	/**
	 * Sanitise kind.
	 *
	 * @param string $value Raw value.
	 * @return string
	 */
	public static function sanitize_kind( $value ) {
		return array_key_exists( $value, self::kinds() ) ? $value : 'story';
	}

	/**
	 * Sanitise region.
	 *
	 * @param string $value Raw value.
	 * @return string
	 */
	public static function sanitize_region( $value ) {
		return Settings::text( $value );
	}

	/**
	 * Sanitise a 1/'' flag.
	 *
	 * @param mixed $value Raw value.
	 * @return string
	 */
	public static function sanitize_flag( $value ) {
		return $value ? '1' : '';
	}

	/**
	 * Register post meta.
	 */
	private static function register_meta() {
		$fields = array(
			self::STORY => array(
				'homeward_animal_id'     => 'sanitize_animal_id',
				'homeward_story_id'      => array( Testimonials::class, 'sanitize_story_id' ),
				'homeward_kind'          => 'sanitize_kind',
				'homeward_intro'         => 'sanitize_text_field',
				'homeward_text'          => 'sanitize_text',
				'homeward_pull_quote'    => 'sanitize_textarea_field',
				'homeward_home_duration' => 'sanitize_text_field',
				'homeward_family'        => 'sanitize_text_field',
				'homeward_region'        => 'sanitize_region',
				'homeward_received'      => 'sanitize_month',
				'homeward_featured'      => 'sanitize_flag',
				'homeward_source_hash'   => 'sanitize_key',
			),
			self::EVENT => array(
				'homeward_start'       => 'sanitize_datetime',
				'homeward_end'         => 'sanitize_datetime',
				'homeward_location'    => 'sanitize_text_field',
				'homeward_address'     => 'sanitize_text_field',
				'homeward_description' => 'sanitize_textarea_field',
				'homeward_link'        => 'esc_url_raw',
			),
		);
		foreach ( $fields as $type => $metas ) {
			foreach ( $metas as $key => $callback ) {
				register_post_meta(
					$type,
					$key,
					array(
						'type'              => 'string',
						'single'            => true,
						'show_in_rest'      => true,
						'default'           => '',
						'sanitize_callback' => is_array( $callback ) ? $callback : ( is_callable( array( __CLASS__, $callback ) )
							? array( __CLASS__, $callback ) : $callback ),
						'auth_callback' => array( __CLASS__, 'auth_meta' ),
					)
				);
			}
		}
	}

	/**
	 * Meta auth: the user must be able to edit this post.
	 *
	 * @param bool   $allowed  Default.
	 * @param string $meta_key Meta key.
	 * @param int    $post_id  Post ID.
	 * @return bool
	 */
	public static function sanitize_animal_id( $value ) {
		if ( ! is_scalar( $value ) || ! ctype_digit( (string) $value ) ) {
			return '';
		}
		$post = get_post( (int) $value );
		return $post && Animal_Post_Type::TYPE === $post->post_type ? (string) $post->ID : '';
	}

	/**
	 * Authorize metadata edits against the owning post.
	 *
	 * @param bool   $allowed  Previous authorization result.
	 * @param string $meta_key Metadata key.
	 * @param int    $post_id  Owning post ID.
	 * @return bool
	 */
	public static function auth_meta( $allowed, $meta_key, $post_id ) {
		return current_user_can( 'edit_post', $post_id );
	}
}
