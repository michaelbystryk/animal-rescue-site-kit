<?php
/**
 * All Petstablished API access. The API is undocumented, so everything that
 * knows its URLs and response shapes lives here.
 *
 * @package Homeward
 */
namespace Homeward;

defined( 'ABSPATH' ) || exit;

/**
 * Fetch complete public animal listings and details from Petstablished.
 */
class Source_Petstablished implements Source {
	const BASE = 'https://petstablished.com/api/v2/public/';
	const MAX_PAGES = 40;
	private static $deadline = 0.0;
	private $probe_shelter;
	/**
	 * Choose the shelter number for this source instance.
	 *
	 * @param int|null $shelter_id Shelter number override.
	 * @return void
	 */
	public function __construct( $shelter_id = null ) {
		$this->probe_shelter = $shelter_id;
	}

	/**
	 * Build a public profile address for a source animal.
	 *
	 * @param int $id Source animal ID.
	 * @return string
	 */
	public static function profile_url( $id ) {
		return $id ? 'https://petstablished.com/public/search/pet/' . absint( $id ) : '';
	}

	/**
	 * Build a supported adoption or foster application address.
	 *
	 * @param string $type Application type.
	 * @param int    $id   Source animal ID.
	 * @return string
	 */
	public static function application_url( $type, $id ) {
		return $id && in_array( $type, array( 'adopt', 'foster' ), true )
			? 'https://petstablished.com/public/search/redirect?application_type=' . $type . '&pet_id=' . absint( $id )
			. '&premium=true' : '';
	}

	/**
	 * Shelter id (filterable).
	 *
	 * @return int
	 */
	public static function shelter_id() {
		return (int) Settings::get( 'shelter_id' );
	}

	/**
	 * Is the keyed /pets endpoint configured?
	 *
	 * @return bool
	 */
	public static function has_key() {
		return '' !== Settings::key();
	}

	/**
	 * Human label for the current source.
	 *
	 * @return string
	 */
	public function label() {
		return self::has_key()
			? __( 'Keyed /pets endpoint (configured key)', 'homeward-rescue' ) : __( 'Public shelter listing (no key)', 'homeward-rescue' );
	}

	/**
	 * Every pet in the shelter, as raw arrays. All pages or nothing.
	 *
	 * @return array|\WP_Error
	 */
	public function fetch_all() {
		self::$deadline = microtime( true ) + 120;
		$shelter_id = null === $this->probe_shelter ? self::shelter_id() : (int) $this->probe_shelter;
		if ( $shelter_id < 1 ) {
			return new \WP_Error( 'homeward_config', __( 'Set a shelter ID before syncing.', 'homeward-rescue' ) );
		}
		if ( null !== $this->probe_shelter || ! self::has_key() ) {
			return self::fetch_public( $shelter_id );
		}
		// An Available-only feed must never mark Pending/Hold dogs as adopted.
		$pets = array();
		foreach ( array( 'Available', 'Pending Adoption', 'Hold' ) as $status ) {
			$list = self::fetch_keyed( $status );
			if ( is_wp_error( $list ) ) {
				return $list;
			}
			foreach ( $list as $pet ) {
				$pets[ (int) $pet['id'] ] = $pet;
			}
		}
		return array_values( $pets );
	}

	/**
	 * Detail record for one pet.
	 *
	 * @param int $id Petstablished pet id.
	 * @return array|\WP_Error
	 */
	public function fetch_detail( $id ) {
		self::$deadline = microtime( true ) + 25;
		$data = self::get_json( self::BASE . 'search/pet/' . absint( $id ) );
		if ( is_wp_error( $data ) ) {
			return $data;
		}
		if ( empty( $data['pet'] ) || ! self::valid_pet( $data['pet'] ) || (int) $data['pet']['id'] !== (int) $id ) {
			return new \WP_Error( 'homeward_shape', __( 'Unexpected detail response (no "pet").', 'homeward-rescue' ) );
		}
		return $data['pet'];
	}

