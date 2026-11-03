<?php
/**
 * Markup for cards, grids, the filterable listing, the carousel and the animal page.
 * Everything returns escaped HTML strings.
 *
 * @package Homeward
 */
namespace Homeward;

defined( 'ABSPATH' ) || exit;

/**
 * Render animal lists, profiles and related adoption controls.
 */
class Render {
	/**
	 * Format a count using the configured animal nouns.
	 *
	 * @param int $count Number of animals.
	 * @return string
	 */
	public static function count_label( $count ) {
		/* translators: %d: number of available animals. */
		$label = sprintf( _n( '%d animal', '%d animals', $count, 'homeward-rescue' ), $count );
		if ( 'animal' !== Settings::get( 'noun_singular' ) || 'animals' !== Settings::get( 'noun_plural' ) ) {
			/* translators: 1: number of animals, 2: the site's configured singular or plural animal noun. */
			$label = sprintf(
				_n( '%1$d %2$s', '%1$d %2$s', $count, 'homeward-rescue' ),
				$count,
				Settings::get( 1 === $count ? 'noun_singular' : 'noun_plural' )
			);
		}
		return apply_filters( 'homeward_animal_count_label', $label, $count );
	}

	/**
	 * Unique id counter for multiple components on one page.
	 *
	 * @var int
	 */
	private static $uid = 0;

	/**
	 * Unique DOM id.
	 *
	 * @param string $prefix Prefix.
	 * @return string
	 */
	private static function uid( $prefix ) {
		return $prefix . '-' . ( ++self::$uid );
	}
	/* ------------------------------------------------------------------ *
	 * Small pieces
	 * ------------------------------------------------------------------ */
	/**
	 * Inline SVG icon (decorative).
	 *
	 * @param string $name Icon name.
	 * @return string
	 */
	public static function icon( $name ) {
		$glyphs = array(
			'check'    => '✓',
			'cross'    => '✕',
			'external' => '↗',
			'mail'     => '✉',
			'share'    => '↑',
			'left'     => '←',
			'right'    => '→',
			'close'    => '×',
			'play'     => '▶',
			'paw'      => '♡',
			'info'     => 'ⓘ',
			'heart'    => '♡',
		);
		return isset( $glyphs[ $name ] ) ? '<span class="homeward-animal-icon" aria-hidden="true">'
			. esc_html( $glyphs[ $name ] ) . '</span>' : '';
	}

	/**
	 * Photo alt text.
	 *
	 * @param array $animal Animal.
	 * @return string
	 */
	private static function alt( $animal ) {
		/* translators: %s: dog name. */
		return sprintf( __( 'Photo of %s', 'homeward-rescue' ), $animal['name'] );
	}

	/**
	 * "$600" from "600" / "450.5".
	 *
	 * @param string $fee Fee.
	 * @return string
	 */
	public static function money( $fee ) {
		if ( '' === $fee || ! is_numeric( $fee ) ) {
			return '';
		}
		$f = (float) $fee;
		return Settings::get( 'currency' ) . ' ' . number_format_i18n( $f, floor( $f ) === $f ? 0 : 2 );
	}

	/**
	 * Where a animal comes from, as a phrase for headings.
	 *
	 * @param string $origin Region.
	 * @return string
	 */
	private static function more_from_heading( $origin ) {
		/* translators: 1: configured plural animal noun, 2: origin region. */
		return sprintf( __( 'More %1$s from %2$s', 'homeward-rescue' ), Settings::get( 'noun_plural' ), $origin );
	}

	/**
	 * Card meta line: "Age: age · breed · origin".
	 *
	 * @param array $animal Animal.
	 * @return string
	 */
	private static function card_meta( $animal ) {
		$age = $animal['age'] ? $animal['age'] : $animal['age_group'];
		/* translators: %s: dog age or age category. */
		$parts = array_filter(
			array(
				$age ? sprintf( __( 'Age: %s', 'homeward-rescue' ), $age ) : '',
				$animal['breed_short'],
				$animal['origin'],
			)
		);
		return implode( ' · ', $parts );
	}

	/**
	 * Archive URL.
	 *
	 * @return string
	 */
	public static function archive_url() {
		$url = get_post_type_archive_link( Animal_Post_Type::TYPE );
		return $url ? $url : home_url( '/' . Settings::get( 'archive_slug' ) . '/' );
	}
	/* ------------------------------------------------------------------ *
	 * Cards, grids, carousel
	 * ------------------------------------------------------------------ */
	/**
	 * One animal card.
	 *
	 * @param array $animal Animal view model.
	 * @param array $opts   heading (h2|h3|h4), eager (bool).
	 * @return string
	 */
	public static function card( $animal, $opts = array() ) {
		if ( Animal::protected_post( $animal['id'] ) ) {
			return '';
		}
		$h = in_array( $opts['heading'] ?? 'h3', array( 'h2', 'h3', 'h4' ), true ) ? ( $opts['heading'] ?? 'h3' ) : 'h3';
		$eager = ! empty( $opts['eager'] );
		$img = $animal['thumb'] ? '<img src="' . esc_url( $animal['thumb'] ) . '" alt="' . esc_attr( self::alt( $animal ) )
			. '" width="800" height="1000" decoding="async"' . ( $eager ? ' fetchpriority="high"' : ' loading="lazy"' )
			. '>' : '<span class="homeward-animal-card__nophoto">' . self::icon( 'paw' ) . '</span>';
		$badge = '<span class="homeward-animal-badge homeward-animal-badge--' . esc_attr( $animal['badge'][ 1 ] ) . '">'
			. esc_html( $animal['badge'][ 0 ] ) . '</span>';
		return '<article class="homeward-animal-card">' . '<a class="homeward-animal-card__link" href="'
			. esc_url( $animal['url'] ) . '">' . '<span class="homeward-animal-card__media">' . $img . $badge . '</span>'
			. '<div class="homeward-animal-card__body">' . '<' . $h . ' class="homeward-animal-card__name">'
			. esc_html( $animal['name'] ) . '</' . $h . '>' . '<span class="homeward-animal-card__meta">'
			. esc_html( self::card_meta( $animal ) ) . '</span>' . ( ! empty( $animal['personality'] )
			? '<p class="homeward-animal-card__personality">' . esc_html( $animal['personality'] ) . '</p>' : '' )
			. self::chips( $animal, true ) . '</div></a></article>';
	}

