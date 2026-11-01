<?php
/**
 * Cache-compatible, progressively enhanced contact form.
 *
 * @package Homeward
 */
namespace Homeward;

defined( 'ABSPATH' ) || exit;

/**
 * Validate and deliver contact messages with recoverable form errors.
 */
class Contact_Form {
	private static $count = 0;

	/**
	 * Register the form assets, translations and shortcode.
	 *
	 * @return void
	 */
	public static function register() {
		wp_register_style(
			'homeward-contact',
			HOMEWARD_URL . 'assets/css/contact-form.css',
			array( 'homeward' ),
			HOMEWARD_VERSION
		);
		wp_register_script(
			'homeward-contact',
			HOMEWARD_URL . 'assets/js/contact-form.js',
			array(),
			HOMEWARD_VERSION,
			array( 'strategy' => 'defer', 'in_footer' => true )
		);
		add_shortcode( 'homeward_contact_form', array( __CLASS__, 'render' ) );
		wp_localize_script(
			'homeward-contact',
			'homewardContactL10n',
			array(
				'connectionError' => __( 'Receipt could not be confirmed. Your message is still in the form. Check your connection and try again.', 'homeward-rescue' ),
			)
		);
	}

	private static function value( $data, $key ) {
		return isset( $data[ $key ] ) && is_string( $data[ $key ] ) ? $data[ $key ] : '';
	}

	/**
	 * Sign the topic, animal and return address for a form.
	 *
	 * @param array    $context Form context.
	 * @param int|null $time    Issue time, or the current time.
	 * @return string
	 */
	public static function token( $context, $time = null ) {
		$context['time'] = $time ?? time();
		$payload = base64_encode( wp_json_encode( $context ) );
		return $payload . '.' . hash_hmac( 'sha256', $payload, wp_salt( 'auth' ) );
	}

	/**
	 * Validate a signed form context and its expiry.
	 *
	 * @param string   $token Signed form context.
	 * @param int|null $now   Current time override.
	 * @return array|false
	 */
	public static function context( $token, $now = null ) {
		$parts = explode( '.', $token );
		if ( 2 !== count( $parts ) || strlen( $token ) > 8192
			|| ! hash_equals( hash_hmac( 'sha256', $parts[0], wp_salt( 'auth' ) ), $parts[1] ) ) {
			return false;
		}
		$data = json_decode( base64_decode( $parts[0], true ) ?: '', true );
		return is_array( $data ) && isset( $data['time'], $data['topic'], $data['animal'], $data['return'] )
			&& is_int( $data['time'] ) && $data['time'] <= ( $now ?? time() ) && ( $now
			?? time() ) - $data['time'] <= 24 * HOUR_IN_SECONDS && in_array( $data['topic'], Messages::TOPICS, true )
			&& is_int( $data['animal'] ) && is_string( $data['return'] ) ? $data : false;
	}

