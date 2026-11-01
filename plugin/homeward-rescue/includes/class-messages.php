<?php
/**
 * Private contact inbox, delivery and retention.
 *
 * @package Homeward
 */
namespace Homeward;

defined( 'ABSPATH' ) || exit;

/**
 * Store contact messages privately and manage volunteer access.
 */
class Messages {
	const TYPE = 'homeward_message';
	const CAP = 'edit_homeward_messages';
	const CRON = 'homeward_messages_retention';
	const TOPICS = array( 'general', 'adopt', 'foster', 'volunteer', 'transport', 'animal', 'website' );

	/**
	 * Register inbox, form-delivery and retention hooks.
	 *
	 * @return void
	 */
	public static function init() {
		add_action( 'init', array( __CLASS__, 'register' ) );
		add_action( 'admin_post_homeward_contact', array( Contact_Form::class, 'handle' ) );
		add_action( 'admin_post_nopriv_homeward_contact', array( Contact_Form::class, 'handle' ) );
		add_action( self::CRON, array( __CLASS__, 'prune' ) );
		add_action( 'admin_menu', array( __CLASS__, 'menu' ) );
		add_action( 'admin_notices', array( __CLASS__, 'notice' ) );
		add_filter( 'manage_' . self::TYPE . '_posts_columns', array( __CLASS__, 'columns' ) );
		add_action( 'manage_' . self::TYPE . '_posts_custom_column', array( __CLASS__, 'column' ), 10, 2 );
		add_filter( 'post_row_actions', array( __CLASS__, 'row_actions' ), 10, 2 );
		add_filter( 'bulk_actions-edit-' . self::TYPE, '__return_empty_array' );
	}

