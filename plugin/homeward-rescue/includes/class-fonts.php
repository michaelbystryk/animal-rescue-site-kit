<?php
namespace Homeward;

defined( 'ABSPATH' ) || exit;

/**
 * Describe the bundled fonts, local files and curated pairings.
 */
class Fonts {
	const DEFAULT_HEADING = 'fraunces';
	const DEFAULT_BODY = 'inter';

	/**
	 * Return font names, categories, stacks and available faces.
	 *
	 * @return array
	 */
	public static function all() {
		return array_merge( self::serif_fonts(), self::sans_fonts() );
	}

	private static function serif_fonts() {
		$fallback = ', Georgia, serif';
		$std = array( '400-normal', '600-normal', '700-normal', '400-italic' );
		return array(
			'fraunces' => array(
				'name'     => 'Fraunces',
				'category' => 'serif',
				'stack'    => '"Fraunces"' . $fallback,
				'faces'    => $std,
				'variable' => '100 900',
			),
			'lora' => array(
				'name'     => 'Lora',
				'category' => 'serif',
				'stack'    => '"Lora"' . $fallback,
				'faces'    => $std,
				'variable' => '400 700',
			),
			'source-serif-4' => array(
				'name'     => 'Source Serif 4',
				'category' => 'serif',
				'stack'    => '"Source Serif 4"' . $fallback,
				'faces'    => $std,
				'variable' => '200 900',
			),
			'playfair-display' => array(
				'name'     => 'Playfair Display',
				'category' => 'serif',
				'stack'    => '"Playfair Display"' . $fallback,
				'faces'    => $std,
				'variable' => '400 900',
			),
			'dm-serif-display' => array(
				'name'     => 'DM Serif Display',
				'category' => 'serif',
				'stack'    => '"DM Serif Display"' . $fallback,
				'faces'    => array( '400-normal', '400-italic' ),
				'variable' => '',
			),
			'merriweather' => array(
				'name'     => 'Merriweather',
				'category' => 'serif',
				'stack'    => '"Merriweather"' . $fallback,
				'faces'    => $std,
				'variable' => '300 900',
			),
		);
	}

	private static function sans_fonts() {
		$fallback = ', system-ui, sans-serif';
		$std = array( '400-normal', '600-normal', '700-normal', '400-italic' );
		return array(
			'inter' => array(
				'name'     => 'Inter',
				'category' => 'sans',
				'stack'    => '"Inter"' . $fallback,
				'faces'    => $std,
				'variable' => '100 900',
			),
			'atkinson-hyperlegible' => array(
				'name'     => 'Atkinson Hyperlegible',
				'category' => 'sans',
				'stack'    => '"Atkinson Hyperlegible"' . $fallback,
				'faces'    => array( '400-normal', '700-normal', '400-italic' ),
				'variable' => '',
			),
			'nunito-sans' => array(
				'name'     => 'Nunito Sans',
				'category' => 'sans',
				'stack'    => '"Nunito Sans"' . $fallback,
				'faces'    => $std,
				'variable' => '200 1000',
			),
			'figtree' => array(
				'name'     => 'Figtree',
				'category' => 'sans',
				'stack'    => '"Figtree"' . $fallback,
				'faces'    => $std,
				'variable' => '300 900',
			),
			'work-sans' => array(
				'name'     => 'Work Sans',
				'category' => 'sans',
				'stack'    => '"Work Sans"' . $fallback,
				'faces'    => $std,
				'variable' => '100 900',
			),
			'nunito' => array(
				'name'     => 'Nunito',
				'category' => 'sans',
				'stack'    => '"Nunito"' . $fallback,
				'faces'    => $std,
				'variable' => '200 1000',
			),
		);
	}

	/**
	 * Return all bundled font identifiers.
	 *
	 * @return string[]
	 */
	public static function slugs() {
		return array_keys( self::all() );
	}

	/**
	 * Check whether a font identifier belongs to the catalog.
	 *
	 * @param mixed $slug Candidate font identifier.
	 * @return bool
	 */
	public static function is_valid( $slug ) {
		return is_string( $slug ) && isset( self::all()[ $slug ] );
	}

	/**
	 * Group font choices by serif or sans category.
	 *
	 * @return array
	 */
	public static function grouped() {
		$out = array( 'serif' => array(), 'sans' => array() );
		foreach ( self::all() as $slug => $font ) {
			$out[ $font['category'] ][ $slug ] = $font['name'];
		}
		return $out;
	}

