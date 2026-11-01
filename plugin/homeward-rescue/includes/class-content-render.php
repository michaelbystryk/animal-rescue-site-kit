<?php
/**
 * Queries and markup for cards, shared by templates, blocks and shortcodes.
 *
 * @package Homeward
 */
namespace Homeward;

defined( 'ABSPATH' ) || exit;

/**
 * Render public stories, testimonials and events.
 */
class Content_Render {
	/**
	 * Check whether a record requires a password.
	 *
	 * @param int|\WP_Post $id Post ID or object.
	 * @return bool
	 */
	public static function protected_post( $id ) {
		return Animal::protected_post( $id );
	}

	/**
	 * Register privacy filters for story and event REST responses.
	 *
	 * @return void
	 */
	public static function rest_hooks() {
		foreach ( get_post_types( array( 'show_in_rest' => true ) ) as $type ) {
			add_filter( 'rest_prepare_' . $type, array( __CLASS__, 'rest_prepare' ), 99, 3 );
		}
	}

	/**
	 * Remove private content from password-protected REST responses.
	 *
	 * @param \WP_REST_Response     $response Prepared response.
	 * @param \WP_Post              $post     Requested post.
	 * @param \WP_REST_Request|null $request  Incoming request.
	 * @return \WP_REST_Response
	 */
	public static function rest_prepare( $response, $post, $request = null ) {
		// Editors need the saved fields to edit without accidentally replacing them.
		if ( $request && 'edit' === $request->get_param( 'context' ) && current_user_can( 'edit_post', $post->ID ) ) {
			return $response;
		}
		if ( self::protected_post( $post ) ) {
			$data = $response->get_data();
			unset( $data['meta'], $data['content'], $data['excerpt'] );
			$data['featured_media'] = 0;
			unset( $data['_links']['wp:featuredmedia'], $data['_embedded']['wp:featuredmedia'] );
			if ( method_exists( $response, 'remove_link' ) ) {
				$response->remove_link( 'https://api.w.org/featuredmedia' );
			}
			$response->set_data( $data );
		}
		return $response;
	}

	/**
	 * Render published stories linked to an animal.
	 *
	 * @param int $animal_id Animal post ID.
	 * @return string
	 */
	public static function linked_stories( $animal_id ) {
		$stories = get_posts(
			array(
				'post_type'      => Content_Post_Types::STORY,
				'post_status'    => 'publish',
				'has_password'   => false,
				'posts_per_page' => -1,
				'meta_query'     => array(
					array( 'key' => 'homeward_animal_id', 'value' => (string) $animal_id ),
					array( 'key' => 'homeward_kind', 'value' => 'story' ),
				),
			)
		);
		$links = '';
		foreach ( $stories as $story ) {
			if ( '' !== $story->post_password ) {
				continue;
			}
			$links .= '<li><a href="' . esc_url( get_permalink( $story->ID ) ) . '">' . esc_html( $story->post_title )
				. '</a></li>';
		}
		return $links ? '<section class="homeward-kit homeward-linked-stories"><h2>'
			. esc_html__( 'Adoption stories', 'homeward-rescue' ) . '</h2><ul>' . $links . '</ul></section>' : '';
	}

	/**
	 * Map-pin icon.
	 *
	 * @return string
	 */
	private static function pin() {
		return '<svg class="homeward-icon" aria-hidden="true" width="14" height="14" viewBox="0 0 24 24"'
			. ' fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">'
			. '<path d="M20 10c0 6-8 12-8 12S4 16 4 10a8 8 0 1 1 16 0Z"/><circle cx="12" cy="10" r="3"/></svg>';
	}

	/**
	 * Current site-time boundary for "upcoming".
	 *
	 * @return string "Y-m-d 00:00".
	 */
	public static function today() {
		return wp_date( 'Y-m-d 00:00' );
	}