	/**
	 * Validate fields and spam checks without storing the raw IP address.
	 *
	 * @param array    $input Submitted form fields.
	 * @param string   $ip    Visitor IP address.
	 * @param int|null $now   Current time override.
	 * @return array
	 */
	public static function validate( $input, $ip, $now = null ) {
		$now = $now ?? time();
		$context = self::context( self::value( $input, 'context' ), $now );
		$data = array(
			'name'    => sanitize_text_field( self::value( $input, 'contact_name' ) ),
			'email'   => trim( self::value( $input, 'email' ) ),
			'phone'   => sanitize_text_field( self::value( $input, 'phone' ) ),
			'message' => sanitize_textarea_field( self::value( $input, 'message' ) ),
			'topic'   => $context['topic'] ?? 'general',
			'animal'  => $context['animal'] ?? 0,
		);
		$errors = array();
		if ( ! $context ) {
			$errors['form'] = __( 'This form has expired. Please reload the page and try again.', 'homeward-rescue' );
		} elseif ( $now - $context['time'] < 3 ) {
			$errors['form'] = __( 'Please wait at least 3 seconds, then try again. Reload the page if this continues.', 'homeward-rescue' );
		}
		if ( '' !== self::value( $input, 'website' ) || ( isset( $input['website'] ) && ! is_string( $input['website'] ) ) ) {
			$errors['form'] = __( 'We couldn’t accept this submission. Please try again.', 'homeward-rescue' );
		}
		if ( '' === $data['name'] || self::length( $data['name'] ) > 200 ) {
			$errors['contact_name'] = __( 'Enter your name (up to 200 characters).', 'homeward-rescue' );
		}
		if ( ! is_email( $data['email'] ) || strlen( $data['email'] ) > 254 ) {
			$errors['email'] = __( 'Enter a valid email address.', 'homeward-rescue' );
		}
		if ( self::length( $data['phone'] ) > 100 ) {
			$errors['phone'] = __( 'Use up to 100 characters for your phone number.', 'homeward-rescue' );
		}
		$length = self::length( $data['message'] );
		if ( $length < 10 || $length > 5000 ) {
			$errors['message'] = __( 'Write a message between 10 and 5,000 characters.', 'homeward-rescue' );
		}
		if ( preg_match_all( '~(?:https?://|www\.)[^\s<>]+~iu', self::value( $input, 'message' ) ) > 3 ) {
			$errors['message'] = __( 'Please include no more than 3 links.', 'homeward-rescue' );
		}
		if ( 'animal' === $data['topic'] && ! Messages::animal( $data['animal'] ) ) {
			$errors['form'] = __( 'This animal is no longer available here. Please use the contact page for a general question.', 'homeward-rescue' );
		}
		if ( 'animal' !== $data['topic'] ) {
			$data['animal'] = 0;
		}
		if ( ! $errors ) {
			$human = Turnstile::verify( self::value( $input, 'cf-turnstile-response' ), $ip );
			if ( true !== $human ) {
				$errors['form'] = $human;
			}
		}
		if ( ! $errors ) {
			$errors = self::rate_errors( $ip, $now );
		}
		return array( 'data' => $data, 'errors' => $errors, 'context' => $context );
	}

	private static function rate_errors( $ip, $now ) {
		$errors = array();
		$key = 'homeward_msg_' . hash_hmac( 'sha256', $ip, wp_salt( 'auth' ) );
		$rate = get_transient( $key );
		if ( ! is_array( $rate ) || $rate['until'] <= $now ) {
			$rate = array( 'count' => 0, 'until' => $now + HOUR_IN_SECONDS );
		}
		if ( $rate['count'] >= 5 ) {
			$errors['form'] = __( 'You’ve sent several messages. Please try again in an hour.', 'homeward-rescue' );
		} else {
			++$rate['count'];
			Settings::temporary( $key, $rate, max( 1, $rate['until'] - $now ) );
		}
		return $errors;
	}

	private static function length( $value ) {
		return function_exists( 'mb_strlen' ) ? mb_strlen( $value, 'UTF-8' ) : preg_match_all( '/./us', $value );
	}

	/**
	 * Persist a validated message before attempting email delivery.
	 *
	 * @param array  $input Submitted form fields.
	 * @param string $ip    Visitor IP address.
	 * @return array
	 */
	public static function submit( $input, $ip ) {
		$result = self::validate( $input, $ip );
		if ( ! $result['errors'] ) {
			$id = Messages::save( $result['data'] );
			if ( is_wp_error( $id ) ) {
				$result['errors']['form'] = $id->get_error_message();
			}
		}
		return $result;
	}