	/**
	 * Grid of cards.
	 *
	 * @param array[] $animals Animals.
	 * @param array   $opts    heading, eager_first, paged (bool), class.
	 * @return string
	 */
	public static function grid( $animals, $opts = array() ) {
		$animals = array_values( array_filter( $animals, fn ( $animal ) => ! Animal::protected_post( $animal['id'] ) ) );
		$out = '<ul class="homeward-animal-grid' . ( ! empty( $opts['class'] ) ? ' ' . esc_attr( $opts['class'] ) : '' )
			. '" role="list"' . ( ! empty( $opts['paged'] ) ? ' data-paged' : '' ) . '>';
		foreach ( array_values( $animals ) as $i => $animal ) {
			$out .= '<li class="homeward-animal-grid__item" data-animal-id="' . esc_attr( (string) $animal['id'] ) . '">'
				. self::card(
				$animal,
				array(
					'heading' => $opts['heading'] ?? 'h3',
					'eager'   => ! empty( $opts['eager_first'] ) && 0 === $i,
				)
			) . '</li>';
		}
		return $out . '</ul>';
	}

	/**
	 * Filterable listing (server-rendered, enhanced by animals.js).
	 *
	 * @param array[] $animals Animals.
	 * @param array   $opts    filters (bool), breedFilter (bool), heading (string, visually hidden if 'sr'), label.
	 * @return string
	 */
	public static function listing( $animals, $opts = array() ) {
		$animals = array_values( array_filter( $animals, fn ( $animal ) => ! Animal::protected_post( $animal['id'] ) ) );
		$filters = ! empty( $opts['filters'] );
		$id = self::uid( 'homeward-rescue' );
		$courtesy = array_values(
			array_filter(
				$opts['courtesy'] ?? array(),
				fn ( $animal ) => ! Animal::protected_post( $animal['id'] )
			)
		);
		$all_animals = array_merge( $animals, $courtesy );
		$count = count( $all_animals );
		$out = '<section class="homeward-animals-listing" aria-labelledby="' . esc_attr( $id ) . '-h"' . ( $filters
			? ' data-homeward-animals-listing data-homeward-count-labels="'
			. esc_attr( wp_json_encode( array_map( array( __CLASS__, 'count_label' ), range( 0, $count ) ) ) ) . '"' : '' )
			. '>';
		$out .= '<h2 id="' . esc_attr( $id ) . '-h" class="' . ( ! empty( $opts['heading_visible'] )
			? 'homeward-animals-heading' : 'screen-reader-text' ) . '">' . esc_html(
			$opts['heading'] ?? __( 'Animals looking for a home', 'homeward-rescue' )
		) . '</h2>';
		if ( ! $all_animals ) {
			return $out . '<p class="homeward-animals-empty">'
				. esc_html__( 'All our animals are in their homes or on their way. New animals arrive often, so please check back soon.', 'homeward-rescue' )
				. '</p></section>';
		}
		if ( $filters ) {
			$out .= self::filter_form( $all_animals, $id, $opts['breedFilter'] ?? true );
		}
		/* translators: %d: number of dogs. */
		$out .= '<p class="homeward-animals-count" role="status" data-homeward-count>'
			. esc_html( self::count_label( $count ) ) . '</p>';
		$out .= self::grid( $animals, array( 'eager_first' => ! empty( $opts['eager_first'] ), 'paged' => $filters, ) );
		if ( $courtesy ) {
			$out .= '<section class="homeward-animals-courtesy" data-homeward-courtesy-group aria-labelledby="' . esc_attr( $id )
				. '-courtesy">' . '<h2 id="' . esc_attr( $id ) . '-courtesy" class="homeward-animals-heading">'
				. esc_html__( 'Courtesy listings', 'homeward-rescue' ) . '</h2>' . '<p class="homeward-animals-courtesy__note">'
				. esc_html__( 'Shared as a courtesy to help these animals find homes. Their applications are handled by the contact in each listing.', 'homeward-rescue' )
				. '</p>' . self::grid( $courtesy ) . '</section>';
		}
		if ( $filters ) {
			$out .= '<div class="homeward-animals-empty" data-homeward-empty hidden><p>'
				. esc_html__( 'No animals match all of those filters right now.', 'homeward-rescue' ) . '</p>'
				. '<button type="button" class="homeward-animal-btn homeward-animal-btn--secondary" data-homeward-clear>'
				. esc_html__( 'Clear filters', 'homeward-rescue' ) . '</button></div>';
			$out .= '<div class="homeward-animals-more-wrap"><button type="button"'
				. ' class="homeward-animal-btn homeward-animal-btn--secondary homeward-animals-more"'
				. ' data-homeward-more hidden>' . esc_html__( 'Show more animals', 'homeward-rescue' ) . '</button></div>';
			$out .= '<script type="application/json" data-homeward-animals-json>' . wp_json_encode(
				self::filter_json( $all_animals ),
				JSON_HEX_TAG | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE
			) . '</script>';
		}
		return $out . '</section>';
	}