	/**
	 * Register the private message post type and retention job.
	 *
	 * @return void
	 */
	public static function register() {
		register_post_type(
			self::TYPE,
			array(
				'labels' => array(
					'name'          => __( 'Messages', 'homeward-rescue' ),
					'singular_name' => __( 'Message', 'homeward-rescue' ),
					'menu_name'     => __( 'Messages', 'homeward-rescue' ),
				),
				'public'              => false,
				'publicly_queryable'  => false,
				'exclude_from_search' => true,
				'show_ui'             => true,
				'show_in_rest'        => false,
				'show_in_nav_menus'   => false,
				'can_export'          => false,
				'rewrite'             => false,
				'query_var'           => false,
				'supports'            => array(),
				'menu_icon'           => 'dashicons-email',
				'map_meta_cap'        => false,
				'capabilities'        => array(
					'edit_posts'         => self::CAP,
					'edit_others_posts'  => self::CAP,
					'read_private_posts' => self::CAP,
					'read_post'          => self::CAP,
					'edit_post'          => 'do_not_allow',
					'delete_post'        => 'do_not_allow',
					'create_posts'       => 'do_not_allow',
					'publish_posts'      => 'do_not_allow',
					'delete_posts'       => 'do_not_allow',
				),
			)
		);
		if ( ! wp_next_scheduled( self::CRON ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', self::CRON );
		}
	}

	/**
	 * Resolve an eligible public animal for a contact form.
	 *
	 * @param int $id Animal post ID.
	 * @return \WP_Post|null
	 */
	public static function animal( $id ) {
		$post = $id > 0 ? get_post( $id ) : null;
		return $post && Animal_Post_Type::TYPE === $post->post_type && Animal::public_readable( $id )
			&& empty( $post->post_password ) ? $post : null;
	}

	/**
	 * Return the effective public name of an animal.
	 *
	 * @param int $id Animal post ID.
	 * @return string
	 */
	public static function animal_name( $id ) {
		return Animal::name( $id );
	}

	/**
	 * Choose the topic inbox or fall back to the contact address.
	 *
	 * @param string $topic Message topic.
	 * @return string
	 */
	public static function recipient( $topic ) {
		$overrides = Settings::get( 'message_recipients' );
		$address = is_array( $overrides ) ? ( $overrides[ $topic ] ?? '' ) : '';
		$default = Settings::get( 'contact_email' );
		return is_email( $address ) ? $address : ( is_email( $default ) ? $default : '' );
	}

	/**
	 * Persist a validated message before attempting notification delivery.
	 *
	 * @param array $data Validated message fields.
	 * @return int|\WP_Error
	 */
	public static function save( $data ) {
		$subject = Message_Email::subject( $data );
		$id = wp_insert_post(
			wp_slash(
				array(
					'post_type'      => self::TYPE,
					'post_status'    => 'private',
					'post_title'     => $subject,
					'post_content'   => $data['message'],
					'comment_status' => 'closed',
					'ping_status'    => 'closed',
					'meta_input'     => array(
						'_homeward_from'   => $data['name'],
						'_homeward_email'  => $data['email'],
						'_homeward_phone'  => $data['phone'],
						'_homeward_topic'  => $data['topic'],
						'_homeward_animal' => $data['animal'],
						'_homeward_read'   => '0',
					),
				)
			),
			true
		);
		if ( is_wp_error( $id ) || ! $id ) {
			return new \WP_Error( 'save', __( 'We couldn’t save your message. Please try again.', 'homeward-rescue' ) );
		}
		$to = self::recipient( $data['topic'] );
		$sent = '' !== $to && Message_Email::send( $id, $data, $to );
		update_post_meta( $id, '_homeward_email_failed', $sent ? '0' : '1' );
		return $id;
	}

	/**
	 * Build the authorized inbox view address for a message.
	 *
	 * @param int $id Message post ID.
	 * @return string
	 */
	public static function view_url( $id ) {
		return add_query_arg( array( 'page' => 'homeward-message', 'message' => (int) $id ), admin_url( 'admin.php' ) );
	}

	/**
	 * Return the inbox list columns.
	 *
	 * @return array
	 */
	public static function columns() {
		return array(
			'from'     => __( 'From', 'homeward-rescue' ),
			'email'    => __( 'Email', 'homeward-rescue' ),
			'topic'    => __( 'Topic', 'homeward-rescue' ),
			'animal'   => __( 'Animal', 'homeward-rescue' ),
			'received' => __( 'Received', 'homeward-rescue' ),
			'read'     => __( 'Read / unread', 'homeward-rescue' ),
		);
	}

	/**
	 * Render one inbox column.
	 *
	 * @param string $column Column identifier.
	 * @param int    $id     Message post ID.
	 * @return void
	 */
	public static function column( $column, $id ) {
		$value = get_post_meta( $id, '_homeward_' . $column, true );
		if ( 'from' === $column ) {
			echo '<a href="' . esc_url( self::view_url( $id ) ) . '">' . esc_html( $value ) . '</a>';
		} elseif ( 'topic' === $column ) {
			echo esc_html( Message_Email::topic_label( $value ) );
		} elseif ( 'animal' === $column ) {
			echo self::animal( (int) $value ) ? '<a href="' . esc_url( get_permalink( $value ) ) . '">'
				. esc_html( self::animal_name( $value ) ) . '</a>' : '';
		} elseif ( 'received' === $column ) {
			echo esc_html( get_the_date( '', $id ) . ' ' . get_the_time( '', $id ) );
		} elseif ( 'read' === $column ) {
			echo '1' === $value ? esc_html__( 'Read', 'homeward-rescue' ) : '<strong>'
				. esc_html__( 'Unread', 'homeward-rescue' ) . '</strong>';
		} else {
			echo esc_html( $value );
		}
	}

	/**
	 * Replace message edit actions with the read-only view.
	 *
	 * @param array    $actions Existing row actions.
	 * @param \WP_Post $post    Listed post.
	 * @return array
	 */
	public static function row_actions( $actions, $post ) {
		return self::TYPE === $post->post_type ? array(
			'view' => '<a href="' . esc_url( self::view_url( $post->ID ) ) . '">'
			. esc_html__( 'Read message', 'homeward-rescue' ) . '</a>',
		) : $actions;
	}

	private static function unread_query( $failed = false ) {
		$meta = array( array( 'key' => '_homeward_read', 'value' => '0' ) );
		if ( $failed ) {
			$meta[] = array( 'key' => '_homeward_email_failed', 'value' => '1' );
		}
		return new \WP_Query( array(
			'post_type'      => self::TYPE,
			'post_status'    => 'private',
			'posts_per_page' => 1,
			'fields'         => 'ids',
			'meta_query'     => $meta,
		) );
	}

	/**
	 * Register the message view and show the unread count.
	 *
	 * @return void
	 */
	public static function menu() {
		add_submenu_page(
			'',
			__( 'Message', 'homeward-rescue' ),
			__( 'Message', 'homeward-rescue' ),
			self::CAP,
			'homeward-message',
			array( __CLASS__, 'view' )
		);
		if ( ! current_user_can( self::CAP ) ) {
			return;
		}
		$count = (int) self::unread_query()->found_posts;
		global $menu;
		foreach ( $menu as &$item ) {
			if ( 'edit.php?post_type=' . self::TYPE === $item[2] && $count ) {
				$item[0] .= ' <span class="awaiting-mod"><span class="pending-count">' . $count . '</span></span>';
			}
		}
	}

	/**
	 * Display a message and handle authorized read receipts.
	 *
	 * @return void
	 */
	public static function view() {
		if ( ! current_user_can( self::CAP ) ) {
			wp_die( esc_html__( 'Not allowed.', 'homeward-rescue' ), '', array( 'response' => 403 ) );
		}
		$id = isset( $_GET['message'] ) && is_scalar( $_GET['message'] ) ? absint( $_GET['message'] ) : 0;
		$post = get_post( $id );
		if ( ! $post || self::TYPE !== $post->post_type ) {
			wp_die( esc_html__( 'Message not found.', 'homeward-rescue' ), '', array( 'response' => 404 ) );
		}
		if ( 'POST' === ( $_SERVER['REQUEST_METHOD'] ?? '' ) && 'mark_read' === ( $_POST['homeward_message_action']
			?? '' ) ) {
			check_admin_referer( 'homeward_read_message_' . $id );
			update_post_meta( $id, '_homeward_read', '1' );
		}
		echo '<div class="wrap"><h1>' . esc_html( $post->post_title ) . '</h1><dl>';
		foreach ( array(
			'from'   => __( 'From', 'homeward-rescue' ),
			'email'  => __( 'Email', 'homeward-rescue' ),
			'phone'  => __( 'Phone', 'homeward-rescue' ),
			'topic'  => __( 'Topic', 'homeward-rescue' ),
			'animal' => __( 'Animal', 'homeward-rescue' ),
			) as $key => $label ) {
			echo '<dt><strong>' . esc_html( $label ) . '</strong></dt><dd>';
			self::column( $key, $id );
			echo '</dd>';
		}
		echo '</dl><p>' . esc_html( get_the_date( '', $post ) . ' ' . get_the_time( '', $post ) )
			. '</p><div style="white-space:pre-wrap;overflow-wrap:anywhere;max-width:70ch">' . esc_html( $post->post_content )
			. '</div>';
		if ( '1' === get_post_meta( $id, '_homeward_email_failed', true ) ) {
			echo '<p>' . esc_html__( 'Email delivery failed. This message is safely stored here.', 'homeward-rescue' ) . '</p>';
		}
		$email = get_post_meta( $id, '_homeward_email', true );
		if ( is_email( $email ) ) {
			// Staff-only exception: public pages never output an email link.
			echo '<p><a class="button button-primary" href="' . esc_url(
				'mailto:' . $email . '?subject=' . rawurlencode( 'Re: ' . $post->post_title ),
				array( 'mailto' )
			) . '">' . esc_html__( 'Reply by email', 'homeward-rescue' ) . '</a></p>';
		}
		if ( '1' !== get_post_meta( $id, '_homeward_read', true ) ) {
			echo '<form method="post">';
			wp_nonce_field( 'homeward_read_message_' . $id );
			echo '<input type="hidden" name="homeward_message_action" value="mark_read"><button class="button" type="submit">'
				. esc_html__( 'Mark as read', 'homeward-rescue' ) . '</button></form>';
		}
		echo '</div>';
	}

	/**
	 * Notify inbox users when an email could not be delivered.
	 *
	 * @return void
	 */
	public static function notice() {
		if ( current_user_can( self::CAP ) && self::unread_query( true )->found_posts ) {
			echo '<div class="notice notice-info"><p><a href="' . esc_url( admin_url( 'edit.php?post_type=' . self::TYPE ) )
				. '">' . esc_html__( "A message could not be sent by email. Open Messages to read it", 'homeward-rescue' )
				. '</a></p></div>';
		}
	}

	/**
	 * Permanently delete messages older than the retention period.
	 *
	 * @return void
	 */
	public static function prune() {
		$now = new \DateTimeImmutable( 'now', new \DateTimeZone( 'UTC' ) );
		$before = $now->modify( '-24 months' )->format( 'Y-m-d H:i:s' );
		do {
			$ids = get_posts(
				array(
					'post_type'      => self::TYPE,
					'post_status'    => array( 'private', 'trash' ),
					'posts_per_page' => 100,
					'fields'         => 'ids',
					'date_query'     => array( array( 'column' => 'post_date_gmt', 'before' => $before ) ),
				)
			);
			foreach ( $ids as $id ) {
				if ( ! wp_delete_post( $id, true ) ) {
					return;
				}
			}
		} while ( 100 === count( $ids ) );
	}
}
