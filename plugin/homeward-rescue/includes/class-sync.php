<?php
namespace Homeward;

defined( 'ABSPATH' ) || exit;

/**
 * Reconcile source animals under a lease with review for large availability changes.
 */
class Sync {
	const OPT_STATUS = 'homeward_sync_status';
	const OPT_QUEUE = 'homeward_detail_queue';
	const BATCH = 5;
	const OPT_PENDING = 'homeward_sync_pending';
	const OPT_MISSING = 'homeward_sync_missing';

	/**
	 * Register sync review notices and the confirmation action.
	 *
	 * @return void
	 */
	public static function init() {
		add_action( 'admin_notices', array( __CLASS__, 'notice' ) );
		add_action( 'admin_post_homeward_confirm_sync', array( __CLASS__, 'confirm_action' ) );
	}

	private static function configuration_key() {
		return hash( 'sha256', wp_json_encode( Settings::get() ) );
	}

	/**
	 * Read a pending review only when its configuration still matches.
	 *
	 * @return array
	 */
	public static function pending() {
		$p = get_option( self::OPT_PENDING, array() );
		return is_array( $p ) && ( $p['configuration'] ?? null ) === self::configuration_key() ? $p : array();
	}

	/**
	 * Summarize the counts and matching runs in a pending review.
	 *
	 * @return string
	 */
	public static function pending_message() {
		$p = self::pending();
		if ( ! $p ) {
			return '';
		}
		$c = $p['counts'];
		return sprintf(
			__( 'Sync held for review: received %1$d, previously active %2$d, available %3$d, leaving available %4$d, missing %5$d. Matching runs: %6$d of %7$d.', 'homeward-rescue' ),
			$c['received'],
			$c['active'],
			$c['available'],
			$c['leaving'],
			$c['missing'],
			$p['runs'],
			$p['required']
		);
	}

	/**
	 * Build the capability-checked confirmation form for a pending review.
	 *
	 * @return string
	 */
	public static function confirm_form() {
		$p = self::pending();
		if ( ! $p || ! current_user_can( 'manage_options' ) ) {
			return '';
		}
		return '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) )
			. '"><input type="hidden" name="action" value="homeward_confirm_sync"><input type="hidden" name="token" value="'
			. esc_attr( $p['token'] ) . '"><input type="hidden" name="_wpnonce" value="'
			. esc_attr( wp_create_nonce( 'homeward_confirm_sync_' . $p['token'] ) ) . '"><button class="button" type="submit">'
			. esc_html__( 'Confirm and apply', 'homeward-rescue' ) . '</button></form>';
	}

	/**
	 * Display a pending sync review to administrators.
	 *
	 * @return void
	 */
	public static function notice() {
		if ( self::pending() && current_user_can( 'manage_options' ) ) {
			echo '<div class="notice notice-warning"><p>' . esc_html( self::pending_message() ) . '</p>' . self::confirm_form()
				. '</div>';
		}
	}

	/**
	 * Apply an authorized reviewed snapshot and return to settings.
	 *
	 * @return void
	 */
	public static function confirm_action() {
		$result = self::apply_pending(
			Settings::text( wp_unslash( $_POST['token'] ?? '' ) ),
			Settings::text( wp_unslash( $_POST['_wpnonce'] ?? '' ) )
		);
		if ( is_wp_error( $result ) ) {
			wp_die( esc_html( $result->get_error_message() ) );
		}
		wp_safe_redirect( admin_url( 'options-general.php?page=homeward' ) );
		exit;
	}

