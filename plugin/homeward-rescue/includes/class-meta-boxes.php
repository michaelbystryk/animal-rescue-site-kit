<?php
/**
 * Classic meta boxes for stories and events.
 *
 * @package Homeward
 */

namespace Homeward;

defined( 'ABSPATH' ) || exit;

/**
 * Provide story and event editing controls in the dashboard.
 */
class Meta_Boxes {
	const NONCE_ACTION = 'homeward_save_meta';
	const NONCE_NAME   = 'homeward_meta_nonce';

	/**
	 * Register editor assets, metadata panels and save hooks.
	 *
	 * @return void
	 */
	public static function init() {
		add_action( 'add_meta_boxes', array( __CLASS__, 'add' ) );
		add_action( 'save_post', array( __CLASS__, 'save' ), 10, 2 );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'assets' ) );
		add_filter( 'enter_title_here', array( __CLASS__, 'title_placeholder' ), 10, 2 );
	}

	/**
	 * Placeholder text for the title field.
	 *
	 * @param string   $text Default.
	 * @param \WP_Post $post Post.
	 * @return string
	 */
	public static function title_placeholder( $text, $post ) {
		if ( Content_Post_Types::STORY === $post->post_type ) {
			return __( "Animal's name (or adopter's name for a testimonial)", 'homeward-rescue' );
		}
		if ( Content_Post_Types::EVENT === $post->post_type ) {
			return __( 'Event name', 'homeward-rescue' );
		}
		return $text;
	}

	/**
	 * Enqueue assets on the relevant content editors.
	 *
	 * @param string $hook Current admin screen hook.
	 * @return void
	 */
	public static function assets( $hook ) {
		if ( ! in_array( $hook, array( 'post.php', 'post-new.php' ), true ) ) {
			return;
		}
		$screen = get_current_screen();
		$types = array( Content_Post_Types::STORY, Content_Post_Types::EVENT );
		if ( $screen && in_array( $screen->post_type, $types, true ) ) {
			wp_enqueue_style( 'homeward-core-admin', HOMEWARD_URL . 'assets/css/admin.css', array(), HOMEWARD_VERSION );
		}
	}

	/**
	 * Register story and event metadata panels.
	 *
	 * @return void
	 */
	public static function add() {
		add_meta_box(
			'homeward_story_details',
			__( 'Story details', 'homeward-rescue' ),
			array( __CLASS__, 'story_box' ),
			Content_Post_Types::STORY,
			'normal',
			'high'
		);
		add_meta_box(
			'homeward_event_details',
			__( 'Event details', 'homeward-rescue' ),
			array( __CLASS__, 'event_box' ),
			Content_Post_Types::EVENT,
			'normal',
			'high'
		);
	}

	/**
	 * Read a meta value.
	 *
	 * @param int    $post_id Post ID.
	 * @param string $key     Key.
	 * @return string
	 */
	private static function val( $post_id, $key ) {
		return (string) get_post_meta( $post_id, $key, true );
	}

	/**
	 * Render editable story details.
	 *
	 * @param \WP_Post $post Story being edited.
	 * @return void
	 */
	public static function story_box( $post ) {
		wp_nonce_field( self::NONCE_ACTION, self::NONCE_NAME );
		$id = $post->ID;
		?>
		<div class="homeward-form">
			<p class="homeward-hint">
				<?php esc_html_e( 'Set the photo in the "Photo" box on the right. The animal\'s name goes in the title above.', 'homeward-rescue' ); ?>
			</p>

			<?php
		Testimonials::links_field(
			$id,
			'homeward_animal_id',
			Animal_Post_Type::TYPE,
			__( 'Linked animal', 'homeward-rescue' )
		);
		Testimonials::links_field(
			$id,
			'homeward_story_id',
			Content_Post_Types::STORY,
			__( 'Linked adoption story', 'homeward-rescue' )
		);
		?>
			<?php self::story_text_fields( $id ); ?>
			<?php self::story_details_fields( $id ); ?>
		</div>
		<?php
	}

	private static function story_text_fields( $id ) {
		$kind = self::val( $id, 'homeward_kind' )
			?: ( 'testimonial' === ( $_GET['homeward_kind'] ?? '' ) ? 'testimonial' : 'story' );
		?>
			<p>
				<label for="homeward_kind"><strong><?php esc_html_e( 'Kind', 'homeward-rescue' ); ?></strong></label>
				<select name="homeward_kind" id="homeward_kind">
					<?php foreach ( Content_Post_Types::kinds() as $value => $label ) : ?>
						<option value="<?php echo esc_attr( $value ); ?>" <?php selected( $kind, $value ); ?>>
							<?php echo esc_html( $label ); ?>
						</option>
					<?php endforeach; ?>
				</select>
			</p>

			<p>
				<label for="homeward_intro"><strong><?php esc_html_e( 'Intro line', 'homeward-rescue' ); ?></strong></label>
				<input
					type="text"
					class="widefat"
					name="homeward_intro"
					id="homeward_intro"
					value="<?php echo esc_attr( self::val( $id, 'homeward_intro' ) ); ?>"
					placeholder="<?php esc_attr_e( 'Welcome home,', 'homeward-rescue' ); ?>"
				>
			</p>

			<?php self::story_quote_fields( $id ); ?>
		<?php
	}

	private static function story_quote_fields( $id ) {
		?>
			<p>
				<label for="homeward_text"><strong><?php esc_html_e( 'Story or quote', 'homeward-rescue' ); ?></strong></label>
				<textarea
					class="widefat"
					rows="9"
					name="homeward_text"
					id="homeward_text"
				><?php echo esc_textarea( self::val( $id, 'homeward_text' ) ); ?></textarea>
				<span class="description">
					<?php esc_html_e( 'Plain paragraphs. Leave a blank line between paragraphs. Bold, italic and links are fine; nothing else is kept.', 'homeward-rescue' ); ?>
				</span>
			</p>

			<p>
				<label for="homeward_pull_quote"><strong><?php esc_html_e( 'Featured pull quote (optional)', 'homeward-rescue' ); ?></strong></label>
				<textarea
					class="widefat"
					rows="3"
					name="homeward_pull_quote"
					id="homeward_pull_quote"
					placeholder="<?php esc_attr_e( '[[Short excerpt from this story]]', 'homeward-rescue' ); ?>"
				><?php echo esc_textarea( self::val( $id, 'homeward_pull_quote' ) ); ?></textarea>
				<span class="description">
					<?php esc_html_e( 'Use the adopter\'s own words. If blank, the feature uses the start of the story. Placeholder text in [[double brackets]] is never shown.', 'homeward-rescue' ); ?>
				</span>
			</p>

		<?php
	}

	private static function story_details_fields( $id ) {
		?>
			<p>
				<label for="homeward_home_duration"><strong>
					<?php esc_html_e( 'Time at home when this update was received (optional)', 'homeward-rescue' ); ?>
				</strong></label>
				<input
					type="text"
					class="widefat"
					name="homeward_home_duration"
					id="homeward_home_duration"
					value="<?php echo esc_attr( self::val( $id, 'homeward_home_duration' ) ); ?>"
					placeholder="<?php esc_attr_e( '[[Verified time at home]]', 'homeward-rescue' ); ?>"
				>
				<span class="description">
					<?php esc_html_e( 'Enter a verified duration only. Leave blank if unknown; it is never calculated from the update date. Placeholder text in [[double brackets]] is hidden.', 'homeward-rescue' ); ?>
				</span>
			</p>

			<p>
				<label for="homeward_family"><strong><?php esc_html_e( 'Family / adopter name (optional)', 'homeward-rescue' ); ?></strong></label>
				<input
					type="text"
					class="widefat"
					name="homeward_family"
					id="homeward_family"
					value="<?php echo esc_attr( self::val( $id, 'homeward_family' ) ); ?>"
				>
			</p>

			<p>
				<label for="homeward_region"><strong><?php esc_html_e( 'Where the animal came from', 'homeward-rescue' ); ?></strong></label>
				<select name="homeward_region" id="homeward_region">
					<option value=""><?php esc_html_e( '(not set)', 'homeward-rescue' ); ?></option>
					<?php foreach ( Content_Post_Types::regions() as $region ) : ?>
						<option
							value="<?php echo esc_attr( $region ); ?>"
							<?php selected( self::val( $id, 'homeward_region' ), $region ); ?>
						><?php echo esc_html( $region ); ?></option>
					<?php endforeach; ?>
				</select>
			</p>

			<?php self::story_update_fields( $id ); ?>
		<?php
	}

	private static function story_update_fields( $id ) {
		?>
			<p>
				<label for="homeward_received"><strong><?php esc_html_e( 'Month the update was received', 'homeward-rescue' ); ?></strong></label>
				<input
					type="month"
					name="homeward_received"
					id="homeward_received"
					value="<?php echo esc_attr( self::val( $id, 'homeward_received' ) ); ?>"
				>
			</p>

			<p>
				<label>
					<input
						type="checkbox"
						name="homeward_featured"
						value="1"
						<?php checked( self::val( $id, 'homeward_featured' ), '1' ); ?>
					>
					<strong><?php esc_html_e( 'Featured on home', 'homeward-rescue' ); ?></strong>
				</label>
				<span class="description">
					<?php esc_html_e( 'The newest published adoption story with this checked leads the home and story sections. If none are checked, the newest adoption story leads.', 'homeward-rescue' ); ?>
				</span>
			</p>
		<?php
	}

	/**
	 * Render editable event details.
	 *
	 * @param \WP_Post $post Event being edited.
	 * @return void
	 */
	public static function event_box( $post ) {
		wp_nonce_field( self::NONCE_ACTION, self::NONCE_NAME );
		$id = $post->ID;
		?>
		<div class="homeward-form">
			<p class="homeward-hint"><?php esc_html_e( 'Set the photo in the "Photo" box on the right.', 'homeward-rescue' ); ?></p>

			<p>
				<label for="homeward_start"><strong><?php esc_html_e( 'Starts (required)', 'homeward-rescue' ); ?></strong></label>
				<input
					type="datetime-local"
					name="homeward_start"
					id="homeward_start"
					required
					value="<?php echo esc_attr( str_replace( ' ', 'T', self::val( $id, 'homeward_start' ) ) ); ?>"
				>
			</p>
			<p>
				<label for="homeward_end"><strong><?php esc_html_e( 'Ends (optional)', 'homeward-rescue' ); ?></strong></label>
				<input
					type="datetime-local"
					name="homeward_end"
					id="homeward_end"
					value="<?php echo esc_attr( str_replace( ' ', 'T', self::val( $id, 'homeward_end' ) ) ); ?>"
				>
			</p>
			<?php self::event_location_fields( $id ); ?>
		</div>
		<?php
	}

	private static function event_location_fields( $id ) {
		?>
			<p>
				<label for="homeward_location"><strong><?php esc_html_e( 'Location name', 'homeward-rescue' ); ?></strong></label>
				<input
					type="text"
					class="widefat"
					name="homeward_location"
					id="homeward_location"
					value="<?php echo esc_attr( self::val( $id, 'homeward_location' ) ); ?>"
				>
			</p>
			<p>
				<label for="homeward_address"><strong><?php esc_html_e( 'Address', 'homeward-rescue' ); ?></strong></label>
				<input
					type="text"
					class="widefat"
					name="homeward_address"
					id="homeward_address"
					value="<?php echo esc_attr( self::val( $id, 'homeward_address' ) ); ?>"
				>
			</p>
			<p>
				<label for="homeward_description"><strong><?php esc_html_e( 'Short description (plain text)', 'homeward-rescue' ); ?></strong></label>
				<textarea
					class="widefat"
					rows="4"
					name="homeward_description"
					id="homeward_description"
				><?php echo esc_textarea( self::val( $id, 'homeward_description' ) ); ?></textarea>
			</p>
			<p>
				<label for="homeward_link"><strong><?php esc_html_e( 'Link for tickets or sign-up (optional)', 'homeward-rescue' ); ?></strong></label>
				<input
					type="url"
					class="widefat"
					name="homeward_link"
					id="homeward_link"
					placeholder="https://"
					value="<?php echo esc_attr( self::val( $id, 'homeward_link' ) ); ?>"
				>
			</p>
		<?php
	}

	/**
	 * Save metadata after validating the editor request.
	 *
	 * @param int      $post_id Saved post ID.
	 * @param \WP_Post $post    Saved post.
	 * @return void
	 */
	public static function save( $post_id, $post ) {
		if ( ! isset( $_POST[ self::NONCE_NAME ] ) || ! wp_verify_nonce(
			sanitize_text_field( wp_unslash( $_POST[ self::NONCE_NAME ] ) ),
			self::NONCE_ACTION
		) ) {
			return;
		}
		if ( ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) || wp_is_post_revision( $post_id ) ) {
			return;
		}
		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}

		if ( Content_Post_Types::STORY === $post->post_type ) {
			$map = array(
				'homeward_animal_id'     => array( Content_Post_Types::class, 'sanitize_animal_id' ),
				'homeward_story_id'      => array( Testimonials::class, 'sanitize_story_id' ),
				'homeward_kind'          => array( Content_Post_Types::class, 'sanitize_kind' ),
				'homeward_intro'         => 'sanitize_text_field',
				'homeward_text'          => array( Content_Post_Types::class, 'sanitize_text' ),
				'homeward_pull_quote'    => 'sanitize_textarea_field',
				'homeward_home_duration' => 'sanitize_text_field',
				'homeward_family'        => 'sanitize_text_field',
				'homeward_region'        => array( Content_Post_Types::class, 'sanitize_region' ),
				'homeward_received'      => array( Content_Post_Types::class, 'sanitize_month' ),
			);
			update_post_meta( $post_id, 'homeward_featured', isset( $_POST['homeward_featured'] ) ? '1' : '' );
		} elseif ( Content_Post_Types::EVENT === $post->post_type ) {
			$map = array(
				'homeward_start'       => array( Content_Post_Types::class, 'sanitize_datetime' ),
				'homeward_end'         => array( Content_Post_Types::class, 'sanitize_datetime' ),
				'homeward_location'    => 'sanitize_text_field',
				'homeward_address'     => 'sanitize_text_field',
				'homeward_description' => 'sanitize_textarea_field',
				'homeward_link'        => 'esc_url_raw',
			);
		} else {
			return;
		}

		foreach ( $map as $key => $callback ) {
			if ( ! isset( $_POST[ $key ] ) ) {
				continue;
			}
			// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- sanitised by the callback.
			$value = call_user_func( $callback, wp_unslash( $_POST[ $key ] ) );
			if ( 'homeward_story_id' === $key && (int) $value === (int) $post_id ) {
				$value = '';
			}
			update_post_meta( $post_id, $key, wp_slash( $value ) );
		}
	}
}
