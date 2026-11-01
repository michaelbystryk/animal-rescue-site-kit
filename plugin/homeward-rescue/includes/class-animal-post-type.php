<?php
namespace Homeward;

defined( 'ABSPATH' ) || exit;

/**
 * Register animal records, editable overrides and public access rules.
 */
class Animal_Post_Type {
	const TYPE = 'homeward_animal';

	/**
	 * Describe the volunteer-editable metadata fields.
	 *
	 * @return array
	 */
	public static function fields() {
		return array(
			'homeward_display_name'   => 'text',
			'homeward_region'         => 'text',
			'homeward_visibility'     => array( 'auto', 'show', 'hide' ),
			'homeward_courtesy'       => array( 'auto', 'yes', 'no' ),
			'homeward_featured'       => 'boolean',
			'homeward_needs_foster'   => 'boolean',
			'homeward_blurb'          => 'textarea',
			'homeward_sort_weight'    => 'integer',
			'homeward_badge_override' => 'text',
		);
	}

	/**
	 * Register the animal post type and its metadata.
	 *
	 * @return void
	 */
	public static function register() {
		register_post_type(
			self::TYPE,
			array(
				'labels' => array(
					'name'          => ucfirst( Settings::get( 'noun_plural' ) ),
					'singular_name' => ucfirst( Settings::get( 'noun_singular' ) ),
				),
				'public'          => true,
				'has_archive'     => Settings::get( 'archive_slug' ),
				'rewrite'         => array( 'slug' => Settings::get( 'single_slug' ), 'with_front' => false ),
				'show_in_rest'    => true,
				'rest_base'       => 'animals',
				'supports'        => array( 'title', 'thumbnail', 'custom-fields' ),
				'menu_icon'       => 'dashicons-pets',
				'capability_type' => array( 'homeward_animal', 'homeward_animals' ),
				'map_meta_cap'    => true,
				'capabilities'    => array( 'create_posts' => 'edit_homeward_animals' ),
			)
		);
		foreach ( self::fields() as $key => $kind ) {
			register_post_meta(
				self::TYPE,
				$key,
				array(
					'type'              => in_array( $kind, array( 'boolean', 'integer' ), true ) ? $kind : 'string',
					'single'            => true,
					'show_in_rest'      => true,
					'sanitize_callback' => function ( $value ) use ( $key ) {
						return self::sanitize( $key, $value );
					},
					'auth_callback' => array( __CLASS__, 'auth_overrides' ),
				)
			);
		}
		self::register_source_meta();
	}

	private static function register_source_meta() {
		foreach ( array(
			Animal::M_SOURCE,
			Animal::M_ADAPTER,
			Animal::M_PS_ID,
			Animal::M_STATUS,
			Animal::M_SPECIES,
			Animal::M_SYNCED,
			Animal::M_FIRST_SYNCED,
			Animal::M_CREATED,
			Animal::M_DETAIL,
			Animal::M_GONE,
			Animal::M_LISTED,
			Animal::M_DERIVED_BADGE,
			Animal::M_SORT,
			) as $key ) {
			register_post_meta(
				self::TYPE,
				$key,
				array(
					'type'          => 'string',
					'single'        => true,
					'show_in_rest'  => false,
					'auth_callback' => '__return_false',
				)
			);
		}
		register_post_meta(
			self::TYPE,
			Animal::M_DATA,
			array(
				'type'          => 'object',
				'single'        => true,
				'show_in_rest'  => false,
				'auth_callback' => '__return_false',
			)
		);
	}

	/**
	 * Exclude private animals from public REST search.
	 *
	 * @param array $args Search query arguments.
	 * @return array
	 */
	public static function rest_search( $args ) {
		if ( current_user_can( 'edit_homeward_animals' ) ) {
			return $args;
		}
		$ids = Animal::all_ids();
		update_meta_cache( 'post', $ids );
		$hidden = array_values( array_filter( $ids, fn ( $id ) => ! Animal::public_readable( $id ) ) );
		$args['post__not_in'] = array_values( array_unique( array_merge( $args['post__not_in'] ?? array(), $hidden ) ) );
		return $args;
	}

