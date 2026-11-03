<?php
/**
 * Pure functions for Petstablished pet data: privacy whitelist, name cleaning,
 * origin mapping, ages, sizes and breeds. Offline WordPress doubles exercise
 * these helpers with plain PHP CLI, including version-specific date diagnostics.
 *
 * @package Homeward
 */
namespace Homeward;

defined( 'ABSPATH' ) || exit;

/**
 * Normalize source records using an explicit public field allow-list.
 */
class Pet_Data {
	/**
	 * Every key sanitize_pet() can ever return. Nothing else is stored.
	 *
	 * @var string[]
	 */
	const WHITELIST = array(
		'ps_id',
		'species',
		'raw_name',
		'status',
		'no_longer_available',
		'age_group',
		'dob',
		'age_text',
		'sex',
		'size',
		'primary_breed',
		'secondary_breed',
		'weight',
		'has_special_need',
		'special_needs',
		'is_bonded',
		'ok_dogs',
		'ok_cats',
		'ok_kids',
		'housebroken',
		'is_spayed',
		'shots_up_to_date',
		'adoption_fee',
		'description',
		'photos',
		'thumb_url',
		'adopt_url',
		'youtube_url',
		'created_at',
		'date_received',
		'date_aquired',
		'updated_at',
	);
	/**
	 * Clean regions used for the origin filter and card label.
	 *
	 * @return string[]
	 */
	public static function regions() {
		return array_values( array_unique( array_column( Settings::get( 'origin_rules' ), 'region' ) ) );
	}

	/**
	 * Whitelist a raw listing or detail record from Petstablished.
	 *
	 * Only keys present in the input are returned, so a listing record can be
	 * merged over stored detail data without wiping detail-only fields.
	 *
	 * @param array $raw Raw decoded JSON object for one pet.
	 * @return array Whitelisted, normalised data.
	 */
	public static function sanitize_pet( array $raw ) {
		$out = array_merge(
			self::identity_fields( $raw ),
			self::profile_fields( $raw ),
			self::compatibility_fields( $raw ),
			self::photo_fields( $raw ),
			self::link_and_date_fields( $raw )
		);
		return array_intersect_key( $out, array_flip( self::WHITELIST ) );
	}

	private static function identity_fields( $raw ) {
		$out = array();
		if ( isset( $raw['animal'] ) || isset( $raw['species'] ) ) {
			$species = strtolower( self::text( $raw['animal'] ?? $raw['species'], 20 ) );
			$out['species'] = in_array( $species, array( 'dog', 'cat' ), true ) ? $species : 'other';
		}
		$id = $raw['id'] ?? ( $raw['pet_id'] ?? null );
		if ( is_numeric( $id ) ) {
			$out['ps_id'] = (int) $id;
		}
		if ( array_key_exists( 'name', $raw ) ) {
			$out['raw_name'] = self::text( $raw['name'], 120 );
		}
		if ( array_key_exists( 'status', $raw ) ) {
			$out['status'] = self::text( $raw['status'], 40 );
		}
		if ( array_key_exists( 'no_longer_available', $raw ) ) {
			$out['no_longer_available'] = true === self::tri( $raw['no_longer_available'] );
		}
		return $out;
	}

	private static function profile_fields( $raw ) {
		$out = array();
		if ( array_key_exists( 'age', $raw ) ) {
			$age = ucfirst( strtolower( self::text( $raw['age'], 20 ) ) );
			$out['age_group'] = in_array( $age, array( 'Puppy', 'Kitten', 'Young', 'Adult', 'Senior' ), true ) ? $age : '';
		}
		if ( array_key_exists( 'date_of_birth', $raw ) && is_string( $raw['date_of_birth'] ) ) {
			$dob = trim( $raw['date_of_birth'] );
			if ( preg_match( '/^(\d{4}-\d{2}-\d{2})/', $dob, $m ) ) {
				$out['dob'] = $m[ 1 ];
			} elseif ( preg_match( '/\d+\s*(year|month)/i', $dob ) ) {
				$out['age_text'] = self::text( $dob, 40 );
			}
		}
		if ( array_key_exists( 'sex', $raw ) ) {
			$sex = ucfirst( strtolower( self::text( $raw['sex'], 10 ) ) );
			$out['sex'] = in_array( $sex, array( 'Male', 'Female' ), true ) ? $sex : '';
		}
		if ( array_key_exists( 'size', $raw ) ) {
			$out['size'] = self::normalize_size( self::text( $raw['size'], 80 ) );
		}
		foreach ( array( 'primary_breed', 'secondary_breed' ) as $k ) {
			if ( array_key_exists( $k, $raw ) ) {
				$out[ $k ] = self::text( $raw[ $k ], 80 );
			}
		}
		if ( array_key_exists( 'weight', $raw ) ) {
			$out['weight'] = self::text( $raw['weight'], 30 );
		}
		return $out;
	}