	/**
	 * Query stories.
	 *
	 * @param array $args count, kind ('story'|'testimonial'|''), featured (bool), exclude (IDs).
	 * @return \WP_Query
	 */
	public static function story_query( $args = array() ) {
		$args = wp_parse_args( $args, array( 'count' => 3, 'kind' => 'story', 'featured' => false, 'exclude' => array() ) );
		$meta = array();
		if ( 'testimonial' === $args['kind'] ) {
			$meta[] = array( 'key' => 'homeward_kind', 'value' => 'testimonial' );
		} elseif ( 'story' === $args['kind'] ) {
			$meta[] = array(
				'relation' => 'OR',
				array( 'key' => 'homeward_kind', 'value' => 'story' ),
				array( 'key' => 'homeward_kind', 'compare' => 'NOT EXISTS' ),
			);
		}
		if ( ! empty( $args['featured'] ) ) {
			$meta[] = array( 'key' => 'homeward_featured', 'value' => '1' );
		}
		$query = array(
			'post_type'           => Content_Post_Types::STORY,
			'post_status'         => 'publish',
			'has_password'        => false,
			'posts_per_page'      => (int) $args['count'],
			'orderby'             => array( 'date' => 'DESC', 'ID' => 'DESC' ),
			'order'               => 'DESC',
			'post__not_in'        => array_map( 'absint', $args['exclude'] ),
			'ignore_sticky_posts' => true,
			'no_found_rows'       => true,
		);
		if ( $meta ) {
			$meta['relation'] = 'AND';
			$query['meta_query'] = $meta;
			// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
		}
		return new \WP_Query( $query );
	}

	/**
	 * Newest featured adoption story, falling back to the newest adoption story.
	 * Testimonials never take the lead-story slot.
	 *
	 * @return int Post ID, or zero when no stories are published.
	 */
	public static function featured_story_id() {
		$query = self::story_query( array( 'count' => 1, 'featured' => true ) );
		if ( ! $query->have_posts() ) {
			$query = self::story_query( array( 'count' => 1 ) );
		}
		return $query->have_posts() ? (int) $query->posts[ 0 ]->ID : 0;
	}

	/**
	 * Query events.
	 *
	 * @param string $when  'upcoming' or 'past'.
	 * @param int    $count Number of events, -1 for all.
	 * @return \WP_Query
	 */
	public static function event_query( $when = 'upcoming', $count = 3 ) {
		$upcoming = ( 'past' !== $when );
		return new \WP_Query( array(
			'post_type'           => Content_Post_Types::EVENT,
			'post_status'         => 'publish',
			'has_password'        => false,
			'posts_per_page'      => (int) $count,
			'ignore_sticky_posts' => true,
			'no_found_rows'       => true,
			'meta_key'            => 'homeward_start',
			// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
			'orderby'    => 'meta_value',
			'order'      => $upcoming ? 'ASC' : 'DESC',
			'meta_query' => array(
				// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
				'relation' => 'OR',
				array(
					'relation' => 'AND',
					array( 'key' => 'homeward_end', 'value' => '', 'compare' => '!=' ),
					array( 'key' => 'homeward_end', 'value' => self::today(), 'compare' => $upcoming ? '>=' : '<' ),
				),
				array(
					'relation' => 'AND',
					array(
						'relation' => 'OR',
						array( 'key' => 'homeward_end', 'compare' => 'NOT EXISTS' ),
						array( 'key' => 'homeward_end', 'value' => '' ),
					),
					array( 'key' => 'homeward_start', 'value' => self::today(), 'compare' => $upcoming ? '>=' : '<' ),
				),
			),
		) );
	}

	/**
	 * Story text as safe paragraphs.
	 *
	 * @param int $post_id Post ID.
	 * @return string
	 */
	public static function story_text_html( $post_id ) {
		if ( self::protected_post( $post_id ) ) {
			return '';
		}
		$text = (string) get_post_meta( $post_id, 'homeward_text', true );
		return wpautop( wp_kses( $text, Content_Post_Types::allowed_html() ) );
	}

	/**
	 * Plain, decoded text for excerpts and JSON (escaped at the output boundary).
	 *
	 * @param string $text Text.
	 * @return string
	 */
	private static function plain_text( $text ) {
		return html_entity_decode( wp_strip_all_tags( $text ), ENT_QUOTES | ENT_HTML5, 'UTF-8' );
	}

	/**
	 * Optional editorial fields stay invisible until a volunteer supplies real copy.
	 *
	 * @param string $text Field value.
	 * @return string
	 */
	private static function editorial_text( $text ) {
		$text = trim( self::plain_text( $text ) );
		return false === strpos( $text, '[[' ) ? $text : '';
	}

	/**
	 * Normalize enclosing quote marks without changing the stored story.
	 *
	 * @param string $text  Quote text.
	 * @param int    $words Optional excerpt length; zero preserves an authored pull quote.
	 * @return string Plain text, escaped by the caller.
	 */
	private static function pull_quote( $text, $words = 0 ) {
		$text = self::editorial_text( $text );
		$text = preg_replace( '/^[\s"“”]+|[\s"“”]+$/u', '', $text );
		if ( '' === $text ) {
			return '';
		}
		$text = $words ? wp_trim_words( $text, $words ) : $text;
		return '“' . self::plain_text( wptexturize( $text ) ) . '”';
	}