	/**
	 * Apply precisely the reviewed snapshot under the sync lease.
	 *
	 * @param string $token Reviewed snapshot token.
	 * @param string $nonce Confirmation nonce.
	 * @return array|\WP_Error
	 */
	public static function apply_pending( $token, $nonce ) {
		$p = self::pending();
		if ( ! current_user_can( 'manage_options' ) || ! $p || ! hash_equals( $p['token'], $token )
			|| ! wp_verify_nonce( $nonce, 'homeward_confirm_sync_' . $token ) ) {
			return new \WP_Error( 'homeward_sync_confirm', __( 'This sync confirmation is invalid or out of date.', 'homeward-rescue' ) );
		}
		$lock = Sync_Lock::acquire();
		if ( ! $lock ) {
			return new \WP_Error( 'homeward_sync_busy', __( 'A sync is already running.', 'homeward-rescue' ) );
		}
		Animal::begin_batch();
		try {
			if ( $p !== self::pending() ) {
				return new \WP_Error( 'homeward_sync_stale', __( 'This sync review changed. Review the current counts and try again.', 'homeward-rescue' ) );
			}
			$map = Animal::ps_map();
			$active = array_intersect_assoc( $p['active'], $map );
			return self::apply_listings( self::source(), $lock, Settings::get(), $p['clean'], $p['seen'], $map, $active );
		} finally {
			Animal::end_batch();
			Sync_Lock::release( $lock );
		}
	}

	/**
	 * Create the configured animal source adapter.
	 *
	 * @return Source
	 */
	public static function source() {
		return match ( Settings::get( 'source' ) ) {
			'fixture' => new Source_Fixture(),
			'demo'    => new Source_Demo(),
			default => new Source_Petstablished(),
		};
	}

	/**
	 * Return the latest sync or HTTP backoff expiry.
	 *
	 * @return int
	 */
	public static function paused_until() {
		return max( (int) get_option( 'homeward_sync_paused_until', 0 ), (int) get_option( 'homeward_backoff_until', 0 ) );
	}

	/**
	 * Return the stored sync result.
	 *
	 * @return array
	 */
	public static function status() {
		return (array) get_option( self::OPT_STATUS, array() );
	}

	/**
	 * Read detail work only for the current source connection.
	 *
	 * @return array
	 */
	public static function queue() {
		$saved = get_option( self::OPT_QUEUE, array() );
		return is_array( $saved ) && (int) ( $saved['source'] ?? -1 ) === (int) Settings::get( 'shelter_id' )
			&& ( $saved['adapter'] ?? '' ) === Settings::get( 'source' ) ? (array) ( $saved['ids'] ?? array() ) : array();
	}

	private static function set_queue( $ids ) {
		update_option(
			self::OPT_QUEUE,
			array(
				'source'  => (int) Settings::get( 'shelter_id' ),
				'adapter' => Settings::get( 'source' ),
				'ids'     => array_values( array_unique( array_map( 'intval', $ids ) ) ),
			),
			false
		);
	}

	private static function result( $ok, $message, $counts = array(), $source = null, $safety = false ) {
		$previous = self::status();
		$failures = $ok || $safety ? 0 : (int) ( $previous['failures'] ?? 0 ) + 1;
		if ( $failures >= 3 ) {
			update_option( 'homeward_sync_paused_until', time() + 6 * HOUR_IN_SECONDS, false );
		}
		if ( $ok ) {
			delete_option( 'homeward_sync_paused_until' );
		}
		$status = array(
			'ok'           => $ok,
			'time'         => time(),
			'last_success' => $ok ? time() : ( $previous['last_success'] ?? 0 ),
			'failures'     => $failures,
			'message'      => $message,
			'counts'       => $counts,
			'source'       => ( $source ?: self::source() )->label(),
		);
		if ( ! $ok ) {
			if ( ! $safety && self::pending() ) {
				$pending = self::pending();
				$pending['runs'] = 0;
				update_option( self::OPT_PENDING, $pending, false );
			}
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- fixed, non-sensitive failure message.
			error_log( 'Homeward: ' . $message );
		}
		update_option( self::OPT_STATUS, $status, false );
		return $status;
	}