	/**
	 * Data for client-side filtering.
	 *
	 * @param array[] $animals Animals.
	 * @return array[]
	 */
	private static function filter_json( $animals ) {
		$tri = function ( $v ) {
			return is_bool( $v ) ? $v : null;
		};
		return array_map(
			function ( $d ) use ( $tri ) {
				return array(
					'id'            => $d['id'],
					'name'          => $d['name'],
					'species'       => $d['species'],
					'origin'        => sanitize_title( $d['origin'] ),
					'size'          => sanitize_title( $d['size'] ),
					'age'           => sanitize_title( $d['age_group'] ),
					'sex'           => sanitize_title( $d['sex'] ),
					'breed'         => 'dog' === $d['species'] ? sanitize_title( $d['breed_family'] ?? '' ) : '',
					'kids'          => $tri( $d['ok_kids'] ),
					'dogs'          => $tri( $d['ok_dogs'] ),
					'cats'          => $tri( $d['ok_cats'] ),
					'special'       => (bool) $d['special'],
					'first_synced'  => (int) $d['first_synced'],
					'waiting_since' => (int) $d['waiting_since'],
				);
			},
			array_values( $animals )
		);
	}

	/**
	 * Filter bar (shown only once JS runs).
	 *
	 * @param array[] $animals      Animals.
	 * @param string  $id           Listing id.
	 * @param bool    $breed_filter Enable the dog-only breed family filter.
	 * @return string
	 */
	private static function filter_form( $animals, $id, $breed_filter = true ) {
		$out = '<form class="homeward-animals-filters" data-homeward-filters hidden role="search" aria-label="'
			. esc_attr__( 'Filter animals', 'homeward-rescue' ) . '">';
		$out .= '<div class="homeward-animals-filters__row">';
		// Phones: this button sits next to Sort and opens every other filter (CSS hides it on wider screens).
		$out .= '<button type="button" class="homeward-animals-filters__toggle" aria-expanded="false" data-homeward-filters-toggle>'
			. '<svg class="homeward-animals-filters__icon" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor"'
			. ' stroke-width="2" stroke-linecap="round" aria-hidden="true" focusable="false"><path d="M4 7h10M18 7h2M4 17h2M10 17h10"/>'
			. '<circle cx="16" cy="7" r="2"/><circle cx="8" cy="17" r="2"/></svg>'
			. '<span>' . esc_html__( 'Filters', 'homeward-rescue' ) . '</span>'
			. '<span class="homeward-animals-filters__badge" data-homeward-filters-badge hidden></span></button>';
		$out .= '<div class="homeward-animals-field homeward-animals-field--search"><label for="' . esc_attr( $id ) . '-q">'
			. esc_html__( 'Search by name', 'homeward-rescue' ) . '</label><input type="search" id="' . esc_attr( $id )
			. '-q" name="q" autocomplete="off" enterkeyhint="search"></div>';
		$out .= self::primary_filters( $animals, $id );
		$out .= '</div><details class="homeward-animals-extra" data-homeward-extra>';
		$out .= '<summary>' . esc_html__( 'More filters', 'homeward-rescue' )
			. '<span class="homeward-animals-extra__count" data-homeward-extra-count aria-live="polite"></span></summary>';
		$out .= '<div class="homeward-animals-extra__fields">';
		$out .= self::breed_filter( $animals, $id, $breed_filter );

		if ( in_array( 'age', Settings::public_fields(), true ) ) {
			$out .= self::filter_select(
				$id,
				'age',
				__( 'Age', 'homeward-rescue' ),
				__( 'Any age', 'homeward-rescue' ),
				self::filter_options(
					$animals,
					'age_group',
					array( 'Puppy', 'Kitten', 'Young', 'Adult', 'Senior' )
				)
			);
		}
		$out .= self::filter_select(
			$id,
			'sex',
			__( 'Sex', 'homeward-rescue' ),
			__( 'Any', 'homeward-rescue' ),
			self::filter_options( $animals, 'sex', array( 'Female', 'Male' ) )
		);
		$out .= '</div>';
		$out .= self::compatibility_filters( $id );
		$out .= '<button type="reset" class="homeward-animals-reset" data-homeward-clear>'
			. esc_html__( 'Clear all', 'homeward-rescue' ) . '</button>';
		$out .= '</form>';
		return $out;
	}
	private static function primary_filters( $animals, $id ) {
		$out = '';
		if ( self::filter_options( $animals, 'origin', array() ) ) {
			$out .= self::filter_select(
				$id,
				'origin',
				Settings::get( 'region_label' ),
				__( 'Anywhere', 'homeward-rescue' ),
				self::filter_options( $animals, 'origin', Pet_Data::regions() )
			);
		}
		$out .= self::filter_select(
			$id,
			'species',
			__( 'Species', 'homeward-rescue' ),
			__( 'Any species', 'homeward-rescue' ),
			self::filter_options( $animals, 'species', array( 'dog', 'cat', 'other' ) )
		);
		$out .= self::filter_select(
			$id,
			'size',
			__( 'Size', 'homeward-rescue' ),
			__( 'Any size', 'homeward-rescue' ),
			self::filter_options( $animals, 'size', array( 'Small', 'Medium', 'Large', 'Extra Large' ) )
		);
		$out .= '<div class="homeward-animals-field homeward-animals-field--sort"><label for="' . esc_attr( $id ) . '-sort">'
			. esc_html__( 'Sort', 'homeward-rescue' ) . '</label><select id="' . esc_attr( $id ) . '-sort" name="sort">'
			. '<option value="">' . esc_html__( 'Featured first', 'homeward-rescue' ) . '</option>' . '<option value="newest">'
			. esc_html__( 'Newest', 'homeward-rescue' ) . '</option>' . '<option value="longest-waiting">'
			. esc_html__( 'Longest waiting', 'homeward-rescue' ) . '</option></select></div>';
		return $out;
	}

