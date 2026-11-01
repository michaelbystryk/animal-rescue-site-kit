<?php
/**
 * Settings-owned redirects, relative to the site's home URL.
 */
namespace Homeward;

defined( 'ABSPATH' ) || exit;

/**
 * Validate and apply exact redirects from legacy page addresses.
 */
class Redirects {
	/**
	 * Register the redirect request handler.
	 *
	 * @return void
	 */
	public static function init() {
		add_action( 'parse_request', array( __CLASS__, 'maybe_redirect' ), 0 );
	}

	/**
	 * Normalize a local redirect source path.
	 *
	 * @param mixed $path Candidate path.
	 * @return string|null
	 */
	public static function path( $path ) {
		$path = rawurldecode( (string) $path );
		if ( preg_match( '~[\x00-\x20\x7f\\\\?#:%]|//|(?:^|/)\.{1,2}(?:/|$)~', $path ) ) {
			return null;
		}
		return '/' . trim( strtolower( $path ), '/' ) . ( '' === trim( $path, '/' ) ? '' : '/' );
	}

	/**
	 * Validate a local or secure external redirect target.
	 *
	 * @param string $target Candidate destination.
	 * @return bool
	 */
	public static function valid_target( $target ) {
		if ( '' === $target || preg_match( '/[\x00-\x20\x7f\\\\]/', $target )
			|| preg_match( '/[\x00-\x1f\x7f\\\\]/', rawurldecode( $target ) ) ) {
			return false;
		}
		$parts = wp_parse_url( $target );
		if ( false === $parts || isset( $parts['user'] ) || isset( $parts['pass'] ) ) {
			return false;
		}
		if ( ! isset( $parts['host'] ) ) {
			return ! isset( $parts['scheme'] ) && str_starts_with( $target, '/' ) && ! str_starts_with( $target, '//' )
				&& null !== self::path( $parts['path'] ?? '' );
		}
		$scheme = strtolower( $parts['scheme'] ?? '' );
		return filter_var( $target, FILTER_VALIDATE_URL ) && ( 'https' === $scheme || ( 'http' === $scheme
			&& self::same_site( $parts ) ) ) && null !== self::path( $parts['path'] ?? '/' );
	}

	private static function same_site( $parts ) {
		$home = wp_parse_url( home_url( '/' ) );
		$host = static fn ( $url ) => rtrim( strtolower( $url['host'] ?? '' ), '.' );
		$port = static function ( $url ) {
			$default = 'https' === strtolower( $url['scheme'] ?? '' ) ? 443 : 80;
			return ( $url['port'] ?? $default ) === $default ? null : $url['port'];
		};
		return $host( $parts ) === $host( $home ) && $port( $parts ) === $port( $home );
	}

	/**
	 * Local target matching key, or null for a destination outside this installation.
	 */
	private static function local_path( $target ) {
		$parts = wp_parse_url( $target );
		$path = $parts['path'] ?? '/';
		if ( isset( $parts['host'] ) ) {
			// HTTP to HTTPS on the same host must also be checked for redirect cycles.
			$home = wp_parse_url( home_url( '/' ) );
			if ( ! self::same_site( $parts ) ) {
				return null;
			}
			$base = rtrim( $home['path'] ?? '', '/' );
			if ( '' !== $base && $path !== $base && ! str_starts_with( $path, $base . '/' ) ) {
				return null;
			}
			$path = substr( $path, strlen( $base ) );
		}
		return self::path( $path );
	}

	/**
	 * Parse redirect lines and report invalid or cyclic rules.
	 *
	 * @param string $text   Redirect definitions.
	 * @param array  $errors Collected validation errors.
	 * @return array
	 */
	public static function parse( $text, &$errors = array() ) {
		$errors = array();
		$map = array();
		foreach ( preg_split( '/\r\n|\r|\n/', is_string( $text ) ? $text : '' ) as $i => $line ) {
			$line = trim( $line );
			if ( '' === $line ) {
				continue;
			}
			$fields = preg_split( '/\s+/', $line );
			$old = self::path( $fields[0] );
			if ( count( $fields ) < 2 || count( $fields ) > 3 || null === $old || ! self::valid_target( $fields[1] )
				|| ! in_array( $fields[2] ?? '301', array( '301', '302' ), true ) || isset( $map[ $old ] ) ) {
				$errors[] = $i + 1;
				continue;
			}
			$map[ $old ] = array( 'target' => $fields[1], 'status' => (int) ( $fields[2] ?? 301 ), 'line' => $i + 1 );
		}
		$invalid = array();
		foreach ( $map as $old => $rule ) {
			$seen = array();
			$next = $old;
			while ( null !== $next && isset( $map[ $next ] ) ) {
				if ( isset( $seen[ $next ] ) ) {
					$invalid[] = $old;
					break;
				}
				$seen[ $next ] = true;
				$next = self::local_path( $map[ $next ]['target'] );
			}
		}
		foreach ( $invalid as $old ) {
			$errors[] = $map[ $old ]['line'];
			unset( $map[ $old ] );
		}
		foreach ( $map as &$rule ) {
			unset( $rule['line'] );
		}
		unset( $rule );
		sort( $errors );
		return $map;
	}

