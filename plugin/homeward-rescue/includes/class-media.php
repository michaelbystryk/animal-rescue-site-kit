<?php
namespace Homeward;

defined( 'ABSPATH' ) || exit;

/**
 * Import animal cover images while preserving editorial media.
 */
class Media {
	/**
	 * Identify an image independently of signed query parameters.
	 *
	 * @param string $url Image URL.
	 * @return string
	 */
	public static function identity( $url ) {
		return strtolower( (string) wp_parse_url( $url, PHP_URL_HOST ) ) . (string) wp_parse_url( $url, PHP_URL_PATH );
	}

	/**
	 * Keep attachments referenced by any other post, meta, or stored content.
	 */
	private static function in_use( $attachment, $owner ) {
		global $wpdb;
		$meta = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT post_id FROM {$wpdb->postmeta} WHERE meta_value = %s AND post_id <> %d LIMIT 1",
				(string) $attachment,
				$owner
			)
		);
		// Conservatively keep any numeric/content reference (blocks, galleries and image URLs).
		$content = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT ID FROM {$wpdb->posts} WHERE post_content LIKE %s LIMIT 1",
				'%' . $wpdb->esc_like( (string) $attachment ) . '%'
			)
		);
		$url = wp_get_attachment_url( $attachment );
		$url_use = $url ? $wpdb->get_var(
			$wpdb->prepare(
				"SELECT ID FROM {$wpdb->posts} WHERE post_content LIKE %s LIMIT 1",
				'%' . $wpdb->esc_like( $url ) . '%'
			)
		) : false;
		return $meta || $content || $url_use
			|| (bool) apply_filters( 'homeward_keep_imported_attachment', false, $attachment, $owner );
	}

	/**
	 * Import a changed source cover when no editorial image overrides it.
	 *
	 * @param int   $id   Animal post ID.
	 * @param array $data Normalized source data.
	 * @return void
	 */
	public static function cover( $id, $data ) {
		if ( 'import_cover' !== Settings::get( 'image_mode' ) ) {
			return;
		}
		$url = Pet_Data::photo_url( $data['photos'][ 0 ]['full'] ?? '' );
		if (
			! $url || (
				get_post_thumbnail_id( $id )
				&& self::identity( get_post_meta( $id, '_homeward_ps_photo', true ) ) === self::identity( $url )
			)
		) {
			return;
		}
		// Respect a volunteer's featured image; replace only our own imported cover.
		$existing = get_post_thumbnail_id( $id );
		if ( $existing && ! get_post_meta( $existing, '_homeward_ps_photo', true ) ) {
			return;
		}
		if ( ! function_exists( 'media_sideload_image' ) ) {
			require_once ABSPATH . 'wp-admin/includes/media.php';
			require_once ABSPATH . 'wp-admin/includes/file.php';
			require_once ABSPATH . 'wp-admin/includes/image.php';
		}
		$attachment = media_sideload_image( $url, $id, Animal::name( $id ), 'id' );
		if ( is_wp_error( $attachment ) ) {
			return;
		}
		set_post_thumbnail( $id, $attachment );
		update_post_meta( $attachment, '_homeward_ps_photo', $url );
		update_post_meta( $id, '_homeward_ps_photo', $url );
		if ( $existing && ! self::in_use( $existing, $id ) ) {
			wp_delete_attachment( $existing, true );
		}
	}
}
