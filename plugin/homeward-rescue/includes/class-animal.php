<?php
/**
 * Animal model: meta keys, effective values (synced data + volunteer overrides),
 * view models and cached listings.
 *
 * @package Homeward
 */
namespace Homeward;

defined( 'ABSPATH' ) || exit;

/**
 * Build public animal views from source data and volunteer overrides.
 */
class Animal {
	/**
	 * Check whether a record requires a password.
	 *
	 * @param int|\WP_Post $id Post ID or object.
	 * @return bool
	 */
	public static function protected_post( $id ) {
		$post = get_post( $id );
		return $post && '' !== (string) ( $post->post_password ?? '' );
	}

	/**
	 * Check whether an animal is managed without a source feed.
	 *
	 * @param int $id Animal post ID.
	 * @return bool
	 */
	public static function manual( $id ) {
		return 'manual' === get_post_meta( $id, self::M_ADAPTER, true ) || ! get_post_meta( $id, self::M_PS_ID, true );
	}

	// Synced (written by Sync only).
	const M_SOURCE = '_homeward_source';
	const M_ADAPTER = '_homeward_adapter';
	private static $batch_depth = 0;
	private static $longest_cache = null;
	private static $badge_cache = array();
	const M_SPECIES = '_homeward_species';
	const M_DERIVED_BADGE = '_homeward_badge';
	const M_SORT = '_homeward_sort';
	const M_PS_ID = '_homeward_ps_id';
	const M_DATA = '_homeward_data';
	const M_STATUS = '_homeward_status';
	const M_SYNCED = '_homeward_synced';
	const M_FIRST_SYNCED = '_homeward_first_synced';
	const M_DETAIL = '_homeward_detail';
	const M_GONE = '_homeward_gone';
	const M_CREATED = '_homeward_created';
	/** When the sync saw a known animal come back to available after adoption, a hold or delisting. */
	const M_RELISTED = '_homeward_relisted';
	// Overrides (written by volunteers only; sync never touches them).
	const M_NAME = 'homeward_display_name';
	const M_ORIGIN = 'homeward_region';
	const M_SHOW = 'homeward_visibility';
	const M_COURTESY = 'homeward_courtesy';
	const M_FEATURED = 'homeward_featured';
	const M_BLURB = 'homeward_blurb';
	const M_WEIGHT = 'homeward_sort_weight';
	const M_BADGE = 'homeward_badge_override';
	// Derived (recomputed from the two above).
	const M_LISTED = '_homeward_listed';
	const M_IS_COURT = '_homeward_is_courtesy';
	const M_ORIGIN_FX = '_homeward_origin_effective';
	const CACHE_KEY = 'homeward_listed_v4';

	/**
	 * Invalidate animal caches when records or settings change.
	 *
	 * @return void
	 */
	public static function init() {
		add_action( 'save_post_' . Animal_Post_Type::TYPE, array( __CLASS__, 'flush' ) );
		add_action( 'deleted_post', array( __CLASS__, 'flush' ) );
		add_action( 'update_option_homeward_settings', array( __CLASS__, 'flush' ) );
	}

	/**
	 * Show courtesy listings (setting, default off)?
	 *
	 * @return bool
	 */
	public static function show_courtesy() {
		return 'hide' !== Settings::get( 'courtesy_mode' );
	}

	/**
	 * Meta query for animals shown on the site.
	 *
	 * @param bool $include_courtesy Include courtesy listings.
	 * @return array
	 */
	public static function listed_meta_query( $include_courtesy ) {
		$q = array( 'relation' => 'AND', array( 'key' => self::M_LISTED, 'value' => '1', ), );
		if ( ! $include_courtesy ) {
			$q[] = array( 'key' => self::M_IS_COURT, 'value' => '0', );
		}
		return $q;
	}

	/**
	 * Stored synced data for a post.
	 *
	 * @param int $post_id Post id.
	 * @return array
	 */
	public static function data( $post_id ) {
		$d = get_post_meta( $post_id, self::M_DATA, true );
		return is_array( $d ) ? $d : array();
	}

