<?php
namespace Homeward;

defined( 'ABSPATH' ) || exit;

/**
 * Convert text case without requiring mbstring.
 */
class Text {
	/**
	 * Convert text to lowercase using the available extension.
	 *
	 * @param string $text     Source text.
	 * @param string $encoding Character encoding.
	 * @return string
	 */
	public static function lower( $text, $encoding = 'UTF-8' ) {
		return function_exists( 'mb_strtolower' ) ? mb_strtolower( $text, $encoding ) : strtolower( $text );
	}

	/**
	 * Convert text to uppercase using the available extension.
	 *
	 * @param string $text     Source text.
	 * @param string $encoding Character encoding.
	 * @return string
	 */
	public static function upper( $text, $encoding = 'UTF-8' ) {
		return function_exists( 'mb_strtoupper' ) ? mb_strtoupper( $text, $encoding ) : strtoupper( $text );
	}

	/**
	 * Locate the last occurrence using character offsets.
	 *
	 * @param string $text     Source text.
	 * @param string $needle   Text to find.
	 * @param int    $offset   Starting offset.
	 * @param string $encoding Character encoding.
	 * @return int|false
	 */
	public static function last_position( $text, $needle, $offset = 0, $encoding = 'UTF-8' ) {
		if ( function_exists( 'mb_strrpos' ) ) {
			return mb_strrpos( $text, $needle, $offset, $encoding );
		}
		$position = strrpos( $text, $needle, $offset );
		return false === $position ? false : mb_strlen( substr( $text, 0, $position ), $encoding );
	}
}
