<?php
namespace Homeward;

defined( 'ABSPATH' ) || exit;

/**
 * Share palette, font and contrast choices across the kit.
 */
class Brand {
	/**
	 * Register the theme design settings filter.
	 *
	 * @return void
	 */
	public static function init() {
		add_filter( 'wp_theme_json_data_theme', array( __CLASS__, 'filter_theme_json' ) );
	}

	/**
	 * Return the bundled font catalog.
	 *
	 * @return array
	 */
	public static function fonts() {
		return Fonts::all();
	}

	/**
	 * Resolve an individual font or a legacy pairing.
	 *
	 * @param string $role Heading or body role.
	 * @return string
	 */
	public static function font_slug( $role ) {
		$saved = (array) get_option( 'homeward_settings', array() );
		$key = 'font_' . $role;
		if ( isset( $saved[ $key ], self::fonts()[ $saved[ $key ] ] ) ) {
			return $saved[ $key ];
		}
		$pairs = array(
			'warm'     => array( 'fraunces', 'inter' ),
			'classic'  => array( 'lora', 'source-serif-4' ),
			'friendly' => array( 'nunito-sans', 'atkinson-hyperlegible' ),
			'modern'   => array( 'inter', 'inter' ),
		);
		$pair = $pairs[ $saved['font_pair'] ?? '' ] ?? array( 'fraunces', 'inter' );
		return $pair[ 'heading' === $role ? 0 : 1 ];
	}

	/**
	 * Return the CSS family stack for a text role.
	 *
	 * @param string $role Heading or body role.
	 * @return string
	 */
	public static function font_stack( $role ) {
		$saved = (array) get_option( 'homeward_settings', array() );
		if ( ! isset( $saved[ 'font_' . $role ] ) && 'system' === ( $saved['font_pair'] ?? '' ) ) {
			return 'system-ui, sans-serif';
		}
		return self::fonts()[ self::font_slug( $role ) ]['stack'];
	}

	/**
	 * Resolve saved colours with valid default fallbacks.
	 *
	 * @param array|null $settings Settings to preview, or saved settings.
	 * @return array
	 */
	public static function colors( $settings = null ) {
		$settings = $settings ?? Settings::get();
		$out = array();
		foreach ( array( 'primary', 'accent', 'ink', 'background', 'surface' ) as $slug ) {
			$key = 'color_' . $slug;
			$out[ $slug ] = sanitize_hex_color( Settings::text( $settings[ $key ] ?? '' ) ) ?: Settings::defaults()[ $key ];
		}
		return $out;
	}

	/**
	 * Replace role presets while preserving other theme settings.
	 *
	 * @param \WP_Theme_JSON_Data $theme_json Theme design data.
	 * @return \WP_Theme_JSON_Data
	 */
	public static function filter_theme_json( $theme_json ) {
		$data = $theme_json->get_data();
		$palette = $data['settings']['color']['palette']['theme'] ?? $data['settings']['color']['palette'] ?? array();
		$fonts = $data['settings']['typography']['fontFamilies']['theme'] ?? $data['settings']['typography']['fontFamilies']
			?? array();
		$colors = self::colors();
		$palette = array_values(
			array_filter(
				$palette,
				function ( $row ) use ( $colors ) {
					return ! isset( $colors[ $row['slug'] ?? '' ] );
				}
			)
		);
		foreach ( $colors as $slug => $color ) {
			$palette[] = array( 'slug' => $slug, 'name' => ucfirst( $slug ), 'color' => $color );
		}
		$fonts = array_values(
			array_filter(
				$fonts,
				function ( $row ) {
					return ! in_array( $row['slug'] ?? '', array( 'heading', 'body' ), true );
				}
			)
		);
		foreach ( array( 'heading', 'body' ) as $role ) {
			$fonts[] = array( 'slug' => $role, 'name' => ucfirst( $role ), 'fontFamily' => self::font_stack( $role ) );
		}
		return $theme_json->update_with(
			array(
				'version'  => 3,
				'settings' => array(
					'color'      => array( 'palette' => $palette ),
					'typography' => array( 'fontFamilies' => $fonts ),
				),
			)
		);
	}