	/**
	 * Effective display name.
	 *
	 * @param int $post_id Post id.
	 * @return string
	 */
	public static function name( $post_id ) {
		$o = trim( (string) get_post_meta( $post_id, self::M_NAME, true ) );
		if ( '' !== $o ) {
			return $o;
		}
		$d = self::data( $post_id );
		return isset( $d['raw_name'] ) ? Pet_Data::clean_name( $d['raw_name'] ) : get_the_title( $post_id );
	}

	/**
	 * Recompute visibility, origin and species from current settings.
	 *
	 * @param int $post_id Animal post ID.
	 * @return void
	 */
	public static function refresh_derived( $post_id ) {
		$d = self::data( $post_id );
		$raw = (string) ( $d['raw_name'] ?? '' );
		$status = (string) get_post_meta( $post_id, self::M_STATUS, true );
		$status = $status ? $status : 'hold';
		$show_o = (string) get_post_meta( $post_id, self::M_SHOW, true );
		if ( Pet_Data::is_placeholder( $raw ) && 'publish' === get_post_status( $post_id ) ) {
			wp_update_post( array( 'ID' => $post_id, 'post_status' => 'draft' ) );
		}
		$auto = ! Pet_Data::is_placeholder( $raw ) && ! in_array( $status, Settings::get( 'hidden_statuses' ), true );
		$show = 'show' === $show_o ? true : ( 'hide' === $show_o ? false : $auto );
		$listed = ! Pet_Data::is_placeholder( $raw ) && $show && 'gone' !== $status && in_array(
			$d['species'] ?? self::default_species(),
			Settings::get( 'species' ),
			true
		);
		$court_o = (string) get_post_meta( $post_id, self::M_COURTESY, true );
		$courtesy = 'yes' === $court_o ? true : ( 'no' === $court_o ? false : Pet_Data::is_courtesy( $raw ) );
		$origin = trim( (string) get_post_meta( $post_id, self::M_ORIGIN, true ) );
		if ( '' === $origin ) {
			$origin = Pet_Data::map_origin( $raw );
		}
		if ( $courtesy && ! self::show_courtesy() ) {
			$listed = false;
		}
		update_post_meta( $post_id, self::M_SORT, (int) get_post_meta( $post_id, self::M_WEIGHT, true ) );
		update_post_meta( $post_id, self::M_LISTED, $listed ? '1' : '0' );
		update_post_meta( $post_id, self::M_IS_COURT, $courtesy ? '1' : '0' );
		update_post_meta( $post_id, self::M_ORIGIN_FX, $origin );
		update_post_meta( $post_id, self::M_SPECIES, $d['species'] ?? self::default_species() );
		self::flush();
	}

	/**
	 * View model for templates and JSON.
	 *
	 * @param int|\WP_Post $post    Post.
	 * @param bool         $urgency Include the current listing's urgency badge.
	 * @return array|null
	 */
	public static function get( $post, $urgency = true ) {
		$post = get_post( $post );
		if ( ! $post || self::protected_post( $post ) || Animal_Post_Type::TYPE !== $post->post_type ) {
			return null;
		}
		do_action( 'homeward_view_model_build', $post->ID );
		$animal = self::view_model( $post );
		$animal = self::public_view( $animal );
		$animal = $urgency ? self::urgency_badge( $animal, self::longest_waiting_ids() ) : $animal;
		$animal['badge'][ 0 ] = Settings::get( 'badge_labels' )[ $animal['badge'][ 1 ] ] ?? $animal['badge'][ 0 ];
		return self::cover_view( $animal );
	}

