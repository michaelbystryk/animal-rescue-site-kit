<?php
namespace Homeward;

defined( 'ABSPATH' ) || exit;

/**
 * Connect public queries and rendering to the rescue components.
 */
class Frontend {
	/**
	 * Suppress featured images for password-protected records.
	 *
	 * @param string $html    Existing image markup.
	 * @param int    $post_id Owning post ID.
	 * @return string
	 */
	public static function thumbnail( $html, $post_id ) {
		return Animal::protected_post( $post_id ) ? '' : $html;
	}

	/**
	 * Apply thumbnail privacy to a featured-image block.
	 *
	 * @param string    $html     Rendered markup.
	 * @param array     $block    Parsed block.
	 * @param \WP_Block $instance Block context.
	 * @return string
	 */
	public static function featured_block( $html, $block, $instance ) {
		return self::thumbnail( $html, $instance->context['postId'] ?? get_the_ID() );
	}

	/**
	 * Register component assets and their public translations.
	 *
	 * @return void
	 */
	public static function register_assets() {
		wp_register_style( 'homeward', HOMEWARD_URL . 'assets/css/homeward.css', array(), HOMEWARD_VERSION );
		wp_register_script( 'homeward-config', false, array(), HOMEWARD_VERSION, true );
		wp_register_script(
			'homeward-qr', HOMEWARD_URL . 'assets/js/qr.js', array(), HOMEWARD_VERSION, true
		);
		foreach ( array( 'animals', 'stories', 'donate' ) as $script ) {
			wp_register_script(
				'homeward-' . $script,
				HOMEWARD_URL . 'assets/js/' . $script . '.js',
				'animals' === $script ? array( 'homeward-config', 'homeward-qr' ) : array( 'homeward-config' ),
				HOMEWARD_VERSION,
				array( 'strategy' => 'defer', 'in_footer' => true )
			);
		}
		self::animal_translations();
		wp_localize_script(
			'homeward-config',
			'homewardDonateL10n',
			array(
				'copied' => __( 'Email copied.', 'homeward-rescue' ),
				'manual' => __( 'Select and copy this email: ', 'homeward-rescue' ),
			)
		);
		wp_add_inline_style( 'homeward', Brand::css() );
	}

	private static function animal_translations() {
		wp_localize_script(
			'homeward-config',
			'homewardAnimalsL10n',
			array(
				'archivePath' => wp_parse_url( Render::archive_url(), PHP_URL_PATH ),
				/* translators: %d: number of additional animals, inserted by JavaScript. */
				'more' => __( 'Show more (%d)', 'homeward-rescue' ),
				/* translators: %s: organization name; %%s is filled with an animal name by JavaScript. */
				'shareText'     => sprintf( __( 'Meet %%s from %s', 'homeward-rescue' ), Settings::get( 'org_name' ) ),
				'copied'        => __( 'Link copied', 'homeward-rescue' ),
				'copyFail'      => __( 'Select and copy the link to share it.', 'homeward-rescue' ),
				'shareNetworks' => (array) Settings::get( 'share_networks' ),
				'shareClose'    => __( 'Close sharing options', 'homeward-rescue' ),
				'copyLink'      => __( 'Copy link', 'homeward-rescue' ),
				'copy'          => __( 'Copy', 'homeward-rescue' ),
				'copyDone'      => __( 'Copied ✓', 'homeward-rescue' ),
				'facebook'      => __( 'Facebook', 'homeward-rescue' ),
				'x'             => __( 'X', 'homeward-rescue' ),
				'email'         => __( 'Email', 'homeward-rescue' ),
				'whatsapp'      => __( 'WhatsApp', 'homeward-rescue' ),
				'shareNewTab'   => __( ' (opens in a new tab)', 'homeward-rescue' ),
				'qrShow'        => __( 'Show QR code', 'homeward-rescue' ),
				'qrHide'        => __( 'Hide QR code', 'homeward-rescue' ),
				'qrSave'        => __( 'Save QR code', 'homeward-rescue' ),
				'qrFail'        => __( 'A QR code could not be created. Please use the link instead.', 'homeward-rescue' ),
				/* translators: %s: animal name, inserted by JavaScript. */
				'shareHeading' => __( 'Share %s\'s page', 'homeward-rescue' ),
				/* translators: %s: animal name, inserted by JavaScript. */
				'shareSubject' => __( 'Meet %s', 'homeward-rescue' ),
				/* translators: %s: animal name, inserted by JavaScript. */
				'qrLabel' => __( 'QR code for %s\'s page', 'homeward-rescue' ),
				/* translators: %s: configured singular animal noun. */
				'qrHint' => sprintf(
					__( 'Scan to meet this %s. Save the code for an adoption poster.', 'homeward-rescue' ),
					Settings::get( 'noun_singular' )
				),
				/* translators: %d: number of active filters, inserted by JavaScript. */
				'activeFilters' => __( '(%d active)', 'homeward-rescue' ),
				/* translators: %d: number of active filters, inserted by JavaScript. */
				'filtersActive' => __( 'Filters, %d active', 'homeward-rescue' ),
				'close' => __( 'Close photo viewer', 'homeward-rescue' ),
				'prev'  => __( 'Previous photo', 'homeward-rescue' ),
				'next'  => __( 'Next photo', 'homeward-rescue' ),
				/* translators: 1: current photo number, 2: total photo count. */
				'counter' => __( 'Photo %1$d of %2$d', 'homeward-rescue' ),
			)
		);
	}