	/**
	 * No-key listing: search/shelter_show/{shelter}?page=N until shelter_pets_total_page.
	 *
	 * @return array|\WP_Error
	 */
	private static function fetch_public( $shelter_id ) {
		$pets = array();
		$total = 1;
		for ( $page = 1; $page <= $total; $page++ ) {
			$data = self::get_json( self::BASE . 'search/shelter_show/' . $shelter_id . '?page=' . $page );
			if ( is_wp_error( $data ) ) {
				return $data;
			}
			if ( ! isset( $data['shelter_pets'] ) || ! is_array( $data['shelter_pets'] ) ) {
				return new \WP_Error( 'homeward_shape', __( 'Unexpected listing response (no "shelter_pets").', 'homeward-rescue' ) );
			}
			$declared = $data['shelter_pets_total_page'] ?? null;
			if ( ! ( is_int( $declared ) || is_string( $declared ) ) || ! ctype_digit( (string) $declared )
				|| (int) $declared < 0 || (int) $declared > self::MAX_PAGES || ( $page > 1 && (int) $declared !== $total ) ) {
				return self::incomplete();
			}
			if ( 0 === (int) $declared && $data['shelter_pets'] ) {
				return self::incomplete();
			}
			$total = max( 1, (int) $declared );
			if ( ! $data['shelter_pets'] && $total > 1 ) {
				return self::incomplete();
			}
			foreach ( $data['shelter_pets'] as $pet ) {
				if ( ! self::valid_pet( $pet ) || isset( $pets[ (int) $pet['id'] ] ) ) {
					return self::incomplete();
				}
				$pets[ (int) $pet['id'] ] = $pet;
			}
		}
		return array_values( $pets );
	}

	/**
	 * Keyed endpoint: pets?public_key=KEY&pagination[limit]=100&pagination[page]=N.
	 * Response shape is not confirmed yet, so accept the likely containers.
	 *
	 * @return array|\WP_Error
	 */
	private static function fetch_keyed( $status ) {
		$pets = array();
		$limit = 100;
		$declared_pages = null;
		for ( $page = 1; $page <= self::MAX_PAGES; $page++ ) {
			$url = add_query_arg(
				array(
					'public_key'        => rawurlencode( Settings::key() ),
					'pagination[limit]' => $limit,
					'pagination[page]'  => $page,
					'search[status]'    => rawurlencode( $status ),
				),
				self::BASE . 'pets'
			);
			$data = self::get_json( $url );
			if ( is_wp_error( $data ) ) {
				return $data;
			}
			$list = null;
			foreach ( array( 'collection', 'pets', 'data', 'results' ) as $k ) {
				if ( isset( $data[ $k ] ) && is_array( $data[ $k ] ) ) {
					$list = $data[ $k ];
					break;
				}
			}
			if ( null === $list && array_is_list( $data ) ) {
				$list = $data;
			}
			if ( null === $list ) {
				return new \WP_Error( 'homeward_shape', __( 'Unexpected response from the keyed /pets endpoint.', 'homeward-rescue' ) );
			}
			foreach ( $list as $pet ) {
				if ( ! self::valid_pet( $pet ) || isset( $pets[ (int) $pet['id'] ] ) ) {
					return self::incomplete();
				}
				$pets[ (int) $pet['id'] ] = $pet;
			}
			if ( ! isset( $data['pagination'] ) || ! is_array( $data['pagination'] ) ) {
				return self::incomplete();
			}
			$declared = $data['pagination']['total_pages'] ?? $data['pagination']['pages'] ?? null;
			if ( ! ( is_int( $declared ) || is_string( $declared ) ) || ! ctype_digit( (string) $declared ) ) {
				return self::incomplete();
			}
			$pages = (int) $declared;
			if ( ( 0 === $pages && $list ) || $pages < 0 || ( null !== $declared_pages && $pages !== $declared_pages ) ) {
				return self::incomplete();
			}
			$declared_pages = $pages;
			if ( $pages > self::MAX_PAGES || ( $pages > 1 && ! $list ) ) {
				return self::incomplete();
			}
			if ( ( $pages && $page >= $pages ) || ( ! $pages && count( $list ) < $limit ) ) {
				return array_values( $pets );
			}
		}
		return self::incomplete();
	}