	/**
	 * Run a requested listing sync and a bounded detail refresh.
	 *
	 * @param Source|null $source Adapter override.
	 * @return array
	 */
	public static function run_now( $source = null ) {
		$source = $source instanceof Source ? $source : self::source();
		$status = self::run_listings( $source );
		if ( ! empty( $status['ok'] ) && ( $status['counts']['received'] ?? 51 ) <= 50 && count( self::queue() ) <= 50 ) {
			self::process_details( $source, 50 );
			$status = self::status();
			if ( self::queue() && ! empty( $status['ok'] ) ) {
				$status = self::result(
					false,
					__( 'Some animal details still need updating. Please try Sync now again.', 'homeward-rescue' ),
					$status['counts'] ?? array(),
					$source
				);
			}
		}
		return $status;
	}

	/**
	 * Fetch and reconcile listings under an exclusive lease.
	 *
	 * @param Source|null $source Adapter override.
	 * @return array
	 */
	public static function run_listings( $source = null ) {
		Scheduler::once( 'homeward_sync_listings', time() + HOUR_IN_SECONDS );
		if ( self::paused_until() > time() ) {
			return self::status();
		}
		$lock = Sync_Lock::acquire();
		if ( ! $lock ) {
			return array( 'ok' => false, 'message' => __( 'A sync is already running.', 'homeward-rescue' ) );
		}
		Animal::begin_batch();
		try {
			return self::listings( $source instanceof Source ? $source : self::source(), $lock );
		} finally {
			Animal::end_batch();
			Sync_Lock::release( $lock );
		}
	}

	private static function listings( Source $source, $lock ) {
		$configuration = Settings::get();
		$pets = $source->fetch_all();
		if ( is_wp_error( $pets ) ) {
			return self::result(
				false,
				__( 'Source request failed. Existing animals were kept.', 'homeward-rescue' ),
				array(),
				$source
			);
		}
		if ( ! is_array( $pets ) ) {
			return self::result(
				false,
				__( 'Source returned zero animals. Existing animals were kept.', 'homeward-rescue' ),
				array(),
				$source
			);
		}
		$seen = array();
		$clean = array();
		foreach ( $pets as $pet ) {
			if ( ! Source_Petstablished::valid_pet( $pet ) || isset( $seen[ (int) $pet['id'] ] ) ) {
				return self::result(
					false,
					__( 'Malformed or duplicate listing. Existing animals were kept.', 'homeward-rescue' ),
					array(),
					$source
				);
			}
			$seen[ (int) $pet['id'] ] = true;
			$data = Pet_Data::sanitize_pet( $pet );
			$data['no_longer_available'] = $data['no_longer_available'] ?? false;
			$data['species'] = $data['species'] ?? Animal::default_species();
			if ( in_array( $data['species'], Settings::get( 'species' ), true ) ) {
				$clean[ $data['ps_id'] ] = $data;
			}
		}
		if ( $configuration !== Settings::get() || ! Sync_Lock::owned( $lock ) ) {
			return self::result(
				false,
				__( 'Configuration or sync lock changed. Existing animals were kept.', 'homeward-rescue' ),
				array(),
				$source
			);
		}
		$map = Animal::ps_map();
		$active = self::active_animals( $map );
		$review = self::review_listings( $source, $lock, $configuration, $clean, $seen, $map, $active );
		if ( null !== $review ) {
			return $review;
		}
		return self::apply_listings( $source, $lock, $configuration, $clean, $seen, $map, $active );
	}

	private static function active_animals( $map ) {
		$active = array();
		foreach ( $map as $ps => $id ) {
			if ( 'gone' !== get_post_meta( $id, Animal::M_STATUS, true ) && in_array(
				get_post_meta( $id, Animal::M_SPECIES, true ) ?: Animal::default_species(),
				Settings::get( 'species' ),
				true
			) ) {
				$active[ $ps ] = $id;
			}
		}
		return $active;
	}