	/**
	 * Handle asynchronous submissions and ordinary form redirects.
	 *
	 * @return void
	 */
	public static function handle() {
		nocache_headers();
		if ( 'POST' !== ( $_SERVER['REQUEST_METHOD'] ?? '' ) ) {
			wp_die( esc_html__( 'Please submit the contact form.', 'homeward-rescue' ), '', array( 'response' => 405 ) );
		}
		$input = wp_unslash( $_POST );
		$result = self::submit( $input, self::client_ip() );
		$success = ! $result['errors'];
		$message = $success
			? __( 'Thank you, your message has been received. We’ll reply by email.', 'homeward-rescue' ) : __( 'Please check the form and try again.', 'homeward-rescue' );
		if ( '1' === self::value( $input, 'homeward_fetch' ) ) {
			wp_send_json(
				array(
					'success' => $success,
					'message' => $message,
					'errors'  => $result['errors'],
				),
				$success ? 200 : 422
			);
		}
		$return = wp_validate_redirect( $result['context']['return'] ?? '', home_url( '/contact/' ) );
		if ( $success ) {
			wp_safe_redirect( add_query_arg( 'sent', '1', remove_query_arg( 'sent', $return ) ) . '#form', 303 );
			exit;
		}
		$ticket = self::store_errors( $result, $return );
		wp_safe_redirect( add_query_arg( 'homeward_form', $ticket, remove_query_arg( 'sent', $return ) ) . '#form', 303 );
		exit;
	}

	/**
	 * Resolve the visitor address using configured proxy trust.
	 *
	 * @return string
	 */
	public static function client_ip() {
		$remote = self::value( $_SERVER, 'REMOTE_ADDR' );
		$ip = $remote;
		if ( Settings::get( 'trust_proxy' ) ) {
			foreach ( array( 'HTTP_CF_CONNECTING_IP', 'HTTP_X_FORWARDED_FOR' ) as $header ) {
				$candidate = trim( explode( ',', self::value( $_SERVER, $header ) )[0] );
				if ( filter_var( $candidate, FILTER_VALIDATE_IP ) ) {
					$ip = $candidate;
					break;
				}
			}
		}
		$ip = apply_filters( 'homeward_client_ip', $ip );
		return is_string( $ip ) && filter_var( $ip, FILTER_VALIDATE_IP ) ? $ip : $remote;
	}

	/**
	 * Store recoverable errors behind a short-lived ticket.
	 *
	 * @param array  $result Submission result.
	 * @param string $return Validated return address.
	 * @return string
	 */
	public static function store_errors( $result, $return ) {
		$nonce = bin2hex( random_bytes( 24 ) );
		Settings::temporary(
			'homeward_form_' . $nonce,
			array(
				'errors'  => $result['errors'],
				'values'  => $result['data'],
				'return'  => $return,
				'expires' => time() + 10 * 60,
			),
			10 * 60
		);
		return $nonce . '.' . hash_hmac( 'sha256', $nonce, wp_salt( 'auth' ) );
	}

	private static function recovery_return ( $return ) {
		foreach ( array( 'homeward_form', 'sent', 'topic', 'animal' ) as $key ) {
			$return = remove_query_arg( $key, $return );
		}
		return $return;
	}

	/**
	 * Recover errors only for their original return address.
	 *
	 * @param string $ticket Recovery ticket.
	 * @param string $return Validated return address.
	 * @return array|false
	 */
	public static function recover_errors( $ticket, $return ) {
		if ( ! preg_match( '/^([a-f0-9]{48})\.([a-f0-9]{64})$/D', $ticket, $parts )
			|| ! hash_equals( hash_hmac( 'sha256', $parts[1], wp_salt( 'auth' ) ), $parts[2] ) ) {
			return false;
		}
		$key = 'homeward_form_' . $parts[1];
		$stored = get_transient( $key );
		if ( ! is_array( $stored ) || $stored['expires'] < time()
			|| self::recovery_return ( $stored['return'] ) !== self::recovery_return ( $return ) ) {
			return false;
		}
		delete_transient( $key );
		return $stored;
	}

	/**
	 * Prevent caching or referrer leakage on recovery pages.
	 *
	 * @return void
	 */
	public static function recovery_headers() {
		if ( self::value( $_GET, 'homeward_form' ) ) {
			nocache_headers();
			header( 'Referrer-Policy: no-referrer' );
		}
	}

	/**
	 * Render a contact form from block or shortcode attributes.
	 *
	 * @param array $attrs Form attributes.
	 * @return string
	 */
	public static function render( $attrs = array() ) {
		return self::render_form( is_array( $attrs ) ? $attrs : array() );
	}

