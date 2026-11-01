<?php
/**
 * Notification email for a new website message: a clear subject, a friendly sender name,
 * and a small branded HTML layout (with a plain-text part) that staff can act on.
 *
 * @package homeward-rescue
 */

namespace Homeward;

defined( 'ABSPATH' ) || exit;

class Message_Email {
	/**
	 * Human label for a topic.
	 *
	 * @param string $topic Topic key.
	 * @return string
	 */
	public static function topic_label( $topic ) {
		$labels = array(
			'general'   => __( 'General question', 'homeward-rescue' ),
			'adopt'     => __( 'Adopting', 'homeward-rescue' ),
			'foster'    => __( 'Fostering', 'homeward-rescue' ),
			'volunteer' => __( 'Volunteering', 'homeward-rescue' ),
			'transport' => __( 'Transport volunteering', 'homeward-rescue' ),
			'animal'    => sprintf(
				/* translators: %s: plural animal noun, such as dogs. */ __( 'About one of our %s', 'homeward-rescue' ),
				Settings::get( 'noun_plural' )
			),
			'website' => __( 'Website feedback', 'homeward-rescue' ),
		);
		return $labels[ $topic ] ?? ucfirst( $topic );
	}

	/**
	 * Subject line, also used as the message title in wp-admin.
	 * Names and topics come from validated form data.
	 *
	 * @param array $data Validated form data.
	 * @return string
	 */
	public static function subject( $data ) {
		$name = trim( (string) $data['name'] );
		if ( 'animal' === $data['topic'] && $data['animal'] ) {
			/* translators: 1: animal name, 2: sender name. */
			return sprintf(
				__( 'Question about %1$s from %2$s', 'homeward-rescue' ),
				Messages::animal_name( $data['animal'] ),
				$name
			);
		}
		/* translators: 1: topic label, 2: sender name. */
		return sprintf( __( '%1$s from %2$s', 'homeward-rescue' ), self::topic_label( $data['topic'] ), $name );
	}

	/**
	 * Send the notification.
	 *
	 * @param int    $id   Message post ID.
	 * @param array  $data Validated form data.
	 * @param string $to   Recipient.
	 * @return bool
	 */
	public static function send( $id, $data, $to ) {
		$subject = self::subject( $data );
		$text    = self::text( $id, $data );
		$html    = self::html( $id, $data, $subject );
		$from_name = function () {
			/* translators: %s: site name. */
			return sprintf(
				__( '%s website', 'homeward-rescue' ),
				wp_specialchars_decode( Settings::get( 'org_name' ), ENT_QUOTES )
			);
		};
		$alt = function ( $mailer ) use ( $text ) {
			$mailer->AltBody = $text; // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
		};
		add_filter( 'wp_mail_from_name', $from_name );
		add_action( 'phpmailer_init', $alt );
		$reply_name = str_replace( array( '"', "\r", "\n" ), '', $data['name'] );
		$sent       = wp_mail(
			$to,
			sanitize_text_field( $subject ),
			$html,
			array(
				'Content-Type: text/html; charset=UTF-8',
				'Reply-To: "' . $reply_name . '" <' . $data['email'] . '>',
			)
		);
		remove_filter( 'wp_mail_from_name', $from_name );
		remove_action( 'phpmailer_init', $alt );
		return (bool) $sent;
	}

	/**
	 * Animal details for the email, when the message is about an animal.
	 *
	 * @param int $animal_id Animal post ID.
	 * @return array{name:string,url:string,thumb:string}|null
	 */
	private static function animal( $animal_id ) {
		if ( ! $animal_id || ! Messages::animal( (int) $animal_id ) ) {
			return null;
		}
		$thumb = '';
		$model = Animal::data( (int) $animal_id );
		$thumb = is_array( $model ) ? (string) ( $model['thumb_url'] ?? ( $model['photos'][0]['thumb'] ?? '' ) ) : '';
		return array(
			'name'  => Messages::animal_name( $animal_id ),
			'url'   => (string) get_permalink( $animal_id ),
			'thumb' => $thumb,
		);
	}