	/**
	 * Normalize redirect definitions and report settings errors.
	 *
	 * @param mixed $text Submitted redirect definitions.
	 * @return string
	 */
	public static function sanitize( $text ) {
		$map = self::parse( $text, $errors );
		if ( $errors ) {
			/* translators: %s: comma-separated line numbers. */
			add_settings_error(
				'homeward_settings',
				'redirects',
				sprintf(
					__( 'Invalid or looping redirect lines were omitted: %s.', 'homeward-rescue' ),
					implode( ', ', $errors )
				)
			);
		}
		$lines = array();
		foreach ( $map as $old => $rule ) {
			$lines[] = $old . ' ' . $rule['target'] . ' ' . $rule['status'];
		}
		return implode( "\n", $lines );
	}

	/**
	 * Find a redirect and retain permitted query parameters.
	 *
	 * @param string     $path  Requested local path.
	 * @param array      $query Request query parameters.
	 * @param array|null $rules Rules to use, or saved rules.
	 * @return array|null
	 */
	public static function match( $path, $query = array(), $rules = null ) {
		$rules = $rules ?? self::parse( Settings::get( 'redirects' ) );
		$rule = $rules[ self::path( $path ) ?? '' ] ?? null;
		if ( ! $rule ) {
			return null;
		}
		$keep = array_filter(
			array_intersect_key(
				$query,
				array_flip(
					array(
						'utm_source',
						'utm_medium',
						'utm_campaign',
						'utm_content',
						'utm_term',
						'fbclid',
						'gclid',
					)
				)
			),
			'is_scalar'
		);
		$target = $rule['target'];
		$pet = $query['pet_id'] ?? '';
		if ( self::local_path( $target ) === self::path( '/' . Settings::get( 'archive_slug' ) ) && is_scalar( $pet )
			&& preg_match( '/^[0-9]+$/D', (string) $pet ) && (int) $pet > 0 ) {
			$ids = get_posts(
				array(
					'post_type'      => Animal_Post_Type::TYPE,
					'post_status'    => 'publish',
					'posts_per_page' => 1,
					'fields'         => 'ids',
					'no_found_rows'  => true,
					'meta_key'       => Animal::M_PS_ID,
					'meta_value'     => (int) $pet,
				)
			);
			if ( $ids && Animal::public_readable( $ids[0] ) ) {
				$link = get_permalink( $ids[0] );
				if ( self::local_path( $link ) !== self::path( $path ) ) {
					$target = $link;
				}
			}
		}
		$fragment = '';
		if ( false !== ( $pos = strpos( $target, '#' ) ) ) {
			$fragment = substr( $target, $pos );
			$target = substr( $target, 0, $pos );
		}
		parse_str( (string) wp_parse_url( $target, PHP_URL_QUERY ), $existing );
		$keep = array_diff_key( $keep, $existing );
		if ( $keep ) {
			$target .= ( str_contains( $target, '?' ) ? '&' : '?' ) . http_build_query( $keep );
		}
		$rule['target'] = $target . $fragment;
		return $rule;
	}

	/**
	 * Strip the installation directory from a request path.
	 *
	 * @param string $uri Request URI.
	 * @return string|null
	 */
	public static function request_path( $uri ) {
		$path = (string) wp_parse_url( $uri, PHP_URL_PATH );
		$base = rtrim( (string) wp_parse_url( home_url( '/' ), PHP_URL_PATH ), '/' );
		if ( '' !== $base && $path !== $base && ! str_starts_with( $path, $base . '/' ) ) {
			return null;
		}
		return substr( $path, strlen( $base ) );
	}

	/**
	 * Apply a matching redirect to a public GET or HEAD request.
	 *
	 * @return void
	 */
	public static function maybe_redirect() {
		if ( is_admin() || ! in_array( $_SERVER['REQUEST_METHOD'] ?? 'GET', array( 'GET', 'HEAD' ), true ) ) {
			return;
		}
		$uri = wp_unslash(
			$_SERVER['REQUEST_URI'] ?? ''
		); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		$path = self::request_path( $uri );
		parse_str( (string) wp_parse_url( $uri, PHP_URL_QUERY ), $query );
		$rule = null === $path ? null : self::match( $path, $query );
		if ( ! $rule ) {
			return;
		}
		$target = str_starts_with( $rule['target'], '/' ) ? home_url( $rule['target'] ) : $rule['target'];
		// phpcs:ignore WordPress.Security.SafeRedirect.wp_redirect_wp_redirect -- Destinations were validated when parsed.
		wp_redirect( $target, $rule['status'], 'Homeward Rescue' );
		exit;
	}
}