	/**
	 * Suppress embeds for animals that are not public.
	 *
	 * @param array    $data Embed response.
	 * @param \WP_Post $post Requested post.
	 * @return array|false
	 */
	public static function oembed( $data, $post ) {
		return Animal::protected_post( $post ) || ( Animal_Post_Type::TYPE === $post->post_type
			&& ! Animal::public_readable( $post->ID ) ) ? false : $data;
	}

	/**
	 * Restrict public REST listings to visible animals.
	 *
	 * @param array $args Query arguments.
	 * @return array
	 */
	public static function rest_query( $args ) {
		if ( current_user_can( 'edit_homeward_animals' ) ) {
			return $args;
		}
		$visibility = array(
			'relation' => 'OR',
			array( 'key' => Animal::M_LISTED, 'value' => '1' ),
			array( 'key' => Animal::M_STATUS, 'value' => 'gone' ),
		);
		$args['meta_query'] = array( 'relation' => 'AND', $args['meta_query'] ?? array(), $visibility );
		return $args;
	}

	/**
	 * Deny REST reads of animals that are not publicly readable.
	 *
	 * @param mixed            $result  Existing dispatch result.
	 * @param \WP_REST_Server  $server  REST server.
	 * @param \WP_REST_Request $request Incoming request.
	 * @return mixed
	 */
	public static function rest_read( $result, $server, $request ) {
		if ( ! in_array( $request->get_method(), array( 'GET', 'HEAD' ), true )
			|| current_user_can( 'edit_homeward_animals' ) ) {
			return $result;
		}
		if ( preg_match( '~^/wp/v2/animals/(\d+)(?:/|$)~', $request->get_route(), $match )
			&& ! Animal::public_readable( (int) $match[ 1 ] ) ) {
			return new \WP_Error( 'rest_post_invalid_id', __( 'Invalid post ID.', 'homeward-rescue' ), array( 'status' => 404 ) );
		}
		return $result;
	}

	/**
	 * Remove source metadata from public REST responses.
	 *
	 * @param \WP_REST_Response $response Prepared response.
	 * @param \WP_Post          $post     Requested animal.
	 * @return \WP_REST_Response
	 */
	public static function rest_prepare( $response, $post ) {
		if ( current_user_can( 'edit_homeward_animals' ) ) {
			return $response;
		}
		$data = $response->get_data();
		unset( $data['meta'], $data['content'], $data['excerpt'] );
		$response->set_data( $data );
		return $response;
	}

	/**
	 * Sanitize an override according to its declared type.
	 *
	 * @param string $key Metadata key.
	 * @param mixed  $v   Submitted value.
	 * @return string|bool|int
	 */
	public static function sanitize( $key, $v ) {
		$type = self::fields()[ $key ] ?? 'text';
		if ( is_array( $type ) ) {
			return in_array( $v, $type, true ) ? $v : 'auto';
		}
		if ( 'boolean' === $type ) {
			return filter_var( $v, FILTER_VALIDATE_BOOLEAN );
		}
		if ( 'integer' === $type ) {
			return (int) $v;
		}
		return 'textarea' === $type ? sanitize_textarea_field( is_scalar( $v ) ? (string) $v : '' ) : Settings::text( $v );
	}

	/**
	 * Return labels for volunteer-editable overrides.
	 *
	 * @return array
	 */
	public static function labels() {
		return array(
			'homeward_display_name'   => __( 'Display name', 'homeward-rescue' ),
			'homeward_region'         => __( 'Region', 'homeward-rescue' ),
			'homeward_visibility'     => __( 'Visibility', 'homeward-rescue' ),
			'homeward_courtesy'       => __( 'Courtesy listing', 'homeward-rescue' ),
			'homeward_featured'       => __( 'Featured', 'homeward-rescue' ),
			'homeward_needs_foster'   => __( 'Needs foster', 'homeward-rescue' ),
			'homeward_blurb'          => __( 'Short description', 'homeward-rescue' ),
			'homeward_sort_weight'    => __( 'Sort weight', 'homeward-rescue' ),
			'homeward_badge_override' => __( 'Badge override', 'homeward-rescue' ),
		);
	}

	private static $saving = false;

	/**
	 * Check whether an animal is managed without a source feed.
	 *
	 * @param int $id Animal post ID.
	 * @return bool
	 */
	public static function manual( $id ) {
		return Animal::manual( $id );
	}