	private static function view_model( $post ) {
		$id = $post->ID;
		$d = self::data( $id );
		$name = self::name( $id );
		$status = (string) get_post_meta( $id, self::M_STATUS, true );
		$status = $status ? $status : 'hold';
		$origin = (string) get_post_meta( $id, self::M_ORIGIN_FX, true );
		$photos = self::photos( $d );
		$thumb = ! empty( $d['thumb_url'] ) ? $d['thumb_url'] : ( $photos[ 0 ]['thumb'] ?? '' );
		$special = ! empty( $d['has_special_need'] ) || '' !== trim( (string) ( $d['special_needs'] ?? '' ) );
		$bonded = ! empty( $d['is_bonded'] );
		$youtube = Pet_Data::youtube_id( (string) ( $d['youtube_url'] ?? '' ) );
		if ( '' === $youtube ) {
			$youtube = Pet_Data::youtube_in_html( $post->post_content );
		}
		$badge = self::status_badge( $id, $status, $special, $bonded );
		$created = (int) get_post_meta( $id, self::M_CREATED, true );
		$first_synced = (int) get_post_meta( $id, self::M_FIRST_SYNCED, true );
		// Legacy animal posts were created by their first sync, never by the last refresh.
		$first_synced = $first_synced ?: (int) get_post_time( 'U', true, $post );
		// Intake: the date staff enter (date_aquired), else the received date, else the created date, else our first sync.
		$intake = Pet_Data::date_timestamp( $d['date_aquired'] ?? '' )
			?: Pet_Data::date_timestamp( $d['date_received'] ?? '' )
			?: Pet_Data::date_timestamp( $d['created_at'] ?? '' ) ?: $first_synced;
		$relisted = (int) get_post_meta( $id, self::M_RELISTED, true );
		// A relisted animal waits from its return, not from an intake date that may be years old.
		$waiting_since = max( $intake, $relisted );
		$blurb = trim( (string) get_post_meta( $id, self::M_BLURB, true ) );
		$species = get_post_meta( $id, self::M_SPECIES, true ) ?: ( $d['species'] ?? self::default_species() );
		$animal = array_merge(
			array(
				'id'            => $id,
				'species'       => (string) $species,
				'ps_id'         => (int) get_post_meta( $id, self::M_PS_ID, true ),
				'name'          => $name,
				'url'           => get_permalink( $id ),
				'status'        => $status,
				'raw_status'    => (string) ( $d['status'] ?? '' ),
				'listed'        => '1' === get_post_meta( $id, self::M_LISTED, true ),
				'courtesy'      => '1' === get_post_meta( $id, self::M_IS_COURT, true ),
				'placeholder'   => Pet_Data::is_placeholder( (string) ( $d['raw_name'] ?? '' ) ),
				'featured'      => (bool) get_post_meta( $id, self::M_FEATURED, true ),
				'sort_weight'   => (int) get_post_meta( $id, self::M_WEIGHT, true ),
				'created'       => $created ? $created : (int) get_post_time( 'U', true, $post ),
				'first_synced'  => $first_synced,
				'intake'        => $intake,
				'relisted'      => $relisted,
				'waiting_since' => $waiting_since,
			),
			self::profile_view( $d, $origin, $special, $bonded ),
			array(
				'photos'      => $photos,
				'thumb'       => $thumb,
				'adopt_url'   => Applications::url( 'adopt', $id, $d ),
				'youtube'     => $youtube,
				'blurb'       => $blurb,
				'personality' => Description::personality( $blurb, $post->post_content, $name ),
				'badge'       => $badge,
				'ps_url'      => Source_Petstablished::profile_url( $d['ps_id'] ?? 0 ),
			)
		);
		return $animal;
	}