	private static function review_listings( $source, $lock, $configuration, $clean, $seen, $map, $active ) {
		$available = 0;
		$leaving = 0;
		foreach ( $map as $ps => $id ) {
			if ( 'available' !== get_post_meta( $id, Animal::M_STATUS, true ) ) {
				continue;
			}
			++$available;
			$next = $clean[ $ps ] ?? null;
			if ( ( $next && ( ! empty( $next['no_longer_available'] )
				|| 'available' !== Pet_Data::status_key( $next['status'] ) ) ) || ( ! $next && isset( $active[ $ps ] )
					&& ! isset( $seen[ $ps ] ) ) ) {
				++$leaving;
			}
		}
		$missing = array_diff_key( $active, $seen );
		$tracking = get_option( self::OPT_MISSING, array() );
		$prior = ( $tracking['configuration'] ?? null ) === self::configuration_key() ? ( $tracking['ids']
			?? array() ) : array();
		$missing_runs = array();
		foreach ( $missing as $id ) {
			$missing_runs[ $id ] = ( $prior[ $id ] ?? 0 ) + 1;
		}
		update_option(
			self::OPT_MISSING,
			array(
				'configuration' => self::configuration_key(),
				'ids'           => $missing_runs,
			),
			false
		);
		$required = max( 1, (int) apply_filters( 'homeward_sync_drop_confirm_runs', 3 ) );
		if ( ! $clean || count( $clean ) < count( $active ) * 0.5 || $leaving > $available / 2 ) {
			$review = self::record_pending( $configuration, $clean, $seen, $active, $available, $leaving, $missing, $required );
			$runs = $review['runs'];
			$numbers = $review['counts'];
			if ( $runs < $required ) {
				// Even changing partial feeds cannot keep the same missing animal available forever.
				if ( $configuration === Settings::get() && Sync_Lock::owned( $lock ) ) {
					foreach ( $missing_runs as $id => $count ) {
						if ( $count >= $required ) {
							self::mark_gone( $id );
						}
					}
				}
				return self::result( false, self::pending_message(), $numbers, $source, true );
			}
		} else {
			delete_option( self::OPT_PENDING );
		}
		return null;
	}

	private static function record_pending(
		$configuration,
		$clean,
		$seen,
		$active,
		$available,
		$leaving,
		$missing,
		$required
	) {
		// Compare availability rather than mutable descriptions or feed order.
		$signature = array();
		foreach ( $clean as $ps => $data ) {
			$signature[ $ps ] = ! empty( $data['no_longer_available'] ) ? 'gone' : Pet_Data::status_key( $data['status'] );
		}
		ksort( $signature );
		$seen_ids = array_keys( $seen );
		sort( $seen_ids );
		$token = hash( 'sha256', wp_json_encode( array( $configuration, $signature, $seen_ids ) ) );
		$previous = self::pending();
		$runs = ( $previous['token'] ?? '' ) === $token ? $previous['runs'] + 1 : 1;
		$numbers = array(
			'received'  => count( $clean ),
			'active'    => count( $active ),
			'available' => $available,
			'leaving'   => $leaving,
			'missing'   => count( $missing ),
		);
		update_option(
			self::OPT_PENDING,
			array(
				'token'         => $token,
				'configuration' => self::configuration_key(),
				'clean'         => $clean,
				'seen'          => $seen,
				'active'        => $active,
				'counts'        => $numbers,
				'runs'          => $runs,
				'required'      => $required,
				'time'          => time(),
			),
			false
		);
		return array( 'runs' => $runs, 'counts' => $numbers );
	}

	private static function apply_listings( Source $source, $lock, $configuration, $clean, $seen, $map, $active ) {
		if ( $configuration !== Settings::get() || ! Sync_Lock::owned( $lock ) ) {
			return self::result(
				false,
				__( 'Configuration or sync lock changed. Existing animals were kept.', 'homeward-rescue' ),
				array(),
				$source
			);
		}
		add_option( 'homeward_badge_baseline', time(), '', false );
		$counts = array( 'received' => count( $clean ), 'created' => 0, 'updated' => 0, 'gone' => 0 );
		$queue = self::queue();
		$result = self::apply_animal_listings( $source, $lock, $clean, $map, $counts, $queue );
		if ( null !== $result ) {
			return $result;
		}
		foreach ( array_diff_key( $active, $seen ) as $id ) {
			if ( ! Sync_Lock::owned( $lock ) ) {
				return self::result(
					false,
					__( 'Sync lock expired. Existing animals were kept.', 'homeward-rescue' ),
					$counts,
					$source
				);
			}
			self::mark_gone( $id );
			++$counts['gone'];
		}
		self::set_queue( $queue );
		Animal::flush();
		if ( $queue ) {
			Scheduler::once( 'homeward_sync_details', time() + 90 );
		}
		delete_option( self::OPT_PENDING );
		$status = self::result( true, __( 'Listing sync completed.', 'homeward-rescue' ), $counts, $source );
		do_action( 'homeward_sync_completed', $status );
		return $status;
	}

