<?php
/**
 * Description cleaner: turns Petstablished's pasted HTML into tidy, readable
 * markup. Pure PHP (wp_kses is applied by the caller / Pet_Data::kses when
 * WordPress is loaded), so it can be tested with plain PHP CLI.
 *
 * @package Homeward
 */
namespace Homeward;

defined( 'ABSPATH' ) || exit;

/**
 * Clean animal descriptions and derive readable text excerpts.
 */
class Description {
	/**
	 * Acronyms kept upper case when normalising ALL-CAPS headings.
	 *
	 * @var string[]
	 */
	const ACRONYMS = array( 'TV', 'AKA', 'ASAP', 'FAQ', 'BBQ', 'ADHD' );

	/**
	 * Tags allowed in stored and displayed descriptions (wp_kses format).
	 *
	 * @return array
	 */
	public static function allowed_html() {
		$plain = array();
		$tags = array(
			'p',
			'br',
			'strong',
			'b',
			'em',
			'i',
			'u',
			'ul',
			'ol',
			'li',
			'h1',
			'h2',
			'h3',
			'h4',
			'h5',
			'h6',
			'blockquote',
			'span',
			'div',
			'font',
		);
		foreach ( $tags as $t ) {
			$plain[ $t ] = array();
		}
		$plain['a'] = array( 'href' => true, 'target' => true, 'rel' => true, );
		return $plain;
	}

	/**
	 * Clean description HTML for display.
	 *
	 * - strips inline styles, fonts, spans, divs and every attribute but link hrefs
	 * - turns double line breaks into paragraphs, removes empty paragraphs
	 * - short ALL-CAPS (or all-bold) lines become sentence-case <h3> headings
	 * - "ENERGY LEVEL: Moderate" style labels become "<strong>Energy level:</strong> Moderate"
	 *
	 * @param string $html Raw (already kses-sanitised) HTML.
	 * @return string
	 */
	public static function clean( $html ) {
		$h = (string) $html;
		if ( '' === trim( $h ) ) {
			return '';
		}
		// Whitespace and entities.
		$h = str_replace( array( "\r\n", "\r", '&nbsp;', '&#160;', "\xC2\xA0" ), array( "\n", "\n", ' ', ' ', ' ' ), $h );
		$h = preg_replace( '/&#(?:0*160|x0*a0);/i', ' ', $h );
		// Dangerous / irrelevant blocks with their content.
		$h = preg_replace( '#<(script|style|iframe|object|embed|noscript|title|head)\b[^>]*>.*?</\1\s*>#is', '', $h );
		$h = preg_replace( '#<!--.*?-->#s', '', $h );
		// Divs act as line breaks; everything outside our tag list is unwrapped.
		$h = preg_replace( '#</div\s*>#i', '<br>', $h );
		$h = strip_tags( $h, '<p><br><strong><b><em><i><ul><ol><li><a><h1><h2><h3><h4><h5><h6><blockquote>' );
		// Normalise tags and attributes.
		$h = preg_replace_callback( '#<(/?)([a-z0-9]+)\b([^>]*)>#i', array( __CLASS__, 'normalise_tag' ), $h );
		$h = preg_replace( '#<a>(.*?)</a>#s', '$1', $h );
		// Collapse whitespace around tags; paragraphs and breaks.
		$h = preg_replace( '/[ \t]+/', ' ', $h );
		$h = preg_replace( '#\s*<br>\s*#', '<br>', $h );
		$h = preg_replace( '#(<br>){2,}#', '</p><p>', $h );
		$h = preg_replace( '#\s*(</?(?:p|h3|ul|ol|li|blockquote)>)\s*#', '$1', $h );
		// Ensure top-level text sits inside block elements.
		$h = self::wrap_orphans( $h );
		// Paragraph-level tidy.
		$h = preg_replace( '#<p>(?:<br>)+#', '<p>', $h );
		$h = preg_replace( '#(?:<br>)+</p>#', '</p>', $h );
		$h = preg_replace( '#<(strong|em)>\s*</\1>#', '', $h );
		$h = preg_replace( '#<p>(?:\s|<br>)*</p>#', '', $h );
		$h = preg_replace( '#<li>(?:\s|<br>)*</li>#', '', $h );
		$h = preg_replace( '#<(ul|ol)></\1>#', '', $h );
		// Headings and labels.
		$h = preg_replace_callback( '#<p>(.*?)</p>#s', array( __CLASS__, 'maybe_heading' ), $h );
		$h = preg_replace_callback( '#<h3>(.*?)</h3>#s', array( __CLASS__, 'heading_case' ), $h );
		$h = self::balance( $h );
		$h = self::bold_labels( $h );
		$h = preg_replace( '#<(strong|em)>\s*</\1>#', '', $h );
		// One block per line for readable source.
		$h = preg_replace( '#(</(?:p|h3|ul|ol|blockquote)>)#', "$1\n", $h );
		return trim( $h );
	}

