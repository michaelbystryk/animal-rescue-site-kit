<?php
namespace Homeward;

defined( 'ABSPATH' ) || exit;

/**
 * Publish rescue metadata and reconcile supported SEO plugin output.
 */
class Seo {
	private static function protected_request() {
		$id = is_home() ? (int) get_option( 'page_for_posts' ) : get_queried_object_id();
		return ( is_singular() || is_home() ) && Animal::protected_post( $id );
	}

	/**
	 * Register metadata, sitemap and image accessibility hooks.
	 *
	 * @return void
	 */
	public static function init() {
		add_action( 'wp_head', array( __CLASS__, 'head' ), 5 );
		add_filter( 'document_title_parts', array( __CLASS__, 'title' ) );
		add_filter( 'wp_robots', array( __CLASS__, 'robots' ) );
		foreach ( array(
			'rank_math/frontend/description',
			'wpseo_metadesc',
			'wpseo_opengraph_desc',
			'wpseo_twitter_description',
			) as $hook ) {
			add_filter( $hook, array( __CLASS__, 'plugin_description' ) );
		}
		foreach ( array(
			'rank_math/frontend/title',
			'wpseo_title',
			'wpseo_opengraph_title',
			'wpseo_twitter_title',
			) as $hook ) {
			add_filter( $hook, array( __CLASS__, 'plugin_title' ) );
		}
		foreach ( array(
			'rank_math/opengraph/facebook/image',
			'rank_math/opengraph/twitter/image',
			'rank_math/opengraph/facebook/og_image',
			'rank_math/opengraph/twitter/twitter_image',
			'wpseo_opengraph_image',
			'wpseo_twitter_image',
			) as $hook ) {
			add_filter( $hook, array( __CLASS__, 'plugin_image' ) );
		}
		add_filter( 'rank_math/frontend/robots', array( __CLASS__, 'rank_robots' ) );
		add_filter( 'wpseo_robots', array( __CLASS__, 'yoast_robots' ) );
		add_filter( 'wp_sitemaps_posts_query_args', array( __CLASS__, 'sitemap_args' ), 10, 2 );
		add_filter( 'rank_math/sitemap/entry', array( __CLASS__, 'sitemap_entry' ), 10, 3 );
		add_filter( 'wpseo_sitemap_entry', array( __CLASS__, 'sitemap_entry' ), 10, 3 );
		add_filter( 'rank_math/json_ld', array( __CLASS__, 'plugin_schema' ), 99 );
		add_filter( 'wpseo_schema_graph', array( __CLASS__, 'yoast_schema' ), 99 );
		add_filter( 'rank_math/opengraph/facebook/og_type', array( __CLASS__, 'og_type' ) );
		add_filter( 'wpseo_opengraph_type', array( __CLASS__, 'og_type' ) );
		add_filter( 'rank_math/sitemap/exclude_post_type', array( __CLASS__, 'exclude_post_type' ), 20, 2 );
		add_filter( 'rank_math/sitemap/posts_to_exclude', array( __CLASS__, 'sitemap_exclusions' ), 20 );
		add_filter( 'wpseo_exclude_from_sitemap_by_post_ids', array( __CLASS__, 'sitemap_exclusions' ), 20 );
		add_action( 'admin_init', array( __CLASS__, 'enable_sitemaps' ) );
		add_action( 'homeward_settings_saved', array( __CLASS__, 'clear_sitemap_cache' ) );
		add_filter( 'render_block', array( __CLASS__, 'image_alt' ), 20, 2 );
	}

	/**
	 * Check whether a supported SEO plugin owns page metadata.
	 *
	 * @return bool
	 */
	public static function active() {
		return (bool) apply_filters(
			'homeward_seo_plugin_active',
			defined( 'WPSEO_VERSION' ) || defined( 'RANK_MATH_VERSION' ) || class_exists( '\\RankMath' )
			|| defined( 'AIOSEO_VERSION' ) || class_exists( 'The_SEO_Framework\Load' )
		);
	}

	private static function animal() {
		return is_singular( Animal_Post_Type::TYPE ) && Animal::public_readable( get_queried_object_id() )
			? Animal::get( get_queried_object_id(), false ) : null;
	}

