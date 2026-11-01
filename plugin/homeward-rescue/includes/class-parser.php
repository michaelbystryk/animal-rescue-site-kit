<?php
/**
 * Pure parser for the legacy Happy Tails and Testimonials pages.
 *
 * No WordPress functions are used here so it can be tested with the plain php CLI.
 *
 * @package Homeward
 */
namespace Homeward;

defined( 'ABSPATH' ) || exit;

/**
 * Turns legacy page HTML into arrays of story data.
 */
class Parser {
	const BLOCK_TAGS = array(
		'p',
		'div',
		'figure',
		'figcaption',
		'section',
		'article',
		'ul',
		'ol',
		'li',
		'blockquote',
		'table',
		'tr',
		'td',
		'th',
		'center',
	);
	/**
	 * Parse the Happy Tails page content.
	 *
	 * @param string $html Page content.
	 * @return array[] Stories.
	 */
	public static function parse_happy_tails( $html ) {
		$tokens = self::tokenize( $html );
		$stories = array();
		$cur = null;
		foreach ( $tokens as $tok ) {
			if ( 'image' === $tok['type'] ) {
				if ( null !== $cur && '' !== $cur['title'] ) {
					$stories[] = self::finish( $cur, 'story' );
					$cur = null;
				}
				if ( null === $cur ) {
					$cur = self::blank();
				}
				if ( '' === $cur['image'] ) {
					$cur['image'] = $tok['url'];
				}
				continue;
			}
			if ( 'heading' === $tok['type'] ) {
				$is_name = in_array( $tok['level'], array( 3, 4 ), true ) || ( null !== $cur && '' !== $cur['image']
					&& '' === $cur['title'] );
				if ( ! $is_name || '' === $tok['text'] ) {
					continue;
				}
				if ( null !== $cur && '' !== $cur['title'] ) {
					$stories[] = self::finish( $cur, 'story' );
					$cur = null;
				}
				if ( null === $cur ) {
					$cur = self::blank();
				}
				$cur['title'] = rtrim( $tok['text'], " !" );
				continue;
			}
			// Paragraph.
			if ( null === $cur || '' === $tok['text'] ) {
				continue;
			}
			$text = $tok['text'];
			if ( preg_match( '/^\*?\s*(.*?)\s*\bupdate\s+received\b\s*:?\s*(.*)$/i', $text, $m ) ) {
				$cur['received'] = self::parse_month( $m[ 2 ] );
				if ( '' !== $cur['title'] ) {
					$stories[] = self::finish( $cur, 'story' );
				}
				$cur = null;
				continue;
			}
			if ( '' === $cur['title'] ) {
				if ( '' === $cur['intro'] && self::looks_like_intro( $text ) ) {
					$cur['intro'] = $text;
				}
				continue;
			}
			$cur['text'][] = $text;
		}
		if ( null !== $cur && '' !== $cur['title'] ) {
			$stories[] = self::finish( $cur, 'story' );
		}
		return $stories;
	}

	/**
	 * Parse the Testimonials page content.
	 *
	 * @param string $html Page content.
	 * @return array[] Stories.
	 */
	public static function parse_testimonials( $html ) {
		$tokens = self::tokenize( $html );
		$stories = array();
		$pending = null;
		foreach ( $tokens as $tok ) {
			if ( 'paragraph' !== $tok['type'] || '' === $tok['text'] ) {
				continue;
			}
			$text = $tok['text'];
			// A lone "- Name" line that follows a quote.
			if ( null !== $pending && preg_match( '/^[-\x{2013}\x{2014}]\s*(\S.{0,79})$/u', $text, $m ) ) {
				$stories[] = self::testimonial( $pending, $m[ 1 ] );
				$pending = null;
				continue;
			}
			if ( preg_match( '/^(.*\S)\s+[-\x{2013}\x{2014}]\s+(\S[^\n]{0,79})$/us', $text, $m )
				&& str_word_count( $m[ 2 ] ) <= 10 && strlen( $m[ 1 ] ) > 40 ) {
				$stories[] = self::testimonial( $m[ 1 ], $m[ 2 ] );
				$pending = null;
				continue;
			}
			$pending = strlen( $text ) > 40 ? $text : null;
		}
		return $stories;
	}

	/**
	 * Stable hash used for de-duplication.
	 *
	 * @param array $story Story array.
	 * @return string
	 */
	public static function hash( array $story ) {
		$body = strtolower( preg_replace( '/\s+/u', ' ', implode( ' ', $story['text'] ) ) );
		return sha1( $story['kind'] . '|' . strtolower( trim( $story['title'] ) ) . '|' . substr( $body, 0, 300 ) );
	}

	/**
	 * Parse "May 2021" into "2021-05". Returns '' when not recognised.
	 *
	 * @param string $text Date text.
	 * @return string
	 */
	public static function parse_month( $text ) {
		$months = array(
			'jan' => 1,
			'feb' => 2,
			'mar' => 3,
			'apr' => 4,
			'may' => 5,
			'jun' => 6,
			'jul' => 7,
			'aug' => 8,
			'sep' => 9,
			'oct' => 10,
			'nov' => 11,
			'dec' => 12,
		);
		if ( preg_match( '/([A-Za-z]{3,9})\.?,?\s*(\d{4})/', $text, $m ) ) {
			$key = strtolower( substr( $m[ 1 ], 0, 3 ) );
			if ( isset( $months[ $key ] ) ) {
				return sprintf( '%04d-%02d', (int) $m[ 2 ], $months[ $key ] );
			}
		}
		return '';
	}