	private static function apply_animal_listings( $source, $lock, $clean, $map, &$counts, &$queue ) {
		foreach ( $clean as $ps => $data ) {
			if ( ! Sync_Lock::owned( $lock ) ) {
				return self::result(
					false,
					__( 'Sync lock expired. No missing animals were marked gone.', 'homeward-rescue' ),
					$counts,
					$source
				);
			}
			$id = $map[ $ps ] ?? 0;
			$status = ! empty( $data['no_longer_available'] ) ? 'gone' : Pet_Data::status_key( $data['status'] );
			if ( ! $id && 'gone' === $status ) {
				continue;
			}
			if ( ! $id ) {
				$id = self::insert_animal( $ps, $data );
				if ( is_wp_error( $id ) || ! $id ) {
					Animal::flush();
					return self::result(
						false,
						__( 'An animal could not be saved. No missing animals were marked gone.', 'homeward-rescue' ),
						$counts,
						$source
					);
				}
				++$counts['created'];
				self::update( $id, $data );
				do_action( 'homeward_animal_created', $id );
			} else {
				if ( get_post_meta( $id, Animal::M_DETAIL, true ) ) {
					unset( $data['description'], $data['photos'] );
				}
				self::update( $id, $data );
				++$counts['updated'];
			}
			if ( ! get_post_meta( $id, Animal::M_DETAIL, true ) && 'gone' !== $status ) {
				$queue[] = $ps;
			}
		}
		return null;
	}

	private static function insert_animal( $ps, $data ) {
		$id = wp_insert_post(
			wp_slash(
				array(
					'post_type'      => Animal_Post_Type::TYPE,
					'post_status'    => Pet_Data::is_placeholder( $data['raw_name'] ) ? 'draft' : 'publish',
					'post_title'     => Pet_Data::clean_name( $data['raw_name'] ),
					'post_name'      => sanitize_title( Pet_Data::clean_name( $data['raw_name'] ) ),
					'post_content'   => '',
					'comment_status' => 'closed',
					'ping_status'    => 'closed',
				)
			),
			true
		);
		if ( is_wp_error( $id ) || ! $id ) {
			return $id;
		}
		update_post_meta( $id, Animal::M_PS_ID, $ps );
		update_post_meta( $id, Animal::M_SOURCE, (int) Settings::get( 'shelter_id' ) );
		update_post_meta( $id, Animal::M_FIRST_SYNCED, time() );
		return $id;
	}