	/**
	 * Build a short search description for an animal.
	 *
	 * @param array $animal Public animal view model.
	 * @return string
	 */
	public static function description( $animal ) {
		if ( Animal::protected_post( $animal['id'] ) ) {
			return __( 'Protected', 'homeward-rescue' );
		}
		if ( 'gone' === $animal['status'] ) {
			/* translators: %s: animal name. */
			return sprintf(
				__( '%s has found a home. Meet the animals still waiting for a family.', 'homeward-rescue' ),
				$animal['name']
			);
		}
		$post = get_post( $animal['id'] );
		$text = $animal['blurb'] ?: Description::plain_text( Description::clean( $post->post_content ) );
		/* translators: 1: animal name, 2: organization name. */
		return Description::excerpt(
			$text ?: sprintf(
				__( '%1$s is looking for a home with %2$s.', 'homeward-rescue' ),
				$animal['name'],
				Settings::get( 'org_name' )
			),
			160
		);
	}

	private static function name( $a ) {
		/* translators: %s: animal name. */
		return sprintf(
			'gone' === $a['status']
			? __( '%s found a home', 'homeward-rescue' ) : __( 'Meet %s, available for adoption', 'homeward-rescue' ),
			$a['name']
		);
	}

	/**
	 * Replace an SEO plugin description for rescue content.
	 *
	 * @param string $old Existing description.
	 * @return string
	 */
	public static function plugin_description( $old ) {
		if ( self::protected_request() ) {
			return __( 'Protected', 'homeward-rescue' );
		}
		if ( self::has_custom( 'description' ) ) {
			return $old;
		}
		$a = self::animal();
		return $a ? self::description( $a ) : ( self::page_or_archive_description() ?: $old );
	}

	/**
	 * Replace an SEO plugin title for rescue content.
	 *
	 * @param string $old Existing title.
	 * @return string
	 */
	public static function plugin_title( $old ) {
		if ( self::protected_request() ) {
			return get_the_title( get_queried_object_id() );
		}
		if ( self::has_custom( 'title' ) ) {
			return $old;
		}
		$a = self::animal();
		$title = $a ? self::name( $a ) : self::archive_title();
		return $title ? $title . ' · ' . Settings::get( 'org_name' ) : $old;
	}

	/**
	 * Choose a public rescue image for social sharing.
	 *
	 * @param string $old Existing image URL.
	 * @return string
	 */
	public static function plugin_image( $old ) {
		if ( self::protected_request() ) {
			return '';
		}
		$a = self::animal();
		if ( $a ) {
			return ( $a['photos'][ 0 ]['full'] ?? '' ) ?: ( $a['thumb'] ?: $old );
		}
		if ( is_post_type_archive( Animal_Post_Type::TYPE ) ) {
			$animals = Animal::all_listed();
			return ( $animals[0]['thumb'] ?? '' ) ?: $old;
		}
		return $old;
	}

	/**
	 * Adjust WordPress title parts for rescue pages.
	 *
	 * @param array $parts Existing title parts.
	 * @return array
	 */
	public static function title( $parts ) {
		if ( self::active() ) {
			return $parts;
		}
		$a = self::animal();
		if ( $a ) {
			$parts['title'] = self::name( $a );
		} elseif ( is_post_type_archive( Animal_Post_Type::TYPE ) ) {
			/* translators: %s: configured plural animal noun. */
			$parts['title'] = sprintf( __( 'Meet our %s', 'homeward-rescue' ), Settings::get( 'noun_plural' ) );
		} elseif ( self::archive_title() ) {
			$parts['title'] = self::archive_title();
		}
		return $parts;
	}

	private static function hidden( $a ) {
		return $a && ( 'gone' === $a['status'] || ! $a['listed'] );
	}

	/**
	 * Apply visibility rules to WordPress robots directives.
	 *
	 * @param array $robots Existing directives.
	 * @return array
	 */
	public static function robots( $robots ) {
		if ( self::hidden( self::animal() ) ) {
			unset( $robots['index'] );
			$robots['noindex'] = true;
			$robots['follow'] = true;
		}
		return $robots;
	}

	/**
	 * Apply visibility rules to Rank Math robots directives.
	 *
	 * @param array $robots Existing directives.
	 * @return array
	 */
	public static function rank_robots( $robots ) {
		if ( self::hidden( self::animal() ) ) {
			$robots['index'] = 'noindex';
			$robots['follow'] = 'follow';
		}
		return $robots;
	}