	/**
	 * Render the contact form and any recovered values or errors.
	 *
	 * @param array $attrs  Form attributes.
	 * @param array $errors Field-specific errors.
	 * @param array $values Previously submitted values.
	 * @return string
	 */
	public static function render_form( $attrs = array(), $errors = array(), $values = array() ) {
		$attrs = self::form_attributes( $attrs );
		list( $topic, $animal, $return, $errors, $values ) = self::form_context( $attrs, $errors, $values );
		$context = self::token( array( 'topic' => $topic, 'animal' => $animal, 'return' => $return ) );
		wp_enqueue_style( 'homeward-contact' );
		wp_enqueue_script( 'homeward-contact' );
		$id = 1 === ++self::$count ? 'form' : 'form-' . self::$count;
		$sent = ! $errors && '1' === self::value( $_GET, 'sent' );
		$status = $sent
			? __( 'Thank you, your message has been received. We’ll reply by email.', 'homeward-rescue' ) : ( $errors
			? __( 'Please check the form and try again.', 'homeward-rescue' ) : '' );
		$out = '<section class="homeward-kit homeward-contact" id="' . $id . '" aria-labelledby="' . $id
			. '-heading"><h2 id="' . $id . '-heading">' . esc_html( $attrs['heading'] ) . '</h2>';
		if ( $attrs['intro'] ) {
			$out .= '<p>' . esc_html( $attrs['intro'] ) . '</p>';
		}
		$out .= self::animal_context( $animal );
		$out .= '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) )
			. '" data-homeward-contact novalidate>' . '<input type="hidden" name="action" value="homeward_contact">'
			. '<input type="hidden" name="context" value="' . esc_attr( $context ) . '">';
		$out .= '<p class="homeward-contact__status" role="status" aria-live="polite" aria-atomic="true"'
			. ' tabindex="-1" data-contact-status>' . esc_html( $status ) . '</p><p class="homeward-contact__again">'
			. '<button type="button" class="homeward-contact__again-btn" data-contact-again>'
			. esc_html__( 'Send another message', 'homeward-rescue' )
			. '</button></p><p class="homeward-contact__error" data-contact-error="form">' . esc_html( $errors['form'] ?? '' )
			. '</p>';
		$out .= '<div class="homeward-contact__trap" aria-hidden="true"><label for="' . $id . '-website">'
			. esc_html__( 'Leave this field empty', 'homeward-rescue' ) . '</label><input id="' . $id
			. '-website" name="website" tabindex="-1" autocomplete="off"></div>';
		$out .= self::form_fields( $id, $errors, $values );
		$out .= Turnstile::widget();
		$out .= '<button type="submit" class="homeward-contact__submit">' . esc_html( $attrs['button'] )
			. '</button><p class="homeward-contact__privacy">' . esc_html( $attrs['privacy'] ) . '</p></form></section>';
		return $out;
	}
	private static function form_attributes( $attrs ) {
		$attrs = wp_parse_args(
			$attrs,
			array(
				'topic'   => 'general',
				'heading' => __( 'Send us a message', 'homeward-rescue' ),
				'intro'   => '',
				'button'  => __( 'Send message', 'homeward-rescue' ),
				'privacy' => __( "We use your details only to answer you.", 'homeward-rescue' ),
				'animal'  => 0,
			)
		);
		// RichText attributes encode entities; decode once before escaping as plain text.
		foreach ( array( 'heading', 'intro', 'button', 'privacy' ) as $key ) {
			$attrs[ $key ] = html_entity_decode( (string) $attrs[ $key ], ENT_QUOTES | ENT_HTML5, 'UTF-8' );
		}
		if ( '' === trim( $attrs['heading'] ) ) {
			$attrs['heading'] = __( 'Send us a message', 'homeward-rescue' );
		}
		if ( '' === trim( $attrs['button'] ) ) {
			$attrs['button'] = __( 'Send message', 'homeward-rescue' );
		}
		return $attrs;
	}