	private static function compatibility_fields( $raw ) {
		$out = array();
		// Compatibility is read only from provider toggles, never from description text.
		$bools = array(
			'has_special_need'      => 'has_special_need',
			'is_bonded'             => 'is_bonded',
			'is_ok_with_other_dogs' => 'ok_dogs',
			'is_ok_with_other_cats' => 'ok_cats',
			'is_ok_with_other_kids' => 'ok_kids',
			// Prefer these names when present, including explicit unknown values.
			'is_ok_with_cats'  => 'ok_cats',
			'is_ok_with_kids'  => 'ok_kids',
			'is_housebroken'   => 'housebroken',
			'is_spayed'        => 'is_spayed',
			'shots_up_to_date' => 'shots_up_to_date',
		);
		foreach ( $bools as $from => $to ) {
			if ( array_key_exists( $from, $raw ) ) {
				$out[ $to ] = self::tri( $raw[ $from ] );
			}
		}
		if ( array_key_exists( 'special_needs', $raw ) ) {
			$out['special_needs'] = self::text( $raw['special_needs'], 300 );
			if ( in_array( strtolower( $out['special_needs'] ), array( 'none', 'no', 'n/a', 'false', '0' ), true ) ) {
				$out['special_needs'] = '';
			}
		}
		if ( array_key_exists( 'adoption_fee', $raw ) ) {
			$fee = preg_replace( '/[^0-9.]/', '', self::text( $raw['adoption_fee'], 30 ) );
			$out['adoption_fee'] = ( is_numeric( $fee ) && (float) $fee >= 0 ) ? $fee : '';
		}
		if ( array_key_exists( 'description', $raw ) ) {
			$out['description'] = self::kses( is_string( $raw['description'] ) ? $raw['description'] : '' );
		}
		return $out;
	}

	private static function photo_fields( $raw ) {
		$out = array();
		if ( isset( $raw['photos'] ) && is_array( $raw['photos'] ) ) {
			$photos = array();
			foreach ( $raw['photos'] as $i => $p ) {
				if ( ! is_array( $p ) ) {
					continue;
				}
				$full = self::photo_url( $p['main_photo_url'] ?? '' );
				$th = self::photo_url( $p['thumb_url'] ?? '' );
				if ( '' === $full && '' === $th ) {
					continue;
				}
				$photos[] = array(
					'full'  => $full ? $full : $th,
					'thumb' => $th ? $th : $full,
					'pos'   => isset( $p['position'] ) ? (int) $p['position'] : $i + 1,
				);
			}
			usort(
				$photos,
				function ( $a, $b ) {
					return $a['pos'] <=> $b['pos'];
				}
			);
			$out['photos'] = array_map(
				function ( $p ) {
					return array( 'full' => $p['full'], 'thumb' => $p['thumb'] );
				},
				$photos
			);
		} elseif ( ! empty( $raw['images'] ) && is_array( $raw['images'] ) ) {
			$photos = array();
			foreach ( $raw['images'] as $p ) {
				$u = is_array( $p ) ? self::photo_url( $p['thumb_url'] ?? '' ) : '';
				if ( $u ) {
					$photos[] = array( 'full' => $u, 'thumb' => $u );
				}
			}
			if ( $photos ) {
				// Listings have thumbnails only: never replace the detail gallery.
				$out['thumb_url'] = $photos[ 0 ]['thumb'];
			}
		}
		return $out;
	}

	private static function link_and_date_fields( $raw ) {
		$out = array();
		if ( array_key_exists( 'thumb_url', $raw ) ) {
			$out['thumb_url'] = self::photo_url( $raw['thumb_url'] );
		}
		if ( array_key_exists( 'adopt_url', $raw ) ) {
			$out['adopt_url'] = self::petstablished_url( $raw['adopt_url'] );
		}
		if ( array_key_exists( 'youtube_url', $raw ) || array_key_exists( 'youtube_urls', $raw ) ) {
			$cands = array_merge( array( $raw['youtube_url'] ?? '' ), (array) ( $raw['youtube_urls'] ?? array() ) );
			$yt = '';
			foreach ( $cands as $c ) {
				if ( is_string( $c ) && '' !== self::youtube_id( $c ) ) {
					$yt = 'https://www.youtube.com/watch?v=' . self::youtube_id( $c );
					break;
				}
			}
			$out['youtube_url'] = $yt;
		}
		foreach ( array( 'created_at', 'date_received', 'date_aquired', 'updated_at' ) as $k ) {
			if ( array_key_exists( $k, $raw ) ) {
				$out[ $k ] = self::date_timestamp( $raw[ $k ] ) ? trim( $raw[ $k ] ) : '';
			}
		}
		return $out;
	}

