<?php
namespace Homeward;

defined( 'ABSPATH' ) || exit;

/**
 * Import and remove practice content without overwriting volunteer edits.
 */
class Demo_Content {
	const OPTION = 'homeward_demo_content';
	const RESULT = 'homeward_demo_result';
	const LOCK = 'homeward_demo_lock';
	const META = '_homeward_demo_key';

	/**
	 * Register practice import actions and the command-line entry point.
	 *
	 * @return void
	 */
	public static function init() {
		add_action( 'homeward_import_demo', array( __CLASS__, 'action' ) );
		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			\WP_CLI::add_command( 'homeward import-demo', array( __CLASS__, 'cli' ) );
		}
	}

	/**
	 * Return the bundled practice content directory.
	 *
	 * @return string
	 */
	public static function directory() {
		$packaged = HOMEWARD_DIR . 'demo-content';
		return is_readable( $packaged . '/sample.json' ) ? $packaged : dirname( HOMEWARD_DIR, 2 ) . '/demo-content';
	}

	/**
	 * Import practice content and store the result for the dashboard.
	 *
	 * @return void
	 */
	public static function action() {
		$result = self::import();
		update_option(
			self::RESULT,
			is_wp_error( $result ) ? array( 'ok' => false, 'message' => $result->get_error_message() ) : $result,
			false
		);
	}

	/**
	 * Handle practice content import and removal from WP-CLI.
	 *
	 * @param array $args       Positional command arguments.
	 * @param array $assoc_args Named command arguments.
	 * @return void
	 */
	public static function cli( $args, $assoc_args ) {
		$result = isset( $assoc_args['remove'] ) ? self::remove() : self::import();
		if ( is_wp_error( $result ) ) {
			\WP_CLI::error( $result->get_error_message() );
			return;
		}
		\WP_CLI::success( $result['message'] );
	}

	private static function allowed() {
		return ( defined( 'WP_CLI' ) && WP_CLI ) || current_user_can( 'manage_options' );
	}

	private static function lock() {
		if ( (int) get_option( self::LOCK, 0 ) < time() - 15 * MINUTE_IN_SECONDS ) {
			delete_option( self::LOCK );
		}
		return add_option( self::LOCK, time(), '', false );
	}

	private static function state() {
		return array_merge( array( 'created' => array(), 'pages' => array() ), (array) get_option( self::OPTION, array() ) );
	}

	/**
	 * Hash the fields used to detect edits to imported content.
	 *
	 * @param int $id Post or attachment ID.
	 * @return string
	 */
	public static function fingerprint( $id ) {
		$post = get_post( $id );
		if ( ! $post ) {
			return '';
		}
		$fields = array();
		foreach ( array(
			'post_type',
			'post_status',
			'post_title',
			'post_name',
			'post_content',
			'post_excerpt',
			'post_password',
			) as $field ) {
			$fields[ $field ] = $post->$field ?? '';
		}
		$meta = get_post_meta( $id );
		foreach ( array(
			Animal::M_SYNCED,
			Animal::M_DETAIL,
			Animal::M_LISTED,
			Animal::M_SORT,
			Animal::M_IS_COURT,
			Animal::M_ORIGIN_FX,
			'_edit_lock',
			'_edit_last',
			'_homeward_wizard_hash',
			) as $key ) {
			unset( $meta[ $key ] );
		}
		ksort( $meta );
		return hash( 'sha256', wp_json_encode( array( $fields, $meta, get_post_thumbnail_id( $id ) ) ) );
	}

	/**
	 * Check that a setup-created record has not been edited.
	 *
	 * @param int $id Post ID.
	 * @return bool
	 */
	public static function wizard_unchanged( $id ) {
		$hash = get_post_meta( $id, '_homeward_wizard_hash', true );
		return is_string( $hash ) && '' !== $hash && hash_equals( $hash, self::fingerprint( $id ) );
	}

	/**
	 * Record the current fingerprint of a setup-created post.
	 *
	 * @param int $id Post ID.
	 * @return void
	 */
	public static function remember_wizard( $id ) {
		update_post_meta( $id, '_homeward_wizard_hash', self::fingerprint( $id ) );
	}

	private static function media_used( $id ) {
		$url = wp_get_attachment_url( $id );
		$stem = $url ? pathinfo( (string) wp_parse_url( $url, PHP_URL_PATH ), PATHINFO_FILENAME ) : '';
		foreach ( get_posts(
			array(
				'post_type'   => get_post_types(),
				'post_status' => array(
					'publish',
					'draft',
					'pending',
					'private',
					'future',
					'trash',
					'inherit',
					'auto-draft',
				),
				'posts_per_page' => -1,
			)
			) as $post ) {
			if ( (int) $post->ID === (int) $id ) {
				continue;
			}
			if ( $stem && str_contains( $post->post_content, $stem ) ) {
				return true;
			}
			// Core gallery shortcodes may refer to media without a URL or wp-image class.
			if ( preg_match_all( '/\b(?:ids|include)\s*=\s*[\"\']([0-9,\s]+)[\"\']/i', $post->post_content, $galleries ) ) {
				foreach ( $galleries[1] as $ids ) {
					if ( in_array( (int) $id, array_map( 'intval', explode( ',', $ids ) ), true ) ) {
						return true;
					}
				}
			}
			if ( (int) get_post_thumbnail_id( $post->ID ) === (int) $id || ( $url && str_contains( $post->post_content, $url ) )
				|| preg_match(
					'/(?:wp-image-|"id"\s*:\s*|"ids"\s*:\s*\[[^\]]*)' . (int) $id . '\b/',
					$post->post_content
			) ) {
				return true;
			}
			// Includes photos referenced through an animal's saved profile.
			if ( $url && str_contains( wp_json_encode( get_post_meta( $post->ID, Animal::M_DATA, true ) ), $url ) ) {
				return true;
			}
		}
		return false;
	}

	private static function remember( &$state, $key, $id ) {
		$state['created'][ $key ] = (int) $id;
		update_option( self::OPTION, $state, false );
		return (int) $id;
	}

	/**
	 * Recover an interrupted insert by its ownership marker, never by its title.
	 */
	private static function owned( &$state, $key, $type ) {
		$id = $state['created'][ $key ] ?? 0;
		if ( $id && get_post_type( $id ) === $type && get_post_meta( $id, self::META, true ) === $key ) {
			return (int) $id;
		}
		$posts = get_posts(
			array(
				'post_type'      => $type,
				'post_status'    => array( 'publish', 'draft', 'private', 'pending', 'future', 'inherit' ),
				'posts_per_page' => 1,
				'meta_key'       => self::META,
				'meta_value'     => $key,
			)
		);
		return $posts ? self::remember( $state, $key, $posts[0]->ID ) : 0;
	}

	private static function insert( &$state, $key, $post ) {
		$post['meta_input'][ self::META ] = $key;
		$id = wp_insert_post( wp_slash( $post ), true );
		return is_wp_error( $id ) ? $id : ( $id
			? self::remember( $state, $key, $id ) : new \WP_Error( 'homeward_demo_post', __( 'A demo record could not be saved. Run the import again to resume.', 'homeward-rescue' ) ) );
	}

	private static function image( &$state, $image ) {
		$key = 'image:' . $image['file'];
		$id = self::owned( $state, $key, 'attachment' );
		if ( $id ) {
			return $id;
		}
		$file = self::directory() . '/images/' . basename( $image['file'] );
		if ( ! is_readable( $file ) ) {
			return new \WP_Error( 'homeward_demo_image', __( 'A bundled demo illustration is missing.', 'homeward-rescue' ) );
		}
		$upload = wp_upload_bits( basename( $file ), null, file_get_contents( $file ) );
		if ( ! empty( $upload['error'] ) ) {
			return new \WP_Error( 'homeward_demo_upload', __( 'The demo illustrations could not be copied to the Media Library. Check the uploads folder and try again.', 'homeward-rescue' ) );
		}
		$id = wp_insert_attachment(
			wp_slash(
				array(
					'post_title'     => $image['title'],
					'post_status'    => 'inherit',
					'post_mime_type' => 'image/png',
					'meta_input'     => array( self::META => $key ),
				)
			),
			$upload['file'],
			0,
			true
		);
		if ( is_wp_error( $id ) || ! $id ) {
			wp_delete_file( $upload['file'] );
			return is_wp_error( $id )
				? $id : new \WP_Error( 'homeward_demo_image', __( 'A demo illustration could not be saved.', 'homeward-rescue' ) );
		}
		self::remember( $state, $key, $id );
		update_post_meta( $id, '_wp_attachment_image_alt', $image['alt'] );
		if ( ! function_exists( 'wp_generate_attachment_metadata' ) ) {
			require_once ABSPATH . 'wp-admin/includes/image.php';
		}
		wp_update_attachment_metadata( $id, wp_generate_attachment_metadata( $id, $upload['file'] ) );
		return (int) $id;
	}

	/**
	 * Build a deterministic practice feed from the sample manifest.
	 *
	 * @param array $manifest Sample animal records.
	 * @param int   $started  Practice import start time.
	 * @return array
	 */
	public static function pets( $manifest, $started ) {
		foreach ( $manifest as &$pet ) {
			$pet['date_received'] = gmdate( 'Y-m-d', $started - (int) $pet['arrived_days_ago'] * DAY_IN_SECONDS );
		}
		unset( $pet );
		return $manifest;
	}

	private static function animals( &$state, $manifest, $images ) {
		$ids = array();
		foreach ( self::pets( $manifest, $state['started'] ) as $pet ) {
			$ps = (int) $pet['id'];
			$key = 'animal:' . $ps;
			$id = self::owned( $state, $key, Animal_Post_Type::TYPE );
			if ( ! $id ) {
				$data = Pet_Data::sanitize_pet( $pet );
				$description = Pet_Data::kses( $data['description'] ?? '' );
				unset( $data['description'] );
				$cover = $images[ $pet['image'] ] ?? 0;
				$url = $cover ? wp_get_attachment_url( $cover ) : '';
				$data['photos'] = $url ? array( array( 'full' => $url, 'thumb' => $url ) ) : array();
				$data['thumb_url'] = $url;
				$id = self::insert(
					$state,
					$key,
					array(
						'post_type'    => Animal_Post_Type::TYPE,
						'post_status'  => 'publish',
						'post_title'   => Pet_Data::clean_name( $data['raw_name'] ),
						'post_content' => $description,
						'meta_input'   => array(
							Animal::M_PS_ID => $ps,
							Animal::M_SOURCE => 0,
							Animal::M_ADAPTER => 'demo',
							Animal::M_DATA => $data,
							Animal::M_STATUS => Pet_Data::status_key( $data['status'] ),
							Animal::M_SPECIES => $data['species'],
							Animal::M_FIRST_SYNCED => time(),
							Animal::M_SYNCED => time(),
							Animal::M_DETAIL => time(),
							Animal::M_CREATED => Pet_Data::date_timestamp( $data['date_received'] ) ?: time(),
						),
					)
				);
				if ( is_wp_error( $id ) ) {
					return $id;
				}
				if ( $cover ) {
					set_post_thumbnail( $id, $cover );
				}
				Animal::refresh_derived( $id );
			}
			$ids[ $ps ] = $id;
		}
		return $ids;
	}

	private static function import_stories( &$state, $data, $animals ) {
		$visible = array_values( array_filter( $animals, static fn ( $id ) => Animal::public_readable( $id ) ) );
		$stories = array();
		// Adoption stories precede testimonials in the bundled sample.
		foreach ( $data['stories'] as $story ) {
			$key = 'story:' . $story['slug'];
			$id = self::owned( $state, $key, Content_Post_Types::STORY );
			if ( ! $id ) {
				$animal_id = $animals[ $story['animal_demo_id'] ];
				if ( $visible && ! in_array( $animal_id, $visible, true ) ) {
					// Keep sample copy and illustration consistent with a rescue's chosen species.
					$same_sex = array_values(
						array_filter(
							$visible,
							static fn ( $candidate ) => ( Animal::data( $candidate )['sex'] ?? '' ) === ( Animal::data( $animal_id )['sex']
								?? '' )
						)
					);
					$replacement = $same_sex[0] ?? $visible[0];
					$from = get_the_title( $animal_id );
					$to = get_the_title( $replacement );
					foreach ( array( 'title', 'text', 'pull_quote' ) as $field ) {
						$story[ $field ] = str_replace(
							array( $from, explode( ' ', $from )[0] ),
							array( $to, explode( ' ', $to )[0] ),
							$story[ $field ]
						);
					}
					$animal_id = $replacement;
				}
				$id = self::insert(
					$state,
					$key,
					array(
						'post_type'   => Content_Post_Types::STORY,
						'post_status' => 'publish',
						'post_name'   => $story['slug'],
						'post_title'  => $story['title'],
						'meta_input'  => array(
							'homeward_kind'       => $story['kind'],
							'homeward_intro'      => $story['intro'],
							'homeward_text'       => $story['text'],
							'homeward_pull_quote' => $story['pull_quote'],
							'homeward_family'     => $story['family'],
							'homeward_featured'   => $story['featured'] ? '1' : '',
							'homeward_animal_id'  => (string) $animal_id,
							'homeward_story_id'   => isset( $story['story'] ) ? (string) $stories[ $story['story'] ] : '',
						),
					)
				);
				if ( is_wp_error( $id ) ) {
					return $id;
				}
				set_post_thumbnail( $id, get_post_thumbnail_id( $animal_id ) );
			}
			$stories[ $story['slug'] ] = $id;
		}
		return $stories;
	}

	private static function import_pages( &$state, $data, &$skipped ) {
		foreach ( $data['pages'] as $page ) {
			$slug = $page['slug'];
			if ( self::owned( $state, 'page:' . $slug, 'page' ) ) {
				continue;
			}
			$filled = $state['pages'][ $slug ] ?? null;
			if ( $filled && 'page' === get_post_type( $filled['id'] ) && ! empty( $filled['applied'] ) ) {
				continue;
			}
			$existing = get_page_by_path( $slug, OBJECT, 'page' );
			if ( $existing ) {
				if ( ! self::wizard_unchanged( $existing->ID ) ) {
					$skipped[] = $existing->post_title . ' (#' . $existing->ID . ')';
					continue;
				}
				if ( ! $filled || (int) $existing->ID !== $filled['id'] ) {
					$state['pages'][ $slug ] = array(
						'id'       => (int) $existing->ID,
						'original' => $existing->post_content,
						'demo'     => $page['content'],
						'applied'  => false,
					);
					update_option( self::OPTION, $state, false );
				}
				$id = wp_update_post( wp_slash( array( 'ID' => $existing->ID, 'post_content' => $page['content'] ) ), true );
				if ( ! is_wp_error( $id ) && $id ) {
					$state['pages'][ $slug ]['applied'] = true;
					$state['pages'][ $slug ]['hash'] = self::fingerprint( $id );
					update_option( self::OPTION, $state, false );
				}
			} else {
				$id = self::insert(
					$state,
					'page:' . $slug,
					array(
						'post_type'    => 'page',
						'post_status'  => 'publish',
						'post_name'    => $slug,
						'post_title'   => $page['title'],
						'post_content' => $page['content'],
					)
				);
			}
			if ( is_wp_error( $id ) || ! $id ) {
				return is_wp_error( $id )
					? $id : new \WP_Error( 'homeward_demo_page', __( 'A demo page could not be saved. Run the import again to resume.', 'homeward-rescue' ) );
			}
		}
		return true;
	}

	private static function import_images( &$state, $data ) {
		$settings = (array) get_option( 'homeward_settings', array() );
		$has_cats = in_array( 'Cat', array_column( $data['animals'], 'animal' ), true );
		if ( $has_cats && ! get_option( 'homeward_species_customized' )
			&& Settings::get( 'species' ) === Settings::defaults()['species'] ) {
			$settings['species'] = array( 'dog', 'cat' );
			update_option( 'homeward_settings', $settings );
		}
		$images = array();
		foreach ( $data['images'] as $image ) {
			$id = self::image( $state, $image );
			if ( is_wp_error( $id ) ) {
				return $id;
			}
			$images[ $image['file'] ] = $id;
		}
		return $images;
	}

	/**
	 * Resume a practice import under its exclusive lease.
	 *
	 * @return array|\WP_Error
	 */
	public static function import() {
		if ( ! self::allowed() ) {
			return new \WP_Error( 'homeward_demo_permission', __( 'An administrator needs to import demo content.', 'homeward-rescue' ) );
		}
		if ( ! self::lock() ) {
			return new \WP_Error( 'homeward_demo_busy', __( 'A demo import or removal is already running. Please wait a moment.', 'homeward-rescue' ) );
		}
		try {
			$file = self::directory() . '/sample.json';
			$data = is_readable( $file ) ? json_decode( file_get_contents( $file ), true ) : null;
			if ( ! is_array( $data ) || 1 !== ( $data['version'] ?? 0 ) ) {
				return new \WP_Error( 'homeward_demo_file', __( 'The bundled demo sample is missing or unreadable.', 'homeward-rescue' ) );
			}
			$state = self::state();
			$state['started'] = $state['started'] ?? time();
			$prior_created = $state['created'];
			$skipped = array();
			update_option( self::OPTION, $state, false );
			$images = self::import_images( $state, $data );
			if ( is_wp_error( $images ) ) {
				return $images;
			}
			$animals = self::animals( $state, $data['animals'], $images );
			if ( is_wp_error( $animals ) ) {
				return $animals;
			}
			$stories = self::import_stories( $state, $data, $animals );
			if ( is_wp_error( $stories ) ) {
				return $stories;
			}
			$pages = self::import_pages( $state, $data, $skipped );
			if ( is_wp_error( $pages ) ) {
				return $pages;
			}
			if ( ! Settings::get( 'shelter_id' ) ) {
				$connection = Config_Import::save_settings( array( 'source' => 'demo' ), true );
				if ( is_wp_error( $connection ) ) {
					return $connection;
				}
				$message = __( 'Practice animals use a separate offline connection. Add your Petstablished shelter number to connect real animals; remove the demo records when you finish practising.', 'homeward-rescue' );
			} else {
				$message = __( 'Your shelter number and animal connection were kept. Made-up sample animals were added for practice.', 'homeward-rescue' );
			}
			foreach ( array_diff_key( $state['created'], $prior_created ) as $key => $id ) {
				$state['hashes'][ $key ] = self::fingerprint( $id );
			}
			update_option( self::OPTION, $state, false );
			if ( $skipped ) {
				$message .= ' ' . __( 'Skipped existing pages:', 'homeward-rescue' ) . ' ' . implode( ', ', $skipped );
			}
			Animal::flush();
			return array(
				'ok'      => true,
				'message' => __( 'Practice content is ready. The animals and stories are made up.', 'homeward-rescue' ) . ' '
				. $message,
			);
		} finally {
			delete_option( self::LOCK );
		}
	}

	private static function restore_pages( &$state, &$kept ) {
		foreach ( $state['pages'] as $slug => $page ) {
			$post = get_post( $page['id'] );
			if ( $post && ! empty( $page['hash'] ) && hash_equals( $page['hash'], self::fingerprint( $post->ID ) ) ) {
				$result = wp_update_post( wp_slash( array( 'ID' => $post->ID, 'post_content' => $page['original'] ) ), true );
				if ( is_wp_error( $result ) ) {
					return $result;
				}
				self::remember_wizard( $post->ID );
			} elseif ( $post ) {
				$kept[] = $post->post_title . ' (#' . $post->ID . ')';
			}
			unset( $state['pages'][ $slug ] );
			update_option( self::OPTION, $state, false );
		}
		return true;
	}

	/**
	 * Remove unchanged practice records and report retained edits.
	 *
	 * @return array|\WP_Error
	 */
	public static function remove() {
		if ( ! self::allowed() ) {
			return new \WP_Error( 'homeward_demo_permission', __( 'An administrator needs to remove demo content.', 'homeward-rescue' ) );
		}
		if ( ! self::lock() ) {
			return new \WP_Error( 'homeward_demo_busy', __( 'A demo import or removal is already running. Please wait a moment.', 'homeward-rescue' ) );
		}
		try {
			$state = self::state();
			$kept = array();
			$restored = self::restore_pages( $state, $kept );
			if ( is_wp_error( $restored ) ) {
				return $restored;
			}
			// Remove posts before their attachments. Never delete an unmarked record.
			foreach ( array( false, true ) as $attachments ) {
				foreach ( $state['created'] as $key => $id ) {
					if ( ( 'attachment' === get_post_type( $id ) ) !== $attachments ) {
						continue;
					}
					if ( get_post( $id ) && get_post_meta( $id, self::META, true ) === $key ) {
						if ( empty( $state['hashes'][ $key ] ) || ! hash_equals( $state['hashes'][ $key ], self::fingerprint( $id ) )
							|| ( $attachments && self::media_used( $id ) ) ) {
							$kept[] = get_the_title( $id ) . ' (#' . $id . ')';
							continue;
						}
						// Attachments are retained if referenced, including by retained demo records.
						$deleted = $attachments ? wp_delete_attachment( $id, false ) : wp_trash_post( $id );
						if ( ! $deleted ) {
							return new \WP_Error( 'homeward_demo_remove', __( 'A demo record could not be removed. Run removal again to resume.', 'homeward-rescue' ) );
						}
					}
					unset( $state['created'][ $key ] );
					update_option( self::OPTION, $state, false );
				}
			}
			if ( $kept ) {
				update_option( self::OPTION, $state, false );
			} else {
				delete_option( self::OPTION );
			}
			Animal::flush();
			$result = array(
				'ok'      => true,
				'kept'    => $kept,
				'message' => __( 'Unchanged demo records were removed. Existing pages and your animal source setting were kept.', 'homeward-rescue' )
				. ( $kept ? ' ' . __( 'Kept modified or referenced records:', 'homeward-rescue' ) . ' '
					. implode( ', ', array_unique( $kept ) ) : '' ),
			);
			update_option( self::RESULT, $result, false );
			return $result;
		} finally {
			delete_option( self::LOCK );
		}
	}
}