	/**
	 * Evaluate the text and background combinations used by the theme.
	 *
	 * @param array $settings Colour settings to evaluate.
	 * @return array
	 */
	public static function contrast_report( $settings ) {
		$colors = self::colors( $settings );
		$checks = array();
		foreach ( array(
			array( 'ink', 'background' ),
			array( 'ink', 'surface' ),
			array( 'primary', 'background' ),
			array( 'ink', 'accent' ),
			) as $pair ) {
			$ratio = self::contrast( $colors[ $pair[0] ], $colors[ $pair[1] ] );
			$checks[] = array(
				'text'       => $pair[0],
				'background' => $pair[1],
				'ratio'      => round( $ratio, 2 ),
				'passes'     => $ratio >= 4.5,
			);
		}
		return $checks;
	}

	/**
	 * Calculate relative luminance for a hexadecimal colour.
	 *
	 * @param string $hex Hexadecimal colour.
	 * @return float|int
	 */
	public static function luminance( $hex ) {
		$hex = self::hex( $hex );
		$rgb = sscanf( $hex, '#%02x%02x%02x' );
		if ( 3 !== count( $rgb ) ) {
			return 0;
		}
		$rgb = array_map(
			function ( $v ) {
				$v /= 255;
				return $v <= 0.04045 ? $v / 12.92 : ( ( $v + 0.055 ) / 1.055 ) ** 2.4;
			},
			$rgb
		);
		return $rgb[ 0 ] * 0.2126 + $rgb[ 1 ] * 0.7152 + $rgb[ 2 ] * 0.0722;
	}

	/**
	 * Calculate the contrast ratio between two colours.
	 *
	 * @param string $a First colour.
	 * @param string $b Second colour.
	 * @return float
	 */
	public static function contrast( $a, $b ) {
		$a = self::luminance( $a );
		$b = self::luminance( $b );
		return ( max( $a, $b ) + 0.05 ) / ( min( $a, $b ) + 0.05 );
	}

	private static function hex( $hex ) {
		if ( preg_match( '/^#([a-f0-9])([a-f0-9])([a-f0-9])$/i', $hex, $m ) ) {
			return '#' . $m[ 1 ] . $m[ 1 ] . $m[ 2 ] . $m[ 2 ] . $m[ 3 ] . $m[ 3 ];
		}
		return preg_match( '/^#[a-f0-9]{6}$/i', $hex ) ? $hex : '#1F4E79';
	}

	private static function palette( $rows ) {
		$out = array();
		foreach ( (array) $rows as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}
			if ( isset( $row['slug'], $row['color'] ) ) {
				$out[ $row['slug'] ] = $row['color'];
			} else {
				$out = array_merge( $out, self::palette( $row ) );
			}
		}
		return $out;
	}

	/**
	 * Build the shared component colour variables.
	 *
	 * @return string
	 */
	public static function css() {
		$palette = function_exists( 'wp_get_global_settings' )
			? self::palette( wp_get_global_settings( array( 'color', 'palette' ) ) ) : array();
		$primary = self::hex(
			Settings::get( 'color_primary' ) ?: ( $palette['primary'] ?? $palette['accent-1'] ?? '#1F4E79' )
		);
		$rules = '';
		if ( $primary ) {
			$foreground = self::contrast( $primary, '#ffffff' ) >= 4.5 ? '#ffffff' : '#111111';
			// Body links need AA on white too; keep a separate dark text tone.
			$link = $primary;
			if ( self::contrast( $link, '#ffffff' ) < 4.5 ) {
				$link = '#111111';
			}
			$rules .= '--homeward-primary:' . $primary . ';--homeward-primary-foreground:' . $foreground . ';--homeward-link:'
				. $link . ';';
		}
		if ( $accent = sanitize_hex_color( Settings::get( 'color_accent' ) ) ) {
			$rules .= '--homeward-accent:' . $accent . ';';
		}
		return $rules ? ':where(.homeward-kit,.homeward-animal-lightbox,.homeward-story-dialog,.homeward-animal-share){'
			. $rules . '}' : '';
	}
}
