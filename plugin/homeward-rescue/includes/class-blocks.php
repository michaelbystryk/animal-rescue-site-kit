<?php
namespace Homeward;

defined( 'ABSPATH' ) || exit;

/**
 * Register and render the rescue blocks and their shortcode aliases.
 */
class Blocks {
	const NAMES = array(
		'animal-grid',
		'animal-carousel',
		'animal-profile',
		'apply-button',
		'story-tabs',
		'story-cards',
		'story-feature',
		'upcoming-events',
		'donate-panel',
		'contact-form',
	);
	/**
	 * Register block definitions and the editor script.
	 *
	 * @return void
	 */
	public static function register() {
		wp_register_script(
			'homeward-editor',
			HOMEWARD_URL . 'assets/js/editor.js',
			array(
				'wp-blocks',
				'wp-element',
				'wp-block-editor',
				'wp-components',
				'wp-i18n',
				'wp-server-side-render',
			),
			HOMEWARD_VERSION,
			true
		);
		wp_set_script_translations( 'homeward-editor', 'homeward-rescue', HOMEWARD_DIR . 'languages' );
		foreach ( self::NAMES as $name ) {
			register_block_type( HOMEWARD_DIR . 'blocks/' . $name );
		}
	}

	/**
	 * Render a named block with its assets and wrapper.
	 *
	 * @param string         $name    Block name without the namespace.
	 * @param array          $attrs   Block attributes.
	 * @param \WP_Block|null $block   Block context.
	 * @param string|null    $wrapper Prebuilt wrapper attributes.
	 * @return string
	 */
	public static function render( $name, $attrs = array(), $block = null, $wrapper = null ) {
		if ( 'contact-form' === $name ) {
			return Contact_Form::render( $attrs );
		}
		$id = (int) ( $block->context['postId'] ?? get_the_ID() );
		$html = '';
		if ( 'animal-grid' === $name ) {
			$html = self::animal_grid( $attrs );
		} elseif ( 'animal-carousel' === $name ) {
			$html = self::animal_carousel( $attrs );
		} elseif ( 'animal-profile' === $name ) {
			$animal = Animal::public_readable( $id ) ? Animal::get( $id ) : null;
			if ( $animal && 'publish' === get_post( $id )->post_status ) {
				$html = Render::animal_page( $animal, get_post( $id ), $attrs );
			}
		} elseif ( 'apply-button' === $name ) {
			$html = Applications::button( $attrs['type'] ?? 'adopt', get_post_type( $id ) === Animal_Post_Type::TYPE ? $id : 0 );
		} elseif ( 'story-tabs' === $name ) {
			$html = Content_Render::tabs();
		} elseif ( 'story-feature' === $name ) {
			$html = Content_Render::stories_feature( array( 'count' => 1 ) );
		} elseif ( 'story-cards' === $name ) {
			$args = array(
				'count'    => max( 1, min( 100, (int) ( $attrs['count'] ?? 3 ) ) ),
				'kind'     => $attrs['kind'] ?? 'story',
				'featured' => ! empty( $attrs['featured'] ),
			);
			$html = 'feature' === ( $attrs['layout'] ?? 'grid' )
				? Content_Render::stories_feature( $args ) : Content_Render::stories_grid( $args );
		} elseif ( 'upcoming-events' === $name ) {
			$count = max( 1, min( 100, (int) ( $attrs['count'] ?? 3 ) ) );
			$html = Content_Render::events_grid( 'upcoming', $count, __( 'No upcoming events yet.', 'homeward-rescue' ) );
			if ( ! empty( $attrs['showPast'] ) ) {
				$html .= '<h2>' . esc_html__( 'Past events', 'homeward-rescue' ) . '</h2>'
					. Content_Render::events_grid( 'past', $count );
			}
		} elseif ( 'donate-panel' === $name ) {
			$html = Donations::render( $attrs );
		}
		if ( '' === $html ) {
			return '';
		}
		Frontend::block_assets( $name );
		$wrapper = $wrapper ?? 'class="' . esc_attr( self::wrapper_class( $attrs ) ) . '"';
		return '<div ' . $wrapper . '>' . $html . '</div>';
	}