	/**
	 * Apply visibility rules to Yoast robots directives.
	 *
	 * @param string $robots Existing directives.
	 * @return string
	 */
	public static function yoast_robots( $robots ) {
		return self::hidden( self::animal() ) ? 'noindex, follow' : $robots;
	}

	/**
	 * Exclude nonpublic animals from core sitemap queries.
	 *
	 * @param array  $args Sitemap query arguments.
	 * @param string $type Post type.
	 * @return array
	 */
	public static function sitemap_args( $args, $type ) {
		$args['has_password'] = false;
		if ( Animal_Post_Type::TYPE === $type ) {
			$args['meta_query'] = array(
				'relation' => 'AND',
				(array) ( $args['meta_query'] ?? array() ),
				Animal::listed_meta_query( true ),
			);
		}
		if ( 'page' === $type ) {
			$args['post__not_in'] = self::sitemap_exclusions( $args['post__not_in'] ?? array() );
		}
		return $args;
	}

	/**
	 * Remove nonpublic animal entries from core sitemaps.
	 *
	 * @param array    $entry Existing sitemap entry.
	 * @param string   $type  Post type.
	 * @param \WP_Post $post  Listed post.
	 * @return array
	 */
	public static function sitemap_entry( $entry, $type, $post ) {
		if ( $post instanceof \WP_Post && Animal::protected_post( $post ) ) {
			return false;
		}
		if ( $post instanceof \WP_Post && Animal_Post_Type::TYPE === $post->post_type
			&& '1' !== get_post_meta( $post->ID, Animal::M_LISTED, true ) ) {
			return false;
		}
		if ( $post instanceof \WP_Post && 'page' === $post->post_type && self::redirected_page( $post ) ) {
			return false;
		}
		return $entry;
	}

	private static function has_custom( $field ) {
		$id = is_home() ? (int) get_option( 'page_for_posts' ) : get_queried_object_id();
		$yoast = 'description' === $field ? 'metadesc' : $field;
		if ( $id && ( is_singular() || is_home() ) && ( '' !== trim(
			(string) get_post_meta(
				$id,
				'rank_math_' . $field,
				true
			)
			) || '' !== trim( (string) get_post_meta( $id, '_yoast_wpseo_' . $yoast, true ) ) ) ) {
			return true;
		}
		if ( is_front_page() && class_exists( '\RankMath\Helper' ) ) {
			$value = trim( (string) \RankMath\Helper::get_settings( 'titles.homepage_' . $field ) );
			$automatic = 'description' === $field ? array( '', '%sitedesc%' ) : array(
				'',
				'%sitename% %page% %sep% %sitedesc%',
				'%sitename% %sep% %sitedesc%',
			);
			return ! in_array( $value, $automatic, true );
		}
		return false;
	}

	private static function plain( $html ) {
		return trim(
			(string) preg_replace(
				'/\s+/u',
				' ',
				html_entity_decode(
					wp_strip_all_tags( strip_shortcodes( $html ) ),
					ENT_QUOTES | ENT_HTML5,
					'UTF-8'
				)
			)
		);
	}

	/**
	 * Extract a readable description from page content.
	 *
	 * @param string $content Page content.
	 * @return string
	 */
	public static function page_description( $content ) {
		preg_match_all( '~<p\b[^>]*>(.*?)</p\s*>~is', $content, $paragraphs, PREG_SET_ORDER );
		$first = '';
		foreach ( $paragraphs as $paragraph ) {
			$tags = new \WP_HTML_Tag_Processor( $paragraph[0] );
			$tags->next_tag( 'P' );
			$text = self::plain( $paragraph[1] );
			if ( '' === $text || $tags->has_class( 'is-style-eyebrow' ) ) {
				continue;
			}
			if ( $tags->has_class( 'homeward-page-header__lead' ) ) {
				return Description::excerpt( $text, 155 );
			}
			if ( '' === $first ) {
				$first = $text;
			}
		}
		return Description::excerpt( $first ?: self::plain( get_bloginfo( 'description' ) ), 155 );
	}