	/**
	 * Verified metadata only. The update month is not an adoption date.
	 *
	 * @param int $post_id Post ID.
	 * @return string
	 */
	private static function story_meta( $post_id ) {
		$home = self::editorial_text( (string) get_post_meta( $post_id, 'homeward_home_duration', true ) );
		$region = self::editorial_text( (string) get_post_meta( $post_id, 'homeward_region', true ) );
		$parts = array();
		if ( $home ) {
			/* translators: %s: verified duration at home. */
			$parts[] = sprintf( __( 'Home %s', 'homeward-rescue' ), $home );
		}
		if ( $region ) {
			/* translators: %s: origin region. */
			$parts[] = sprintf( __( 'From %s', 'homeward-rescue' ), $region );
		}
		return implode( ' · ', $parts );
	}

	/**
	 * Only extract an animal's name when the attribution or sentence is explicit.
	 *
	 * @param string $family Attribution.
	 * @param string $text   Quote.
	 * @return string
	 */
	private static function testimonial_animal( $family, $text ) {
		$name = '(\p{Lu}[\p{L}\p{M}\x{2019}\x{0027}-]*)';
		$noun = preg_quote( Settings::get( 'noun_singular' ), '/' );
		if ( preg_match(
			'/^' . $name . '[\x{2019}\x{0027}]s\s+(?i:mum|mom|dad|family|adopter|parents?)\b/u',
			$family,
			$matches
			) || preg_match(
				'/\b(?i:adopted|rescued|a (?:animal|pet|dog|cat|' . $noun . ') named|our (?:animal|pet|dog|cat|' . $noun
				. ') named)\s+' . $name . '\b/u',
				$text,
				$matches
			) || preg_match(
				'/^' . $name . '\s+(?i:came to us|settled in|joined our family|is a character)\b/u',
				$text,
				$matches
		) ) {
			return $matches[ 1 ];
		}
		return '';
	}

	/**
	 * Print the shared story dialog template.
	 *
	 * @return void
	 */
	public static function story_dialog() {
		require HOMEWARD_DIR . 'templates/story-dialog.php';
	}