	/**
	 * Describe editable fields for manually managed animals.
	 *
	 * @return array
	 */
	public static function manual_fields() {
		return array(
			'status'           => array( 'available', 'pending', 'gone' ),
			'species'          => array( 'dog', 'cat', 'other' ),
			'sex'              => array( '', 'Male', 'Female' ),
			'age_group'        => array( '', 'Puppy', 'Kitten', 'Young', 'Adult', 'Senior' ),
			'size'             => array( '', 'Small', 'Medium', 'Large', 'Extra Large' ),
			'primary_breed'    => 'Breed',
			'adoption_fee'     => 'Adoption fee',
			'ok_dogs'          => array( '', 'yes', 'no' ),
			'ok_cats'          => array( '', 'yes', 'no' ),
			'ok_kids'          => array( '', 'yes', 'no' ),
			'housebroken'      => array( '', 'yes', 'no' ),
			'is_spayed'        => array( '', 'yes', 'no' ),
			'shots_up_to_date' => array( '', 'yes', 'no' ),
			'description'      => 'Description',
		);
	}

	private static function manual_box( $post ) {
		$data = Animal::data( $post->ID );
		$data['status'] = get_post_meta( $post->ID, Animal::M_STATUS, true ) ?: 'available';
		$data['description'] = $post->post_content;
		foreach ( self::manual_fields() as $key => $choices ) {
			$value = $data[ $key ] ?? '';
			$tri = array( 'ok_dogs', 'ok_cats', 'ok_kids', 'housebroken', 'is_spayed', 'shots_up_to_date' );
			if ( in_array( $key, $tri, true ) ) {
				$value = null === $value || '' === $value ? '' : ( $value ? 'yes' : 'no' );
			}
			$name = 'homeward_manual[' . $key . ']';
			$labels = array(
				'is_spayed'        => __( 'Spayed or neutered', 'homeward-rescue' ),
				'shots_up_to_date' => __( 'Vaccinations up to date', 'homeward-rescue' ),
			);
			$label = $labels[ $key ] ?? ( is_array( $choices ) ? ucfirst( str_replace( '_', ' ', $key ) ) : $choices );
			echo '<p><label>' . esc_html( $label ) . '<br>';
			if ( is_array( $choices ) ) {
				echo '<select name="' . esc_attr( $name ) . '">';
				foreach ( $choices as $choice ) {
					$label = 'gone' === $choice ? __( 'Adopted / hidden', 'homeward-rescue' ) : ( '' === $choice
						? __( 'Unknown', 'homeward-rescue' ) : ucfirst( $choice ) );
					echo '<option value="' . esc_attr( $choice ) . '" ' . selected( $value, $choice, false ) . '>' . esc_html( $label )
						. '</option>';
				}
				echo '</select>';
			} elseif ( 'description' === $key ) {
				echo '<textarea class="widefat" rows="10" name="' . esc_attr( $name ) . '">'
					. esc_textarea( $value ) . '</textarea>';
			} else {
				echo '<input class="widefat" name="' . esc_attr( $name ) . '" value="' . esc_attr( $value ) . '">';
			}
			echo '</label></p>';
		}
	}

	private static function save_manual( $id, $input ) {
		if ( ! self::manual( $id ) || ! is_array( $input ) ) {
			return;
		}
		$data = Animal::data( $id );
		foreach ( self::manual_fields() as $key => $choices ) {
			$value = $input[ $key ] ?? '';
			$value = is_scalar( $value ) ? (string) $value : '';
			if ( is_array( $choices ) ) {
				$value = in_array( $value, $choices, true ) ? $value : $choices[0];
			}
			$tri = array( 'ok_dogs', 'ok_cats', 'ok_kids', 'housebroken', 'is_spayed', 'shots_up_to_date' );
			if ( in_array( $key, $tri, true ) ) {
				$value = '' === $value ? null : 'yes' === $value;
			}
			if ( 'description' !== $key ) {
				$data[ $key ] = is_array( $choices ) ? $value : Settings::text( $value );
			}
		}
		$data['raw_name'] = get_the_title( $id );
		$data['no_longer_available'] = 'gone' === $data['status'];
		update_post_meta( $id, Animal::M_ADAPTER, 'manual' );
		update_post_meta( $id, Animal::M_DATA, wp_slash( $data ) );
		update_post_meta( $id, Animal::M_STATUS, $data['status'] );
		update_post_meta( $id, Animal::M_SPECIES, $data['species'] );
		$description = is_scalar( $input['description'] ?? '' ) ? Pet_Data::kses(
			(string) ( $input['description'] ?? '' )
		) : '';
		wp_update_post( wp_slash( array( 'ID' => $id, 'post_content' => $description ) ) );
	}