	private static function animal_grid( $attrs ) {
		$args = array(
			'limit'    => max( 0, min( 1000, (int) ( $attrs['limit'] ?? 0 ) ) ),
			'featured' => ! empty( $attrs['featured'] ),
			'species'  => $attrs['species'] ?? '',
			'origin'   => $attrs['region'] ?? '',
			'status'   => (array) ( $attrs['status'] ?? array() ),
		);
		$section = 'section' === Settings::get( 'courtesy_mode' ) && ( $attrs['courtesySection'] ?? true );
		if ( 'section' === Settings::get( 'courtesy_mode' ) && ! $section ) {
			$args['courtesy'] = false;
		}
		$html = Render::listing(
			Animal::query( $args ),
			array(
				'filters'     => $attrs['filters'] ?? true,
				'breedFilter' => $attrs['breedFilter'] ?? true,
				'courtesy'    => $section ? Animal::query( array_merge( $args, array( 'courtesy' => true ) ) ) : array(),
			)
		);
		return $html;
	}

	private static function animal_carousel( $attrs ) {
		$limit = max( 1, min( 24, (int) ( $attrs['limit'] ?? 8 ) ) );
		$source = $attrs['source'] ?? 'featured';
		$animals = 'featured' === $source ? Animal::carousel( $limit ) : Animal::query();
		if ( 'newest' === $source ) {
			usort( $animals, fn ( $a, $b ) => array( $b['first_synced'], $b['id'] ) <=> array( $a['first_synced'], $a['id'] ) );
		}
		// Provider has no foster flag in its public contract: use an explicit editorial marker.
		if ( 'needs-foster' === $source ) {
			$animals = array_values(
				array_filter(
					$animals,
					fn ( $a ) => (bool) get_post_meta( $a['id'], 'homeward_needs_foster', true )
				)
			);
		}
		$html = Render::carousel(
			array_slice( $animals, 0, $limit ),
			array(
				'heading' => ! empty( $attrs['heading'] ) ? $attrs['heading'] : __( 'Ready for home', 'homeward-rescue' ),
			)
		);
		return $html;
	}

	/**
	 * Build the block wrapper classes from column settings.
	 *
	 * @param array $attrs Block attributes.
	 * @return string
	 */
	public static function wrapper_class( $attrs ) {
		return 'homeward-kit homeward-animals-block homeward-block homeward-columns-'
			. max( 1, min( 6, (int) ( $attrs['columns'] ?? 4 ) ) );
	}

	/**
	 * Register shortcode aliases with normalized attributes.
	 *
	 * @return void
	 */
	public static function shortcodes() {
		foreach ( array(
			'animals'  => 'animal-grid',
			'carousel' => 'animal-carousel',
			'stories'  => 'story-cards',
			'events'   => 'upcoming-events',
			'donate'   => 'donate-panel',
			) as $short => $block ) {
			add_shortcode(
				'homeward_' . $short,
				function ( $atts ) use ( $block ) {
					$atts = (array) $atts;
					foreach ( array( 'filters', 'featured', 'courtesySection', 'breedFilter', 'showPast' ) as $key ) {
						if ( isset( $atts[ strtolower( $key ) ] ) ) {
							$atts[ $key ] = filter_var( $atts[ strtolower( $key ) ], FILTER_VALIDATE_BOOLEAN );
						}
					}
					foreach ( array( 'status', 'methods' ) as $key ) {
						if ( isset( $atts[ $key ] ) && is_string( $atts[ $key ] ) ) {
							$atts[ $key ] = array_map( 'trim', explode( ',', $atts[ $key ] ) );
						}
					}
					return self::render( $block, $atts );
				}
			);
		}
	}
}