	private static function profile_view( $d, $origin, $special, $bonded ) {
		return array(
			'age_group'    => (string) ( $d['age_group'] ?? '' ),
			'age'          => Pet_Data::age_label( (string) ( $d['dob'] ?? '' ), (string) ( $d['age_text'] ?? '' ) ),
			'sex'          => (string) ( $d['sex'] ?? '' ),
			'size'         => (string) ( $d['size'] ?? '' ),
			'weight'       => (string) ( $d['weight'] ?? '' ),
			'breed' => Pet_Data::breed_label( (string) ( $d['primary_breed'] ?? '' ), (string) ( $d['secondary_breed'] ?? '' ) ),
			'breed_family' => Pet_Data::breed_family( (string) ( $d['primary_breed'] ?? '' ) ),
			'breed_short'  => Pet_Data::breed_short(
				(string) ( $d['primary_breed'] ?? '' ),
				(string) ( $d['secondary_breed'] ?? '' )
			),
			'origin'           => $origin,
			'ok_dogs'          => $d['ok_dogs'] ?? null,
			'ok_cats'          => $d['ok_cats'] ?? null,
			'ok_kids'          => $d['ok_kids'] ?? null,
			'housebroken'      => $d['housebroken'] ?? null,
			'is_spayed'        => $d['is_spayed'] ?? null,
			'shots_up_to_date' => $d['shots_up_to_date'] ?? null,
			'special'          => $special,
			'special_needs'    => (string) ( $d['special_needs'] ?? '' ),
			'bonded'           => $bonded,
			'fee'              => (string) ( $d['adoption_fee'] ?? '' ),
		);
	}

	private static function photos( $d ) {
		$photos = array();
		foreach ( (array) ( $d['photos'] ?? array() ) as $p ) {
			if ( ! empty( $p['full'] ) ) {
				$photos[] = array( 'full' => $p['full'], 'thumb' => $p['thumb'] ?? $p['full'], );
			}
		}
		if ( ! $photos && ! empty( $d['thumb_url'] ) ) {
			$photos[] = array( 'full' => $d['thumb_url'], 'thumb' => $d['thumb_url'], );
		}
		return $photos;
	}

	private static function status_badge( $id, $status, $special, $bonded ) {
		$badge_o = trim( (string) get_post_meta( $id, self::M_BADGE, true ) );
		if ( '' !== $badge_o ) {
			$badge = array( $badge_o, 'custom' );
		} elseif ( 'pending' === $status && Settings::get( 'badge_pending' ) ) {
			$badge = array( __( 'Adoption pending', 'homeward-rescue' ), 'pending' );
		} elseif ( 'gone' === $status ) {
			$badge = array( __( 'Adopted', 'homeward-rescue' ), 'gone' );
		} elseif ( 'hold' === $status ) {
			$badge = array( __( 'On hold', 'homeward-rescue' ), 'hold' );
		} elseif ( $special && Settings::get( 'badge_special_needs' ) ) {
			$badge = array( __( 'Special needs', 'homeward-rescue' ), 'special' );
		} elseif ( $bonded && Settings::get( 'badge_bonded' ) ) {
			$badge = array( __( 'Bonded pair', 'homeward-rescue' ), 'bonded' );
		} else {
			$badge = array( __( 'Available', 'homeward-rescue' ), 'available' );
		}
		return $badge;
	}

	private static function public_view( $animal ) {
		$fields = Settings::public_fields();
		foreach ( array(
			'fee'                => array( 'fee' ),
			'weight'             => array( 'weight' ),
			'age'                => array( 'age', 'age_group' ),
			'breed'              => array( 'breed', 'breed_short', 'breed_family' ),
			'special_needs_text' => array( 'special_needs' ),
			'video'              => array( 'youtube' ),
			'compat'             => array( 'ok_dogs', 'ok_cats', 'ok_kids' ),
			'housebroken'        => array( 'housebroken' ),
			'is_spayed'          => array( 'is_spayed' ),
			'shots_up_to_date'   => array( 'shots_up_to_date' ),
			) as $field => $keys ) {
			if ( ! in_array( $field, $fields, true ) ) {
				foreach ( $keys as $key ) {
					$animal[ $key ] = is_bool( $animal[ $key ] ) || null === $animal[ $key ] ? null : '';
				}
			}
		}
		return $animal;
	}