	/**
	 * One story card.
	 *
	 * @param int  $post_id Post ID.
	 * @param bool $feature Use the large editorial treatment.
	 * @return string
	 */
	public static function story_card( $post_id, $feature = false ) {
		if ( self::protected_post( $post_id ) ) {
			return '';
		}
		Frontend::story_assets();
		$title = self::plain_text( get_the_title( $post_id ) );
		$url = get_permalink( $post_id );
		$region = (string) get_post_meta( $post_id, 'homeward_region', true );
		$family = (string) get_post_meta( $post_id, 'homeward_family', true );
		$kind = 'testimonial' === get_post_meta( $post_id, 'homeward_kind', true ) ? 'testimonial' : 'story';
		$feature = $feature && 'story' === $kind;
		$plain = self::plain_text( (string) get_post_meta( $post_id, 'homeward_text', true ) );
		$marker = 'HOMEWARD_EXCERPT_END';
		$text = 'testimonial' === $kind ? wp_html_excerpt( $plain, 200, $marker ) : wp_trim_words( $plain, 32, $marker );
		if ( str_ends_with( $text, $marker ) ) {
			$text = preg_replace( '/[\p{P}\s]+$/u', '', substr( $text, 0, -strlen( $marker ) ) ) . '…';
		}
		$thumb = get_the_post_thumbnail( $post_id, 'large', array( 'loading' => 'lazy', 'alt' => $title ) );
		$label = wp_unique_id( 'homeward-story-label-' );
		$data = self::story_data( $post_id, $title, $plain, $kind, $family, $region );
		$html = '<article class="homeward-card homeward-story-card' . ( $feature ? ' homeward-story-feature' . ( $thumb
			? '' : ' homeward-story-feature--text' ) : '' ) . ( 'testimonial' === $kind ? ' is-testimonial' : '' ) . '">';
		$html .= '<a class="homeward-story-card__link" href="' . esc_url( $url ) . '" aria-labelledby="' . esc_attr(
			$feature ? $label . '-read ' . $label : $label
		) . '">';
		if ( $feature ) {
			$html .= self::feature_body( $post_id, $title, $plain, $family, $thumb, $label );
		} elseif ( 'testimonial' === $kind ) {
			$html .= self::testimonial_body( $post_id, $title, $family, $text, $label, $data );
		} else {
			$html .= self::story_body( $post_id, $title, $family, $text, $thumb, $label );
		}
		$html .= '</a><script type="application/json" class="homeward-story-data">';
		// Hex flags prevent quotes, ampersands or </script> in copy from escaping the data island.
		$html .= wp_json_encode( $data, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT );
		$html .= '</script></article>';
		return $html;
	}
	private static function story_data( $post_id, $title, $plain, $kind, $family, $region ) {
		$animal = 'testimonial' === $kind ? self::testimonial_animal( $family ?: $title, $plain ) : $title;
		$date = Content_Post_Types::sanitize_month( (string) get_post_meta( $post_id, 'homeward_received', true ) );
		$date = $date ? date_create_immutable( $date . '-01', wp_timezone() ) : false;
		$data = array(
			'slug'  => (string) get_post_field( 'post_name', $post_id ),
			'kind'  => $kind,
			'title' => $title,
			'dog'   => $animal,
			'intro' => self::plain_text( (string) get_post_meta( $post_id, 'homeward_intro', true ) ),
			/* translators: %s: origin region. */
			'origin' => $region ? sprintf( __( 'From %s', 'homeward-rescue' ), $region ) : '',
			/* translators: %s: localized month and year. */
			'received' => $date ? sprintf(
				__( 'Update received %s', 'homeward-rescue' ),
				wp_date( 'F Y', $date->getTimestamp() )
			) : '',
			'family' => self::plain_text( $family ),
			/* translators: %s: animal name. */
			'dogLabel' => $animal && 'testimonial' === $kind ? sprintf( __( 'Adopted %s', 'homeward-rescue' ), $animal ) : '',
			'image'    => wp_kses_post(
				get_the_post_thumbnail(
					$post_id,
					'large',
					array( 'loading' => 'eager', 'decoding' => 'async', 'alt' => $title )
				)
			),
			'text' => wp_kses_post( self::story_text_html( $post_id ) ),
		);
		return $data;
	}

	private static function feature_body( $post_id, $title, $plain, $family, $thumb, $label ) {
		$html = '';
		$quote = self::editorial_text( (string) get_post_meta( $post_id, 'homeward_pull_quote', true ) );
		$quote = '' !== $quote ? self::pull_quote( $quote ) : self::pull_quote( $plain, 32 );
		$meta = self::story_meta( $post_id );
		if ( $thumb ) {
			$html .= '<div class="homeward-card__media">' . $thumb . '</div>';
		}
		$html .= '<div class="homeward-card__body"><h3 class="homeward-card__title" id="' . esc_attr( $label ) . '">'
			. esc_html( $title ) . '</h3>';
		if ( $meta ) {
			$html .= '<p class="homeward-card__meta">' . esc_html( $meta ) . '</p>';
		}
		if ( $quote ) {
			$html .= '<blockquote class="homeward-story-feature__quote"><p>' . esc_html( $quote ) . '</p></blockquote>';
		}
		if ( self::editorial_text( $family ) && $family !== $title ) {
			$html .= '<p class="homeward-card__byline">' . esc_html( $family ) . '</p>';
		}
		$html .= '<span class="homeward-story-feature__read" id="' . esc_attr( $label . '-read' ) . '">'
			. esc_html__( 'Read their story', 'homeward-rescue' ) . '<span aria-hidden="true"> →</span></span></div>';
		return $html;
	}

	private static function testimonial_body( $post_id, $title, $family, $text, $label, $data ) {
		$html = '';
		$avatar = get_the_post_thumbnail( $post_id, 'thumbnail', array( 'loading' => 'lazy', 'alt' => '' ) );
		$html .= '<div class="homeward-card__body"><span class="homeward-quote-mark" aria-hidden="true">“</span>';
		$html .= '<blockquote class="homeward-quote"><p>' . esc_html( $text ) . '</p></blockquote>';
		$html .= '<div class="homeward-quote__attribution"><span class="homeward-quote__avatar" aria-hidden="true">'
			. ( $avatar ?: '&#9829;' ) . '</span>';
		$html .= '<div><h3 class="homeward-quote__name" id="' . esc_attr( $label ) . '">' . esc_html(
			$family ?: $title
		) . '</h3>';
		if ( $data['dogLabel'] ) {
			$html .= '<p class="homeward-quote__dog">' . esc_html( $data['dogLabel'] ) . '</p>';
		}
		$html .= '</div></div><span class="homeward-quote__read">' . esc_html__( 'Read testimonial →', 'homeward-rescue' )
			. '</span></div>';
		return $html;
	}