	/**
	 * Plain-text part.
	 *
	 * @param int   $id   Message ID.
	 * @param array $data Form data.
	 * @return string
	 */
	public static function text( $id, $data ) {
		$lines = array(
			self::subject( $data ),
			'',
			__( 'Name:', 'homeward-rescue' ) . ' ' . $data['name'],
			__( 'Email:', 'homeward-rescue' ) . ' ' . $data['email'],
		);
		if ( '' !== trim( (string) $data['phone'] ) ) {
			$lines[] = __( 'Phone:', 'homeward-rescue' ) . ' ' . $data['phone'];
		}
		$lines[] = __( 'Topic:', 'homeward-rescue' ) . ' ' . self::topic_label( $data['topic'] );
		$animal     = self::animal( $data['animal'] );
		if ( $animal ) {
			$lines[] = __( 'Animal:', 'homeward-rescue' ) . ' ' . $animal['name'] . ' (' . $animal['url'] . ')';
		}
		$lines[] = '';
		$lines[] = $data['message'];
		$lines[] = '';
		$lines[] = __( 'Reply to this email to answer them directly.', 'homeward-rescue' );
		$lines[] = __( 'View in Messages:', 'homeward-rescue' ) . ' ' . Messages::view_url( $id );
		return implode( "\n", $lines );
	}

	/**
	 * HTML part. Table layout with inline styles, so it renders in Gmail, Outlook and phones.
	 *
	 * @param int    $id      Message ID.
	 * @param array  $data    Form data.
	 * @param string $subject Subject.
	 * @return string
	 */
	public static function html( $id, $data, $subject ) {
		$ink    = '#2b1f1b';
		$muted  = '#6f5a52';
		$coral  = Settings::get( 'color_primary' ) ?: '#345a48';
		$cream  = '#f4f6f4';
		$line   = '#efe2da';
		$font   = "font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,Helvetica,Arial,sans-serif;";
		$animal    = self::animal( $data['animal'] );
		$details = self::detail_rows( $data, $font, $coral, $line, $muted, $ink );

		$animal_html = self::animal_summary( $animal, $font, $cream, $muted, $ink );

		$reply = 'mailto:' . $data['email'] . '?subject=' . rawurlencode( 'Re: ' . $subject );
		$button = function ( $href, $label, $primary ) use ( $font, $coral, $ink, $line ) {
			$style = $primary ? 'background:' . $coral . ';color:#ffffff;border:1px solid ' . $coral . ';'
				: 'background:#ffffff;color:' . $ink . ';border:1px solid ' . $line . ';';
			return '<a href="' . esc_url( $href, array( 'http', 'https', 'mailto' ) ) . '" style="' . $font . $style
				. 'display:inline-block;padding:11px 20px;border-radius:999px;font-size:15px;'
				. 'font-weight:600;text-decoration:none;margin:0 8px 8px 0;">' . esc_html( $label ) . '</a>';
		};
		return '<!doctype html><html><head><meta charset="utf-8">'
			. '<meta name="viewport" content="width=device-width,initial-scale=1"><title>'
			. esc_html( $subject ) . '</title></head>' . '<body style="margin:0;padding:0;background:' . $cream . ';">'
			. '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:' . $cream
			. ';"><tr><td align="center" style="padding:24px 12px;">'
			. '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="max-width:600px;">'
			. '<tr><td style="' . $font . 'padding:0 4px 12px;font-size:14px;font-weight:700;color:' . $coral . ';">'
			. esc_html( wp_specialchars_decode( Settings::get( 'org_name' ), ENT_QUOTES ) ) . '</td></tr>'
			. '<tr><td style="background:#ffffff;border-radius:18px;padding:28px 28px 24px;">' . '<div style="' . $font
			. 'font-size:12px;color:' . $coral
			. ';text-transform:uppercase;letter-spacing:.12em;font-weight:600;margin:0 0 6px;">'
			. esc_html__( 'New website message', 'homeward-rescue' ) . '</div>' . '<h1 style="' . $font
			. 'font-size:22px;line-height:1.3;color:' . $ink . ';margin:0 0 20px;">' . esc_html( $subject ) . '</h1>'
			. $animal_html . '<div style="' . $font . 'font-size:16px;line-height:1.6;color:' . $ink
			. ';background:#fffaf7;border-left:3px solid ' . $coral
			. ';padding:14px 16px;border-radius:0 12px 12px 0;margin:0 0 20px;">' . nl2br( esc_html( $data['message'] ) )
			. '</div>' . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="margin:0 0 22px;">'
			. $details . '</table>'
			. $button( $reply, __( 'Reply to', 'homeward-rescue' ) . ' ' . strtok( trim( $data['name'] ), ' ' ), true )
			. $button( Messages::view_url( $id ), __( 'View in Messages', 'homeward-rescue' ), false ) . '</td></tr>'
			. '<tr><td style="' . $font . 'padding:14px 4px 0;font-size:12px;line-height:1.5;color:' . $muted . ';">' . esc_html(
			sprintf(
				/* translators: %s: site name. */ __( 'Replying to this email goes straight to the sender. Sent from the contact form on the %s website; a copy is saved in WordPress under Messages.', 'homeward-rescue' ),
				wp_specialchars_decode( Settings::get( 'org_name' ), ENT_QUOTES )
			)
		) . '</td></tr>' . '</table></td></tr></table></body></html>';
	}
	private static function animal_summary( $animal, $font, $cream, $muted, $ink ) {
		$animal_html = '';
		if ( $animal ) {
			$img      = $animal['thumb'] ? '<td style="width:64px;padding-right:12px;vertical-align:middle;"><img src="'
				. esc_url( $animal['thumb'] ) . '" width="64" height="64" alt="" style="display:block;width:64px;height:64px;'
				. 'border-radius:12px;object-fit:cover;"></td>' : '';
			$animal_html = '<table role="presentation" cellpadding="0" cellspacing="0" style="margin:0 0 20px;background:'
				. $cream . ';border-radius:14px;width:100%;"><tr><td style="padding:12px;">'
				. '<table role="presentation" cellpadding="0" cellspacing="0"><tr>'
				. $img . '<td style="' . $font . 'vertical-align:middle;"><div style="font-size:12px;color:' . $muted
				. ';text-transform:uppercase;letter-spacing:.08em;">' . esc_html__( 'About', 'homeward-rescue' ) . '</div>'
				. '<a href="' . esc_url( $animal['url'] ) . '" style="font-size:17px;font-weight:600;color:' . $ink
				. ';text-decoration:none;">' . esc_html( $animal['name'] ) . ' &rarr;</a></td></tr></table></td></tr></table>';
		}

		return $animal_html;
	}