	private static function archive_title() {
		if ( is_post_type_archive( Animal_Post_Type::TYPE ) ) {
			/* translators: %s: configured plural animal noun. */
			return sprintf( __( 'Meet our %s', 'homeward-rescue' ), Settings::get( 'noun_plural' ) );
		}
		if ( is_post_type_archive( Content_Post_Types::STORY ) ) {
			return __( 'Adoption stories', 'homeward-rescue' );
		}
		return is_post_type_archive( Content_Post_Types::EVENT ) ? __( 'Events', 'homeward-rescue' ) : '';
	}

	private static function page_or_archive_description() {
		$name = Settings::get( 'org_name' );
		if ( is_post_type_archive( Animal_Post_Type::TYPE ) ) {
			/* translators: 1: configured plural animal noun, 2: organization name. */
			return sprintf(
				__( 'Meet the %1$s waiting for a home with %2$s. See photos and learn how to adopt.', 'homeward-rescue' ),
				Settings::get( 'noun_plural' ),
				$name
			);
		}
		if ( is_post_type_archive( Content_Post_Types::STORY ) ) {
			/* translators: %s: organization name. */
			return sprintf(
				__( 'Adoption stories and updates from families who adopted through %s.', 'homeward-rescue' ),
				$name
			);
		}
		if ( is_post_type_archive( Content_Post_Types::EVENT ) ) {
			/* translators: %s: organization name. */
			return sprintf( __( 'Events and fundraisers supporting the animals in the care of %s.', 'homeward-rescue' ), $name );
		}
		$id = is_home() ? (int) get_option( 'page_for_posts' ) : get_queried_object_id();
		$post = get_post( $id );
		return $post && ! Animal::protected_post( $post ) && ( is_page() || is_home() )
			? self::page_description( $post->post_content ) : '';
	}

	/**
	 * Choose the Open Graph type for the current rescue page.
	 *
	 * @param string $type Existing Open Graph type.
	 * @return string
	 */
	public static function og_type( $type ) {
		return is_archive() || is_home() ? 'website' : $type;
	}

	/**
	 * Build organization schema from the configured rescue details.
	 *
	 * @return array
	 */
	public static function organization() {
		$org = array(
			'@type' => 'NGO',
			'@id'   => home_url( '/#organization' ),
			'name'  => Settings::get( 'org_name' ),
			'url'   => home_url( '/' ),
		);
		if ( $logo = Settings::get( 'logo_url' ) ) {
			$org['logo'] = array( '@type' => 'ImageObject', 'url' => $logo, 'contentUrl' => $logo );
		}
		$org['sameAs'] = array_values( array_filter( array_column( Settings::get( 'social' ), 'url' ) ) );
		foreach ( array(
			'contact_email' => 'email',
			'phone'         => 'telephone',
			'country'       => 'areaServed',
			) as $key => $property ) {
			if ( $value = Settings::get( $key ) ) {
				$org[ $property ] = $value;
			}
		}
		return $org;
	}

	private static function animal_schema( $a ) {
		$description = self::description( $a );
		$about = array( '@type' => 'Thing', 'name' => $a['name'], 'description' => $description );
		$images = array_slice( array_column( $a['photos'], 'full' ), 0, 5 );
		if ( $images ) {
			$about['image'] = $images;
		}
		return array(
			'@type'       => 'WebPage',
			'@id'         => $a['url'] . '#webpage',
			'url'         => $a['url'],
			'name'        => self::name( $a ) . ' · ' . Settings::get( 'org_name' ),
			'description' => $description,
			'about'       => $about,
			'isPartOf'    => array(
				'@type' => 'WebSite',
				'@id'   => home_url( '/#website' ),
				'name'  => Settings::get( 'org_name' ),
				'url'   => home_url( '/' ),
			),
		);
	}

	private static function archive_schema() {
		$items = array();
		foreach ( Animal::all_listed() as $a ) {
			$items[] = array(
				'@type'    => 'ListItem',
				'position' => count( $items ) + 1,
				'url'      => $a['url'],
				'name'     => $a['name'],
			);
		}
		return array(
			'@type'           => 'ItemList',
			'@id'             => Render::archive_url() . '#animals',
			'name'            => self::archive_title(),
			'url'             => Render::archive_url(),
			'numberOfItems'   => count( $items ),
			'itemListElement' => $items,
		);
	}