	private static function story_body( $post_id, $title, $family, $text, $thumb, $label ) {
		$html = '';
		if ( $thumb ) {
			$html .= '<div class="homeward-card__media">' . $thumb
				. '<span class="homeward-card__overlay" aria-hidden="true"><span>'
				. esc_html__( 'Read story →', 'homeward-rescue' ) . '</span></span></div>';
		}
		$html .= '<div class="homeward-card__body">';
		$meta = self::story_meta( $post_id );
		if ( $meta ) {
			$html .= '<p class="homeward-card__meta">' . self::pin() . ' ' . esc_html( $meta ) . '</p>';
		}
		$html .= '<h3 class="homeward-card__title" id="' . esc_attr( $label ) . '">' . esc_html( $title ) . '</h3>';
		if ( $text ) {
			$html .= '<p class="homeward-card__text">' . esc_html( $text ) . '</p>';
		}
		if ( $family && $family !== $title ) {
			$html .= '<p class="homeward-card__byline">' . esc_html( $family ) . '</p>';
		}
		$html .= '</div>';
		return $html;
	}

	/**
	 * Formatted start (and end) for an event.
	 *
	 * @param int $post_id Post ID.
	 * @return string Plain text, not escaped.
	 */
	public static function event_when( $post_id ) {
		if ( self::protected_post( $post_id ) ) {
			return '';
		}
		$start = self::timestamp( (string) get_post_meta( $post_id, 'homeward_start', true ) );
		$end = self::timestamp( (string) get_post_meta( $post_id, 'homeward_end', true ) );
		if ( ! $start ) {
			return '';
		}
		$out = wp_date( get_option( 'date_format', 'F j, Y' ) . ' ' . get_option( 'time_format', 'g:i a' ), $start );
		if ( $end ) {
			$out .= ( wp_date( 'Y-m-d', $start ) === wp_date( 'Y-m-d', $end ) ) ? ' - '
				. wp_date( get_option( 'time_format', 'g:i a' ), $end ) : ' - ' . wp_date(
				get_option( 'date_format', 'F j, Y' ) . ' ' . get_option( 'time_format', 'g:i a' ),
				$end
			);
		}
		return $out;
	}

	/**
	 * Site-time string to timestamp.
	 *
	 * @param string $value "Y-m-d H:i".
	 * @return int
	 */
	private static function timestamp( $value ) {
		if ( '' === $value ) {
			return 0;
		}
		$value = Content_Post_Types::sanitize_datetime( $value );
		if ( ! $value ) {
			return 0;
		}
		$dt = date_create_immutable( $value, wp_timezone() );
		return $dt ? $dt->getTimestamp() : 0;
	}

	/**
	 * One event card.
	 *
	 * @param int $post_id Post ID.
	 * @return string
	 */
	public static function event_card( $post_id ) {
		if ( self::protected_post( $post_id ) ) {
			return '';
		}
		$title = get_the_title( $post_id );
		$url = get_permalink( $post_id );
		$start = self::timestamp( (string) get_post_meta( $post_id, 'homeward_start', true ) );
		$place = (string) get_post_meta( $post_id, 'homeward_location', true );
		$desc = wp_trim_words( (string) get_post_meta( $post_id, 'homeward_description', true ), 28 );
		$thumb = get_the_post_thumbnail( $post_id, 'large', array( 'loading' => 'lazy', 'alt' => $title ) );
		$html = '<article class="homeward-card homeward-event-card">';
		if ( $thumb ) {
			$html .= '<a class="homeward-card__media" href="' . esc_url( $url ) . '" tabindex="-1" aria-hidden="true">' . $thumb
				. '</a>';
			// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- core markup.
		}
		$html .= '<div class="homeward-card__body">';
		if ( $start ) {
			$html .= '<p class="homeward-card__meta"><time datetime="' . esc_attr( wp_date( 'c', $start ) ) . '">' . esc_html(
				wp_date(
					get_option( 'date_format', 'F j, Y' ) . ' ' . get_option( 'time_format', 'g:i a' ),
					$start
				)
			) . '</time></p>';
		}
		$html .= '<h3 class="homeward-card__title"><a href="' . esc_url( $url ) . '">' . esc_html( $title ) . '</a></h3>';
		if ( $place ) {
			$html .= '<p class="homeward-card__place">' . self::pin() . ' ' . esc_html( $place ) . '</p>';
			// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static svg.
		}
		if ( $desc ) {
			$html .= '<p class="homeward-card__text">' . esc_html( $desc ) . '</p>';
		}
		$html .= '</div></article>';
		return $html;
	}

