<?php
/**
 * Staff-only kennel cards and adoption posters.
 *
 * @package Homeward
 */
namespace Homeward;

defined( 'ABSPATH' ) || exit;

/** Connect print views to animal profiles and the admin list. */
class Kennel_Print {
	/** Register print routes and list controls. */
	public static function init() {
		add_filter( 'template_include', array( __CLASS__, 'template' ), 99 );
		add_filter( 'post_row_actions', array( __CLASS__, 'row_actions' ), 10, 2 );
		add_filter( 'bulk_actions-edit-' . Animal_Post_Type::TYPE, array( __CLASS__, 'bulk_actions' ) );
		add_filter( 'handle_bulk_actions-edit-' . Animal_Post_Type::TYPE, array( __CLASS__, 'bulk' ), 10, 3 );
		add_action( 'restrict_manage_posts', array( __CLASS__, 'list_button' ), 10, 2 );
		add_action( 'admin_post_homeward_print', array( __CLASS__, 'admin_page' ) );
	}

	/** Return a profile's print URL. */
	public static function url( $id ) {
		return add_query_arg( array( 'homeward_print' => '1' ), get_permalink( $id ) );
	}

	/** Return the staff print link. */
	public static function link( $id ) {
		return current_user_can( 'edit_posts' ) ? '<a class="homeward-print-link" href="'
			. esc_url( self::url( $id ) ) . '">' . esc_html__( 'Print kennel card', 'homeward-rescue' ) . '</a>' : '';
	}

	/** Use a standalone template for the profile query-var route. */
	public static function template( $template ) {
		if ( ! isset( $_GET['homeward_print'] ) || ! is_singular( Animal_Post_Type::TYPE ) ) {
			return $template;
		}
		self::authorize();
		nocache_headers();
		return HOMEWARD_DIR . 'templates/kennel-print.php';
	}

	/** Require the same staff capability on every entry point. */
	private static function authorize() {
		if ( ! current_user_can( 'edit_posts' ) ) {
			wp_die( esc_html__( 'You cannot print animal cards.', 'homeward-rescue' ), '', array( 'response' => 403 ) );
		}
	}

	/** Add the animal row action. */
	public static function row_actions( $actions, $post ) {
		if ( Animal_Post_Type::TYPE === $post->post_type && 'publish' === $post->post_status ) {
			$link = self::link( $post->ID );
			if ( $link ) {
				$actions['homeward_print'] = $link;
			}
		}
		return $actions;
	}

	/** Offer printing for selected rows. */
	public static function bulk_actions( $actions ) {
		if ( current_user_can( 'edit_posts' ) ) {
			$actions['homeward_print'] = __( 'Print kennel cards', 'homeward-rescue' );
		}
		return $actions;
	}

	/** Validate the list form before issuing a signed print destination. */
	public static function bulk( $redirect, $action, $ids ) {
		if ( 'homeward_print' !== $action ) {
			return $redirect;
		}
		self::authorize();
		$nonce = $_REQUEST['_wpnonce'] ?? '';
		if ( ! is_string( $nonce ) || ! wp_verify_nonce( $nonce, 'bulk-posts' ) ) {
			wp_die( esc_html__( 'The print request expired. Please try again.', 'homeward-rescue' ) );
		}
		return self::admin_url( $ids );
	}

	/** Sign the exact list of rows to print. */
	private static function admin_url( $ids ) {
		$ids = implode( ',', array_unique( array_map( 'absint', $ids ) ) );
		return add_query_arg(
			array(
				'action'   => 'homeward_print',
				'ids'      => $ids,
				'_wpnonce' => wp_create_nonce( 'homeward_print:' . $ids ),
			),
			admin_url( 'admin-post.php' )
		);
	}

	/** Print available animals from the current visible, filtered admin page. */
	public static function list_button( $type, $which ) {
		global $wp_query;
		if ( Animal_Post_Type::TYPE !== $type || 'top' !== $which || ! current_user_can( 'edit_posts' ) ) {
			return;
		}
		$ids = array();
		foreach ( $wp_query->posts as $post ) {
			$animal = self::animal( $post->ID );
			if ( $animal && $animal['listed'] && 'available' === $animal['status'] ) {
				$ids[] = $post->ID;
			}
		}
		if ( $ids ) {
			echo '<a class="button" href="' . esc_url( self::admin_url( $ids ) ) . '">'
				. esc_html__( 'Print all available', 'homeward-rescue' ) . '</a>';
		}
	}