	/**
	 * Check the minimum structure required for a source record.
	 *
	 * @param mixed $pet Candidate source record.
	 * @return bool
	 */
	public static function valid_pet( $pet ) {
		return is_array( $pet ) && isset( $pet['id'], $pet['name'], $pet['status'] ) && ( is_int( $pet['id'] )
			|| is_string( $pet['id'] ) ) && ctype_digit( (string) $pet['id'] ) && false !== filter_var(
			$pet['id'],
			FILTER_VALIDATE_INT,
			array( 'options' => array( 'min_range' => 1 ) )
		) && is_string( $pet['name'] ) && '' !== trim( $pet['name'] ) && is_string( $pet['status'] )
			&& '' !== trim( $pet['status'] );
	}

	private static function incomplete() {
		return new \WP_Error( 'homeward_incomplete', __( 'Incomplete or inconsistent Petstablished feed; existing animals were kept.', 'homeward-rescue' ) );
	}

	/**
	 * GET a URL and decode JSON, with a timeout and readable errors.
	 *
	 * @param string $url URL.
	 * @return array|\WP_Error
	 */
	private static $last_request = 0.0;
	private static function get_json( $url ) {
		$until = (int) get_option( 'homeward_backoff_until', 0 );
		if ( $until > time() ) {
			return new \WP_Error( 'homeward_backoff', __( 'Source is cooling down; retry later.', 'homeward-rescue' ) );
		}
		$wait = 1.0 - ( microtime( true ) - self::$last_request );
		if ( $wait > 0 ) {
			usleep( (int) ceil( $wait * 1000000 ) );
		}
		if ( self::$deadline && microtime( true ) + 20 > self::$deadline ) {
			return self::incomplete();
		}
		self::$last_request = microtime( true );
		$res = wp_remote_get(
			$url,
			apply_filters(
				'homeward_request_args',
				array(
					'timeout'             => 20,
					'redirection'         => 0,
					'user-agent'          => 'Homeward Rescue sync/' . HOMEWARD_VERSION . '; ' . home_url( '/' ),
					'headers'             => array( 'Accept' => 'application/json' ),
					'limit_response_size' => 8 * 1024 * 1024,
				),
				self::redact( $url )
			)
		);
		$safe = self::redact( $url );
		if ( is_wp_error( $res ) ) {
			/* translators: %s: redacted source URL. */
			// Do not persist arbitrary transport messages (which may contain keys or bodies).
			return new \WP_Error( 'homeward_http', sprintf( __( 'Request failed (%s).', 'homeward-rescue' ), $safe ) );
		}
		$code = (int) wp_remote_retrieve_response_code( $res );
		if ( 429 === $code || $code >= 500 ) {
			$retry = wp_remote_retrieve_header( $res, 'retry-after' );
			$seconds = is_numeric( $retry ) ? (int) $retry : max( 0, (int) strtotime( (string) $retry ) - time() );
			$failures = min( 6, (int) get_option( 'homeward_http_failures', 0 ) + 1 );
			update_option( 'homeward_http_failures', $failures, false );
			update_option( 'homeward_backoff_until', time() + max( 60 * ( 2 ** $failures ), min( 86400, $seconds ) ), false );
		} elseif ( 200 === $code ) {
			delete_option( 'homeward_http_failures' );
		}
		if ( 200 !== $code ) {
			/* translators: 1: HTTP status, 2: URL. */
			return new \WP_Error( 'homeward_http', sprintf( __( 'HTTP %1$d from %2$s', 'homeward-rescue' ), $code, $safe ) );
		}
		$data = json_decode( wp_remote_retrieve_body( $res ), true );
		if ( ! is_array( $data ) ) {
			/* translators: %s: URL. */
			return new \WP_Error( 'homeward_json', sprintf( __( 'Invalid JSON from %s', 'homeward-rescue' ), $safe ) );
		}
		return $data;
	}

	/**
	 * Remove the public key from URLs shown in messages.
	 *
	 * @param string $url URL.
	 * @return string
	 */
	public static function redact( $url ) {
		return preg_replace( '/(public_key|api_key|key)(?:=|%3D)[^&\s]+/i', '$1=[redacted]', $url );
	}
}