	/**
	 * Parse an absolute ISO date while rejecting invalid or future values.
	 *
	 * @param mixed $value Source date value.
	 * @return int
	 */
	public static function date_timestamp( $value ) {
		if ( ! is_string( $value ) || ! preg_match(
			'/^\d{4}-\d{2}-\d{2}(?:[T ]\d{2}:\d{2}(?::\d{2}(?:\.\d+)?)?(?:Z|[+-]\d{2}:?\d{2})?)?$/D',
			trim( $value )
		) ) {
			return 0;
		}
		try {
			$date = new \DateTimeImmutable( trim( $value ), new \DateTimeZone( 'UTC' ) );
			$errors = \DateTimeImmutable::getLastErrors();
			$stamp = $date->getTimestamp();
			return self::date_errors_clear( $errors ) && $stamp > 0 && $stamp <= time() ? $stamp : 0;
		} catch ( \Exception $e ) {
			return 0;
		}
	}

	/**
	 * Accept the date-parser diagnostics returned by supported PHP versions.
	 *
	 * @param array|false $errors Date parser diagnostics.
	 * @return bool
	 */
	public static function date_errors_clear( $errors ) {
		return false === $errors || ( is_array( $errors ) && 0 === ( $errors['warning_count'] ?? -1 )
			&& 0 === ( $errors['error_count'] ?? -1 ) );
	}

	/**
	 * Public display name from an internal label.
	 * "BEAR -  (Vanc. Isl) - sf/jo" → "Bear". "Ms. Marley (China) sf" → "Ms. Marley".
	 *
	 * @param string $raw Raw Petstablished name.
	 * @return string
	 */
	public static function clean_name( $raw ) {
		$raw = self::squish( (string) $raw );
		if ( ! Settings::get( 'name_cleaning' ) ) {
			return $raw;
		}
		$parts = self::split_name( $raw );
		$name = trim( $parts[ 0 ], " \t-–:/," );
		if ( '' === $name ) {
			$name = $raw;
		}
		return self::title_case( $name );
	}

	/**
	 * Map the origin hint in a raw name to a clean region ('' when unknown).
	 *
	 * @param string $raw Raw Petstablished name.
	 * @return string
	 */
	public static function map_origin( $raw ) {
		if ( ! Settings::get( 'origin_enabled' ) ) {
			return '';
		}
		$raw = self::squish( (string) $raw );
		$parts = self::split_name( $raw );
		$rest = $parts[ 1 ];
		preg_match_all( '/\(([^)]*)\)/', $rest, $m );
		$bracket = implode( ' ', $m[ 1 ] );
		foreach ( array( $bracket, $rest ) as $hay ) {
			$region = self::match_region( $hay );
			if ( '' !== $region ) {
				return $region;
			}
		}
		return '';
	}

	/**
	 * Region for a free-text origin hint, e.g. "US, N Mexico" → "USA".
	 *
	 * @param string $text Origin text.
	 * @return string
	 */
	public static function match_region( $text ) {
		$t = strtolower( (string) $text );
		$t = Settings::replace_pattern(
			Settings::get( 'courtesy_pattern' ),
			' ',
			$t,
			Settings::defaults()['courtesy_pattern']
		);
		if ( '' === trim( $t ) ) {
			return '';
		}
		$rules = apply_filters( 'homeward_origin_rules', Settings::get( 'origin_rules' ) );
		foreach ( (array) $rules as $rule ) {
			foreach ( (array) ( $rule['patterns'] ?? array() ) as $re ) {
				if ( Settings::match_pattern( $re, $t ) ) {
					return Settings::text( $rule['region'] ?? '' );
				}
			}
		}
		return '';
	}

	/**
	 * Is this one of the generic application placeholders?
	 *
	 * @param string $raw Raw name.
	 * @return bool
	 */
	public static function is_placeholder( $raw ) {
		return (bool) apply_filters(
			'homeward_is_placeholder',
			(bool) Settings::match_pattern(
				Settings::get( 'placeholder_pattern' ),
				(string) $raw,
				Settings::PLACEHOLDER
			),
			$raw
		);
	}