	/** Validate a signed admin request and return printable animals. */
	public static function admin_animals( $request ) {
		self::authorize();
		$ids = $request['ids'] ?? '';
		$nonce = $request['_wpnonce'] ?? '';
		if ( ! is_string( $ids ) || ! preg_match( '/^\d+(?:,\d+)*$/D', $ids )
			|| ! is_string( $nonce ) || ! wp_verify_nonce( $nonce, 'homeward_print:' . $ids ) ) {
			wp_die( esc_html__( 'The print request expired. Please try again.', 'homeward-rescue' ) );
		}
		return array_values( array_filter( array_map( array( __CLASS__, 'animal' ), explode( ',', $ids ) ) ) );
	}

	/** Exclude private, protected and placeholder records from printable material. */
	public static function animal( $id ) {
		$post = get_post( (int) $id );
		if ( ! $post || Animal_Post_Type::TYPE !== $post->post_type || 'publish' !== $post->post_status
			|| Animal::protected_post( $post ) ) {
			return null;
		}
		$animal = Animal::get( $post );
		return $animal && ! $animal['placeholder'] ? $animal : null;
	}

	/** Serve the signed bulk or visible-list request. */
	public static function admin_page() {
		$animals = self::admin_animals( $_GET );
		nocache_headers();
		echo self::page( $animals ); // Escaped by the renderer.
		exit;
	}

	/** Render a standalone document without theme navigation or headers. */
	public static function page( $animals ) {
		self::authorize();
		$out = '<!doctype html><html lang="' . esc_attr( get_bloginfo( 'language' ) ) . '"><head>'
			. '<meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">'
			. '<meta name="robots" content="noindex,nofollow"><title>'
			. esc_html__( 'Print kennel cards', 'homeward-rescue' ) . '</title>';
		foreach ( array( 'homeward', 'kennel-print' ) as $file ) {
			$out .= '<link rel="stylesheet" href="' . esc_url( HOMEWARD_URL . 'assets/css/' . $file . '.css' ) . '">';
		}
		$out .= '<style>' . self::brand_css() . '</style>';
		foreach ( array( 'qr', 'kennel-print' ) as $file ) {
			$out .= '<script defer src="' . esc_url( HOMEWARD_URL . 'assets/js/' . $file . '.js' ) . '"></script>';
		}
		$out .= '</head><body class="homeward-kit homeward-print" data-layout="half" data-paper="letter">'
			. self::controls() . '<main>';
		if ( ! $animals ) {
			$out .= '<p>' . esc_html__( 'No printable animals selected.', 'homeward-rescue' ) . '</p>';
		}
		$single = 1 === count( $animals );
		if ( $single ) {
			$animals[] = $animals[ 0 ];
		}
		foreach ( array_chunk( $animals, 2 ) as $sheet ) {
			$out .= '<div class="homeward-print-sheet">';
			foreach ( $sheet as $i => $animal ) {
				$out .= self::card( $animal, $single && 1 === $i );
			}
			$out .= '</div>';
		}
		return $out . '</main></body></html>';
	}

	/** Apply the kit's palette and locally bundled heading/body fonts. */
	private static function brand_css() {
		$css = Brand::css();
		foreach ( array( 'heading', 'body' ) as $role ) {
			$slug = Brand::font_slug( $role );
			$font = Fonts::all()[ $slug ];
			foreach ( Fonts::preview_faces( $slug ) as $face ) {
				$css .= '@font-face{font-family:"' . $font['name'] . '";src:url("'
					. esc_url( HOMEWARD_URL . 'assets/fonts/' . $face['file'] ) . '") format("woff2");font-weight:'
					. $face['weight'] . ';font-display:swap;}';
			}
			$css .= '.homeward-print{--homeward-' . $role . '-font:' . Brand::font_stack( $role ) . ';}';
		}
		return $css;
	}