	private static function cover_view( $animal ) {
		$id = $animal['id'];
		$cover = get_the_post_thumbnail_url( $id, 'large' );
		$demo_cover = in_array(
			get_post_meta( $id, self::M_ADAPTER, true ),
			array( 'fixture', 'demo' ),
			true
		) && str_starts_with(
			(string) get_post_meta( get_post_thumbnail_id( $id ), '_homeward_demo_key', true ),
			'image:'
		);
		if ( $cover && ( self::manual( $id ) || 'import_cover' === Settings::get( 'image_mode' ) || $demo_cover ) ) {
			$animal['thumb'] = $cover;
			if ( $demo_cover || self::manual( $id ) ) {
				// Offline detail refreshes keep the local demo illustration.
				$animal['photos'] = array( array( 'full' => $cover, 'thumb' => $cover ) );
			}
			if ( $animal['photos'] ) {
				$animal['photos'][ 0 ] = array( 'full' => $cover, 'thumb' => $cover );
			}
		}
		return $animal;
	}

	/**
	 * All animals listed on the site, sorted (featured, sort weight, newest). Cached.
	 *
	 * @return array[] View models.
	 */
	public static function all_listed() {
		$animals = self::listed_base();
		$longest = self::longest_waiting_ids( $animals );
		// Evaluate time-sensitive badges on every read, including cache hits.
		return array_map(
			function ( $animal ) use ( $longest ) {
				$animal = self::urgency_badge( $animal, $longest );
				$animal['badge'][ 0 ] = Settings::get( 'badge_labels' )[ $animal['badge'][ 1 ] ] ?? $animal['badge'][ 0 ];
				return $animal;
			},
			$animals
		);
	}

	/**
	 * Cache IDs only: privacy filters and effective values are re-evaluated on every read.
	 */
	private static function listed_base() {
		$ids = get_transient( self::CACHE_KEY );
		if ( ! is_array( $ids ) ) {
			$ids = get_posts(
				array(
					'post_type'        => Animal_Post_Type::TYPE,
					'post_status'      => 'publish',
					'posts_per_page'   => -1,
					'fields'           => 'ids',
					'no_found_rows'    => true,
					'suppress_filters' => false,
					'meta_query'       => self::listed_meta_query( true ),
					// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
				)
			);
			Settings::temporary( self::CACHE_KEY, $ids, 12 * HOUR_IN_SECONDS );
		}
		update_meta_cache( 'post', $ids );
		$animals = array();
		foreach ( $ids as $id ) {
			$animal = self::get( $id, false );
			if ( $animal ) {
				$animals[] = $animal;
			}
		}
		usort( $animals, array( __CLASS__, 'compare' ) );
		return $animals;
	}

	/**
	 * Whether a wait start is believable: not in the future and not older than the limit.
	 * Older dates usually mean an animal was adopted and relisted before relisting was tracked.
	 * Filter `homeward_max_waiting_years` (default 3).
	 *
	 * @param int $since Unix time.
	 * @return bool
	 */
	private static function plausible_wait( $since ) {
		$years = (float) apply_filters( 'homeward_max_waiting_years', 3 );
		return $since > 0 && $since <= time() && $since >= time() - (int) round( $years * 365.25 * DAY_IN_SECONDS );
	}

	/**
	 * Oldest 10% (rounded up) of visible, available animals; stable ties by id.
	 */
	private static function longest_waiting_ids( $animals = null ) {
		if ( null !== self::$longest_cache ) {
			return self::$longest_cache;
		}
		$animals = $animals ?? self::listed_base();
		$available = array_filter(
			$animals,
			fn ( $d ) => 'available' === $d['status'] && $d['listed'] && ( ! $d['courtesy'] || self::show_courtesy() )
			&& ( ! Settings::get( 'badge_long_wait_days' )
				|| time() - $d['waiting_since'] >= Settings::get( 'badge_long_wait_days' ) * DAY_IN_SECONDS )
			&& self::plausible_wait( $d['waiting_since'] )
		);
		usort(
			$available,
			fn ( $a, $b ) => array( $a['waiting_since'], $a['id'] ) <=> array( $b['waiting_since'], $b['id'] )
		);
		self::$longest_cache = array_column( array_slice( $available, 0, (int) ceil( count( $available ) / 10 ) ), 'id' );
		return self::$longest_cache;
	}