	private static function filter_options( $animals, $key, $order ) {
		$vals = array_unique( array_filter( array_column( $animals, $key ) ) );
		usort(
			$vals,
			function ( $a, $b ) use ( $order ) {
				$ia = array_search( $a, $order, true );
				$ib = array_search( $b, $order, true );
				return array( false === $ia ? 99 : $ia, $a ) <=> array( false === $ib ? 99 : $ib, $b );
			}
		);
		return $vals;
	}

	private static function filter_select( $id, $name, $label, $any, $values ) {
		$fid = $id . '-' . $name;
		$h = '<div class="homeward-animals-field"><label for="' . esc_attr( $fid ) . '">' . esc_html( $label )
			. '</label><select id="' . esc_attr( $fid ) . '" name="' . esc_attr( $name ) . '"><option value="">'
			. esc_html( $any ) . '</option>';
		foreach ( $values as $v ) {
			$h .= '<option value="' . esc_attr( sanitize_title( $v ) ) . '">' . esc_html( $v ) . '</option>';
		}
		return $h . '</select></div>';
	}

	private static function filter_toggle( $name, $label ) {
		return '<label class="homeward-animals-toggle"><input type="checkbox" name="' . esc_attr( $name )
			. '" value="1"><span>' . self::icon( 'check' ) . esc_html( $label ) . '</span></label>';
	}

	private static function breed_filter( $animals, $id, $breed_filter ) {
		$out = '';
		$breed_dogs = array_filter( $animals, static fn ( $animal ) => 'dog' === $animal['species'] );
		$families = array_count_values( array_filter( array_column( $breed_dogs, 'breed_family' ) ) );
		if ( $breed_filter && in_array( 'dog', Settings::get( 'species' ), true )
			&& in_array( 'breed', Settings::public_fields(), true ) && count( $families ) >= 2 ) {
			uksort(
				$families,
				function ( $a, $b ) use ( $families ) {
					return ( 'Other' === $a ) <=> ( 'Other' === $b ) ?: $families[ $b ] <=> $families[ $a ] ?: strcmp( $a, $b );
				}
			);
			$fid = $id . '-breed';
			$breed_label = count( array_unique( array_column( $animals, 'species' ) ) ) > 1
				? __( 'Dog breed family', 'homeward-rescue' ) : __( 'Breed family', 'homeward-rescue' );
			$out .= '<div class="homeward-animals-field"><label for="' . esc_attr( $fid ) . '">' . esc_html( $breed_label )
				. '</label><select id="' . esc_attr( $fid ) . '" name="breed" aria-describedby="' . esc_attr( $fid )
				. '-hint"><option value="">' . esc_html__( 'Any breed', 'homeward-rescue' ) . '</option>';
			foreach ( $families as $family => $count ) {
				$out .= '<option value="' . esc_attr( sanitize_title( $family ) ) . '">' . esc_html( $family . ' (' . $count . ')' )
					. '</option>';
			}
			$out .= '</select><p class="homeward-animals-extra__hint" id="' . esc_attr( $fid ) . '-hint">'
				. esc_html__( 'Includes mixes', 'homeward-rescue' ) . '</p></div>';
		}

		return $out;
	}

	private static function compatibility_filters( $id ) {
		$out = '';
		$out .= '<fieldset class="homeward-animals-toggles" aria-describedby="' . esc_attr( $id ) . '-compat-help"><legend>'
			. esc_html__( 'Only show animals that are', 'homeward-rescue' ) . '</legend>';
		if ( in_array( 'compat', Settings::public_fields(), true ) ) {
			$out .= self::filter_toggle( 'kids', __( 'Good with kids', 'homeward-rescue' ) );
			$out .= self::filter_toggle( 'dogs', __( 'Good with dogs', 'homeward-rescue' ) );
			$out .= self::filter_toggle( 'cats', __( 'Good with cats', 'homeward-rescue' ) );
		}
		$out .= self::filter_toggle( 'special', __( 'Special needs', 'homeward-rescue' ) );
		$out .= '</fieldset><p class="homeward-animals-extra__hint" id="' . esc_attr( $id ) . '-compat-help">'
			. esc_html__( 'Compatibility filters include confirmed matches only. Unknown compatibility is not a match.', 'homeward-rescue' )
			. '</p></details>';
		return $out;
	}