	/**
	 * Merge rescue schema while preserving plugin node identifiers.
	 *
	 * @param array $data Existing schema nodes.
	 * @return array
	 */
	public static function plugin_schema( $data ) {
		if ( self::protected_request() ) {
			return array( 'homeward_organization' => self::organization() );
		}
		$removed = array();
		$data = self::clean_schema(
			$data,
			! is_singular( 'post' ),
			is_page() || is_archive() || is_home() || is_singular( Animal_Post_Type::TYPE ),
			$removed
		);
		$data = self::remove_references( $data, $removed );
		$org = self::organization();
		$found = false;
		foreach ( $data as &$node ) {
			if ( is_array( $node )
				&& array_intersect( array( 'Organization', 'NGO' ), (array) ( $node['@type'] ?? array() ) ) ) {
				foreach ( array( 'logo', 'sameAs', 'email', 'telephone', 'areaServed' ) as $property ) {
					unset( $node[ $property ] );
				}
				$node = array_merge( $node, $org, array( '@id' => $node['@id'] ?? $org['@id'] ) );
				$org = $node;
				$found = true;
			}
		}
		unset( $node );
		if ( ! $found ) {
			$data['homeward_organization'] = $org;
		}
		foreach ( $data as &$node ) {
			if ( is_array( $node ) && in_array( 'WebSite', (array) ( $node['@type'] ?? array() ), true ) ) {
				$node['publisher'] = array( '@id' => $org['@id'] );
				$node['name'] = Settings::get( 'org_name' );
			}
		}
		unset( $node );
		$a = self::animal();
		$page = $a ? self::animal_schema( $a ) : null;
		if ( ! $page && is_page() ) {
			$url = get_permalink( get_queried_object_id() );
			$page = array(
				'@type' => 'WebPage',
				'@id'   => $url . '#webpage',
				'url'   => $url,
				'name'  => get_the_title( get_queried_object_id() ),
			);
		}
		$data = self::merge_page_schema( $data, $page );
		if ( is_post_type_archive( Animal_Post_Type::TYPE ) ) {
			$data['homeward_animals'] = self::archive_schema();
		}
		return $data;
	}

	private static function merge_page_schema( $data, $page ) {
		if ( $page ) {
			$found = false;
			foreach ( $data as &$node ) {
				if ( is_array( $node ) && array_intersect(
					array(
						'WebPage',
						'ItemPage',
						'AboutPage',
						'ContactPage',
						'CollectionPage',
					),
					(array) ( $node['@type'] ?? array() )
				) ) {
					$node = array_merge(
						$node,
						$page,
						array(
							'@id'      => $node['@id'] ?? $page['@id'],
							'isPartOf' => $node['isPartOf'] ?? ( $page['isPartOf'] ?? array( '@id' => home_url( '/#website' ) ) ),
						)
					);
					$found = true;
					break;
				}
			}
			unset( $node );
			if ( ! $found ) {
				$data['homeward_webpage'] = $page;
			}
		}
		return $data;
	}

	/**
	 * Return merged schema as the node list expected by Yoast.
	 *
	 * @param array $data Existing schema nodes.
	 * @return array
	 */
	public static function yoast_schema( $data ) {
		return array_values( self::plugin_schema( $data ) );
	}

	/**
	 * Print fallback metadata when no supported SEO plugin owns it.
	 *
	 * @return void
	 */
	public static function head() {
		if ( self::active() || self::protected_request() ) {
			return;
		}
		$a = self::animal();
		$archive = is_post_type_archive( Animal_Post_Type::TYPE );
		$description = $a ? self::description( $a ) : self::page_or_archive_description();
		if ( $description ) {
			echo '<meta name="description" content="' . esc_attr( $description ) . '">';
		}
		if ( $a || $archive || is_page() ) {
			$title = $a ? self::name( $a ) : ( self::archive_title() ?: get_the_title( get_queried_object_id() ) );
			$url = $a ? $a['url'] : ( $archive ? Render::archive_url() : get_permalink( get_queried_object_id() ) );
			$image = self::plugin_image( Settings::get( 'logo_url' ) );
			if ( $archive ) {
				echo '<link rel="canonical" href="' . esc_url( $url ) . '">';
			}
			foreach ( array(
				'type'        => 'website',
				'site_name'   => Settings::get( 'org_name' ),
				'title'       => $title . ' · ' . Settings::get( 'org_name' ),
				'description' => $description,
				'url'         => $url,
				'image'       => $image,
				) as $key => $value ) {
				if ( $value ) {
					echo '<meta property="og:' . esc_attr( $key ) . '" content="' . esc_attr( $value ) . '">';
				}
			}
			echo '<meta name="twitter:card" content="' . ( $image ? 'summary_large_image' : 'summary' ) . '">';
		}
		$graph = self::plugin_schema(
			array(
				'website' => array(
					'@type' => 'WebSite',
					'@id'   => home_url( '/#website' ),
					'url'   => home_url( '/' ),
				),
			)
		);
		echo '<script type="application/ld+json">' . wp_json_encode(
			array(
				'@context' => 'https://schema.org',
				'@graph'   => array_values( $graph ),
			),
			JSON_HEX_TAG | JSON_HEX_AMP | JSON_UNESCAPED_SLASHES
		) . '</script>';
	}