	/** Render accessible layout, paper and print controls. */
	private static function controls() {
		$out = '<div class="homeward-print-controls">';
		$choices = array(
			'layout' => array(
				__( 'Layout', 'homeward-rescue' ),
				array(
					'half'   => __( 'Half page kennel cards', 'homeward-rescue' ),
					'poster' => __( 'Full page adoption poster', 'homeward-rescue' ),
				),
			),
			'paper' => array( __( 'Paper', 'homeward-rescue' ), array( 'letter' => __( 'Letter', 'homeward-rescue' ),
				'a4' => __( 'A4', 'homeward-rescue' ) ) ),
		);
		foreach ( $choices as $key => $choice ) {
			$out .= '<label>' . esc_html( $choice[ 0 ] ) . ' <select data-print-' . esc_attr( $key ) . '>';
			foreach ( $choice[ 1 ] as $value => $label ) {
				$out .= '<option value="' . esc_attr( $value ) . '">' . esc_html( $label ) . '</option>';
			}
			$out .= '</select></label> ';
		}
		$out .= '<button type="button" data-print-button disabled>'
			. esc_html__( 'Print', 'homeward-rescue' ) . '</button><p data-print-status data-qr-error="'
			. esc_attr__( 'A QR code could not be created. The page link is printed instead.', 'homeward-rescue' ) . '">'
			. esc_html__( 'Preparing local QR codes. JavaScript is required.', 'homeward-rescue' ) . '</p>';
		return $out . '<p>' . esc_html__(
			'Choose matching paper in the print dialog and turn off browser headers and footers.',
			'homeward-rescue'
		) . '</p></div>';
	}

	/** Use an optional normalized focal point, otherwise favour the upper third. */
	private static function photo_position( $id ) {
		$point = get_post_meta( $id, 'homeward_focal_point', true );
		if ( ! is_array( $point ) ) {
			$point = get_post_meta( get_post_thumbnail_id( $id ), 'homeward_focal_point', true );
		}
		if ( ! is_array( $point ) || ! is_numeric( $point['x'] ?? null ) || ! is_numeric( $point['y'] ?? null ) ) {
			return '50% 33%';
		}
		return ( max( 0, min( 1, (float) $point['x'] ) ) * 100 ) . '% '
			. ( max( 0, min( 1, (float) $point['y'] ) ) * 100 ) . '%';
	}

	/** Render one animal using escaped public fields and a local QR placeholder. */
	private static function card( $animal, $copy ) {
		$id = $animal['id'];
		$logo = Settings::get( 'logo_url' );
		$logo_id = Settings::get( 'logo_id' ) ?: get_theme_mod( 'custom_logo' );
		if ( $logo_id ) {
			$logo = wp_get_attachment_url( $logo_id ) ?: $logo;
		}
		$out = '<article class="homeward-print-card' . ( $copy ? ' homeward-print-copy' : '' ) . '"><header>';
		if ( $logo ) {
			$out .= '<img class="homeward-print-logo" src="' . esc_url( $logo ) . '" alt="">';
		}
		$out .= '<strong>' . esc_html( Settings::get( 'org_name' ) ) . '</strong></header>';
		$photo = $animal['photos'][ 0 ]['full'] ?? $animal['thumb'];
		if ( $photo ) {
			$out .= '<img class="homeward-print-photo" src="' . esc_url( $photo ) . '" alt="'
				. esc_attr( $animal['name'] ) . '" style="object-position:' . esc_attr( self::photo_position( $id ) ) . '">';
		}
		$out .= '<div class="homeward-print-info"><h1>' . esc_html( $animal['name'] ) . '</h1><p>'
			. esc_html( implode( ' · ', array_filter( array( $animal['age'] ?: $animal['age_group'],
				$animal['sex'], $animal['size'], $animal['breed'] ) ) ) ) . '</p>' . Render::chips( $animal );
		$fee = Render::money( $animal['fee'] );
		if ( '' !== $fee ) {
			$out .= '<p>' . esc_html__( 'Adoption fee', 'homeward-rescue' ) . ': ' . esc_html( $fee ) . '</p>';
		}
		$text = html_entity_decode( wp_strip_all_tags( get_post_field( 'post_content', $id ) ), ENT_QUOTES, 'UTF-8' );
		$line = wp_trim_words( $text, 22 );
		$out .= '<p class="homeward-print-description">' . esc_html( str_replace( "\u{2014}", '-', $line ) ) . '</p></div>';
		/* translators: %s: animal name. */
		$caption = sprintf( __( 'Scan to meet %s', 'homeward-rescue' ), $animal['name'] );
		return $out . '<figure class="homeward-print-qr"><img data-print-qr="' . esc_url( $animal['url'] )
			. '" data-print-qr-fallback="' . esc_url( add_query_arg( array( 'p' => $id ), home_url( '/' ) ) )
			. '" alt="' . esc_attr( $caption ) . '" width="128" height="128" hidden><figcaption>' . esc_html( $caption )
			. '</figcaption><a href="' . esc_url( $animal['url'] ) . '">' . esc_html( $animal['url'] )
			. '</a></figure></article>';
	}
}