	/**
	 * Top-level blocks of cleaned HTML.
	 *
	 * @param string $clean Output of clean().
	 * @return string[]
	 */
	public static function blocks( $clean ) {
		$tokens = preg_split( '#(</?(?:p|h3|ul|ol|blockquote)>)#', (string) $clean, -1, PREG_SPLIT_DELIM_CAPTURE );
		$depth = 0;
		$block = '';
		$out = array();
		foreach ( $tokens as $token ) {
			if ( preg_match( '#^<(p|h3|ul|ol|blockquote)>$#', $token ) ) {
				++$depth;
			}
			if ( $depth ) {
				$block .= $token;
			}
			if ( preg_match( '#^</(p|h3|ul|ol|blockquote)>$#', $token ) && $depth ) {
				--$depth;
				if ( ! $depth ) {
					$out[] = $block;
					$block = '';
				}
			}
		}
		return $out;
	}

	/**
	 * Split into [visible, more] for the "Keep reading" expander.
	 *
	 * Long descriptions (> $threshold characters of text) show the first $keep
	 * paragraphs; a heading at the cut point moves into the hidden part.
	 *
	 * @param string $clean     Output of clean().
	 * @param int    $threshold Plain-text length that triggers the split.
	 * @param int    $keep      Paragraphs to keep visible.
	 * @return string[] Two HTML strings; the second is '' when not split.
	 */
	public static function split( $clean, $threshold = 1800, $keep = 3 ) {
		$blocks = self::blocks( $clean );
		if ( mb_strlen( self::plain_text( $clean ), 'UTF-8' ) <= $threshold ) {
			return array( implode( "\n", $blocks ), '' );
		}
		// Some pasted stories are one enormous paragraph. Add readable breaks,
		// retaining inline formatting and links, so these can expand as well.
		if ( count( $blocks ) <= $keep ) {
			$expanded = array();
			foreach ( $blocks as $block ) {
				$expanded = array_merge( $expanded, self::paragraph_chunks( $block ) );
			}
			$blocks = $expanded;
		}
		$visible = array();
		$paras = 0;
		$i = 0;
		for ( $n = count( $blocks ); $i < $n && $paras < $keep; $i++ ) {
			$visible[] = $blocks[ $i ];
			if ( ! str_starts_with( $blocks[ $i ], '<h3>' ) ) {
				++$paras;
			}
		}
		// Don't end the visible part on a heading.
		while ( $visible && str_starts_with( end( $visible ), '<h3>' ) ) {
			array_pop( $visible );
			--$i;
		}
		$rest = array_slice( $blocks, $i );
		if ( ! $rest ) {
			return array( implode( "\n", $blocks ), '' );
		}
		return array( implode( "\n", $visible ), implode( "\n", $rest ) );
	}

