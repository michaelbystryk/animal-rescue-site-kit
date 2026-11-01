<?php
/**
 * Tools > Homeward Import: turns the legacy Happy Tails and Testimonials pages into draft stories.
 *
 * @package Homeward
 */
namespace Homeward;

defined( 'ABSPATH' ) || exit;

/**
 * Preview and import stories from configured legacy pages.
 */
class Importer {
	const PAGE = 'homeward-import';
	const ACTION = 'homeward_run_import';

	/**
	 * Register the legacy import screen.
	 *
	 * @return void
	 */
	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ) );
	}

	/**
	 * Add the import screen for administrators.
	 *
	 * @return void
	 */
	public static function menu() {
		add_management_page(
			__( 'Homeward Import', 'homeward-rescue' ),
			__( 'Homeward Import', 'homeward-rescue' ),
			'manage_options',
			self::PAGE,
			array( __CLASS__, 'screen' )
		);
	}

	/**
	 * Parse both source pages from the database.
	 *
	 * @return array{items: array[], missing: string[]}
	 */
	public static function collect() {
		$items = array();
		$missing = array();
		$sources = array(
			Settings::get( 'import_stories_slug' ) => array( Parser::class, 'parse_happy_tails' ),
			Settings::get( 'import_testimonials_slug' ) => array( Parser::class, 'parse_testimonials' ),
		);
		foreach ( $sources as $slug => $parser ) {
			if ( ! $slug ) {
				continue;
			}
			// Look up by slug at any depth, so pages nested under a parent are found.
			$found = get_posts(
				array(
					'post_type'      => 'page',
					'name'           => $slug,
					'post_status'    => array( 'publish', 'private', 'draft' ),
					'posts_per_page' => 1,
				)
			);
			$page = $found ? $found[ 0 ] : null;
			if ( ! $page ) {
				$missing[] = $slug;
				continue;
			}
			foreach ( call_user_func( $parser, $page->post_content ) as $story ) {
				$items[] = $story;
			}
		}
		return array( 'items' => $items, 'missing' => $missing );
	}

	/**
	 * Hashes already imported.
	 *
	 * @return string[]
	 */
	private static function existing_hashes() {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$rows = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT meta_value FROM {$wpdb->postmeta} WHERE meta_key = %s",
				'homeward_source_hash'
			)
		);
		return array_flip( (array) $rows );
	}

	/**
	 * Find a Media Library attachment for an image URL.
	 *
	 * @param string $url Image URL.
	 * @return int Attachment ID or 0.
	 */
	public static function find_attachment( $url ) {
		if ( '' === $url ) {
			return 0;
		}
		$candidates = array( $url, Parser::strip_size_suffix( $url ) );
		// Also try the same upload path on this site, in case the page used another domain.
		$pos = strpos( $url, '/wp-content/uploads/' );
		if ( false !== $pos ) {
			$uploads = wp_get_upload_dir();
			$local = trailingslashit( $uploads['baseurl'] ) . substr( $url, $pos + strlen( '/wp-content/uploads/' ) );
			$candidates[] = $local;
			$candidates[] = Parser::strip_size_suffix( $local );
		}
		foreach ( array_unique( $candidates ) as $candidate ) {
			$id = attachment_url_to_postid( $candidate );
			if ( $id ) {
				return (int) $id;
			}
		}
		return 0;
	}

	/**
	 * Build the preview rows.
	 *
	 * @return array{rows: array[], missing: string[]}
	 */
	public static function plan() {
		$data = self::collect();
		$existing = self::existing_hashes();
		$rows = array();
		foreach ( $data['items'] as $story ) {
			$story['attachment'] = self::find_attachment( $story['image'] );
			$story['duplicate'] = isset( $existing[ $story['hash'] ] );
			$rows[] = $story;
		}
		return array( 'rows' => $rows, 'missing' => $data['missing'] );
	}

	/**
	 * Create one draft story.
	 *
	 * @param array $story Parsed story with attachment.
	 * @return int Post ID or 0.
	 */
	private static function create( array $story ) {
		$args = array(
			'post_type'   => Content_Post_Types::STORY,
			'post_status' => 'draft',
			'post_title'  => sanitize_text_field( $story['title'] ),
			'meta_input'  => array(
				'homeward_kind'        => $story['kind'],
				'homeward_intro'       => $story['intro'],
				'homeward_text'        => implode( "\n\n", $story['text'] ),
				'homeward_family'      => $story['family'],
				'homeward_received'    => $story['received'],
				'homeward_source_hash' => $story['hash'],
			),
		);
		if ( $story['received'] ) {
			$local = $story['received'] . '-01 12:00:00';
			if ( strtotime( $local ) < time() ) {
				$args['post_date'] = $local;
				$args['post_date_gmt'] = get_gmt_from_date( $local );
			}
		}
		$id = wp_insert_post( wp_slash( $args ), true );
		if ( is_wp_error( $id ) || ! $id ) {
			return 0;
		}
		if ( ! empty( $story['attachment'] ) ) {
			set_post_thumbnail( $id, (int) $story['attachment'] );
		}
		return (int) $id;
	}

	/**
	 * Run the import for rows that are not duplicates.
	 *
	 * @return array{created: int[], skipped: int}
	 */
	public static function run() {
		$plan = self::plan();
		$created = array();
		$skipped = 0;
		$seen = array();
		foreach ( array_reverse( $plan['rows'] ) as $story ) {
			if ( $story['duplicate'] || isset( $seen[ $story['hash'] ] ) ) {
				++$skipped;
				continue;
			}
			$seen[ $story['hash'] ] = true;
			$id = self::create( $story );
			if ( $id ) {
				$created[] = $id;
			}
		}
		return array( 'created' => $created, 'skipped' => $skipped );
	}

	/**
	 * Render the import preview and process an authorized import.
	 *
	 * @return void
	 */
	public static function screen() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to do this.', 'homeward-rescue' ) );
		}
		echo '<div class="wrap"><h1>' . esc_html__( 'Homeward Import', 'homeward-rescue' ) . '</h1>';
		if ( isset( $_POST[ self::ACTION ] ) ) {
			check_admin_referer( self::ACTION );
			$result = self::run();
			echo '<div class="notice notice-success"><p>';
			printf(
				/* translators: 1: created count, 2: skipped count */
				esc_html__( 'Created %1$d draft stories. Skipped %2$d already imported. Review them and publish when happy.', 'homeward-rescue' ),
				count( $result['created'] ),
				(int) $result['skipped']
			);
			echo ' <a href="' . esc_url( admin_url( 'edit.php?post_type=' . Content_Post_Types::STORY . '&post_status=draft' ) )
				. '">' . esc_html__( 'View drafts', 'homeward-rescue' ) . '</a></p></div>';
		}
		$plan = self::plan();
		echo '<p>'
			. esc_html__( 'Reads the legacy page slugs selected in Homeward settings and prepares one draft story for each. Nothing is changed until you press Import. Stories already imported are skipped, so it is safe to run again. Try it on staging first.', 'homeward-rescue' )
			. '</p>';
		foreach ( $plan['missing'] as $slug ) {
			echo '<div class="notice notice-warning inline"><p>' . sprintf(
				/* translators: %s: page slug */
				esc_html__( 'No page with the slug "%s" was found.', 'homeward-rescue' ),
				esc_html( $slug )
			) . '</p></div>';
		}
		$new = self::render_preview( $plan );
		echo '<form method="post" style="margin-top:16px">';
		wp_nonce_field( self::ACTION );
		echo '<input type="hidden" name="' . esc_attr( self::ACTION ) . '" value="1">';
		printf(
			'<p>%s</p>',
			esc_html(
				sprintf(
					/* translators: %d: number of new stories */
					_n( '%d new story will be created as a draft.', '%d new stories will be created as drafts.', $new, 'homeward-rescue' ),
					$new
				)
			)
		);
		submit_button(
			__( 'Import', 'homeward-rescue' ),
			'primary',
			'submit',
			true,
			$new ? array() : array( 'disabled' => 'disabled' )
		);
		echo '</form></div>';
	}
	private static function render_preview( $plan ) {
		$new = 0;
		echo '<h2>' . esc_html__( 'Preview', 'homeward-rescue' ) . '</h2>';
		echo '<table class="widefat striped"><thead><tr>';
		foreach ( array(
			'#',
			__( 'Kind', 'homeward-rescue' ),
			__( 'Title', 'homeward-rescue' ),
			__( 'Intro', 'homeward-rescue' ),
			__( 'Received', 'homeward-rescue' ),
			__( 'Text', 'homeward-rescue' ),
			__( 'Photo', 'homeward-rescue' ),
			__( 'Status', 'homeward-rescue' ),
			) as $head ) {
			echo '<th>' . esc_html( $head ) . '</th>';
		}
		echo '</tr></thead><tbody>';
		if ( ! $plan['rows'] ) {
			echo '<tr><td colspan="8">' . esc_html__( 'Nothing found to import.', 'homeward-rescue' ) . '</td></tr>';
		}
		foreach ( $plan['rows'] as $i => $row ) {
			if ( ! $row['duplicate'] ) {
				++$new;
			}
			if ( $row['attachment'] ) {
				/* translators: %d: attachment ID */
				$photo = sprintf( __( 'Matched (#%d)', 'homeward-rescue' ), $row['attachment'] );
			} elseif ( $row['image'] ) {
				$photo = __( 'Not found in Media Library', 'homeward-rescue' );
			} else {
				$photo = __( 'None', 'homeward-rescue' );
			}
			echo '<tr>';
			echo '<td>' . (int) ( $i + 1 ) . '</td>';
			echo '<td>' . esc_html( $row['kind'] ) . '</td>';
			echo '<td><strong>' . esc_html( $row['title'] ) . '</strong></td>';
			echo '<td>' . esc_html( $row['intro'] ) . '</td>';
			echo '<td>' . esc_html( $row['received'] ) . '</td>';
			echo '<td>' . esc_html( wp_trim_words( implode( ' ', $row['text'] ), 18 ) ) . '</td>';
			echo '<td>' . esc_html( $photo ) . '</td>';
			echo '<td>' . esc_html(
				$row['duplicate'] ? __( 'Already imported', 'homeward-rescue' ) : __( 'New', 'homeward-rescue' )
			) . '</td>';
			echo '</tr>';
		}
		echo '</tbody></table>';
		return $new;
	}

}