	/**
	 * Is this a "Courtesy Post" (not an Homeward dog)?
	 *
	 * @param string $raw Raw name.
	 * @return bool
	 */
	public static function is_courtesy( $raw ) {
		return (bool) Settings::match_pattern(
			Settings::get( 'courtesy_pattern' ),
			(string) $raw,
			Settings::defaults()['courtesy_pattern']
		);
	}

	/**
	 * Our status key: available, pending, hold or gone.
	 *
	 * @param string $status Petstablished status.
	 * @return string
	 */
	public static function status_key( $status ) {
		$s = strtolower( trim( (string) $status ) );
		if ( 'available' === $s ) {
			return 'available';
		}
		if ( '' === $s ) {
			return 'hold';
		}
		if ( str_contains( $s, 'pending' ) ) {
			return 'pending';
		}
		if ( str_contains( $s, 'hold' ) ) {
			return 'hold';
		}
		if ( in_array( $s, array( 'adopted', 'transferred', 'no_longer_available', 'no longer available' ), true ) ) {
			return 'gone';
		}
		return 'hold';
	}

	/**
	 * Small / Medium / Large / Extra Large ('' when unknown).
	 *
	 * @param string $size Raw size, e.g. "Small 25 lbs (11 kg) or less".
	 * @return string
	 */
	public static function normalize_size( $size ) {
		$s = strtolower( (string) $size );
		if ( preg_match( '/\b(x-?large|extra\s*large|xl|giant)\b/', $s ) ) {
			return 'Extra Large';
		}
		foreach ( array( 'small', 'medium', 'large' ) as $k ) {
			if ( str_contains( $s, $k ) ) {
				return ucfirst( $k );
			}
		}
		return '';
	}

	/**
	 * Human age: "9 months", "1.5 years", "6 years".
	 *
	 * @param string   $dob      ISO date of birth (Y-m-d) or ''.
	 * @param string   $fallback Petstablished text like "6 years, 0 months".
	 * @param int|null $now      Timestamp (for tests).
	 * @return string
	 */
	public static function age_label( $dob, $fallback = '', $now = null ) {
		$now = $now ?? time();
		$months = null;
		if ( $dob && preg_match( '/^(\d{4})-(\d{2})-(\d{2})/', $dob, $m ) ) {
			$y = (int) gmdate( 'Y', $now );
			$mo = (int) gmdate( 'n', $now );
			$d = (int) gmdate( 'j', $now );
			$months = ( $y - (int) $m[ 1 ] ) * 12 + ( $mo - (int) $m[ 2 ] ) - ( $d < (int) $m[ 3 ] ? 1 : 0 );
		} elseif ( $fallback && preg_match( '/(?:(\d+)\s*years?)?[,\s]*(?:(\d+)\s*months?)?/i', $fallback, $m )
			&& ( ! empty( $m[ 1 ] ) || ! empty( $m[ 2 ] ) ) ) {
			$months = (int) ( $m[ 1 ] ?? 0 ) * 12 + (int) ( $m[ 2 ] ?? 0 );
		}
		if ( null === $months || $months < 0 ) {
			return '';
		}
		if ( $months < 12 ) {
			$months = max( 1, $months );
			return 1 === $months ? '1 month' : $months . ' months';
		}
		if ( $months < 36 ) {
			$half = floor( $months / 6 ) / 2;
			return ( 1.0 === (float) $half )
				? '1 year' : rtrim( rtrim( number_format( $half, 1, '.', '' ), '0' ), '.' ) . ' years';
		}
		return floor( $months / 12 ) . ' years';
	}