	private static function detail_rows( $data, $font, $coral, $line, $muted, $ink ) {
		$rows   = array(
			__( 'Name', 'homeward-rescue' )  => esc_html( $data['name'] ),
			__( 'Email', 'homeward-rescue' ) => '<a href="' . esc_url( 'mailto:' . $data['email'] ) . '" style="color:' . $coral
			. ';">' . esc_html( $data['email'] ) . '</a>',
		);
		if ( '' !== trim( (string) $data['phone'] ) ) {
			$rows[ __( 'Phone', 'homeward-rescue' ) ] = '<a href="' . esc_url(
				'tel:' . preg_replace( '/[^0-9+]/', '', $data['phone'] )
			) . '" style="color:' . $coral . ';">' . esc_html( $data['phone'] ) . '</a>';
		}
		$rows[ __( 'Topic', 'homeward-rescue' ) ] = esc_html( self::topic_label( $data['topic'] ) );
		$rows[ __( 'Received', 'homeward-rescue' ) ] = esc_html( wp_date( 'D, M j, Y \a\t g:i a' ) );
		$details = '';
		foreach ( $rows as $label => $value ) {
			$details .= '<tr><td style="' . $font . 'padding:8px 0;border-top:1px solid ' . $line . ';width:96px;color:' . $muted
				. ';font-size:13px;vertical-align:top;">' . esc_html( $label ) . '</td>' . '<td style="' . $font
				. 'padding:8px 0;border-top:1px solid ' . $line . ';color:' . $ink . ';font-size:15px;">' . $value . '</td></tr>';
		}

		return $details;
	}

}