	/**
	 * Split very long paragraphs at word boundaries, balancing inline tags.
	 */
	private static function paragraph_chunks( $block ) {
		if ( ! str_starts_with( $block, '<p>' ) || mb_strlen( self::plain_text( $block ) ) < 700 ) {
			return array( $block );
		}
		$tokens = preg_split( '/(<[^>]+>|\s+)/u', substr( $block, 3, -4 ), -1, PREG_SPLIT_DELIM_CAPTURE );
		$out = array();
		$stack = array();
		$part = '<p>';
		$length = 0;
		foreach ( $tokens as $token ) {
			if ( preg_match( '/^<(strong|em|a)\b/', $token, $m ) ) {
				$stack[] = array( $m[ 1 ], $token );
			} elseif ( preg_match( '#^</(strong|em|a)>$#', $token ) ) {
				array_pop( $stack );
			} elseif ( ! str_starts_with( $token, '<' ) ) {
				$length += mb_strlen( html_entity_decode( $token, ENT_QUOTES | ENT_HTML5, 'UTF-8' ) );
			}
			$part .= $token;
			if ( $length >= 500 && preg_match( '/^\s+$/u', $token ) ) {
				foreach ( array_reverse( $stack ) as $tag ) {
					$part .= '</' . $tag[ 0 ] . '>';
				}
				$out[] = $part . '</p>';
				$part = '<p>' . implode( '', array_column( $stack, 1 ) );
				$length = 0;
			}
		}
		if ( '' !== self::plain_text( $part ) ) {
			$out[] = $part . '</p>';
		}
		return $out;
	}

	/**
	 * Plain text from HTML.
	 *
	 * @param string $html HTML.
	 * @return string
	 */
	public static function plain_text( $html ) {
		$t = preg_replace( '#<(br|/p|/h\d|/li)\b[^>]*>#i', ' ', (string) $html );
		$t = html_entity_decode( strip_tags( $t ), ENT_QUOTES | ENT_HTML5, 'UTF-8' );
		return Pet_Data::squish( $t );
	}

	/**
	 * Choose a short personality excerpt from editorial or source text.
	 *
	 * @param string $blurb Editorial introduction.
	 * @param string $html  Source description.
	 * @param string $name  Animal name to omit from repeated openings.
	 * @return string
	 */
	public static function personality( $blurb, $html, $name = '' ) {
		$name = self::plain_text( $name );
		foreach ( array( $blurb, $html ) as $source ) {
			$clean = self::clean( $source );
			// clean() sentence-cases headings; discard their contents before flattening.
			$clean = preg_replace( '#<h3>.*?</h3>#s', "\n", $clean );
			$clean = preg_replace( '#</?(?:p|li|ul|ol|blockquote)>|<br>#', "\n", $clean );
			foreach ( preg_split( '/\R/u', $clean ) as $line ) {
				$line = self::plain_text( $line );
				if ( self::is_shouting( $line ) ) {
					continue;
				}
				// Keep titles such as "Ms. Marley" together, and retain unpunctuated lines.
				preg_match_all(
					'/.*?(?:[!?]|(?<!\bMr)(?<!\bMrs)(?<!\bMs)(?<!\bDr)\.)(?:["”’\x{0027}])?(?=\s|$)|.+$/iu',
					$line,
					$sentences
				);
				foreach ( $sentences[ 0 ] as $sentence ) {
					$sentence = preg_replace( '/^[\s"“”‘’\x{0027}]+|[\s"“”‘’\x{0027}]+$/u', '', $sentence );
					if ( self::is_shouting( $sentence ) || preg_match(
						'/^(?:hi|hello|hey|greetings|howdy|my\s+name\s+is|meet|here\s+is\s+some\s+info(?:rmation)?|ready\s+for)\b/iu',
						$sentence
						) || preg_match(
							'/^(?i:i(?:[’\x{0027}]m|\s+am)\s+)\p{Lu}[\p{L}\p{M}’\x{0027}-]*/u',
							$sentence
						) || ( '' !== $name && preg_match(
							'/^i(?:[’\x{0027}]m|\s+am)\s+' . preg_quote( $name, '/' ) . '\b/iu',
							$sentence
						) ) || preg_match(
							'/^(?:(?:primary|secondary)\s+breed|breed|age|weight|sex|size|location|adoption\s+fee)\s*:/iu',
							$sentence
						) || str_ends_with( $sentence, '?' ) ) {
						continue;
					}
					// Rescue listings often start the trait line with a "Highlight(s):" label.
					$sentence = preg_replace( '/^(?:personality\s+)?highlights?(?:\s*\(s\))?\s*[:\-–]\s*/iu', '', $sentence );
					if ( '' !== $name ) {
						$sentence = preg_replace( '/^' . preg_quote( $name, '/' ) . '\s+is\s+/iu', '', $sentence );
					}
					// Judge the displayed copy, not a long name or a greeting plus a fragment.
					if ( mb_strlen( $sentence, 'UTF-8' ) < 25 || ! preg_match(
						'/\b(?:loves?|enjoys?|gentl[ey]|playful|sweet|calm|cuddl\p{L}*|energetic|shy|friendly|smart|loyal|'
						. 'affectionate|curious|goofy|snuggl\p{L}*|plays?|fetch|walks?|follows?)\b/iu',
						$sentence
					) ) {
						continue;
					}
					$sentence = Text::upper( mb_substr( $sentence, 0, 1, 'UTF-8' ), 'UTF-8' )
						. mb_substr( $sentence, 1, null, 'UTF-8' );
					$excerpt = self::excerpt( $sentence, 90 );
					if ( mb_strlen( $excerpt, 'UTF-8' ) >= 25 ) {
						return $excerpt;
					}
				}
			}
		}
		return '';
	}