	/**
	 * Strip a WordPress size suffix such as -768x1024 from an image URL.
	 *
	 * @param string $url Image URL.
	 * @return string
	 */
	public static function strip_size_suffix( $url ) {
		return preg_replace( '/-\d+x\d+(\.[A-Za-z0-9]+)(\?.*)?$/', '$1', $url );
	}

	/**
	 * Blank story.
	 *
	 * @return array
	 */
	private static function blank() {
		return array(
			'kind'     => 'story',
			'title'    => '',
			'intro'    => '',
			'text'     => array(),
			'family'   => '',
			'received' => '',
			'image'    => '',
		);
	}

	/**
	 * Finish a story and add the hash.
	 *
	 * @param array  $story Story.
	 * @param string $kind  Kind.
	 * @return array
	 */
	private static function finish( array $story, $kind ) {
		$story['kind'] = $kind;
		$story['hash'] = self::hash( $story );
		return $story;
	}

	/**
	 * Build a testimonial.
	 *
	 * @param string $quote Quote.
	 * @param string $name  Name.
	 * @return array
	 */
	private static function testimonial( $quote, $name ) {
		$story = self::blank();
		$story['title'] = trim( $name );
		$story['family'] = trim( $name );
		$story['text'] = array( trim( $quote ) );
		return self::finish( $story, 'testimonial' );
	}

	/**
	 * Is this a short lead-in such as "Welcome home,"?
	 *
	 * @param string $text Text.
	 * @return bool
	 */
	private static function looks_like_intro( $text ) {
		return strlen( $text ) <= 60 && ( preg_match( '/^welcome\b/i', $text ) || preg_match( '/[,:]$/', $text ) );
	}

	/**
	 * Flatten HTML into ordered image, heading and paragraph tokens.
	 *
	 * @param string $html HTML (block comments allowed).
	 * @return array[]
	 */
	public static function tokenize( $html ) {
		$html = preg_replace( '/<!--.*?-->/s', '', (string) $html );
		if ( '' === trim( $html ) ) {
			return array();
		}
		$previous = libxml_use_internal_errors( true );
		$doc = new \DOMDocument();
		$doc->loadHTML( '<?xml encoding="UTF-8"><div id="homeward-root">' . $html . '</div>' );
		libxml_clear_errors();
		libxml_use_internal_errors( $previous );
		$root = $doc->getElementById( 'homeward-root' );
		if ( ! $root ) {
			return array();
		}
		$tokens = array();
		$buf = '';
		self::walk( $root, $tokens, $buf );
		self::flush( $tokens, $buf );
		return $tokens;
	}

	/**
	 * Walk the DOM in document order.
	 *
	 * @param \DOMNode $node   Node.
	 * @param array    $tokens Tokens (by reference).
	 * @param string   $buf    Text buffer (by reference).
	 */
	private static function walk( $node, array & $tokens, & $buf ) {
		foreach ( $node->childNodes as $child ) {
			if ( XML_TEXT_NODE === $child->nodeType ) {
				$buf .= $child->nodeValue;
				continue;
			}
			if ( XML_ELEMENT_NODE !== $child->nodeType ) {
				continue;
			}
			$name = strtolower( $child->nodeName );
			if ( 'img' === $name ) {
				self::flush( $tokens, $buf );
				$src = $child->getAttribute( 'src' );
				if ( '' === $src ) {
					$src = $child->getAttribute( 'data-src' );
				}
				if ( '' !== $src ) {
					$tokens[] = array( 'type' => 'image', 'url' => $src );
				}
			} elseif ( 'br' === $name ) {
				self::flush( $tokens, $buf );
			} elseif ( preg_match( '/^h([1-6])$/', $name, $m ) ) {
				self::flush( $tokens, $buf );
				$tokens[] = array( 'type' => 'heading', 'level' => (int) $m[ 1 ], 'text' => self::clean( $child->textContent ), );
			} elseif ( in_array( $name, array( 'script', 'style', 'iframe' ), true ) ) {
				self::flush( $tokens, $buf );
			} elseif ( in_array( $name, self::BLOCK_TAGS, true ) ) {
				self::flush( $tokens, $buf );
				self::walk( $child, $tokens, $buf );
				self::flush( $tokens, $buf );
			} else {
				self::walk( $child, $tokens, $buf );
			}
		}
	}

	/**
	 * Push the text buffer as a paragraph token.
	 *
	 * @param array  $tokens Tokens.
	 * @param string $buf    Buffer.
	 */
	private static function flush( array & $tokens, & $buf ) {
		$text = self::clean( $buf );
		$buf = '';
		if ( '' !== $text ) {
			$tokens[] = array( 'type' => 'paragraph', 'text' => $text );
		}
	}

	/**
	 * Normalise whitespace.
	 *
	 * @param string $text Text.
	 * @return string
	 */
	private static function clean( $text ) {
		$text = str_replace( "\xC2\xA0", ' ', $text );
		return trim( preg_replace( '/\s+/u', ' ', $text ) );
	}
}