	private static function update( $id, $data ) {
		update_post_meta( $id, Animal::M_SOURCE, (int) Settings::get( 'shelter_id' ) );
		update_post_meta( $id, Animal::M_ADAPTER, Settings::get( 'source' ) );
		$old = Animal::data( $id );
		// Up for adoption before this update (pending still counts: the animal kept waiting).
		$was_up = in_array( (string) get_post_meta( $id, Animal::M_STATUS, true ), array( '', 'available', 'pending' ), true )
			&& '0' !== get_post_meta( $id, Animal::M_LISTED, true );
		// An animal missing from the feed for a short while (a source blip) has not been relisted.
		$gone_at = (int) get_post_meta( $id, Animal::M_GONE, true );
		$min_away = (int) apply_filters( 'homeward_relist_min_days', 3 ) * DAY_IN_SECONDS;
		$away_long = $gone_at ? time() - $gone_at >= $min_away : true;
		$description = array_key_exists( 'description', $data ) ? Pet_Data::kses( $data['description'] ) : null;
		unset( $data['description'] );
		$new = array_intersect_key( array_merge( $old, $data ), array_flip( Pet_Data::WHITELIST ) );
		unset( $new['description'] );
		update_post_meta( $id, Animal::M_DATA, wp_slash( $new ) );
		$status = ! empty( $new['no_longer_available'] ) ? 'gone' : Pet_Data::status_key( $new['status'] ?? '' );
		if ( 'gone' === $status ) {
			self::mark_gone( $id );
		} else {
			update_post_meta( $id, Animal::M_STATUS, $status );
			delete_post_meta( $id, Animal::M_GONE );
		}
		update_post_meta( $id, Animal::M_SPECIES, $new['species'] ?? Animal::default_species() );
		update_post_meta( $id, Animal::M_SYNCED, time() );
		update_post_meta(
			$id,
			Animal::M_CREATED,
			Pet_Data::date_timestamp( $new['created_at'] ?? '' ) ?: (int) get_post_meta( $id, Animal::M_FIRST_SYNCED, true )
		);
		$change = array( 'ID' => $id, 'post_title' => Animal::name( $id ) );
		if ( null !== $description ) {
			$change['post_content'] = $description;
		}
		if ( Pet_Data::is_placeholder( $new['raw_name'] ?? '' ) ) {
			$change['post_status'] = 'draft';
		}
		// Revive retention drafts, but never republish a manually hidden/trash/private post.
		if ( ! Pet_Data::is_placeholder( $new['raw_name'] ?? '' ) && get_post_meta( $id, '_homeward_retired', true )
			&& 'gone' !== $status && 'draft' === get_post( $id )->post_status ) {
			$change['post_status'] = 'publish';
			delete_post_meta( $id, '_homeward_retired' );
		}
		wp_update_post( wp_slash( $change ) );
		Animal::refresh_derived( $id );
		// Back from adoption, a hold or delisting: the wait starts again now.
		if ( ! $was_up && $away_long && 'available' === $status && '1' === get_post_meta( $id, Animal::M_LISTED, true ) ) {
			update_post_meta( $id, Animal::M_RELISTED, time() );
		}
	}

	private static function mark_gone( $id ) {
		if ( Animal_Post_Type::manual( $id ) ) {
			return;
		}
		$was = get_post_meta( $id, Animal::M_STATUS, true );
		update_post_meta( $id, Animal::M_STATUS, 'gone' );
		if ( ! get_post_meta( $id, Animal::M_GONE, true ) ) {
			update_post_meta( $id, Animal::M_GONE, time() );
		}
		Animal::refresh_derived( $id );
		if ( 'gone' !== $was ) {
			do_action( 'homeward_animal_gone', $id );
		}
	}

	/**
	 * Refresh a bounded batch of detail records under the sync lease.
	 *
	 * @param Source|null $source Adapter override.
	 * @param int         $limit  Maximum records to process.
	 * @return int
	 */
	public static function process_details( $source = null, $limit = self::BATCH ) {
		if ( self::paused_until() > time() ) {
			Scheduler::once( 'homeward_sync_details', self::paused_until() + 1 );
			return 0;
		}
		$lock = Sync_Lock::acquire();
		if ( ! $lock ) {
			Scheduler::once( 'homeward_sync_details', time() + 90 );
			return 0;
		}
		Animal::begin_batch();
		try {
			return self::detail_batch( $source, $limit, $lock );
		} finally {
			Animal::end_batch();
			Sync_Lock::release( $lock );
		}
	}