	/**
	 * Horizontal carousel ("Ready for home").
	 *
	 * @param array[] $animals Animals.
	 * @param array   $opts    heading, eyebrow, link (bool).
	 * @return string
	 */
	public static function carousel( $animals, $opts = array() ) {
		$animals = array_values( array_filter( $animals, fn ( $animal ) => ! Animal::protected_post( $animal['id'] ) ) );
		$id = self::uid( 'homeward-carousel' );
		$heading = $opts['heading'] ?? __( 'Ready for home', 'homeward-rescue' );
		$out = '<section class="homeward-animal-carousel" aria-labelledby="'
			. esc_attr( $id ) . '-h" data-homeward-carousel>';
		$out .= '<div class="homeward-animal-carousel__head"><div>';
		if ( ! empty( $opts['eyebrow'] ) ) {
			$out .= '<p class="homeward-animal-eyebrow">' . esc_html( $opts['eyebrow'] ) . '</p>';
		}
		$out .= '<h2 id="' . esc_attr( $id ) . '-h" class="homeward-animal-carousel__title">' . esc_html( $heading )
			. '</h2></div>';
		$out .= '<div class="homeward-animal-carousel__nav homeward-js-only">'
			. '<button type="button" class="homeward-animal-iconbtn" data-homeward-prev aria-controls="' . esc_attr( $id )
			. '-track" aria-label="' . esc_attr__( 'Previous animals', 'homeward-rescue' ) . '">' . self::icon( 'left' )
			. '</button>' . '<button type="button" class="homeward-animal-iconbtn" data-homeward-next aria-controls="'
			. esc_attr( $id ) . '-track" aria-label="' . esc_attr__( 'Next animals', 'homeward-rescue' ) . '">'
			. self::icon( 'right' ) . '</button>' . '</div></div>';
		if ( ! $animals ) {
			$out .= '<p class="homeward-animals-empty">'
				. esc_html__( 'New animals are on their way. Please check back soon.', 'homeward-rescue' ) . '</p>';
		} else {
			$out .= '<ul class="homeward-animal-carousel__track" id="' . esc_attr( $id )
				. '-track" role="list" tabindex="0" aria-label="' . esc_attr( $heading ) . '">';
			foreach ( $animals as $animal ) {
				$out .= '<li class="homeward-animal-carousel__item">' . self::card( $animal ) . '</li>';
			}
			$out .= '</ul>';
		}
		if ( ! isset( $opts['link'] ) || $opts['link'] ) {
			$out .= '<p class="homeward-animal-carousel__all">'
				. '<a class="homeward-animal-btn homeward-animal-btn--secondary" href="'
				. esc_url( self::archive_url() ) . '">' . esc_html__( 'View all animals', 'homeward-rescue' ) . '</a></p>';
		}
		return $out . '</section>';
	}
	/* ------------------------------------------------------------------ *
	 * Page sections
	 * ------------------------------------------------------------------ */
	/**
	 * Foster CTA band (text from Animals → Settings & sync).
	 *
	 * @return string
	 */
	public static function foster_cta() {
		$url = Applications::url( 'foster' );
		return $url ? '<section class="homeward-animal-cta"><h2>'
			. esc_html__( 'Help an animal feel at home', 'homeward-rescue' ) . '</h2>' . Applications::button( 'foster' )
			. '</section>' : '';
	}

	/**
	 * /animals/ intro: content of the "meet-our-animals" page if it exists, else a default header.
	 *
	 * @return string
	 */
	public static function intro() {
		/* translators: %s: configured plural animal noun. */
		return '<header class="homeward-animals-intro"><div class="homeward-animal-wrap"><h1>' . esc_html(
			sprintf(
				__( 'Meet our %s', 'homeward-rescue' ),
				Settings::get( 'noun_plural' )
			)
		) . '</h1></div></header>';
	}

	/**
	 * Full /animals/ page body.
	 *
	 * @return string
	 */
	public static function archive_page() {
		$animals = Animal::query();
		$out = self::intro();
		$out .= '<div class="homeward-animal-wrap homeward-animals-main">';
		$out .= self::listing(
			$animals,
			array(
				'filters'  => true,
				'courtesy' => 'section' === Settings::get( 'courtesy_mode' )
				? Animal::query( array( 'courtesy' => true ) ) : array(),
				'eager_first' => true,
			)
		);
		$out .= '</div>';
		$out .= self::foster_cta();
		return $out;
	}

	/**
	 * Description HTML for display: cleaned, Wagtopia links to our own pages, kses.
	 *
	 * @param \WP_Post $post Post.
	 * @return string
	 */
	public static function description( $post ) {
		if ( Animal::protected_post( $post ) ) {
			return '';
		}
		$html = Description::clean( $post->post_content );
		// Links to sibling dogs on Wagtopia/Petstablished → their page on our site.
		$html = preg_replace_callback(
			'#href="(https?://(?:www\.)?(?:wagtopia\.com/search/pet\?id=|'
			. 'petstablished\.com/(?:public/search/)?pets?/)(\d+)[^"]*)"#',
			function ( $m ) {
				$pid = Animal::post_id_for( (int) $m[ 2 ] );
				return $pid ? 'href="' . esc_url( get_permalink( $pid ) ) . '"' : $m[ 0 ];
			},
			$html
		);
		// Our own links open in the same tab.
		$html = preg_replace(
			'#(href="' . preg_quote( home_url(), '#' ) . '[^"]*") target="_blank" rel="noopener nofollow"#',
			'$1',
			$html
		);
		return wp_kses( $html, Description::allowed_html() );
	}

	/**
	 * Compatibility chips: show only confirmed yes/no values.
	 *
	 * @param array $animal Animal.
	 * @param bool  $card   Limit cards to two known kids/animals/cats traits.
	 * @return string
	 */
	public static function chips( $animal, $card = false ) {
		$defs = array(
			'ok_dogs'          => array(
				__( 'Good with dogs', 'homeward-rescue' ),
				__( 'Not good with dogs', 'homeward-rescue' ),
			),
			'ok_cats'          => array(
				__( 'Good with cats', 'homeward-rescue' ),
				__( 'Not good with cats', 'homeward-rescue' ),
			),
			'ok_kids'          => array(
				__( 'Good with kids', 'homeward-rescue' ),
				__( 'Not good with kids', 'homeward-rescue' ),
			),
			'is_spayed'        => array(
				__( 'Spayed or neutered', 'homeward-rescue' ),
				__( 'Not spayed or neutered', 'homeward-rescue' ),
			),
			'shots_up_to_date' => array(
				__( 'Vaccinations up to date', 'homeward-rescue' ),
				__( 'Vaccinations not up to date', 'homeward-rescue' ),
			),
			'housebroken'      => array(
				__( 'Housebroken', 'homeward-rescue' ),
				__( 'Not housebroken yet', 'homeward-rescue' ),
			),
		);
		$items = '';
		$count = 0;
		foreach ( $defs as $key => $labels ) {
			$profile_only = in_array( $key, array( 'housebroken', 'is_spayed', 'shots_up_to_date' ), true );
			if ( ! is_bool( $animal[ $key ] ?? null ) || ( $card && $profile_only ) ) {
				continue;
			}
			$yes = (bool) $animal[ $key ];
			$items .= '<li class="homeward-animal-chip ' . ( $yes ? 'is-yes' : 'is-no' ) . '">' . self::icon(
				$yes ? 'check' : 'cross'
			) . '<span>' . esc_html( $yes ? $labels[ 0 ] : $labels[ 1 ] ) . '</span></li>';
			if ( $card && ++$count >= 2 ) {
				break;
			}
		}
		return $items ? '<ul class="homeward-animal-chips" role="list" aria-label="'
			. esc_attr__( 'Compatibility and health', 'homeward-rescue' ) . '">' . $items . '</ul>' : '';
	}