	/**
	 * Keep rescue content eligible for plugin sitemaps.
	 *
	 * @param bool   $exclude Existing exclusion state.
	 * @param string $type    Post type.
	 * @return bool
	 */
	public static function exclude_post_type( $exclude, $type ) {
		return in_array(
			$type,
			array(
				Animal_Post_Type::TYPE,
				Content_Post_Types::STORY,
				Content_Post_Types::EVENT,
			),
			true
		) ? false : $exclude;
	}

	/**
	 * Enable eligible rescue content in supported sitemap settings.
	 *
	 * @return void
	 */
	public static function enable_sitemaps() {
		$options = get_option( 'rank-math-options-sitemap' );
		if ( ! is_array( $options ) ) {
			return;
		}
		$changed = false;
		foreach ( array( Animal_Post_Type::TYPE, Content_Post_Types::STORY, Content_Post_Types::EVENT ) as $type ) {
			if ( 'on' !== ( $options[ "pt_{$type}_sitemap" ] ?? '' ) ) {
				$options[ "pt_{$type}_sitemap" ] = 'on';
				$changed = true;
			}
		}
		if ( $changed ) {
			update_option( 'rank-math-options-sitemap', $options );
			self::clear_sitemap_cache();
		}
	}

	/**
	 * Clear supported sitemap caches after rescue settings change.
	 *
	 * @return void
	 */
	public static function clear_sitemap_cache() {
		if ( is_callable( array( '\RankMath\Sitemap\Cache', 'invalidate_storage' ) ) ) {
			\RankMath\Sitemap\Cache::invalidate_storage();
		}
	}

	private static function redirected_page( $page ) {
		return null !== Redirects::match( '/' . trim( get_page_uri( $page ), '/' ) . '/' );
	}

	/**
	 * Add nonpublic animal IDs to plugin sitemap exclusions.
	 *
	 * @param array $excluded Existing excluded IDs.
	 * @return array
	 */
	public static function sitemap_exclusions( $excluded ) {
		$excluded = is_array( $excluded ) ? $excluded : explode( ',', (string) $excluded );
		foreach ( get_posts(
			array(
				'post_type'      => 'page',
				'post_status'    => 'publish',
				'posts_per_page' => -1,
				'no_found_rows'  => true,
			)
			) as $page ) {
			if ( self::redirected_page( $page ) ) {
				$excluded[] = $page->ID;
			}
		}
		foreach ( get_posts(
			array(
				'post_type'      => 'any',
				'post_status'    => 'publish',
				'has_password'   => true,
				'posts_per_page' => -1,
			)
			) as $post ) {
			if ( Animal::protected_post( $post ) ) {
				$excluded[] = $post->ID;
			}
		}
		$ids = Animal::all_ids();
		update_meta_cache( 'post', $ids );
		foreach ( $ids as $id ) {
			if ( '1' !== get_post_meta( $id, Animal::M_LISTED, true ) ) {
				$excluded[] = $id;
			}
		}
		return array_values( array_unique( array_filter( array_map( 'intval', $excluded ) ) ) );
	}