	private static function detail_batch( $source, $limit, $lock ) {
		$configuration = Settings::get();
		$map = Animal::ps_map();
		$source = $source instanceof Source ? $source : self::source();
		$queue = self::queue();
		$done = 0;
		$errors = 0;
		$batch = array_splice( $queue, 0, max( 1, min( 50, (int) $limit ) ) );
		foreach ( $batch as $ps ) {
			$id = $map[ $ps ] ?? 0;
			if ( ! $id || 'gone' === get_post_meta( $id, Animal::M_STATUS, true ) ) {
				continue;
			}
			$raw = $source->fetch_detail( $ps );
			if ( is_wp_error( $raw ) || ! Source_Petstablished::valid_pet( $raw ) || (int) $raw['id'] !== (int) $ps ) {
				++$errors;
				$queue[] = $ps;
				continue;
			}
			if ( $configuration !== Settings::get() || ! Sync_Lock::owned( $lock ) ) {
				return $done;
			}
			// The listing remains authoritative for availability; a stale detail cannot undo it.
			$data = Pet_Data::sanitize_pet( $raw );
			unset( $data['status'], $data['no_longer_available'] );
			if ( 'gone' === get_post_meta( $id, Animal::M_STATUS, true ) ) {
				continue;
			}
			self::update( $id, $data );
			update_post_meta( $id, Animal::M_DETAIL, time() );
			if ( 'fixture' !== Settings::get( 'source' ) ) {
				Media::cover( $id, Animal::data( $id ) );
			}
			++$done;
		}
		self::set_queue( $queue );
		Animal::flush();
		if ( $errors ) {
			self::result( false, __( 'Detail requests failed; safe retries are queued.', 'homeward-rescue' ) );
		} elseif ( $done ) {
			$status = self::status();
			$status['failures'] = 0;
			$status['detail_errors'] = 0;
			update_option( self::OPT_STATUS, $status, false );
		}
		if ( $queue ) {
			Scheduler::once( 'homeward_sync_details', max( time() + 90, self::paused_until() + 1 ) );
		}
		return $done;
	}

	/**
	 * Queue eligible animal details for the daily refresh.
	 *
	 * @return void
	 */
	public static function daily_refresh() {
		Scheduler::once( 'homeward_daily_refresh', time() + DAY_IN_SECONDS );
		$lock = Sync_Lock::acquire();
		if ( ! $lock ) {
			return;
		}
		Animal::begin_batch();
		try {
			$queue = self::queue();
			foreach ( Animal::ps_map() as $ps => $id ) {
				Animal::refresh_derived( $id );
				if ( 'gone' !== get_post_meta( $id, Animal::M_STATUS, true ) ) {
					$queue[] = $ps;
				}
			}
			self::set_queue( $queue );
		} finally {
			Animal::end_batch();
			Sync_Lock::release( $lock );
		}
		Scheduler::once( 'homeward_sync_details', max( time() + 90, self::paused_until() + 1 ) );
	}

	/**
	 * Retire departed animals after the configured retention period.
	 *
	 * @return void
	 */
	public static function cleanup() {
		Scheduler::once( 'homeward_cleanup', time() + DAY_IN_SECONDS );
		$days = (int) Settings::get( 'gone_retention_days' );
		if ( ! $days ) {
			return;
		}
		$lock = Sync_Lock::acquire();
		if ( ! $lock ) {
			return;
		}
		Animal::begin_batch();
		try {
			foreach ( Animal::all_ids() as $id ) {
				if ( Animal_Post_Type::manual( $id ) ) {
					continue;
				}
				$gone = (int) get_post_meta( $id, Animal::M_GONE, true );
				if ( 'gone' !== get_post_meta( $id, Animal::M_STATUS, true ) || ! $gone || $gone >= time() - $days * DAY_IN_SECONDS
					|| 'publish' !== get_post( $id )->post_status ) {
					continue;
				}
				$stories = get_posts(
					array(
						'post_type'      => Content_Post_Types::STORY,
						'post_status'    => array( 'publish', 'draft', 'private', 'pending', 'future', 'trash' ),
						'meta_key'       => 'homeward_animal_id',
						'meta_value'     => $id,
						'fields'         => 'ids',
						'posts_per_page' => 1,
					)
				);
				if ( $stories ) {
					continue;
				}
				wp_update_post( array( 'ID' => $id, 'post_status' => 'draft' ) );
				update_post_meta( $id, '_homeward_retired', 1 );
			}
			Animal::flush();
		} finally {
			Animal::end_batch();
			Sync_Lock::release( $lock );
		}
	}
}