	/**
	 * Gallery with lightbox (enhanced by JS; plain links without it).
	 *
	 * @param array $animal Animal.
	 * @return string
	 */
	private static function gallery( $animal ) {
		$photos = $animal['photos'];
		/* translators: %s: dog name. */
		$label = sprintf( __( 'Photos of %s', 'homeward-rescue' ), $animal['name'] );
		if ( ! $photos ) {
			return '<section class="homeward-animal-gallery is-empty" aria-label="' . esc_attr( $label )
				. '"><div class="homeward-animal-gallery__main"><span class="homeward-animal-card__nophoto">' . self::icon( 'paw' )
				. '</span></div></section>';
		}
		$n = count( $photos );
		$first = $photos[ 0 ];
		$out = '<section class="homeward-animal-gallery" aria-label="' . esc_attr( $label )
			. '" data-homeward-gallery data-name="' . esc_attr( $animal['name'] ) . '">';
		$out .= '<a class="homeward-animal-gallery__main" href="' . esc_url( $first['full'] )
			. '" data-homeward-open data-index="0">' . '<img src="' . esc_url( $first['full'] ) . '" alt="'
			. esc_attr( self::alt( $animal ) )
			. '" width="1000" height="1000" fetchpriority="high" decoding="async" data-homeward-main>'
			. '<img class="homeward-animal-gallery__backdrop" src="' . esc_url( $first['full'] )
			. '" alt="" aria-hidden="true" width="1000" height="1000" decoding="async" data-homeward-backdrop>' . ( $n > 1
			/* translators: %d: photo count. */ ? '<span class="homeward-animal-gallery__count">'
			. esc_html( sprintf( _n( '%d photo', '%d photos', $n, 'homeward-rescue' ), $n ) ) . '</span>' : '' )
			. '<span class="screen-reader-text" data-homeward-open-label>' . esc_html__( 'Open photo viewer', 'homeward-rescue' )
			. '</span>' . '</a>';
		if ( $n > 1 ) {
			$out .= '<ul class="homeward-animal-gallery__thumbs" role="list">';
			foreach ( $photos as $i => $p ) {
				$out .= '<li><a class="homeward-animal-gallery__thumb" href="' . esc_url( $p['full'] ) . '" data-index="' . (int) $i
					. '"' . ( 0 === $i ? ' aria-current="true"' : '' ) . '>'
				/* translators: 1: photo number, 2: total, 3: dog name. */ . '<img src="' . esc_url( $p['thumb'] ) . '" alt="'
					. esc_attr(
					sprintf(
						__( 'Photo of %3$s (%1$d of %2$d)', 'homeward-rescue' ),
						$i + 1,
						$n,
						$animal['name']
					)
				) . '" width="160" height="160" loading="lazy" decoding="async">' . '</a></li>';
			}
			$out .= '</ul>';
		}
		$out .= '<script type="application/json" data-homeward-photos>' . wp_json_encode(
			array_column( $photos, 'full' ),
			JSON_HEX_TAG | JSON_UNESCAPED_SLASHES
		) . '</script>';
		return $out . '</section>';
	}

	/**
	 * Facts list.
	 *
	 * @param array $animal Animal.
	 * @return string
	 */
	private static function facts( $animal ) {
		$rows = array(
			__( 'Weight', 'homeward-rescue' ) => $animal['weight'],
			__( 'Breed', 'homeward-rescue' ) => $animal['breed'],
			__( 'From', 'homeward-rescue' ) => $animal['origin'],
			__( 'Adoption fee', 'homeward-rescue' ) => self::money( $animal['fee'] ),
		);
		$out = '<dl class="homeward-animal-facts">';
		foreach ( $rows as $label => $value ) {
			if ( '' === (string) $value ) {
				continue;
			}
			$out .= '<div><dt>' . esc_html( $label ) . '</dt><dd>' . esc_html( $value ) . '</dd></div>';
		}
		return $out . '</dl>';
	}

	/**
	 * Mailto for "Ask about {Name}".
	 *
	 * @param array $animal Animal.
	 * @return string
	 */
	private static function ask_url( $animal ) {
		/* translators: %s: dog name. */
		$subject = sprintf( __( 'Question about %s', 'homeward-rescue' ), $animal['name'] );
		$body = "\n\n" . $animal['url'];
		return 'mailto:' . Settings::get( 'contact_email' ) . '?subject=' . rawurlencode( $subject ) . '&body='
			. rawurlencode( $body );
	}

