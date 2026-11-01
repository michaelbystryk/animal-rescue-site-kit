<?php
/**
 * Public block email obfuscation, without changing saved editor content.
 *
 * @package Homeward
 */
namespace Homeward;

defined( 'ABSPATH' ) || exit;

/**
 * Obscure public email addresses while preserving usable links.
 */
class Email_Guard {
	/**
	 * Whether this response needs the footer decoder.
	 */
	private static $encoded = false;

	/**
	 * Register public email protection hooks.
	 *
	 * @return void
	 */
	public static function init() {
		add_filter( 'render_block', array( __CLASS__, 'render' ), 99 );
		add_filter( 'the_content', array( __CLASS__, 'render' ), 99 );
		add_filter( 'widget_text_content', array( __CLASS__, 'render' ), 99 );
		add_action( 'wp_footer', array( __CLASS__, 'decoder' ), 1 );
	}

	/**
	 * Editor rendering uses admin, REST or AJAX, never the public transformation.
	 */
	private static function enabled() {
		return Settings::get( 'email_guard' ) && ! is_admin() && ! is_feed() && ! wp_doing_ajax()
			&& ! ( defined( 'REST_REQUEST' ) && REST_REQUEST ) && ! wp_is_json_request();
	}

	/**
	 * This deters regex harvesters; it is deliberately reversible in the browser.
	 */
	private static function encode( $value ) {
		self::$encoded = true;
		return base64_encode( strrev( $value ) );
	}

	/**
	 * Encode public email text and links while preserving raw elements.
	 *
	 * @param string $html Rendered page content.
	 * @return string
	 */
	public static function render( $html ) {
		if ( ! self::enabled() || false === strpos( $html, '@' ) && false === stripos( $html, 'mailto:' ) ) {
			return $html;
		}
		$pattern = <<<'REGEX'
~<!--[\s\S]*?(?:-->|$)
|<!\[CDATA\[[\s\S]*?(?:\]\]>|$)
|<(?<raw>script|style|textarea)(?=[\s/>])(?:[^>"']|"[^"]*"|'[^']*')*>[\s\S]*?(?:</\k<raw>\s*>|$)
|</?[a-z][a-z0-9:-]*(?=[\s/>])(?:[^>"']|"[^"]*"|'[^']*')*>
|<![^>]*>
|(?<![a-z0-9.!#$%&'*+/=?^_`{|}\~-])[a-z0-9!#$%&'*+/=?^_`{|}\~-]+
(?:\.[a-z0-9!#$%&'*+/=?^_`{|}\~-]+)*
@(?:[a-z0-9](?:[a-z0-9-]*[a-z0-9])?\.)+[a-z]{2,}(?![a-z0-9_-])~i
REGEX;
		return preg_replace_callback(
			str_replace( "\n", '', $pattern ),
			static function ( $match ) {
				$value = $match[0];
				if ( '<' === $value[0] ) {
					if ( preg_match( '~^<(?!script\b|style\b|textarea\b|input\b)[a-z][a-z0-9:-]*(?=[\s/>])~i', $value ) ) {
						return self::mailto( $value );
					}
					return $value;
				}
				$fallback = str_replace( array( '@', '.' ), array( ' [at] ', ' [dot] ' ), $value );
				return '<span class="homeward-email" data-e="' . self::encode( $value ) . '">' . esc_html( $fallback ) . '</span>';
			},
			$html
		);
	}

	/**
	 * Match whole attributes so href-like text inside another attribute stays untouched.
	 */
	private static function mailto( $tag ) {
		$pattern = <<<'REGEX'
~\s+([^\s=/>]+)(?:\s*=\s*(?:"([^"]*)"|'([^']*)'|([^\s"'=<>`]+)))?~
REGEX;
		return preg_replace_callback(
			$pattern,
			static function ( $match ) {
				$attribute = strtolower( $match[1] );
				if ( ! in_array( $attribute, array( 'href', 'data-homeward-copy' ), true ) ) {
					return $match[0];
				}
				$value = html_entity_decode( $match[2] ?? $match[3] ?? $match[4] ?? '', ENT_QUOTES | ENT_HTML5, 'UTF-8' );
				if ( 'data-homeward-copy' === $attribute && false !== strpos( $value, '@' ) ) {
					return ' data-homeward-copy="" data-homeward-email-copy="' . self::encode( $value ) . '"';
				}
				if ( 0 !== stripos( $value, 'mailto:' ) || '' === substr( $value, 7 ) ) {
					return $match[0];
				}
				return ' href="#" data-homeward-mailto="' . self::encode( substr( $value, 7 ) ) . '"';
			},
			$tag,
			-1,
			$count,
			PREG_UNMATCHED_AS_NULL
		);
	}

	/**
	 * Print the client decoder for protected email addresses.
	 *
	 * @return void
	 */
	public static function decoder() {
		if ( ! self::$encoded || ! self::enabled() ) {
			return;
		}
		?>
<script id="homeward-email-guard">
(function () {
	'use strict';
	var decode = function (value) {
		return new TextDecoder().decode(Uint8Array.from(atob(value), function (c) {
			return c.charCodeAt(0);
		}).reverse());
	};
	document.querySelectorAll('.homeward-email[data-e]').forEach(function (el) {
		el.textContent = decode(el.getAttribute('data-e'));
		el.removeAttribute('data-e');
	});
	document.querySelectorAll('[data-homeward-mailto]').forEach(function (el) {
		el.setAttribute('href', 'mailto:' + decode(el.getAttribute('data-homeward-mailto')));
		el.removeAttribute('data-homeward-mailto');
	});
	document.querySelectorAll('[data-homeward-email-copy]').forEach(function (el) {
		el.setAttribute('data-homeward-copy', decode(el.getAttribute('data-homeward-email-copy')));
		el.removeAttribute('data-homeward-email-copy');
	});
})();
</script>
		<?php
	}
}