	/**
	 * Grid of story cards.
	 *
	 * @param array $args Query args.
	 * @return string
	 */
	public static function stories_grid( $args = array() ) {
		$q = self::story_query( $args );
		if ( ! $q->have_posts() ) {
			return '';
		}
		$quotes = ! empty( $args['kind'] ) && 'testimonial' === $args['kind'];
		$html = '<div class="homeward-grid homeward-stories' . ( $quotes ? ' homeward-testimonials' : '' ) . '">';
		foreach ( $q->posts as $p ) {
			$html .= self::story_card( $p->ID );
		}
		wp_reset_postdata();
		return $html . '</div>';
	}

	/**
	 * One lead story followed by regular cards. Count includes the lead story.
	 * The featured-only filter belongs to grid mode; here it selects the lead.
	 *
	 * @param array $args count and kind.
	 * @return string
	 */
	public static function stories_feature( $args = array() ) {
		$args = wp_parse_args( $args, array( 'count' => 3, 'kind' => 'story' ) );
		$id = self::featured_story_id();
		if ( ! $id ) {
			return self::stories_grid( $args );
		}
		$html = '<div class="homeward-stories-feature" data-homeward-story-group>' . self::story_card( $id, true );
		if ( (int) $args['count'] > 1 ) {
			$args['count'] = (int) $args['count'] - 1;
			$args['featured'] = false;
			$args['exclude'] = array( $id );
			$html .= self::stories_grid( $args );
		}
		return $html . '</div>';
	}

	/**
	 * Grid of event cards.
	 *
	 * @param string $when  upcoming|past.
	 * @param int    $count Count.
	 * @param string $empty Message when empty.
	 * @return string
	 */
	public static function events_grid( $when = 'upcoming', $count = 3, $empty = '' ) {
		$q = self::event_query( $when, $count );
		if ( ! $q->have_posts() ) {
			return $empty ? '<p class="homeward-empty">' . esc_html( $empty ) . '</p>' : '';
		}
		$html = '<div class="homeward-grid homeward-events">';
		foreach ( $q->posts as $p ) {
			$html .= self::event_card( $p->ID );
		}
		wp_reset_postdata();
		return $html . '</div>';
	}

	/**
	 * Render the story and testimonial tab sections.
	 *
	 * @return string
	 */
	public static function tabs() {
		Frontend::story_assets();
		$id = wp_unique_id( 'homeward-tabs-' );
		$out = '<div data-homeward-happy-tails><nav class="homeward-filter" data-homeward-tabs aria-label="'
			. esc_attr__( 'Stories', 'homeward-rescue' ) . '">';
		foreach ( Content_Post_Types::kinds() as $kind => $label ) {
			$out .= '<a class="homeward-pill' . ( 'story' === $kind ? ' is-active' : '' ) . '" id="' . esc_attr(
				$id . '-' . $kind
			) . '" data-homeward-kind="' . esc_attr( $kind ) . '" href="#' . esc_attr( $id . '-panel-' . $kind ) . '">'
				. esc_html( $label ) . '</a>';
		}
		$out .= '</nav>';
		foreach ( Content_Post_Types::kinds() as $kind => $label ) {
			$out .= '<section id="' . esc_attr( $id . '-panel-' . $kind ) . '" data-homeward-story-group data-homeward-panel="'
				. esc_attr( $kind ) . '" aria-label="' . esc_attr( $label ) . '">';
			$query = self::story_query( array( 'kind' => $kind, 'count' => -1 ) );
			$out .= '<div class="homeward-grid homeward-stories' . ( 'testimonial' === $kind ? ' homeward-testimonials' : '' )
				. '" data-homeward-batch="12">';
			foreach ( $query->posts as $post ) {
				$out .= self::story_card( $post->ID );
			}
			$out .= '</div><div class="homeward-show-more" hidden>'
				. '<button type="button" class="homeward-reader-button" data-homeward-more>'
				. esc_html__( 'Show more stories', 'homeward-rescue' ) . '</button></div></section>';
		}
		return $out . '</div>';
	}
}