	/**
	 * Return the display label for a font category.
	 *
	 * @param string $category Catalog category.
	 * @return string
	 */
	public static function category_label( $category ) {
		return 'serif' === $category
			? __( 'Serif (classic, with small feet)', 'homeward-rescue' ) : __( 'Sans serif (clean, no feet)', 'homeward-rescue' );
	}

	/**
	 * Return the theme files for a bundled font.
	 *
	 * @param string $slug Font identifier.
	 * @return array
	 */
	public static function theme_files( $slug ) {
		$font = self::all()[ $slug ] ?? null;
		if ( ! $font ) {
			return array();
		}
		$files = array();
		foreach ( $font['faces'] as $face ) {
			$files[] = $slug . '-latin-' . $face . '.woff2';
		}
		if ( $font['variable'] ) {
			$files[] = $slug . '-latin-wght-normal.woff2';
		}
		return $files;
	}

	/**
	 * Return the font faces needed for setup previews.
	 *
	 * @param string $slug Font identifier.
	 * @return array
	 */
	public static function preview_faces( $slug ) {
		$font = self::all()[ $slug ] ?? null;
		if ( ! $font ) {
			return array();
		}
		if ( $font['variable'] ) {
			return array(
				array(
					'file'   => $slug . '-latin-wght-normal.woff2',
					'weight' => $font['variable'],
					'style'  => 'normal',
				),
			);
		}
		$faces = array();
		foreach ( array( '400', '700' ) as $weight ) {
			if ( in_array( $weight . '-normal', $font['faces'], true ) ) {
				$faces[] = array(
					'file'   => $slug . '-latin-' . $weight . '-normal.woff2',
					'weight' => $weight,
					'style'  => 'normal',
				);
			}
		}
		return $faces;
	}

	/**
	 * Return the curated heading and body font pairings.
	 *
	 * @return array
	 */
	public static function pairings() {
		return array(
			'warm-classic' => array(
				'name'    => __( 'Warm and classic', 'homeward-rescue' ),
				'heading' => 'fraunces',
				'body'    => 'inter',
			),
			'friendly-round' => array(
				'name'    => __( 'Friendly and round', 'homeward-rescue' ),
				'heading' => 'nunito',
				'body'    => 'nunito-sans',
			),
			'easy-to-read' => array(
				'name'    => __( 'Easy to read', 'homeward-rescue' ),
				'heading' => 'atkinson-hyperlegible',
				'body'    => 'atkinson-hyperlegible',
			),
			'bold-modern' => array(
				'name'    => __( 'Bold and modern', 'homeward-rescue' ),
				'heading' => 'work-sans',
				'body'    => 'figtree',
			),
			'elegant' => array(
				'name'    => __( 'Elegant', 'homeward-rescue' ),
				'heading' => 'playfair-display',
				'body'    => 'source-serif-4',
			),
			'storybook' => array(
				'name'    => __( 'Storybook', 'homeward-rescue' ),
				'heading' => 'dm-serif-display',
				'body'    => 'figtree',
			),
			'trusted-and-steady' => array(
				'name'    => __( 'Trusted and steady', 'homeward-rescue' ),
				'heading' => 'merriweather',
				'body'    => 'work-sans',
			),
			'soft-and-bookish' => array(
				'name'    => __( 'Soft and bookish', 'homeward-rescue' ),
				'heading' => 'lora',
				'body'    => 'nunito',
			),
		);
	}

	/**
	 * Find the pairing that matches two chosen fonts.
	 *
	 * @param string $heading Heading font identifier.
	 * @param string $body    Body font identifier.
	 * @return string
	 */
	public static function pairing_for( $heading, $body ) {
		foreach ( self::pairings() as $key => $pair ) {
			if ( $pair['heading'] === $heading && $pair['body'] === $body ) {
				return $key;
			}
		}
		return '';
	}

	/**
	 * Build local font-face rules for setup previews.
	 *
	 * @param string $base_url Base URL of the bundled preview fonts.
	 * @return string
	 */
	public static function preview_css( $base_url ) {
		$css = '';
		foreach ( self::all() as $slug => $font ) {
			foreach ( self::preview_faces( $slug ) as $face ) {
				$css .= '@font-face{font-family:"' . $font['name'] . '";src:url("' . esc_url_raw(
					trailingslashit( $base_url ) . $face['file']
				) . '") format("woff2");font-weight:' . $face['weight'] . ';font-style:' . $face['style'] . ';font-display:swap;}';
			}
		}
		return $css;
	}
}