	/**
	 * Render animal details and volunteer overrides.
	 *
	 * @param \WP_Post $post Animal being edited.
	 * @return void
	 */
	public static function box( $post ) {
		wp_nonce_field( 'homeward_animal_save', 'homeward_animal_nonce' );
		if ( self::manual( $post->ID ) ) {
			self::manual_box( $post );
		} else {
			echo '<p>'
				. esc_html__( 'Sync owns the description and source data. These overrides survive every sync.', 'homeward-rescue' )
				. '</p>';
		}
		foreach ( self::fields() as $key => $type ) {
			$value = get_post_meta( $post->ID, $key, true );
			echo '<p><label for="' . esc_attr( $key ) . '">' . esc_html( self::labels()[ $key ] ) . '</label><br>';
			if ( is_array( $type ) ) {
				echo '<select id="' . esc_attr( $key ) . '" name="' . esc_attr( $key ) . '">';
				foreach ( $type as $choice ) {
					echo '<option value="' . esc_attr( $choice ) . '" ' . selected(
						$value ?: 'auto',
						$choice,
						false
					) . '>' . esc_html(
						array(
							'auto' => __( 'Automatic', 'homeward-rescue' ),
							'show' => __( 'Show', 'homeward-rescue' ),
							'hide' => __( 'Hide', 'homeward-rescue' ),
							'yes'  => __( 'Yes', 'homeward-rescue' ),
							'no'   => __( 'No', 'homeward-rescue' ),
						)[ $choice ]
					) . '</option>';
				}
				echo '</select>';
			} elseif ( 'boolean' === $type ) {
				echo '<input type="checkbox" id="' . esc_attr( $key ) . '" name="' . esc_attr( $key ) . '" value="1" '
					. checked( (bool) $value, true, false ) . '>';
			} else {
				echo '<input class="widefat" type="' . ( 'integer' === $type ? 'number' : 'text' ) . '" id="' . esc_attr( $key )
					. '" name="' . esc_attr( $key ) . '" value="' . esc_attr( $value ) . '">';
			}
			echo '</p>';
		}
	}

	/**
	 * Check whether the current user may edit animal overrides.
	 *
	 * @param bool   $allowed Previous authorization result.
	 * @param string $key     Metadata key.
	 * @param int    $id      Animal post ID.
	 * @return bool
	 */
	public static function auth_overrides( $allowed, $key, $id ) {
		return current_user_can( 'homeward_edit_overrides' ) && current_user_can( 'edit_post', $id );
	}

	/**
	 * Save authorized animal edits after checking the nonce.
	 *
	 * @param int $id Animal post ID.
	 * @return void
	 */
	public static function save( $id ) {
		if ( self::$saving || ! isset( $_POST['homeward_animal_nonce'] ) || ! wp_verify_nonce(
			Settings::text( wp_unslash( $_POST['homeward_animal_nonce'] ) ),
			'homeward_animal_save'
			) || ! self::auth_overrides( false, '', $id ) || wp_is_post_revision( $id ) || ( defined( 'DOING_AUTOSAVE' )
				&& DOING_AUTOSAVE ) ) {
			return;
		}
		self::$saving = true;
		try {
			if ( isset( $_POST['homeward_manual'] ) ) {
				self::save_manual( $id, wp_unslash( $_POST['homeward_manual'] ) );
			}
			foreach ( self::fields() as $key => $type ) {
				if ( isset( $_POST[ $key ] ) || 'boolean' === $type ) {
					update_post_meta( $id, $key, wp_slash( self::sanitize( $key, wp_unslash( $_POST[ $key ] ?? false ) ) ) );
				}
			}
			Animal::refresh_derived( $id );
			Animal::flush();
		} finally {
			self::$saving = false;
		}
	}

	/**
	 * Refresh derived fields after a REST save.
	 *
	 * @param \WP_Post $post Saved animal.
	 * @return void
	 */
	public static function rest_saved( $post ) {
		Animal::refresh_derived( $post->ID );
		Animal::flush();
	}
}
