<?php
/**
 * A plain text guide to the organization and its public pages.
 */
namespace Homeward;

defined( 'ABSPATH' ) || exit;

/**
 * Publish a plain-text guide to selected public rescue pages.
 */
class Llms_Txt {
	const PATH = '/llms.txt';

	/**
	 * Register the llms.txt request handler.
	 *
	 * @return void
	 */
	public static function init() {
		add_action( 'template_redirect', array( __CLASS__, 'serve' ), -100 );
	}

	/**
	 * Check whether this request targets the enabled guide.
	 *
	 * @return bool
	 */
	public static function is_request() {
		$path = wp_parse_url(
			wp_unslash(
				$_SERVER['REQUEST_URI'] ?? ''
			),
			PHP_URL_PATH
		); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		return Settings::get( 'llms_txt' ) && is_string( $path )
			&& $path === wp_parse_url( home_url( self::PATH ), PHP_URL_PATH );
	}

	private static function label( $text ) {
		return trim(
			preg_replace(
				'/[\r\n\[\]<>]+/',
				' ',
				html_entity_decode( wp_strip_all_tags( $text ), ENT_QUOTES | ENT_HTML5, 'UTF-8' )
			)
		);
	}

	/**
	 * Build the guide from eligible public pages.
	 *
	 * @return string
	 */
	public static function text() {
		$lines = array( '# ' . self::label( Settings::get( 'org_name' ) ), '', '## Adoption', '' );
		$lines[] = '- [' . self::label( Settings::get( 'noun_plural' ) ) . '](' . Render::archive_url() . ')';
		$lines[] = '- Currently listed for adoption: ' . count( Animal::all_listed() );
		$lines[] = '';
		$lines[] = '## Pages';
		foreach ( Settings::get( 'llms_pages' ) as $id ) {
			$post = get_post( $id );
			if ( ! $post || 'page' !== $post->post_type || 'publish' !== $post->post_status || '' !== $post->post_password ) {
				continue;
			}
			$url = get_permalink( $id );
			if ( Redirects::match( '/' . trim( get_page_uri( $post ), '/' ) . '/' ) ) {
				continue;
			}
			$lines[] = '- [' . self::label( get_the_title( $id ) ) . '](' . $url . ')';
		}
		$lines[] = '';
		$lines[] = '## Sitemap';
		$path = defined( 'RANK_MATH_VERSION' ) || defined( 'WPSEO_VERSION' ) ? '/sitemap_index.xml' : '/wp-sitemap.xml';
		$lines[] = '- [Sitemap](' . home_url( $path ) . ')';
		return implode( "\n", $lines ) . "\n";
	}

	/**
	 * Serve the guide with a plain-text content type.
	 *
	 * @return void
	 */
	public static function serve() {
		if ( ! self::is_request() ) {
			return;
		}
		status_header( 200 );
		header( 'Content-Type: text/plain; charset=utf-8' );
		header( 'Cache-Control: public, max-age=3600' );
		header( 'X-Robots-Tag: noindex' );
		echo self::text(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- plain text response.
		exit;
	}
}