	/**
	 * Excerpt on a word boundary.
	 *
	 * @param string $text Plain text.
	 * @param int    $max  Max characters.
	 * @return string
	 */
	public static function excerpt( $text, $max = 160 ) {
		$text = Pet_Data::squish( $text );
		if ( mb_strlen( $text, 'UTF-8' ) <= $max ) {
			return $text;
		}
		$cut = mb_substr( $text, 0, $max, 'UTF-8' );
		$sp = Text::last_position( $cut, ' ', 0, 'UTF-8' );
		if ( false === $sp ) {
			return '';
			// No complete word fits alongside the ellipsis.
		}
		$cut = mb_substr( $cut, 0, $sp, 'UTF-8' );
		return preg_replace( '/[\s,;:.\-–\x{2014}]+$/u', '', $cut ) . '…';
	}

	/**
	 * "GETS ALONG WITH" → "Gets along with", keeping known acronyms.
	 *
	 * @param string $s Text.
	 * @return string
	 */
	public static function sentence_case( $s ) {
		$words = preg_split( '/(\s+)/u', Pet_Data::squish( $s ), -1, PREG_SPLIT_DELIM_CAPTURE );
		$first = true;
		foreach ( $words as & $w ) {
			if ( '' === trim( $w ) ) {
				continue;
			}
			$bare = preg_replace( '/[^\p{L}]/u', '', $w );
			if ( in_array( $bare, (array) apply_filters( 'homeward_description_acronyms', self::ACRONYMS ), true )
				&& ! $first ) {
				continue;
			}
			$w = Text::lower( $w, 'UTF-8' );
			if ( $first && preg_match( '/\p{L}/u', $w ) ) {
				$w = preg_replace_callback(
					'/\p{Ll}/u',
					function ( $m ) {
						return Text::upper( $m[ 0 ], 'UTF-8' );
					},
					$w,
					1
				);
				$first = false;
			}
		}
		return implode( '', $words );
	}

	/**
	 * Is this text mostly upper case (and has at least 3 letters)?
	 *
	 * @param string $text Plain text.
	 * @return bool
	 */
	public static function is_shouting( $text ) {
		$letters = preg_replace( '/[^\p{L}]/u', '', (string) $text );
		if ( mb_strlen( $letters, 'UTF-8' ) < 3 ) {
			return false;
		}
		return ! preg_match( '/\p{Ll}/u', $letters );
	}