	/**
	 * One badge only; existing status, special-needs, bonded and override badges win.
	 */
	private static function urgency_badge( $animal, $longest ) {
		// Request-local memoization avoids caching private view-model fields across readers.
		if ( isset( self::$badge_cache[ $animal['id'] ] ) ) {
			$animal['badge'] = self::$badge_cache[ $animal['id'] ];
			return $animal;
		}
		if ( 'available' !== $animal['status'] || ! $animal['listed'] || 'available' !== $animal['badge'][ 1 ] ) {
			return $animal;
		}
		// "New" means new to the rescue: Petstablished's intake date, never a relisting.
		// When that falls back to our own first-sync time, ignore dogs present when tracking began,
		// so the first sync after install or launch doesn't mark every dog as new.
		$baseline = (int) get_option( 'homeward_badge_baseline', 0 );
		if ( ! $baseline ) {
			$baseline = time();
			add_option( 'homeward_badge_baseline', $baseline, '', false );
		}
		$arrived = (int) $animal['intake'];
		$fallback = $arrived === (int) $animal['first_synced'];
		$elapsed = time() - $arrived;
		$is_new = $arrived > 0 && $elapsed >= 0 && $elapsed < Settings::get( 'badge_new_days' ) * DAY_IN_SECONDS
			&& ( ! $fallback || $arrived > $baseline + 24 * HOUR_IN_SECONDS );
		if ( $is_new ) {
			$animal['badge'] = array( __( 'New', 'homeward-rescue' ), 'new' );
		} elseif ( in_array( $animal['id'], $longest, true ) ) {
			$animal['badge'] = array( __( 'Longest waiting', 'homeward-rescue' ), 'longest-waiting' );
		}
		self::$badge_cache[ $animal['id'] ] = $animal['badge'];
		return $animal;
	}

	/**
	 * Default ordering.
	 *
	 * @param array $a Animal.
	 * @param array $b Animal.
	 * @return int
	 */
	public static function compare( $a, $b ) {
		$first = array( (int) $b['featured'], $b['sort_weight'], $b['created'], $b['id'] );
		$second = array( (int) $a['featured'], $a['sort_weight'], $a['created'], $a['id'] );
		return $first <=> $second;
	}

	/**
	 * Listed animals with filters.
	 *
	 * @param array $args {
	 *     @type bool|null $courtesy Null follows settings; true selects courtesy; false excludes it.
	 *     @type bool   $featured Only featured animals.
	 *     @type string $origin   Region.
	 *     @type string $size     Size.
	 *     @type int[]  $exclude  Post ids to skip.
	 *     @type int    $limit    0 = all.
	 * }
	 * @return array[]
	 */
	public static function query( $args = array() ) {
		$args = wp_parse_args(
			$args,
			array(
				'courtesy' => null,
				'species'  => '',
				'status'   => array(),
				'featured' => false,
				'origin'   => '',
				'size'     => '',
				'exclude'  => array(),
				'limit'    => 0,
			)
		);
		$out = array();
		foreach ( self::all_listed() as $animal ) {
			if ( ( null !== $args['courtesy'] && (bool) $args['courtesy'] !== $animal['courtesy'] )
				|| ( null === $args['courtesy'] && 'show' !== Settings::get( 'courtesy_mode' ) && $animal['courtesy'] ) ) {
				continue;
			}
			if ( ( $args['species'] && $args['species'] !== $animal['species'] ) || ( $args['status']
				&& ! in_array( $animal['status'], (array) $args['status'], true ) ) ) {
				continue;
			}
			if ( $args['featured'] && ! $animal['featured'] ) {
				continue;
			}
			if ( $args['origin'] && 0 !== strcasecmp( $args['origin'], $animal['origin'] ) ) {
				continue;
			}
			if ( $args['size'] && 0 !== strcasecmp( $args['size'], $animal['size'] ) ) {
				continue;
			}
			if ( in_array( $animal['id'], (array) $args['exclude'], true ) ) {
				continue;
			}
			$out[] = $animal;
			if ( $args['limit'] && count( $out ) >= $args['limit'] ) {
				break;
			}
		}
		return $out;
	}