	/**
	 * Enqueue a registered component script.
	 *
	 * @param string $name Component script suffix.
	 * @return void
	 */
	public static function script( $name ) {
		wp_enqueue_script( 'homeward-' . $name );
	}

	/**
	 * Enqueue the shared component styles and configuration.
	 *
	 * @return void
	 */
	public static function assets() {
		wp_enqueue_style( 'homeward' );
		wp_enqueue_script( 'homeward-config' );
	}

	/**
	 * Enqueue assets required by a specific block.
	 *
	 * @param string $name Block name without the namespace.
	 * @return void
	 */
	public static function block_assets( $name ) {
		self::assets();
		if ( in_array( $name, array( 'animal-grid', 'animal-carousel', 'animal-profile' ), true ) ) {
			self::script( 'animals' );
		}
		if ( 'donate-panel' === $name ) {
			self::script( 'donate' );
		}
		if ( str_starts_with( $name, 'story-' ) ) {
			self::story_assets();
		}
	}

	/**
	 * Enqueue story interactions and the shared dialog.
	 *
	 * @return void
	 */
	public static function story_assets() {
		self::assets();
		self::script( 'stories' );
		if ( ! has_action( 'wp_footer', array( Content_Render::class, 'story_dialog' ) ) ) {
			add_action( 'wp_footer', array( Content_Render::class, 'story_dialog' ), 5 );
		}
	}

	/**
	 * Apply animal visibility rules to public WordPress queries.
	 *
	 * @param \WP_Query $query Query being prepared.
	 * @return void
	 */
	public static function public_query( $query ) {
		if ( is_admin() || ! $query->is_main_query() || $query->is_singular() ) {
			return;
		}
		if ( ! $query->is_search() && ! $query->is_feed() && ! $query->is_post_type_archive( Animal_Post_Type::TYPE ) ) {
			return;
		}
		$args = Animal_Post_Type::rest_search( array( 'post__not_in' => (array) $query->get( 'post__not_in' ) ) );
		if ( isset( $args['post__not_in'] ) ) {
			$query->set( 'post__not_in', $args['post__not_in'] );
		}
	}

	/**
	 * Enqueue assets needed by the current archive or single page.
	 *
	 * @return void
	 */
	public static function enqueue() {
		$post = get_queried_object();
		$content = $post instanceof \WP_Post ? $post->post_content : '';
		if ( is_singular(
			array(
				Animal_Post_Type::TYPE,
				Content_Post_Types::STORY,
				Content_Post_Types::EVENT,
			)
			) || is_post_type_archive(
				array(
					Animal_Post_Type::TYPE,
					Content_Post_Types::STORY,
					Content_Post_Types::EVENT,
				)
			) || self::needs_assets( $content ) ) {
			self::assets();
		}
	}

	private static function needs_assets( $content, $seen = array() ) {
		if ( str_contains( $content, 'wp:homeward/' ) || preg_match(
			'/\[homeward_(animals|carousel|stories|events|donate)\b/',
			$content
		) ) {
			return true;
		}
		$blocks = parse_blocks( $content );
		while ( $blocks ) {
			$block = array_shift( $blocks );
			$blocks = array_merge( $blocks, $block['innerBlocks'] ?? array() );
			if ( 'core/block' === ( $block['blockName'] ?? '' ) && ( $id = (int) ( $block['attrs']['ref'] ?? 0 ) )
				&& ! in_array( $id, $seen, true ) ) {
				$post = get_post( $id );
				if ( $post && self::needs_assets( $post->post_content, array_merge( $seen, array( $id ) ) ) ) {
					return true;
				}
			}
		}
		return false;
	}

	/**
	 * Return a not-found response for nonpublic animal records.
	 *
	 * @return void
	 */
	public static function placeholder_404() {
		if ( ! is_singular( Animal_Post_Type::TYPE ) ) {
			return;
		}
		if ( ! Animal::public_readable( get_queried_object_id() ) ) {
			global $wp_query;
			$wp_query->set_404();
			status_header( 404 );
			nocache_headers();
		}
	}

	/**
	 * Replace rescue post content with its public component view.
	 *
	 * @param string $content Existing post content.
	 * @return string
	 */
	public static function content( $content ) {
		if ( ! is_singular() || ! in_the_loop() || ! is_main_query() ) {
			return $content;
		}
		$id = get_the_ID();
		if ( post_password_required( $id ) ) {
			return get_the_password_form( $id );
		}
		$type = get_post_type( $id );
		if ( Content_Post_Types::STORY === $type ) {
			return '<div class="homeward-kit">' . Content_Render::story_text_html( $id ) . Testimonials::render_linked( $id )
				. '</div>';
		}
		if ( Content_Post_Types::EVENT === $type ) {
			$out = '<div class="homeward-kit"><p>' . esc_html( Content_Render::event_when( $id ) ) . '</p><p>'
				. esc_html( get_post_meta( $id, 'homeward_location', true ) ) . '<br>'
				. esc_html( get_post_meta( $id, 'homeward_address', true ) ) . '</p>'
				. wpautop( esc_html( get_post_meta( $id, 'homeward_description', true ) ) );
			$link = Settings::https( get_post_meta( $id, 'homeward_link', true ) );
			if ( $link ) {
				$out .= '<p><a class="homeward-animal-btn" href="' . esc_url( $link ) . '">'
					. esc_html__( 'Tickets and sign-up', 'homeward-rescue' ) . '</a></p>';
			}
			return $out . '</div>';
		}
		return $content;
	}
}