	/**
	 * Apply button.
	 *
	 * @param array  $animal Animal.
	 * @param string $class  Extra class.
	 * @param bool   $short  Short label.
	 * @return string
	 */
	public static function apply_button( $animal, $class = '', $short = false ) {
		/* translators: %s: dog name. */
		$label = $short
			? __( 'Apply', 'homeward-rescue' ) : sprintf( __( 'Apply to adopt %s', 'homeward-rescue' ), $animal['name'] );
		return '<a class="homeward-animal-btn homeward-animal-btn--primary ' . esc_attr( $class ) . '" href="'
			. esc_url( $animal['adopt_url'] ) . '" target="_blank" rel="noopener">' . '<span>' . esc_html( $label ) . '</span>'
			. self::icon( 'external' ) . '<span class="screen-reader-text">'
			. esc_html__( '(opens the Petstablished application in a new tab)', 'homeward-rescue' ) . '</span></a>';
	}

	/**
	 * Render an animal profile with the requested sections.
	 *
	 * @param array    $animal Public animal view model.
	 * @param \WP_Post $post   Animal post.
	 * @param array    $opts   Profile section options.
	 * @return string
	 */
	public static function animal_page( $animal, $post, $opts = array() ) {
		if ( Animal::protected_post( $post ) ) {
			return get_the_password_form( $post );
		}
		$opts = wp_parse_args(
			$opts,
			array(
				'gallery'        => true,
				'video'          => true,
				'facts'          => true,
				'compatibility'  => true,
				'description'    => true,
				'apply'          => true,
				'moreFromRegion' => true,
			)
		);
		$id = self::uid( 'homeward-profile' );
		$name = $animal['name'];
		$can_apply = $opts['apply'] && $animal['listed'] && ! in_array( $animal['status'], array( 'gone', 'hold' ), true )
			&& Applications::url( 'adopt', $animal['id'] );
		/* translators: %s: configured plural animal noun. */
		$out = '<div class="homeward-animal-page' . ( $can_apply ? ' has-actionbar' : '' )
			. '"><div class="homeward-animal-wrap"><nav class="homeward-animal-crumbs" aria-label="'
			. esc_attr__( 'Breadcrumb', 'homeward-rescue' ) . '"><a href="' . esc_url( self::archive_url() )
			. '" data-homeward-back>' . esc_html( sprintf( __( 'All %s', 'homeward-rescue' ), Settings::get( 'noun_plural' ) ) )
			. '</a></nav>';
		if ( 'gone' === $animal['status'] ) {
			/* translators: %s: animal name. The separate More %s heading uses the configured plural animal noun. */
			return $out . '<h1>' . esc_html( sprintf( __( '%s has found a home', 'homeward-rescue' ), $name ) ) . '</h1>'
				. Features::adopted_donate( $animal ) . Content_Render::linked_stories( $animal['id'] ) . Testimonials::render_linked( $animal['id'] ) . '</div>'
				. self::carousel(
				Animal::query( array( 'exclude' => array( $animal['id'] ), 'limit' => 8 ) ),
				array(
					'heading' => sprintf( __( 'More %s', 'homeward-rescue' ), Settings::get( 'noun_plural' ) ),
				)
			) . '</div>';
		}
		if ( 'pending' === $animal['status'] || ! $animal['listed'] || 'hold' === $animal['status'] ) {
			$out .= '<p class="homeward-animal-banner">' . esc_html(
				'pending' === $animal['status']
				? __( 'Adoption pending. Please contact us for an update.', 'homeward-rescue' ) : __( 'Not taking applications right now.', 'homeward-rescue' )
			) . '</p>';
		}
		$out .= '<div class="homeward-animal-hero">' . ( $opts['gallery'] ? self::gallery( $animal ) : '' );
		$out .= self::profile_glance( $animal, $id, $opts, $can_apply );
		$out .= '<div class="homeward-animal-body">';
		$out .= self::profile_story( $animal, $post, $opts );
		$out .= Features::donate_card( $animal );
		$out .= '</div></div>' . Content_Render::linked_stories( $animal['id'] )
			. Testimonials::render_linked( $animal['id'] ) . '</div>';
		if ( $opts['moreFromRegion'] ) {
			$out .= self::related( $animal );
		}
		if ( $can_apply ) {
			$out .= self::profile_actions( $animal );
		}
		return $out . self::foster_cta() . '</div>';
	}
	private static function profile_actions( $animal ) {
		$name = $animal['name'];
		$out = '';
		/* translators: %s: animal name. */
		$out .= '<div class="homeward-animal-actionbar" role="region" aria-label="'
			. esc_attr( sprintf( __( 'Apply for %s', 'homeward-rescue' ), $name ) ) . '">'
			. Applications::button( 'adopt', $animal['id'] );
		if ( Settings::get( 'contact_email' ) ) {
			$out .= '<a class="homeward-animal-btn homeward-animal-btn--secondary" href="'
				. esc_url( self::ask_url( $animal ), array( 'mailto' ) ) . '">' . esc_html__( 'Ask', 'homeward-rescue' ) . '</a>';
		}
		$out .= '</div>';
		return $out;
	}