	/**
	 * One filter family from the primary breed, including mixes. First match wins.
	 *
	 * @param string $primary Primary breed from Petstablished.
	 * @return string
	 */
	public static function breed_family( $primary ) {
		$breed = strtolower( (string) $primary );
		$breed = preg_replace( '/\b(mix|types)\b/', ' ', $breed );
		$breed = preg_replace( '/[^a-z0-9]+/', ' ', $breed );
		// Malinois has a singular final s; preserve it when stripping plurals.
		$breed = preg_replace( '/\b(?!malinois\b)([a-z]+)s\b/', '$1', $breed );
		$families = array(
			'Jindo'              => 'jindo',
			'Husky'              => 'husky|siberian|malamute',
			'Shepherd'           => 'shepherd|malinois|anatolian|kangal',
			'Retriever'          => 'golden|labrador|lab',
			'Collie and herding' => 'collie|sheltie|aussie|corgi|catahoula|heeler|cattle dog',
			// Before Terrier, so "Staffordshire Bull Terrier" and "Pit Bull Terrier" land here.
			'Bully breeds' => 'pit bull|pitbull|pittie|staffordshire|staffie|staffy|amstaff|american bully|'
			. 'bull terrier|bulldog|boxer|shar pei|mastiff|cane corso',
			'Terrier' => 'terrier|jack russell|parson russell',
			'Spitz'   => 'spitz|shiba|pomeranian|samoyed|akita',
			'Hound'   => 'hound|beagle|coonhound|whippet|greyhound|dachshund|ibizan|saluki|podenco|galgo', // scan-allow: overlap (breed names)
			'Pointer'            => 'pointer|vizsla|weimaraner|setter|shorthaired|wirehaired',
			'Spaniel'            => 'spaniel',
			'Guardian and giant' => 'pyrenee|newfoundland|rottweiler|bernese|saint bernard|st bernard|leonberger|great dane',
			'Small breeds'       => 'chihuahua|papillon|poodle|maltese|shih tzu|yorkie', // scan-allow: overlap (breed names)
		);
		$breed = self::squish( $breed );
		foreach ( $families as $family => $keywords ) {
			if ( preg_match( '/\b(' . $keywords . ')\b/', $breed ) ) {
				return $family;
			}
		}
		return 'Other';
	}

	/**
	 * Full breed label: "Jack Russell Terrier / Spitz".
	 *
	 * @param string $primary   Primary breed.
	 * @param string $secondary Secondary breed.
	 * @return string
	 */
	public static function breed_label( $primary, $secondary = '' ) {
		$p = self::clean_breed( $primary );
		$s = self::clean_breed( $secondary );
		if ( $s && 0 !== strcasecmp( $s, $p ) ) {
			return $p ? $p . ' / ' . $s : $s;
		}
		return $p;
	}

	/**
	 * Short breed label for cards: "Jindo Mix".
	 *
	 * @param string $primary   Primary breed.
	 * @param string $secondary Secondary breed.
	 * @return string
	 */
	public static function breed_short( $primary, $secondary = '' ) {
		$p = self::clean_breed( $primary );
		$s = self::clean_breed( $secondary );
		if ( '' === $p ) {
			return $s;
		}
		if ( $s && ! preg_match( '/\bmix(es|ed)?\b/i', $p ) ) {
			return $p . ' Mix';
		}
		return $p;
	}

	/**
	 * Drop "(Unknown Type, Small)" and similar from breed names.
	 *
	 * @param string $breed Breed.
	 * @return string
	 */
	public static function clean_breed( $breed ) {
		$b = preg_replace( '/\s*\([^)]*\)/', '', (string) $breed );
		$b = self::squish( $b );
		return preg_match( '/^(mixed breed|unknown|other)$/i', $b ) ? ( 'mixed breed' === strtolower( $b )
			? 'Mixed Breed' : '' ) : $b;
	}

	/**
	 * YouTube video id from any common URL form ('' if none).
	 *
	 * @param string $url URL.
	 * @return string
	 */
	public static function youtube_id( $url ) {
		$url = trim( (string) $url );
		if ( preg_match(
			'~^(?:https?:)?//(?:www\.|m\.)?(?:youtube(?:-nocookie)?\.com/'
			. '(?:watch\?(?:.*&)?v=|shorts/|embed/|live/|v/)|youtu\.be/)([A-Za-z0-9_-]{11})~',
			$url,
			$m
		) ) {
			return $m[ 1 ];
		}
		return '';
	}

	/**
	 * First YouTube id linked in an HTML description ('' if none).
	 *
	 * @param string $html Description.
	 * @return string
	 */
	public static function youtube_in_html( $html ) {
		if ( preg_match_all( '~href=["\']([^"\']+)["\']~i', (string) $html, $m ) ) {
			foreach ( $m[ 1 ] as $u ) {
				$id = self::youtube_id( html_entity_decode( $u ) );
				if ( $id ) {
					return $id;
				}
			}
		}
		return '';
	}

