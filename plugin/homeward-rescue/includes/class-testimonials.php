<?php
namespace Homeward;

defined( 'ABSPATH' ) || exit;

/**
 * Connect testimonials to their animals and adoption stories.
 */
class Testimonials {
	/**
	 * Register testimonial editing and listing hooks.
	 *
	 * @return void
	 */
	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ) );
		add_action( 'pre_get_posts', array( __CLASS__, 'filter_list' ) );
	}

	/**
	 * Add a testimonial shortcut to the story menu.
	 *
	 * @return void
	 */
	public static function menu() {
		add_submenu_page(
			'edit.php?post_type=homeward_story',
			__( 'Testimonials', 'homeward-rescue' ),
			__( 'Testimonials', 'homeward-rescue' ),
			'edit_homeward_stories',
			'edit.php?post_type=homeward_story&homeward_kind=testimonial'
		);
		add_submenu_page(
			'edit.php?post_type=homeward_story',
			__( 'Add testimonial', 'homeward-rescue' ),
			__( 'Add testimonial', 'homeward-rescue' ),
			'edit_homeward_stories',
			'post-new.php?post_type=homeward_story&homeward_kind=testimonial'
		);
	}

	/**
	 * Filter the dashboard story list when testimonials are requested.
	 *
	 * @param \WP_Query $query Dashboard query.
	 * @return void
	 */
	public static function filter_list( $query ) {
		if ( ! is_admin() || ! $query->is_main_query() || Content_Post_Types::STORY !== $query->get( 'post_type' )
			|| 'testimonial' !== ( $_GET['homeward_kind'] ?? '' ) ) {
			return;
		}
		$meta = (array) $query->get( 'meta_query' );
		$query->set(
			'meta_query',
			array(
				'relation' => 'AND',
				$meta,
				array( 'key' => 'homeward_kind', 'value' => 'testimonial' ),
			)
		);
	}

	/**
	 * Validate a linked published adoption story ID.
	 *
	 * @param mixed $value Candidate story ID.
	 * @return int
	 */
	public static function sanitize_story_id( $value ) {
		if ( ! is_scalar( $value ) || ! ctype_digit( (string) $value ) ) {
			return '';
		}
		$post = get_post( (int) $value );
		return $post && Content_Post_Types::STORY === $post->post_type
			&& 'testimonial' !== get_post_meta( $post->ID, 'homeward_kind', true ) ? (string) $post->ID : '';
	}

	/**
	 * Render an editorial relationship selector.
	 *
	 * @param int    $post_id Post being edited.
	 * @param string $key     Relationship metadata key.
	 * @param string $type    Linked post type.
	 * @param string $label   Control label.
	 * @return void
	 */
	public static function links_field( $post_id, $key, $type, $label ) {
		echo '<p><label for="' . esc_attr( $key ) . '">' . esc_html( $label ) . '</label><select name="' . esc_attr( $key )
			. '" id="' . esc_attr( $key ) . '"><option value="">' . esc_html__( 'None', 'homeward-rescue' ) . '</option>';
		$posts = get_posts(
			array(
				'post_type'      => $type,
				'post_status'    => array( 'publish', 'draft', 'pending', 'private' ),
				'posts_per_page' => -1,
				'orderby'        => 'title',
				'order'          => 'ASC',
				'post__not_in'   => array( $post_id ),
			)
		);
		foreach ( $posts as $post ) {
			if ( ! current_user_can( 'edit_post', $post->ID ) || ( Content_Post_Types::STORY === $type
				&& ! self::sanitize_story_id( $post->ID ) ) ) {
				continue;
			}
			echo '<option value="' . esc_attr( $post->ID ) . '" '
				. selected( get_post_meta( $post_id, $key, true ), $post->ID, false ) . '>' . esc_html( $post->post_title )
				. '</option>';
		}
		echo '</select><span class="description">'
			. esc_html__( 'Optional. Linked testimonials appear on this page once published.', 'homeward-rescue' )
			. '</span></p>';
	}

	/**
	 * Render public testimonials linked to an animal or story.
	 *
	 * @param int $post_id Related post ID.
	 * @return string
	 */
	public static function render_linked( $post_id ) {
		$type = get_post_type( $post_id );
		if ( Animal::protected_post( $post_id ) ) {
			return '';
		}
		if ( ! in_array(
			$type,
			array( Animal_Post_Type::TYPE, Content_Post_Types::STORY ),
			true
			) || ( Content_Post_Types::STORY === $type
				&& 'testimonial' === get_post_meta( $post_id, 'homeward_kind', true ) ) ) {
			return '';
		}
		$key = Animal_Post_Type::TYPE === $type ? 'homeward_animal_id' : 'homeward_story_id';
		$quotes = get_posts(
			array(
				'post_type'      => Content_Post_Types::STORY,
				'post_status'    => 'publish',
				'has_password'   => false,
				'posts_per_page' => -1,
				'orderby'        => 'date',
				'order'          => 'DESC',
				'post__not_in'   => array( $post_id ),
				'meta_query'     => array(
					'relation' => 'AND',
					array( 'key' => 'homeward_kind', 'value' => 'testimonial' ),
					array( 'key' => $key, 'value' => (string) $post_id ),
				),
			)
		);
		$out = '';
		foreach ( $quotes as $quote ) {
			if ( '' !== $quote->post_password ) {
				continue;
			}
			$out .= Content_Render::story_card( $quote->ID );
		}
		if ( ! $out ) {
			return '';
		}
		Frontend::story_assets();
		return '<section class="homeward-kit homeward-linked-testimonials"><h2>'
			. esc_html__( 'Words from their families', 'homeward-rescue' ) . '</h2><div class="homeward-testimonials">' . $out
			. '</div></section>';
	}
}