	private static function form_context( $attrs, $errors, $values ) {
		$topic = in_array( $attrs['topic'], Messages::TOPICS, true ) ? $attrs['topic'] : 'general';
		$animal = (int) $attrs['animal'];
		// Only the general contact page inherits query arguments; inline forms keep their topic.
		if ( ! $values && 'general' === $topic && is_page( 'contact' ) ) {
			$query_topic = self::value( $_GET, 'topic' );
			if ( in_array( $query_topic, Messages::TOPICS, true ) ) {
				$topic = $query_topic;
			}
			$animal = ctype_digit( self::value( $_GET, 'animal' ) ) ? (int) $_GET['animal'] : 0;
		}
		if ( 'animal' !== $topic ) {
			$animal = 0;
		}
		if ( 'animal' === $topic && ! Messages::animal( $animal ) ) {
			$topic = 'general';
			$animal = 0;
		}
		$return = $attrs['_return'] ?? get_permalink();
		$return = explode( '#', wp_validate_redirect( $return ?: home_url( '/contact/' ), home_url( '/contact/' ) ) )[0];
		// Preserve the selected topic for the contact page after a normal POST.
		if ( is_page( 'contact' ) ) {
			$return = add_query_arg( array( 'topic' => $topic, 'animal' => $animal ), $return );
		}
		$return = remove_query_arg( 'homeward_form', remove_query_arg( 'sent', $return ) );
		if ( ! $errors && ! $values && self::value( $_GET, 'homeward_form' ) ) {
			nocache_headers();
			$restored = self::recover_errors( self::value( $_GET, 'homeward_form' ), $return );
			if ( $restored ) {
				$errors = $restored['errors'];
				$values = $restored['values'];
				$topic = $values['topic'];
				$animal = $values['animal'];
			}
		}
		return array( $topic, $animal, $return, $errors, $values );
	}

	private static function animal_context( $animal ) {
		$out = '';
		if ( $animal ) {
			$photo = '';
			$model = Animal::data( $animal );
			$photo = $model['thumb_url'] ?? ( $model['photos'][0]['thumb'] ?? '' );
			if ( ! $photo ) {
				$photo = get_the_post_thumbnail_url( $animal, 'thumbnail' );
			}
			$out .= '<div class="homeward-contact__animal">' . ( $photo ? '<img src="' . esc_url( $photo )
				. '" alt="" width="64" height="64">' : '' ) . '<p>' . esc_html(
				sprintf(
					__( 'About %s', 'homeward-rescue' ),
					Messages::animal_name( $animal )
				)
			) . '</p></div>';
		}
		return $out;
	}

	private static function form_fields( $id, $errors, $values ) {
		$out = '';
		foreach ( array(
			'contact_name' => array( __( 'Name (required)', 'homeward-rescue' ), 'text', 'name', 200 ),
			'email'        => array( __( 'Email (required)', 'homeward-rescue' ), 'email', 'email', 254 ),
			'phone'        => array( __( 'Phone (optional)', 'homeward-rescue' ), 'tel', 'tel', 100 ),
			'message'      => array(
				__( 'Message (required)', 'homeward-rescue' ),
				'textarea',
				'',
				5000,
			),
			) as $key => $field ) {
			$error = $errors[ $key ] ?? '';
			$value = $values[ 'contact_name' === $key ? 'name' : $key ] ?? '';
			$out .= '<div class="homeward-contact__field"><label for="' . $id . '-' . $key . '">' . esc_html( $field[0] )
				. '</label>';
			$common = ' id="' . $id . '-' . $key . '" name="' . $key . '" maxlength="' . $field[3] . '" aria-describedby="' . $id
				. '-' . $key . '-error"' . ( $error ? ' aria-invalid="true"' : '' ) . ( 'phone' !== $key ? ' required' : '' );
			$out .= 'textarea' === $field[1] ? '<textarea' . $common . ' rows="6" minlength="10">' . esc_textarea( $value )
				. '</textarea>' : '<input type="' . $field[1] . '"' . $common . ' autocomplete="' . $field[2] . '" value="'
				. esc_attr( $value ) . '">';
			$out .= '<p class="homeward-contact__error" id="' . $id . '-' . $key . '-error" data-contact-error="' . $key . '">'
				. esc_html( $error ) . '</p></div>';
		}
		return $out;
	}

}