	private static function profile_glance( $animal, $id, $opts, $can_apply ) {
		$name = $animal['name'];
		$out = '';
		$out .= '<aside class="homeward-animal-glance" aria-labelledby="' . esc_attr( $id )
			. '"><div class="homeward-animal-glance__card"><p class="homeward-animal-eyebrow">'
			. esc_html__( 'Adoption details', 'homeward-rescue' )
			. '</p><span class="homeward-animal-badge homeward-animal-badge--' . esc_attr( $animal['badge'][ 1 ] ) . '">'
			. esc_html( $animal['badge'][ 0 ] ) . '</span><h1 id="' . esc_attr( $id ) . '" class="homeward-animal-glance__name">'
			. esc_html( $name ) . '</h1>';
		if ( $animal['blurb'] ) {
			$out .= '<p class="homeward-animal-lead">' . esc_html( $animal['blurb'] ) . '</p>';
		}
		if ( $opts['facts'] ) {
			$out .= '<p class="homeward-animal-glance__sub">' . esc_html(
				implode(
					' · ',
					array_filter(
						array(
							$animal['age'] ?: $animal['age_group'],
							$animal['sex'],
							$animal['size'],
						)
					)
				)
			) . '</p>' . self::facts( $animal );
			$out .= Features::fee_explainer( $animal );
		}
		if ( $opts['compatibility'] ) {
			$out .= self::chips( $animal );
		}
		if ( $animal['special_needs'] ) {
			$out .= '<p>' . esc_html( $animal['special_needs'] ) . '</p>';
		}
		$out .= Kennel_Print::link( $animal['id'] );
		$out .= '<div class="homeward-animal-actions">';
		if ( $can_apply ) {
			$out .= Applications::button( 'adopt', $animal['id'] );
		}
		if ( Settings::get( 'contact_email' ) ) {
			/* translators: %s: animal name. */
			$out .= '<a class="homeward-animal-btn homeward-animal-btn--secondary" href="'
				. esc_url( self::ask_url( $animal ), array( 'mailto' ) ) . '">'
				. esc_html( sprintf( __( 'Ask about %s', 'homeward-rescue' ), $name ) ) . '</a>';
		}
		$out .= '<button type="button" class="homeward-animal-btn homeward-animal-btn--ghost homeward-js-only"'
			. ' data-homeward-share data-title="'
			. esc_attr( $name ) . '" data-url="' . esc_url( $animal['url'] ) . '">' . esc_html__( 'Share', 'homeward-rescue' )
			. '</button><p role="status" data-homeward-share-status></p></div>';
		$out .= Features::household_share( $animal );
		$out .= '</div></aside>';
		return $out;
	}

	private static function profile_story( $animal, $post, $opts ) {
		$name = $animal['name'];
		$out = '';
		if ( $opts['description'] ) {
			list( $visible, $more ) = Description::split( self::description( $post ) );
			/* translators: %s: animal name. */
			$out .= '<article class="homeward-animal-story"><h2>'
				. esc_html( sprintf( __( 'About %s', 'homeward-rescue' ), $name ) ) . '</h2><div class="homeward-animal-desc">'
				. $visible . '</div>';
			if ( $more ) {
				$out .= '<details class="homeward-animal-more" data-homeward-more-details>'
					. '<summary class="homeward-animal-more__summary"><span data-closed>'
					. esc_html__( 'Keep reading', 'homeward-rescue' ) . '</span><span data-open>'
					. esc_html__( 'Show less', 'homeward-rescue' )
					. '</span></summary><div class="homeward-animal-desc" tabindex="-1">' . $more . '</div></details>';
			}
			$out .= '</article>';
		}
		if ( $opts['video'] && $animal['youtube'] ) {
			/* translators: %s: animal name. */
			$out .= '<figure class="homeward-animal-video"><a class="homeward-animal-video__facade"'
				. ' href="https://www.youtube.com/watch?v='
				. esc_attr( $animal['youtube'] ) . '" data-homeward-yt="' . esc_attr( $animal['youtube'] ) . '" data-title="'
				. esc_attr( sprintf( __( 'Video of %s', 'homeward-rescue' ), $name ) )
				. '"><span class="homeward-animal-video__play" aria-hidden="true">▶</span><span>'
				. esc_html__( 'Play video (YouTube)', 'homeward-rescue' ) . '</span></a></figure>';
		}
		return $out;
	}

	/**
	 * "More animals from {origin}" and "Other animals you might love".
	 *
	 * @param array $animal Animal.
	 * @return string
	 */
	private static function related( $animal ) {
		$shown = array( $animal['id'] );
		$id = self::uid( 'homeward-related' );
		$out = '<div class="homeward-animal-related-wrap"><div class="homeward-animal-wrap">';
		if ( $animal['origin'] ) {
			$same = Animal::query( array( 'origin' => $animal['origin'], 'exclude' => $shown, 'limit' => 3, ) );
			if ( $same ) {
				$shown = array_merge( $shown, array_column( $same, 'id' ) );
				$out .= '<section class="homeward-animal-related" aria-labelledby="' . esc_attr( $id ) . '-origin"><h2 id="'
					. esc_attr( $id ) . '-origin" class="homeward-animal-related__title">'
					. esc_html( self::more_from_heading( $animal['origin'] ) ) . '</h2>'
					. self::grid( $same, array( 'class' => 'homeward-animal-grid--three' ) ) . '</section>';
			}
		}
		$like = ! empty( $same )
			? array() : Animal::query( array( 'size' => $animal['size'], 'exclude' => $shown, 'limit' => 3, ) );
		if ( empty( $same ) && count( $like ) < 3 ) {
			$like = array_merge(
				$like,
				Animal::query(
					array(
						'exclude' => array_merge( $shown, array_column( $like, 'id' ) ),
						'limit'   => 3 - count( $like ),
					)
				)
			);
		}
		if ( $like ) {
			$out .= '<section class="homeward-animal-related" aria-labelledby="' . esc_attr( $id ) . '-like"><h2 id="'
				. esc_attr( $id ) . '-like" class="homeward-animal-related__title">'
				. esc_html__( 'Other animals you might love', 'homeward-rescue' ) . '</h2>'
				. self::grid( $like, array( 'class' => 'homeward-animal-grid--three' ) ) . '</section>';
		}
		$out .= '<p class="homeward-animal-related__back">'
			. '<a class="homeward-animal-btn homeward-animal-btn--secondary" href="'
			. esc_url( self::archive_url() ) . '" data-homeward-back>' . self::icon( 'left' ) . '<span>'
			. esc_html__( 'Back to all animals', 'homeward-rescue' ) . '</span></a></p>';
		return $out . '</div></div>';
	}
}