	/**
	 * A photo URL we are willing to hot-link (https, Petstablished's S3 or site).
	 *
	 * @param mixed $url URL.
	 * @return string
	 */
	public static function photo_url( $url ) {
		$url = is_string( $url ) ? trim( $url ) : '';
		$host = strtolower( (string) wp_parse_url( $url, PHP_URL_HOST ) );
		if ( 'https' !== strtolower( (string) wp_parse_url( $url, PHP_URL_SCHEME ) ) || '' === $host ) {
			return '';
		}
		if ( in_array(
			$host,
			(array) apply_filters(
				'homeward_photo_hosts',
				array(
					'petstablished.com',
					'www.petstablished.com',
					'petstablished.s3.amazonaws.com',
				)
			),
			true
			) && ! wp_parse_url( $url, PHP_URL_USER ) && ! wp_parse_url( $url, PHP_URL_PASS )
			&& in_array( wp_parse_url( $url, PHP_URL_PORT ), array( null, 443 ), true ) ) {
			return $url;
		}
		return '';
	}

	/**
	 * A petstablished.com URL (adoption form), or ''.
	 *
	 * @param mixed $url URL.
	 * @return string
	 */
	public static function petstablished_url( $url ) {
		$url = is_string( $url ) ? trim( $url ) : '';
		$host = strtolower( (string) wp_parse_url( $url, PHP_URL_HOST ) );
		if ( 'https' === strtolower( (string) wp_parse_url( $url, PHP_URL_SCHEME ) ) && ( 'petstablished.com' === $host
			|| str_ends_with( $host, '.petstablished.com' ) ) ) {
			return $url;
		}
		return '';
	}

	/**
	 * "Title Case" only words that are entirely upper case; keep mixed case as typed.
	 *
	 * @param string $s Text.
	 * @return string
	 */
	public static function title_case( $s ) {
		$words = explode( ' ', self::squish( $s ) );
		foreach ( $words as & $w ) {
			if ( preg_match( '/\p{Lu}/u', $w ) && ! preg_match( '/\p{Ll}/u', $w ) ) {
				$w = Text::lower( $w, 'UTF-8' );
				$w = preg_replace_callback(
					"/(^|[-'’.])(\p{Ll})/u",
					function ( $m ) {
						return $m[ 1 ] . Text::upper( $m[ 2 ], 'UTF-8' );
					},
					$w
				);
			}
		}
		return implode( ' ', $words );
	}

	/**
	 * Split a raw name into [name part, the rest] at the first "(", "/", ":" or " - ".
	 *
	 * @param string $raw Raw name.
	 * @return string[]
	 */
	private static function split_name( $raw ) {
		if ( preg_match( '/^(.*?)(\s*(?:\(|\/|:|\s[-–]\s?|\s[-–]$).*)$/u', $raw, $m ) ) {
			return array( $m[ 1 ], $m[ 2 ] );
		}
		return array( $raw, '' );
	}

	/**
	 * Tri-state boolean: true, false or null (unknown).
	 *
	 * @param mixed $v Value.
	 * @return bool|null
	 */
	private static function tri( $v ) {
		if ( ! is_scalar( $v ) || '' === $v ) {
			return null;
		}
		if ( is_bool( $v ) ) {
			return $v;
		}
		$s = strtolower( (string) $v );
		if ( in_array( $s, array( '1', 'true', 'yes', 'y' ), true ) ) {
			return true;
		}
		if ( in_array( $s, array( '0', 'false', 'no', 'n' ), true ) ) {
			return false;
		}
		return null;
	}

	/**
	 * Plain single-line text.
	 *
	 * @param mixed $v   Value.
	 * @param int   $max Max length.
	 * @return string
	 */
	private static function text( $v, $max ) {
		if ( ! is_scalar( $v ) ) {
			return '';
		}
		$s = self::squish( strip_tags( html_entity_decode( (string) $v, ENT_QUOTES | ENT_HTML5, 'UTF-8' ) ) );
		return mb_substr( $s, 0, $max, 'UTF-8' );
	}

	/**
	 * Collapse whitespace (including non-breaking spaces).
	 *
	 * @param string $s Text.
	 * @return string
	 */
	public static function squish( $s ) {
		return trim( preg_replace( '/[\s\x{00A0}]+/u', ' ', (string) $s ) );
	}

	/**
	 * Sanitise description HTML: wp_kses when WordPress is loaded, else a strict strip_tags.
	 *
	 * @param string $html HTML.
	 * @return string
	 */
	public static function kses( $html ) {
		$html = Description::clean( $html );
		if ( function_exists( 'wp_kses' ) ) {
			return wp_kses( $html, Description::allowed_html() );
		}
		return $html;
	}
}