	/**
	 * Strip author identities and references, including inline schema nodes.
	 */
	private static function clean_schema( $nodes, $remove_people, $remove_articles, &$removed ) {
		if ( ! is_array( $nodes ) ) {
			return $nodes;
		}
		$types = (array) ( $nodes['@type'] ?? array() );
		$list = array() === $nodes || array_keys( $nodes ) === range( 0, count( $nodes ) - 1 );
		foreach ( $nodes as $key => $value ) {
			$clean = self::clean_schema( $value, $remove_people, $remove_articles, $removed );
			if ( $remove_people && in_array( $key, array( 'author', 'creator', 'reviewedBy' ), true ) ) {
				unset( $nodes[ $key ] );
				continue;
			}
			if ( null === $clean ) {
				unset( $nodes[ $key ] );
			} else {
				$nodes[ $key ] = $clean;
			}
		}
		if ( ( $remove_people && in_array( 'Person', $types, true ) ) || ( $remove_articles
			&& array_intersect( array( 'Article', 'BlogPosting', 'NewsArticle' ), $types ) ) ) {
			if ( isset( $nodes['@id'] ) ) {
				$removed[] = $nodes['@id'];
			}
			return null;
		}
		return $list ? array_values( $nodes ) : $nodes;
	}

	private static function remove_references( $nodes, $removed ) {
		if ( ! is_array( $nodes ) ) {
			return $nodes;
		}
		if ( isset( $nodes['@id'] ) && in_array( $nodes['@id'], $removed, true ) ) {
			return null;
		}
		$list = array() === $nodes || array_keys( $nodes ) === range( 0, count( $nodes ) - 1 );
		foreach ( $nodes as $key => $value ) {
			$clean = self::remove_references( $value, $removed );
			if ( null === $clean ) {
				unset( $nodes[ $key ] );
			} else {
				$nodes[ $key ] = $clean;
			}
		}
		return $list ? array_values( $nodes ) : $nodes;
	}

	/**
	 * Fill empty image alternatives while respecting decorative ancestors.
	 *
	 * @param string $content Rendered block markup.
	 * @param array  $block   Parsed block.
	 * @return string
	 */
	public static function image_alt( $content, $block ) {
		if ( ! in_array( $block['blockName'] ?? '', array( 'core/image', 'core/cover' ), true ) ) {
			return $content;
		}
		$tags = new \WP_HTML_Tag_Processor( $content );
		$ancestors = array();
		$void = array(
			'AREA',
			'BASE',
			'BR',
			'COL',
			'EMBED',
			'HR',
			'IMG',
			'INPUT',
			'LINK',
			'META',
			'PARAM',
			'SOURCE',
			'TRACK',
			'WBR',
		);
		while ( $tags->next_tag( array( 'tag_closers' => 'visit' ) ) ) {
			$tag = $tags->get_tag();
			if ( $tags->is_tag_closer() ) {
				for ( $i = count( $ancestors ) - 1; $i >= 0; --$i ) {
					if ( $ancestors[ $i ]['tag'] === $tag ) {
						$ancestors = array_slice( $ancestors, 0, $i );
						break;
					}
				}
				continue;
			}
			$hidden = 'true' === strtolower( (string) $tags->get_attribute( 'aria-hidden' ) ) || ( $ancestors
				&& end( $ancestors )['hidden'] );
			if ( ! in_array( $tag, $void, true ) ) {
				$ancestors[] = array( 'tag' => $tag, 'hidden' => $hidden );
			}
			if ( 'IMG' !== $tag || $hidden || '' !== trim( (string) $tags->get_attribute( 'alt' ) ) || in_array(
				$tags->get_attribute( 'role' ),
				array( 'presentation', 'none' ),
				true
			) ) {
				continue;
			}
			if ( 'core/cover' === $block['blockName'] && ! $tags->has_class( 'wp-block-cover__image-background' ) ) {
				continue;
			}
			self::set_image_alt( $tags, $block );
		}
		return $tags->get_updated_html();
	}
	private static function set_image_alt( $tags, $block ) {
		$id = (int) ( $block['attrs']['id'] ?? 0 );
		if ( preg_match( '/\bwp-image-(\d+)\b/', (string) $tags->get_attribute( 'class' ), $match ) ) {
			$id = (int) $match[1];
		}
		$alt = $id ? self::plain( (string) get_post_meta( $id, '_wp_attachment_image_alt', true ) ) : '';
		if ( '' === $alt ) {
			$title = self::plain( get_the_title( get_queried_object_id() ) );
			$alt = $title ? Settings::get( 'noun_singular' ) . ', ' . $title . ' page' : Settings::get( 'noun_singular' );
		}
		$tags->set_attribute( 'alt', $alt );
	}

}