	/**
	 * Tag normaliser callback.
	 *
	 * @param array $m Regex match.
	 * @return string
	 */
	private static function normalise_tag( $m ) {
		$close = '/' === $m[ 1 ];
		$tag = strtolower( $m[ 2 ] );
		$map = array(
			'b'  => 'strong',
			'i'  => 'em',
			'h1' => 'h3',
			'h2' => 'h3',
			'h4' => 'h3',
			'h5' => 'h3',
			'h6' => 'h3',
		);
		$tag = $map[ $tag ] ?? $tag;
		if ( 'br' === $tag ) {
			return '<br>';
		}
		if ( 'a' !== $tag ) {
			return $close ? "</{$tag}>" : "<{$tag}>";
		}
		if ( $close ) {
			return '</a>';
		}
		$href = '';
		if ( preg_match( '#href\s*=\s*("([^"]*)"|\'([^\']*)\'|([^\s>]+))#i', $m[ 3 ], $hm ) ) {
			$href = html_entity_decode(
				$hm[ 2 ] ?? '' ? $hm[ 2 ] : ( $hm[ 3 ] ?? '' ? $hm[ 3 ] : ( $hm[ 4 ] ?? '' ) ),
				ENT_QUOTES | ENT_HTML5,
				'UTF-8'
			);
		}
		$href = trim( $href );
		if ( ! preg_match( '#^(https?:|mailto:|tel:)#i', $href ) ) {
			return '<a>';
		}
		$attr = htmlspecialchars( $href, ENT_QUOTES, 'UTF-8' );
		if ( preg_match( '#^https?:#i', $href ) ) {
			return '<a href="' . $attr . '" target="_blank" rel="noopener nofollow">';
		}
		return '<a href="' . $attr . '">';
	}

	/**
	 * Wrap top-level text runs in <p>.
	 *
	 * @param string $h HTML.
	 * @return string
	 */
	private static function wrap_orphans( $h ) {
		$parts = preg_split( '#(<(p|h3|ul|ol|blockquote)>.*?</\2>)#s', $h, -1, PREG_SPLIT_DELIM_CAPTURE );
		$out = '';
		$skip = false;
		foreach ( $parts as $i => $part ) {
			if ( $skip ) {
				// The tag-name capture follows each block capture.
				$skip = false;
				continue;
			}
			if ( preg_match( '#^<(p|h3|ul|ol|blockquote)>#', $part ) && preg_match( '#</(p|h3|ul|ol|blockquote)>$#', $part ) ) {
				// Nested <p> inside a paragraph (sloppy source): flatten.
				$out .= preg_replace( '#(?<!^)<p>|</p>(?!$)#', '<br>', $part );
				$skip = true;
				continue;
			}
			$part = preg_replace( '#</?(p|h3|ul|ol|li|blockquote)>#', '<br>', $part );
			if ( '' !== trim( strip_tags( $part ) ) ) {
				$out .= '<p>' . $part . '</p>';
			}
		}
		return $out;
	}

	/**
	 * Turn a short all-caps or all-bold paragraph into an <h3>.
	 *
	 * @param array $m Regex match of <p>(inner)</p>.
	 * @return string
	 */
	private static function maybe_heading( $m ) {
		$inner = $m[ 1 ];
		if ( str_contains( $inner, '<br>' ) || str_contains( $inner, '<a ' ) ) {
			return $m[ 0 ];
		}
		$text = Pet_Data::squish( html_entity_decode( strip_tags( $inner ), ENT_QUOTES | ENT_HTML5, 'UTF-8' ) );
		$len = mb_strlen( $text, 'UTF-8' );
		if ( $len < 3 || $len > 60 || preg_match( '/[.!?…]$/u', $text ) || preg_match( '/:\s*\S/u', $text ) ) {
			return $m[ 0 ];
		}
		$all_bold = (bool) preg_match( '#^<strong>[^<]*</strong>:?$#', trim( $inner ) )
			&& ! preg_match( '/[.!?]\s/u', $text );
		if ( self::is_shouting( $text ) || $all_bold ) {
			return '<h3>' . htmlspecialchars( rtrim( $text, ': ' ), ENT_QUOTES, 'UTF-8', false ) . '</h3>';
		}
		return $m[ 0 ];
	}