	/**
	 * Home carousel: featured animals, else the newest.
	 *
	 * @param int $limit Max animals.
	 * @return array[]
	 */
	public static function carousel( $limit = 8 ) {
		$featured = self::query( array( 'featured' => true, 'limit' => $limit, ) );
		if ( $featured ) {
			return $featured;
		}
		$all = self::query();
		usort(
			$all,
			function ( $a, $b ) {
				return array( $b['created'], $b['id'] ) <=> array( $a['created'], $a['id'] );
			}
		);
		return array_slice( $all, 0, $limit );
	}

	/**
	 * Post id for a Petstablished id (0 if none).
	 *
	 * @param int $ps_id Petstablished id.
	 * @return int
	 */
	public static function post_id_for( $ps_id ) {
		return self::ps_map()[ (int) $ps_id ] ?? 0;
	}

	/**
	 * Map source animal IDs to their local posts.
	 *
	 * @param int|null    $source  Shelter number, or the configured shelter.
	 * @param string|null $adapter Source adapter, or the configured adapter.
	 * @return array
	 */
	public static function ps_map( $source = null, $adapter = null ) {
		$map = array();
		$legacy = array();
		$source = $source ?? Settings::get( 'shelter_id' );
		$adapter = $adapter ?? Settings::get( 'source' );
		$ids = self::all_ids();
		update_meta_cache( 'post', $ids );
		foreach ( $ids as $id ) {
			if ( self::manual( $id ) ) {
				continue;
			}
			$stored_source = get_post_meta( $id, self::M_SOURCE, true );
			$stored_adapter = get_post_meta( $id, self::M_ADAPTER, true );
			// Legacy posts without a source are claimed only after a validated feed matches their ID.
			if ( ( '' === $stored_source || (int) $stored_source === (int) $source ) && ( '' === $stored_adapter
				|| $stored_adapter === $adapter ) && ( $ps = (int) get_post_meta( $id, self::M_PS_ID, true ) ) ) {
				if ( '' === $stored_source ) {
					$legacy[ $ps ] = $id;
				} else {
					$map[ $ps ] = $id;
				}
			}
		}
		return $map + $legacy;
	}

	/**
	 * Return all local animal post IDs.
	 *
	 * @return int[]
	 */
	public static function all_ids() {
		return get_posts(
			array(
				'post_type'      => Animal_Post_Type::TYPE,
				'post_status'    => array( 'publish', 'draft', 'pending', 'private', 'future', 'trash' ),
				'posts_per_page' => -1,
				'fields'         => 'ids',
				'no_found_rows'  => true,
			)
		);
	}

	/**
	 * Choose the fallback species for records without one.
	 *
	 * @return string
	 */
	public static function default_species() {
		$species = Settings::get( 'species' );
		return 1 === count( $species ) ? $species[ 0 ] : 'other';
	}

	/**
	 * Check publication, password and listing visibility.
	 *
	 * @param int $id Animal post ID.
	 * @return bool
	 */
	public static function public_readable( $id ) {
		return ! self::protected_post( $id ) && 'publish' === get_post_status( $id )
			&& ( '1' === get_post_meta( $id, self::M_LISTED, true ) || 'gone' === get_post_meta( $id, self::M_STATUS, true ) );
	}

	/**
	 * Defer cache invalidation during a group of writes.
	 *
	 * @return void
	 */
	public static function begin_batch() {
		++self::$batch_depth;
	}

	/**
	 * Flush deferred invalidation when the outer batch ends.
	 *
	 * @return void
	 */
	public static function end_batch() {
		self::$batch_depth = max( 0, self::$batch_depth - 1 );
		if ( 0 === self::$batch_depth ) {
			self::flush();
		}
	}

	/**
	 * Invalidate animal listing and badge caches.
	 *
	 * @return void
	 */
	public static function flush() {
		self::$longest_cache = null;
		self::$badge_cache = array();
		if ( self::$batch_depth ) {
			return;
		}
		delete_transient( self::CACHE_KEY );
	}
}