	/**
	 * Sentence-case a shouting heading.
	 *
	 * @param array $m Regex match of <h3>(inner)</h3>.
	 * @return string
	 */
	private static function heading_case( $m ) {
		$text = Pet_Data::squish( html_entity_decode( strip_tags( $m[ 1 ] ), ENT_QUOTES | ENT_HTML5, 'UTF-8' ) );
		$text = rtrim( $text, ': ' );
		if ( '' === $text ) {
			return '';
		}
		if ( self::is_shouting( $text ) ) {
			$text = self::sentence_case( $text );
		}
		return '<h3>' . htmlspecialchars( $text, ENT_QUOTES, 'UTF-8' ) . '</h3>';
	}

	/**
	 * Bold short "Label:" prefixes at the start of a paragraph or line, normalising shouting.
	 *
	 * @param string $h HTML.
	 * @return string
	 */
	private static function bold_labels( $h ) {
		$label = '([\p{L}][\p{L}0-9 &/\'’()-]{1,38}?)';
		$fix = function ( $m ) {
			$text = trim( $m[ 2 ] );
			$words = count( preg_split( '/\s+/', $text ) );
			$shout = self::is_shouting( $text ) || ( mb_strlen( preg_replace( '/[^\p{L}]/u', '', $text ), 'UTF-8' ) < 3
				&& ! preg_match( '/\p{Ll}/u', $text ) );
			// Mixed-case labels only when short (avoid bolding the start of real sentences).
			if ( ! $shout && $words > 3 ) {
				return $m[ 0 ];
			}
			if ( $shout ) {
				$text = self::sentence_case( $text );
			}
			return $m[ 1 ] . '<strong>' . $text . ':</strong> ';
		};
		// Already bold: <strong>LABEL:</strong> or <strong>LABEL</strong>:.
		$h = preg_replace_callback(
			'#(<p>|<br>|<li>)<strong>\s*' . $label . '\s*(?::\s*</strong>|</strong>\s*:)\s*#u',
			$fix,
			$h
		);
		// Plain: LABEL: text.
		$h = preg_replace_callback( '#(<p>|<br>|<li>)\s*' . $label . '\s*:(?=[\s<]|$)\s*#u', $fix, $h );
		return preg_replace( '#:</strong> (</p>|</li>|<br>)#', ':</strong>$1', $h );
	}

	/**
	 * Balance inline tags (strong, em, a) within each block: drop stray closers,
	 * close anything left open at the end of a block.
	 *
	 * @param string $h HTML.
	 * @return string
	 */
	public static function balance( $h ) {
		$tokens = preg_split( '#(</?(?:strong|em|a)\b[^>]*>|</(?:p|li|h3|blockquote)>)#', $h, -1, PREG_SPLIT_DELIM_CAPTURE );
		$out = '';
		$stack = array();
		foreach ( $tokens as $t ) {
			if ( preg_match( '#^<(strong|em|a)\b#', $t, $m ) ) {
				$stack[] = $m[ 1 ];
				$out .= $t;
			} elseif ( preg_match( '#^</(strong|em|a)>$#', $t, $m ) ) {
				if ( $stack && end( $stack ) === $m[ 1 ] ) {
					array_pop( $stack );
					$out .= $t;
				}
			} elseif ( preg_match( '#^</(p|li|h3|blockquote)>$#', $t ) ) {
				while ( $stack ) {
					$out .= '</' . array_pop( $stack ) . '>';
				}
				$out .= $t;
			} else {
				$out .= $t;
			}
		}
		while ( $stack ) {
			$out .= '</' . array_pop( $stack ) . '>';
		}
		return $out;
	}
}
