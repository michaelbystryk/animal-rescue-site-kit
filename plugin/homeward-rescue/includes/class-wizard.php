<?php
namespace Homeward;

defined( 'ABSPATH' ) || exit;

/**
 * Guide volunteers through a resumable six-step rescue setup.
 */
class Wizard {
	const STEPS = array(
		1 => array( 'org_name', 'logo_id', 'contact_email', 'country' ),
		2 => array( 'source', 'shelter_id', 'species' ),
		3 => array( 'donate_paypal_url', 'donate_card_url', 'donate_etransfer_email', 'donate_etransfer_note' ),
		4 => array( 'color_primary', 'color_accent', 'color_ink', 'color_background', 'color_surface' ),
		5 => array( 'font_heading', 'font_body' ),
		6 => array( 'activate_theme', 'import_demo', 'posthog_key', 'posthog_region' ),
	);
	/**
	 * Register the setup screen, checks and upgrade hooks.
	 *
	 * @return void
	 */
	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ) );
		add_action( 'admin_init', array( __CLASS__, 'redirect_once' ), 20 );
		add_action( 'admin_init', array( __CLASS__, 'upgrade_home' ), 21 );
		add_action( 'admin_notices', array( __CLASS__, 'notice' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'assets' ) );
		add_action( 'wp_ajax_homeward_check_source', array( __CLASS__, 'ajax_source' ) );
		add_action( 'wp_ajax_homeward_check_colors', array( __CLASS__, 'ajax_colors' ) );
	}

	/**
	 * Read setup progress with defaults for unfinished installations.
	 *
	 * @return array
	 */
	public static function state() {
		$state = get_option( 'homeward_wizard', array() );
		return array_merge(
			array(
				'step'         => 1,
				'steps'        => array(),
				'completed_at' => 0,
				'finish'       => array( 'activate_theme' => true, 'import_demo' => false ),
			),
			is_array( $state ) ? $state : array()
		);
	}

	/**
	 * Create initial setup progress and request its first redirect.
	 *
	 * @return void
	 */
	public static function activate() {
		if ( add_option( 'homeward_wizard', self::state(), '', false ) ) {
			add_option( 'homeward_wizard_redirect', 1, '', false );
		}
	}

	/**
	 * Add the setup guide to the Settings menu.
	 *
	 * @return void
	 */
	public static function menu() {
		add_options_page(
			__( 'Homeward Setup', 'homeward-rescue' ),
			__( 'Homeward Setup', 'homeward-rescue' ),
			'manage_options',
			'homeward-setup',
			array( __CLASS__, 'screen' )
		);
	}

	/**
	 * Build the setup address for an optional step.
	 *
	 * @param int|null $step Step number.
	 * @return string
	 */
	public static function url( $step = null ) {
		return admin_url( 'options-general.php?page=homeward-setup' . ( $step ? '&step=' . (int) $step : '' ) );
	}

	/**
	 * Consume the activation redirect only on an eligible admin request.
	 *
	 * @return bool
	 */
	public static function consume_redirect() {
		if ( ! get_option( 'homeward_wizard_redirect' ) || ! current_user_can( 'manage_options' ) || wp_doing_ajax()
			|| is_network_admin() || ( defined( 'WP_CLI' ) && WP_CLI ) || ! empty( $_GET['activate-multi'] )
			|| 'POST' === ( $_SERVER['REQUEST_METHOD'] ?? '' ) ) {
			return false;
		}
		delete_option( 'homeward_wizard_redirect' );
		return ! self::state()['completed_at'] && 'homeward-setup' !== ( $_GET['page'] ?? '' );
	}

	/**
	 * Redirect the first eligible request to setup.
	 *
	 * @return void
	 */
	public static function redirect_once() {
		if ( self::consume_redirect() ) {
			wp_safe_redirect( self::url() );
			exit;
		}
	}

	/**
	 * Show the setup reminder while the guide is unfinished.
	 *
	 * @return void
	 */
	public static function notice() {
		if ( current_user_can( 'manage_options' ) && ! self::state()['completed_at'] && 'homeward-setup' !== ( $_GET['page']
			?? '' ) ) {
			echo '<div class="notice notice-info"><p><a href="' . esc_url( self::url() ) . '">'
				. esc_html__( 'Set up your rescue website', 'homeward-rescue' ) . '</a> '
				. esc_html__( 'Your progress is saved after every step.', 'homeward-rescue' ) . '</p></div>';
		}
	}

	/**
	 * Enqueue setup controls and local preview fonts.
	 *
	 * @param string $hook Current admin screen hook.
	 * @return void
	 */
	public static function assets( $hook ) {
		if ( 'settings_page_homeward-setup' !== $hook ) {
			return;
		}
		wp_enqueue_media();
		wp_enqueue_style( 'homeward-wizard', HOMEWARD_URL . 'assets/css/wizard.css', array(), HOMEWARD_VERSION );
		wp_enqueue_script( 'homeward-wizard', HOMEWARD_URL . 'assets/js/wizard.js', array(), HOMEWARD_VERSION, true );
		wp_localize_script(
			'homeward-wizard',
			'homewardWizard',
			array(
				'url'             => admin_url( 'admin-ajax.php' ),
				'nonce'           => wp_create_nonce( 'homeward_wizard_check' ),
				'fonts'           => Brand::fonts(),
				'checking'        => __( 'Checking your animals...', 'homeward-rescue' ),
				'failed'          => __( 'We could not complete that check. Please try again.', 'homeward-rescue' ),
				'chooseLogo'      => __( 'Choose your rescue logo', 'homeward-rescue' ),
				'useLogo'         => __( 'Use this logo', 'homeward-rescue' ),
				'posthogKeyError' => Analytics::key_error(),
			)
		);
		// Bundled preview fonts also work when the companion theme is inactive.
		wp_add_inline_style( 'homeward-wizard', Fonts::preview_css( HOMEWARD_URL . 'assets/fonts/' ) );
	}

	/**
	 * Validate a source connection without changing settings.
	 *
	 * @param array $input Submitted connection fields.
	 * @return array|\WP_Error
	 */
	public static function check_source( $input ) {
		$values = Config_Import::validate_settings( $input );
		if ( is_wp_error( $values ) ) {
			return $values;
		}
		if ( ! isset( $values['source'], $values['shelter_id'] ) ) {
			return new \WP_Error( 'homeward_source', __( 'Choose sample animals or enter your shelter number.', 'homeward-rescue' ) );
		}
		$source = match ( $values['source'] ) {
			'fixture' => new Source_Fixture(),
			'demo'    => new Source_Demo(),
			default => new Source_Petstablished( $values['shelter_id'] ),
		};
		$pets = $source->fetch_all();
		if ( is_wp_error( $pets ) ) {
			return new \WP_Error( 'homeward_source', __( 'We could not find your animals. Check the shelter number and try again.', 'homeward-rescue' ) );
		}
		return array(
			'count'   => count( $pets ),
			'message' => sprintf(
				_n( '%d animal found', '%d animals found', count( $pets ), 'homeward-rescue' ),
				count( $pets )
			),
		);
	}

	private static function authorize_ajax() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error(
				array(
					'message' => __( 'You do not have permission to change these settings.', 'homeward-rescue' ),
				),
				403
			);
		}
		check_ajax_referer( 'homeward_wizard_check', 'nonce' );
	}

	/**
	 * Return an authorized source connection check.
	 *
	 * @return void
	 */
	public static function ajax_source() {
		self::authorize_ajax();
		$result = self::check_source( array( 'shelter_id' => wp_unslash( $_POST['shelter_id'] ?? '' ) ) );
		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array( 'message' => $result->get_error_message() ), 400 );
		}
		wp_send_json_success( $result );
	}

	/**
	 * Build readable contrast feedback for the chosen colours.
	 *
	 * @param array $settings Colour settings to evaluate.
	 * @return array
	 */
	public static function color_feedback( $settings ) {
		$report = Brand::contrast_report( $settings );
		$names = array(
			'ink'        => __( 'Text', 'homeward-rescue' ),
			'background' => __( 'page background', 'homeward-rescue' ),
			'surface'    => __( 'card background', 'homeward-rescue' ),
			'primary'    => __( 'Links', 'homeward-rescue' ),
			'accent'     => __( 'highlight', 'homeward-rescue' ),
		);
		foreach ( $report as &$check ) {
			$check['message'] = sprintf(
				__( '%1$s on %2$s: %3$s:1. %4$s', 'homeward-rescue' ),
				$names[ $check['text'] ],
				$names[ $check['background'] ],
				number_format_i18n( $check['ratio'], 2 ),
				$check['passes']
				? __( 'Easy to read.', 'homeward-rescue' ) : __( 'May be hard to read. Aim for at least 4.5:1.', 'homeward-rescue' )
			);
		}
		return $report;
	}

	/**
	 * Return an authorized colour contrast check.
	 *
	 * @return void
	 */
	public static function ajax_colors() {
		self::authorize_ajax();
		$values = Config_Import::validate_settings( wp_unslash( $_POST['colors'] ?? array() ) );
		if ( is_wp_error( $values ) ) {
			wp_send_json_error( array( 'message' => $values->get_error_message() ), 400 );
		}
		wp_send_json_success( array( 'checks' => self::color_feedback( array_merge( Settings::get(), $values ) ) ) );
	}

	/**
	 * Validate and save a setup step or complete the guide.
	 *
	 * @param int   $step                  Step number.
	 * @param array $input                 Submitted step fields.
	 * @param bool  $confirm_source_change Whether source replacement was confirmed.
	 * @return array|\WP_Error
	 */
	public static function save_step( $step, $input, $confirm_source_change = false ) {
		if ( ! current_user_can( 'manage_options' ) ) {
			return new \WP_Error( 'homeward_permission', __( 'You do not have permission to change these settings.', 'homeward-rescue' ) );
		}
		if ( ! isset( self::STEPS[ $step ] ) || ! is_array( $input ) ) {
			return new \WP_Error( 'homeward_step', __( 'Choose one of the six setup steps.', 'homeward-rescue' ) );
		}
		$known = Config_Import::unknown(
			$input,
			5 === (int) $step ? array_merge( self::STEPS[5], array( 'font_pairing' ) ) : self::STEPS[ $step ],
			'step'
		);
		if ( is_wp_error( $known ) ) {
			return $known;
		}
		if ( 5 === (int) $step && array_key_exists( 'font_pairing', $input ) ) {
			// A chosen pairing card sets both fonts. "custom" keeps the Mix your own choices.
			$pairing = is_string( $input['font_pairing'] ) ? $input['font_pairing'] : '';
			unset( $input['font_pairing'] );
			if ( isset( Fonts::pairings()[ $pairing ] ) ) {
				$input['font_heading'] = Fonts::pairings()[ $pairing ]['heading'];
				$input['font_body'] = Fonts::pairings()[ $pairing ]['body'];
			} elseif ( 'custom' !== $pairing ) {
				return new \WP_Error( 'homeward_config_value', __( 'Choose one of the font pairings, or mix your own.', 'homeward-rescue' ) );
			}
		}
		$required = array(
			1 => array( 'org_name' ),
			2 => array( 'shelter_id' ),
			3 => array(),
			4 => self::STEPS[4],
			5 => self::STEPS[5],
			6 => array( 'activate_theme', 'import_demo' ),
		);
		if ( array_diff( $required[ $step ], array_keys( $input ) ) ) {
			return new \WP_Error( 'homeward_required', __( 'Please complete the fields in this step.', 'homeward-rescue' ) );
		}
		if ( 6 === (int) $step ) {
			return self::save_finish_choices( $input );
		}
		$saved = Config_Import::save_settings( $input, $confirm_source_change );
		if ( is_wp_error( $saved ) ) {
			return $saved;
		}
		$state = self::state();
		$state['steps'][ $step ] = $saved;
		$state['step'] = min( 6, (int) $step + 1 );
		$state['completed_at'] = 0;
		update_option( 'homeward_wizard', $state, false );
		return $state;
	}

	private static function save_finish_choices( $input ) {
		$choices = array_intersect_key( $input, array_flip( array( 'activate_theme', 'import_demo' ) ) );
		foreach ( $choices as $value ) {
			if ( ! is_bool( $value ) ) {
				return new \WP_Error( 'homeward_choice', __( 'Choose yes or no for the finish options.', 'homeward-rescue' ) );
			}
		}
		$saved = Config_Import::save_settings( array_diff_key( $input, $choices ) );
		if ( is_wp_error( $saved ) ) {
			return $saved;
		}
		return self::finish( $choices );
	}

	/**
	 * Create a saved Home page for an eligible completed legacy setup.
	 *
	 * @return void
	 */
	public static function upgrade_home() {
		$state = self::state();
		if ( ! $state['completed_at'] || ! empty( $state['pages']['home'] ) || get_option( 'page_on_front' )
			|| 'homeward' !== get_stylesheet() || ! current_user_can( 'manage_options' )
			|| ! current_user_can( 'publish_pages' ) ) {
			return;
		}
		$page = get_page_by_path( 'home', OBJECT, 'page' );
		$id = $page ? $page->ID : wp_insert_post(
			wp_slash(
				array(
					'post_type'    => 'page',
					'post_status'  => 'publish',
					'post_name'    => 'home',
					'post_title'   => __( 'Home', 'homeward-rescue' ),
					'post_content' => self::home_content(),
				)
			),
			true
		);
		if ( ! $id || is_wp_error( $id ) ) {
			return;
		}
		if ( ! $page ) {
			Demo_Content::remember_wizard( $id );
		}
		$state['pages']['home'] = (int) $id;
		update_option( 'homeward_wizard', $state, false );
		update_option( 'page_on_front', (int) $id );
		update_option( 'show_on_front', 'page' );
	}

	/**
	 * Define the starter pages and their block content.
	 *
	 * @return array
	 */
	public static function starter_pages() {
		return array(
			'home'   => array( __( 'Home', 'homeward-rescue' ), self::home_content() ),
			'adopt'  => array( __( 'Adopt', 'homeward-rescue' ), '<!-- wp:homeward/animal-grid /-->' ),
			'foster' => array(
				__( 'Foster', 'homeward-rescue' ),
				'<!-- wp:homeward/apply-button {"type":"foster"} /-->' . "\n"
				. '<!-- wp:homeward/contact-form {"topic":"foster"} /-->',
			),
			'donate'    => array( __( 'Donate', 'homeward-rescue' ), '<!-- wp:homeward/donate-panel /-->' ),
			'contact'   => array( __( 'Contact', 'homeward-rescue' ), '<!-- wp:homeward/contact-form /-->' ),
			'volunteer' => array(
				__( 'Volunteer', 'homeward-rescue' ),
				'<!-- wp:homeward/contact-form {"topic":"volunteer"} /-->',
			),
			'about' => array(
				__( 'About', 'homeward-rescue' ),
				'<!-- wp:paragraph --><p>' . esc_html( Settings::get( 'org_name' ) ) . '</p><!-- /wp:paragraph -->' . "\n"
				. '<!-- wp:homeward/story-cards {"kind":"testimonial"} /-->',
			),
		);
	}

	/**
	 * Build the starter homepage from registered or bundled patterns.
	 *
	 * @return string
	 */
	public static function home_content() {
		$content = '';
		$theme = wp_get_theme( 'homeward' );
		$directory = $theme->get_stylesheet_directory() . '/patterns/';
		$homeward_pattern_uri = $theme->get_stylesheet_directory_uri();
		foreach ( array(
			'hero',
			'mission',
			'animal-highlights',
			'how-to-adopt',
			'cards',
			'stories',
			'give-cards',
			'testimonials-row',
			'events',
			'contact',
			) as $slug ) {
			$pattern = class_exists( '\WP_Block_Patterns_Registry' )
				? \WP_Block_Patterns_Registry::get_instance()->get_registered( 'homeward/' . $slug ) : null;
			if ( $pattern ) {
				$content .= $pattern['content'] . "\n";
			} elseif ( is_readable( $directory . $slug . '.php' ) ) {
				ob_start();
				include $directory . $slug . '.php';
				$content .= ob_get_clean() . "\n";
			}
		}
		return $content
			?: '<!-- wp:heading {"level":1} --><h1 class="wp-block-heading">' . esc_html( Settings::get( 'org_name' ) )
			. '</h1><!-- /wp:heading --><!-- wp:homeward/animal-carousel /-->'
			. '<!-- wp:homeward/donate-panel /--><!-- wp:homeward/contact-form /-->';
	}

	private static function create_pages() {
		$ids = array();
		foreach ( self::starter_pages() as $slug => $page ) {
			$existing = get_page_by_path( $slug, OBJECT, 'page' );
			$id = $existing ? $existing->ID : wp_insert_post(
				wp_slash(
					array(
						'post_type'    => 'page',
						'post_status'  => 'publish',
						'post_name'    => $slug,
						'post_title'   => $page[0],
						'post_content' => $page[1],
					)
				),
				true
			);
			if ( is_wp_error( $id ) || ! $id ) {
				return new \WP_Error( 'homeward_page', __( 'A page could not be created. Your progress is saved; please try Finish again.', 'homeward-rescue' ) );
			}
			if ( ! $existing ) {
				Demo_Content::remember_wizard( $id );
			}
			$ids[ $slug ] = (int) $id;
		}
		return $ids;
	}

	private static function create_menu( $ids ) {
		$locations = (array) get_theme_mod( 'nav_menu_locations', array() );
		$menu = ! empty( $locations['primary'] )
			? wp_get_nav_menu_object( $locations['primary'] ) : wp_get_nav_menu_object( 'homeward-primary' );
		$menu_id = $menu ? (int) $menu->term_id : wp_create_nav_menu( 'homeward-primary' );
		if ( is_wp_error( $menu_id ) || ! $menu_id ) {
			return new \WP_Error( 'homeward_menu', __( 'The menu could not be saved. Please try Finish again.', 'homeward-rescue' ) );
		}
		$items = wp_get_nav_menu_items( $menu_id ) ?: array();
		$linked = array_map(
			function ( $item ) {
				return 'page' === $item->object ? (int) $item->object_id : 0;
			},
			$items
		);
		$archive = Render::archive_url();
		$archive_link = self::ensure_archive_link( $items, $menu_id, $archive );
		if ( is_wp_error( $archive_link ) ) {
			return $archive_link;
		}
		$navigation = self::create_page_links( $ids, $menu_id, $linked, $archive );
		if ( is_wp_error( $navigation ) ) {
			return $navigation;
		}
		$locations['primary'] = (int) $menu_id;
		set_theme_mod( 'nav_menu_locations', $locations );
		return self::save_navigation( $navigation, $menu_id );
	}

	private static function ensure_archive_link( $items, $menu_id, $archive ) {
		$archive_item = array_filter( $items, static fn ( $item ) => 'custom' === $item->type && $item->url === $archive );
		if ( ! $archive_item ) {
			$item = wp_update_nav_menu_item(
				$menu_id,
				0,
				array(
					'menu-item-title'     => __( 'Animals', 'homeward-rescue' ),
					'menu-item-url'       => $archive,
					'menu-item-type'      => 'custom',
					'menu-item-object'    => 'custom',
					'menu-item-object-id' => 0,
					'menu-item-position'  => 1,
					'menu-item-status'    => 'publish',
				)
			);
			if ( is_wp_error( $item ) || ! $item ) {
				return new \WP_Error( 'homeward_menu', __( 'The archive link could not be saved.', 'homeward-rescue' ) );
			}
		}
		return true;
	}

	private static function create_page_links( $ids, $menu_id, $linked, $archive ) {
		$navigation = '<!-- wp:navigation-link ' . wp_json_encode(
			array(
				'label' => __( 'Animals', 'homeward-rescue' ),
				'url'   => $archive,
				'kind'  => 'custom',
			),
			JSON_HEX_TAG | JSON_HEX_AMP
		) . ' /-->' . "\n";
		foreach ( array( 'adopt', 'foster', 'volunteer', 'donate', 'about', 'contact' ) as $position => $slug ) {
			$id = $ids[ $slug ];
			if ( 'publish' !== get_post_status( $id ) ) {
				continue;
			}
			if ( ! in_array( $id, $linked, true ) ) {
				$item = wp_update_nav_menu_item(
					$menu_id,
					0,
					array(
						'menu-item-title'     => get_the_title( $id ),
						'menu-item-object-id' => $id,
						'menu-item-object'    => 'page',
						'menu-item-type'      => 'post_type',
						'menu-item-position'  => $position + 2,
						'menu-item-classes'   => 'donate' === $slug ? 'homeward-navigation-donate' : '',
						'menu-item-status'    => 'publish',
					)
				);
				if ( is_wp_error( $item ) || ! $item ) {
					return new \WP_Error( 'homeward_menu', __( 'A menu link could not be saved. Please try Finish again.', 'homeward-rescue' ) );
				}
			}
			$navigation .= '<!-- wp:navigation-link ' . wp_json_encode(
				array(
					'label'     => get_the_title( $id ),
					'type'      => 'page',
					'id'        => $id,
					'url'       => get_permalink( $id ),
					'kind'      => 'post-type',
					'className' => 'donate' === $slug ? 'homeward-navigation-donate' : '',
				),
				JSON_HEX_TAG | JSON_HEX_AMP
			) . ' /-->' . "\n";
		}
		return $navigation;
	}

	private static function save_navigation( $navigation, $menu_id ) {
		$existing = get_posts(
			array(
				'post_type'      => 'wp_navigation',
				'name'           => 'homeward-primary',
				'post_status'    => array( 'publish', 'draft' ),
				'posts_per_page' => 1,
			)
		);
		$post = array(
			'post_type'    => 'wp_navigation',
			'post_status'  => 'publish',
			'post_name'    => 'homeward-primary',
			'post_title'   => __( 'Primary', 'homeward-rescue' ),
			'post_content' => $navigation,
		);
		if ( $existing && ! Demo_Content::wizard_unchanged( $existing[0]->ID ) ) {
			return (int) $menu_id;
		}
		if ( $existing ) {
			$post['ID'] = $existing[0]->ID;
		}
		$nav = $existing ? wp_update_post( wp_slash( $post ), true ) : wp_insert_post( wp_slash( $post ), true );
		if ( is_wp_error( $nav ) || ! $nav ) {
			return new \WP_Error( 'homeward_navigation', __( 'The navigation could not be saved. Please try Finish again.', 'homeward-rescue' ) );
		}
		Demo_Content::remember_wizard( $nav );
		update_option( 'homeward_navigation_id', (int) $nav, false );
		return (int) $menu_id;
	}

	private static function finish( $choices ) {
		$state = self::state();
		if ( $state['completed_at'] ) {
			return $state;
		}
		foreach ( range( 1, 5 ) as $step ) {
			if ( ! array_key_exists( $step, $state['steps'] ) ) {
				return new \WP_Error( 'homeward_unfinished', __( 'Save each of the first five steps before finishing.', 'homeward-rescue' ) );
			}
		}
		if ( ! current_user_can( 'publish_pages' ) || ! current_user_can( 'edit_theme_options' ) ) {
			return new \WP_Error( 'homeward_permission', __( 'An administrator needs to create the pages and menu.', 'homeward-rescue' ) );
		}
		$state['finish'] = $choices;
		$state['step'] = 6;
		update_option( 'homeward_wizard', $state, false );
		if ( (int) get_option( 'homeward_wizard_finishing', 0 ) < time() - 15 * MINUTE_IN_SECONDS ) {
			delete_option( 'homeward_wizard_finishing' );
		}
		if ( ! add_option( 'homeward_wizard_finishing', time(), '', false ) ) {
			return new \WP_Error( 'homeward_busy', __( 'Setup is already finishing. Please wait a moment.', 'homeward-rescue' ) );
		}
		try {
			return self::complete_setup( $choices, $state );
		} finally {
			delete_option( 'homeward_wizard_finishing' );
		}
	}

	private static function complete_setup( $choices, $state ) {
		$messages = array();
		$theme = wp_get_theme( 'homeward' );
		if ( $choices['activate_theme'] && $theme->exists() && current_user_can( 'switch_themes' ) ) {
			if ( 'homeward' !== get_stylesheet() ) {
				switch_theme( 'homeward' );
			}
			Config_Import::apply_logo( Settings::get( 'logo_id' ) );
			$messages[] = __( 'The Homeward theme is active.', 'homeward-rescue' );
		} elseif ( $choices['activate_theme'] ) {
			$messages[] = __( 'To add the matching design, ask an administrator to open Appearance > Themes > Add New > Upload Theme, choose homeward.zip from the kit, and activate Homeward.', 'homeward-rescue' );
		}
		$ids = self::create_pages();
		if ( is_wp_error( $ids ) ) {
			return $ids;
		}
		// Preserve an existing site's selected front page, including its editorial content.
		if ( ! get_option( 'page_on_front' ) ) {
			update_option( 'page_on_front', $ids['home'] );
			update_option( 'show_on_front', 'page' );
		}
		$menu = self::create_menu( $ids );
		if ( is_wp_error( $menu ) ) {
			return $menu;
		}
		if ( $choices['import_demo'] && empty( $state['demo_imported_at'] ) ) {
			if ( has_action( 'homeward_import_demo' ) ) {
				do_action( 'homeward_import_demo' );
				$demo = get_option( Demo_Content::RESULT );
				if ( is_array( $demo ) ) {
					if ( empty( $demo['ok'] ) ) {
						return new \WP_Error( 'homeward_demo', $demo['message'] );
					}
					$messages[] = $demo['message'];
				}
				$state['demo_imported_at'] = time();
				update_option( 'homeward_wizard', $state, false );
			} else {
				$messages[] = __( 'Demo content is not available yet. This option was skipped.', 'homeward-rescue' );
			}
		}
		$sync = Sync::run_now();
		$state['pages'] = $ids;
		$state['menu'] = $menu;
		$state['messages'] = $messages;
		$state['sync'] = $sync;
		if ( empty( $sync['ok'] ) || (int) ( $sync['time'] ?? 0 ) < (int) get_option( 'homeward_wizard_finishing' ) ) {
			update_option( 'homeward_wizard', $state, false );
			return new \WP_Error( 'homeward_first_sync', __( 'Your pages and menu are saved. We could not update your animals yet. Check your connection in step 2, then try Finish again.', 'homeward-rescue' ) );
		}
		$state['steps'][6] = $choices;
		$state['completed_at'] = time();
		update_option( 'homeward_wizard', $state, false );
		return $state;
	}

	private static function field( $key, $label, $help, $value, $type = 'text', $options = array() ) {
		$id = 'homeward-' . $key;
		echo '<div class="homeward-setup-field"><label for="' . esc_attr( $id ) . '">' . esc_html( $label ) . '</label>';
		if ( $options ) {
			echo '<select id="' . esc_attr( $id ) . '" name="fields[' . esc_attr( $key ) . ']" aria-describedby="'
				. esc_attr( $id ) . '-help">';
			foreach ( $options as $choice => $name ) {
				echo '<option value="' . esc_attr( $choice ) . '" ' . selected( $value, $choice, false ) . '>' . esc_html( $name )
					. '</option>';
			}
			echo '</select>';
		} else {
			echo '<input id="' . esc_attr( $id ) . '" type="' . esc_attr( $type ) . '" name="fields[' . esc_attr( $key )
				. ']" value="' . esc_attr( is_scalar( $value ) ? $value : '' ) . '" aria-describedby="' . esc_attr( $id ) . '-help"'
				. ( 'number' === $type ? ' min="0" step="1"' : '' ) . '>';
		}
		echo '<p class="description" id="' . esc_attr( $id ) . '-help">' . esc_html( $help ) . '</p></div>';
	}

	private static function font_select( $key, $label, $help, $value ) {
		$id = 'homeward-' . $key;
		echo '<div class="homeward-setup-field"><label for="' . esc_attr( $id ) . '">' . esc_html( $label )
			. '</label><select id="' . esc_attr( $id ) . '" name="fields[' . esc_attr( $key ) . ']" aria-describedby="'
			. esc_attr( $id ) . '-help">';
		foreach ( Fonts::grouped() as $category => $choices ) {
			echo '<optgroup label="' . esc_attr( Fonts::category_label( $category ) ) . '">';
			foreach ( $choices as $slug => $name ) {
				echo '<option value="' . esc_attr( $slug ) . '" ' . selected( $value, $slug, false ) . '>' . esc_html( $name )
					. '</option>';
			}
			echo '</optgroup>';
		}
		echo '</select><p class="description" id="' . esc_attr( $id ) . '-help">' . esc_html( $help ) . '</p></div>';
	}

	/**
	 * Pairing cards drawn in their own fonts, plus a Mix your own section. Works without JavaScript.
	 */
	private static function font_picker( $heading, $body ) {
		$all = Fonts::all();
		$current = Fonts::pairing_for( $heading, $body ) ?: 'custom';
		echo '<fieldset class="homeward-fontpicker"><legend>' . esc_html__( 'Pick a look', 'homeward-rescue' )
			. '</legend><p class="description" id="homeward-fontpicker-help">'
			. esc_html__( 'Each card shows its own fonts. Choose the one that feels like your rescue. The preview on the right updates too.', 'homeward-rescue' )
			. '</p><div class="homeward-fontpicker-grid">';
		foreach ( Fonts::pairings() as $key => $pair ) {
			$h = $all[ $pair['heading'] ];
			$b = $all[ $pair['body'] ];
			$weight = in_array( '700-normal', $h['faces'], true ) ? '700' : '400';
			$id = 'homeward-font_pairing-' . $key;
			echo '<label class="homeward-fontcard" for="' . esc_attr( $id ) . '"><input type="radio" id="' . esc_attr( $id )
				. '" name="fields[font_pairing]" value="' . esc_attr( $key ) . '" data-heading="' . esc_attr( $pair['heading'] )
				. '" data-body="' . esc_attr( $pair['body'] ) . '" aria-describedby="homeward-fontpicker-help" '
				. checked( $current, $key, false ) . '><span class="homeward-fontcard-body">';
			echo '<span class="homeward-fontcard-name">' . esc_html( $pair['name'] )
				. ( Fonts::DEFAULT_HEADING === $pair['heading'] && Fonts::DEFAULT_BODY === $pair['body']
				? ' <span class="homeward-fontcard-default">' . esc_html__( 'Default', 'homeward-rescue' ) . '</span>' : '' )
				. '</span>';
			echo '<span class="homeward-fontcard-heading" style="font-family:' . esc_attr( $h['stack'] ) . ';font-weight:'
				. esc_attr( $weight ) . '">' . esc_html__( 'Meet Biscuit', 'homeward-rescue' ) . '</span>';
			echo '<span class="homeward-fontcard-text" style="font-family:' . esc_attr( $b['stack'] ) . '">'
				. esc_html__( 'Biscuit loves long walks and sunny windows. He is ready for a home of his own.', 'homeward-rescue' )
				. '</span>';
			echo '<span class="homeward-fontcard-fonts">' . esc_html(
				$h['name'] === $b['name'] ? $h['name'] : $h['name'] . ' + ' . $b['name']
			) . '</span></span></label>';
		}
		echo '</div></fieldset>';
		echo '<details class="homeward-fontmix"' . ( 'custom' === $current ? ' open' : '' ) . '><summary>'
			. esc_html__( 'Mix your own', 'homeward-rescue' ) . '</summary>';
		echo '<p class="description">'
			. esc_html__( 'Pick any heading font with any reading font. Without JavaScript, also tick "Use my own mix" so your choices are used.', 'homeward-rescue' )
			. '</p>';
		self::font_select(
			'font_heading',
			__( 'Heading font', 'homeward-rescue' ),
			__( 'Used for page titles and headings.', 'homeward-rescue' ),
			$heading
		);
		self::font_select(
			'font_body',
			__( 'Reading font', 'homeward-rescue' ),
			__( 'Used for paragraphs. Choose one that feels comfortable to read.', 'homeward-rescue' ),
			$body
		);
		echo '<p><label><input type="radio" id="homeward-font_pairing-custom" name="fields[font_pairing]"'
			. ' value="custom" aria-describedby="homeward-fontpicker-help" '
			. checked( $current, 'custom', false ) . '> ' . esc_html__( 'Use my own mix', 'homeward-rescue' )
			. '</label></p></details>';
		echo '<p>'
			. esc_html__( 'All twelve fonts come with Homeward and load from your own website. Nothing is fetched from Google.', 'homeward-rescue' )
			. '</p>';
	}

	private static function checkbox( $key, $label, $help, $checked ) {
		echo '<div class="homeward-setup-field"><label><input type="checkbox" name="fields[' . esc_attr( $key )
			. ']" value="1" ' . checked( $checked, true, false ) . ' aria-describedby="homeward-' . esc_attr( $key ) . '-help"> '
			. esc_html( $label ) . '</label><p class="description" id="homeward-' . esc_attr( $key ) . '-help">'
			. esc_html( $help ) . '</p></div>';
	}

	private static function preview() {
		$styles = '';
		foreach ( Brand::colors() as $key => $color ) {
			$styles .= '--setup-' . $key . ':' . $color . ';';
		}
		$styles .= '--setup-heading:' . Brand::font_stack( 'heading' ) . ';--setup-body:' . Brand::font_stack( 'body' ) . ';';
		echo '<aside class="homeward-setup-preview" style="' . esc_attr( $styles ) . '"><p class="homeward-preview-label">'
			. esc_html__( 'Your website preview', 'homeward-rescue' ) . '</p><h3>'
			. esc_html__( 'A new chapter starts here', 'homeward-rescue' ) . '</h3><p>'
			. esc_html__( 'Meet the animals waiting for a home and the people who care for them.', 'homeward-rescue' )
			. '</p><span class="homeward-preview-link">' . esc_html__( 'Meet our animals', 'homeward-rescue' )
			. '</span><div class="homeward-preview-card"><p>' . esc_html__( 'Every home makes a difference.', 'homeward-rescue' )
			. '</p><span class="homeward-preview-accent">' . esc_html__( 'Welcome home', 'homeward-rescue' )
			. '</span></div></aside>';
	}

	/**
	 * Handle setup submissions and render the current step.
	 *
	 * @return void
	 */
	public static function screen() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Permission denied.', 'homeward-rescue' ) );
		}
		$state = self::state();
		$requested = $_GET['step'] ?? $state['step'];
		$step = is_scalar( $requested ) ? max( 1, min( 6, (int) $requested ) ) : 1;
		$error = null;
		$notice = '';
		$draft = array();
		if ( 'POST' === ( $_SERVER['REQUEST_METHOD'] ?? '' ) ) {
			list( $step, $error, $notice, $draft ) = self::handle_request( $step );
			$state = self::state();
		}
		$values = array_merge(
			Settings::get(),
			array(
				'font_heading' => Brand::font_slug( 'heading' ),
				'font_body'    => Brand::font_slug( 'body' ),
			),
			is_array( $draft ) ? array_filter( $draft, 'is_scalar' ) : array()
		);
		$titles = self::step_titles();
		self::render_header( $step, $titles, $error, $notice );
		if ( $state['completed_at'] && 6 === $step ) {
			self::render_completed( $state );
			return;
		}
		echo '<form class="homeward-setup-form" method="post" action="' . esc_url( self::url( $step ) ) . '"><h2>'
			. esc_html( $titles[ $step ] ) . '</h2>';
		wp_nonce_field( 'homeward_setup' );
		echo '<input type="hidden" name="setup_action" value="save"><input type="hidden" name="step" value="'
			. esc_attr( $step ) . '"><div class="homeward-setup-columns"><div>';
		self::render_step( $step, $values, $state );
		self::render_actions( $step );
		self::render_import();
	}

	private static function render_step( $step, $values, $state ) {
		switch ( $step ) {
			case 1:
				self::render_rescue( $values );
				break;
			case 2:
				self::render_animals( $values );
				break;
			case 3:
				self::render_donations( $values );
				break;
			case 4:
				self::render_colors( $values );
				break;
			case 5:
				self::render_fonts( $values );
				break;
			case 6:
				self::render_finish( $values, $state );
				break;
		}
	}

	private static function handle_request( $step ) {
		check_admin_referer( 'homeward_setup' );
		$confirm = '1' === ( $_POST['confirm_source_change'] ?? '' );
		$notice = '';
		$draft = array();
		if ( 'import' === ( $_POST['setup_action'] ?? '' ) ) {
			$result = self::import_upload( $confirm );
			if ( ! is_wp_error( $result ) ) {
				$step = 1;
				$notice = __( 'Your settings are loaded. Review each step, then finish.', 'homeward-rescue' );
			}
		} else {
			$posted_step = filter_var( $_POST['step'] ?? null, FILTER_VALIDATE_INT );
			$draft = wp_unslash( $_POST['fields'] ?? array() );
			if ( 6 === $posted_step && is_array( $draft ) ) {
				$draft['activate_theme'] = '1' === ( $draft['activate_theme'] ?? '' );
				$draft['import_demo'] = '1' === ( $draft['import_demo'] ?? '' );
			}
			$result = self::save_step( $posted_step, $draft, $confirm );
			$step = is_wp_error( $result ) ? max( 1, min( 6, (int) $posted_step ) ) : $result['step'];
			if ( ! is_wp_error( $result ) ) {
				$draft = array();
				$notice = self::saved_notice( $posted_step );
			}
		}
		$error = is_wp_error( $result ) ? $result->get_error_message() : null;
		return array( $step, $error, $notice, $draft );
	}

	private static function import_upload( $confirm ) {
		$file = $_FILES['config'] ?? array();
		$name = is_string( $file['name'] ?? null ) ? $file['name'] : '';
		if (
			UPLOAD_ERR_OK !== ( $file['error'] ?? null ) ||
			! is_string( $file['tmp_name'] ?? null ) ||
			! is_uploaded_file( $file['tmp_name'] ) ||
			'json' !== strtolower( pathinfo( $name, PATHINFO_EXTENSION ) )
		) {
			return new \WP_Error( 'homeward_upload', __( 'Choose a JSON settings file to upload.', 'homeward-rescue' ) );
		}
		return Config_Import::import_file( $file['tmp_name'], $confirm );
	}

	private static function saved_notice( $step ) {
		$notice = __( 'Your choices are saved.', 'homeward-rescue' );
		if ( 4 === $step ) {
			foreach ( self::color_feedback( Settings::get() ) as $check ) {
				if ( ! $check['passes'] ) {
					$notice .= ' ' . $check['message'];
				}
			}
		}
		return $notice;
	}

	private static function render_rescue( $values ) {
		self::field(
			'org_name',
			__( 'Rescue name', 'homeward-rescue' ),
			__( 'Use the name people know your rescue by.', 'homeward-rescue' ),
			$values['org_name']
		);
		echo '<div class="homeward-setup-field"><label for="homeward-logo_id">'
			. esc_html__( 'Rescue logo', 'homeward-rescue' )
			. '</label><input type="hidden" id="homeward-logo_id" name="fields[logo_id]" value="'
			. esc_attr( $values['logo_id'] )
			. '"><button type="button" class="button" data-homeward-logo aria-describedby="homeward-logo-help">'
			. esc_html__( 'Choose a logo', 'homeward-rescue' )
			. '</button> <button type="button" class="button" data-homeward-logo-remove>'
			. esc_html__( 'Remove logo', 'homeward-rescue' ) . '</button><p id="homeward-logo-help" class="description">'
			. esc_html__( 'Choose an image from your library or upload one. You can add this later.', 'homeward-rescue' )
			. '</p><img data-homeward-logo-preview alt=""' . ( $values['logo_id'] ? ' src="'
			. esc_url( wp_get_attachment_url( (int) $values['logo_id'] ) ) . '"' : ' hidden' ) . '></div>';
		self::field(
			'contact_email',
			__( 'Contact email', 'homeward-rescue' ),
			__( 'Messages from your contact page go here. You can add this later.', 'homeward-rescue' ),
			$values['contact_email'],
			'email'
		);
		self::field(
			'country',
			__( 'Country', 'homeward-rescue' ),
			__( 'Choose Canada to show e-Transfer donations.', 'homeward-rescue' ),
			$values['country'],
			'text',
			array(
				'' => __( 'Choose later', 'homeward-rescue' ),
				'CA' => __( 'Canada', 'homeward-rescue' ),
				'US' => __( 'United States', 'homeward-rescue' ),
				'GB' => __( 'United Kingdom', 'homeward-rescue' ),
				'AU' => __( 'Australia', 'homeward-rescue' ),
				'NZ' => __( 'New Zealand', 'homeward-rescue' ),
			) + ( $values['country'] ? array( $values['country'] => $values['country'] ) : array() )
		);
	}

	private static function render_animals( $values ) {
		self::field(
			'shelter_id',
			__( 'Petstablished shelter number', 'homeward-rescue' ),
			__( 'Find this number in your Petstablished shelter link. Use 0 or leave blank for sample animals.', 'homeward-rescue' ),
			$values['shelter_id'],
			'number'
		);
		echo '<fieldset><legend>' . esc_html__( 'Which animals do you help?', 'homeward-rescue' ) . '</legend>';
		foreach ( array(
			'dog'   => __( 'Dogs', 'homeward-rescue' ),
			'cat'   => __( 'Cats', 'homeward-rescue' ),
			'other' => __( 'Other animals', 'homeward-rescue' ),
			) as $species => $label ) {
			echo '<p><label><input type="checkbox" name="fields[species][]" value="' . esc_attr( $species ) . '" '
				. checked( in_array( $species, $values['species'], true ), true, false )
				. ' aria-describedby="homeward-species-help"> ' . esc_html( $label ) . '</label></p>';
		}
		echo '<p id="homeward-species-help" class="description">'
			. esc_html__( 'The animal address follows this choice until you set a custom slug in settings.', 'homeward-rescue' )
			. '</p></fieldset>';
		echo '<button type="button" class="button" data-homeward-source-check>'
			. esc_html__( 'Check my animals', 'homeward-rescue' ) . '</button><p role="status" data-homeward-source-result></p>';
		if ( Settings::source_change_needs_confirmation() ) {
			self::confirm_source_field();
		}
	}

	private static function render_donations( $values ) {
		self::field(
			'donate_paypal_url',
			__( 'PayPal donation link', 'homeward-rescue' ),
			__( 'Paste your https://paypal.me or paypal.com donation link. Leave blank to add it later.', 'homeward-rescue' ),
			$values['donate_paypal_url'],
			'url'
		);
		self::field(
			'donate_card_url',
			__( 'Card donation link', 'homeward-rescue' ),
			__( 'Optional. Paste the secure https:// link to your card donation page.', 'homeward-rescue' ),
			$values['donate_card_url'],
			'url'
		);
		self::field(
			'donate_etransfer_email',
			__( 'e-Transfer email', 'homeward-rescue' ),
			__( 'Optional. Shown when your country is Canada.', 'homeward-rescue' ),
			$values['donate_etransfer_email'],
			'email'
		);
		self::field(
			'donate_etransfer_note',
			__( 'e-Transfer instructions', 'homeward-rescue' ),
			__( 'Optional. Add a short note to help donors send their gift.', 'homeward-rescue' ),
			$values['donate_etransfer_note']
		);
	}

	private static function render_colors( $values ) {
		$labels = array(
			'primary'    => __( 'Links and buttons', 'homeward-rescue' ),
			'accent'     => __( 'Highlights', 'homeward-rescue' ),
			'ink'        => __( 'Text', 'homeward-rescue' ),
			'background' => __( 'Page background', 'homeward-rescue' ),
			'surface'    => __( 'Card background', 'homeward-rescue' ),
		);
		foreach ( $labels as $slug => $label ) {
			self::field(
				'color_' . $slug,
				$label,
				__( 'Choose a colour. The preview updates as you change it.', 'homeward-rescue' ),
				$values[ 'color_' . $slug ] ?: Settings::defaults()[ 'color_' . $slug ],
				'color'
			);
		}
		echo '<div data-homeward-contrast role="status">';
		foreach ( self::color_feedback( $values ) as $check ) {
			echo '<p' . ( $check['passes'] ? '' : ' class="homeward-contrast-warning"' ) . '>' . esc_html( $check['message'] )
				. '</p>';
		}
		echo '</div>';
	}

	private static function render_fonts( $values ) {
		self::font_picker( $values['font_heading'], $values['font_body'] );
	}

	private static function render_finish( $values, $state ) {
		echo '<p>'
			. esc_html__( 'We will create Home, Adopt, Foster, Donate, Contact, About and Volunteer pages, add a primary menu and update your animals. Existing pages are kept.', 'homeward-rescue' )
			. '</p>';
		echo '<fieldset class="homeward-setup-statistics"><legend>'
			. esc_html__( 'Visitor statistics (optional)', 'homeward-rescue' ) . '</legend>';
		self::field(
			'posthog_key',
			__( 'PostHog project key', 'homeward-rescue' ),
			__( 'Paste your PostHog project API key. It starts with phc_. Leave blank to keep statistics off.', 'homeward-rescue' ),
			$values['posthog_key']
		);
		self::field(
			'posthog_region',
			__( 'PostHog region', 'homeward-rescue' ),
			__( 'Choose the region of your PostHog project.', 'homeward-rescue' ),
			$values['posthog_region'],
			'text',
			Analytics::regions()
		);
		echo '<p>'
			. esc_html__( 'Counts visits from the public only. Logged-in users are never tracked. Session recording, heatmaps and surveys stay off.', 'homeward-rescue' )
			. '</p></fieldset>';
		self::checkbox(
			'activate_theme',
			__( 'Use the Homeward theme', 'homeward-rescue' ),
			__( 'If installed, the matching design will be activated. Otherwise we will show you how to add it.', 'homeward-rescue' ),
			$state['finish']['activate_theme']
		);
		self::checkbox(
			'import_demo',
			__( 'Import demo content', 'homeward-rescue' ),
			__( 'Optional. Add sample animals, stories, pages and pictures. Remove them later with one click in Homeward settings or with wp homeward import-demo --remove.', 'homeward-rescue' ),
			$state['finish']['import_demo']
		);
		if ( ! wp_get_theme( 'homeward' )->exists() ) {
			echo '<p>'
				. esc_html__( 'To install the matching theme, open Appearance > Themes > Add New > Upload Theme and choose homeward.zip from the kit.', 'homeward-rescue' )
				. '</p>';
		}
	}

	private static function step_titles() {
		return array(
			1 => __( 'Your rescue', 'homeward-rescue' ),
			2 => __( 'Your animals', 'homeward-rescue' ),
			3 => __( 'Donations', 'homeward-rescue' ),
			4 => __( 'Colours', 'homeward-rescue' ),
			5 => __( 'Fonts', 'homeward-rescue' ),
			6 => __( 'Finish', 'homeward-rescue' ),
		);
	}

	private static function render_header( $step, $titles, $error, $notice ) {
		echo '<div class="wrap homeward-setup"><h1>' . esc_html__( 'Make this website yours', 'homeward-rescue' ) . '</h1><p>'
			. esc_html__( 'Six small steps. You can leave at any time and pick up where you left off.', 'homeward-rescue' )
			. '</p><nav aria-label="' . esc_attr__( 'Setup steps', 'homeward-rescue' ) . '"><ol class="homeward-setup-steps">';
		foreach ( $titles as $number => $title ) {
			echo '<li><a href="' . esc_url( self::url( $number ) ) . '"' . ( $number === $step ? ' aria-current="step"' : '' )
				. '>' . esc_html( $number . '. ' . $title ) . '</a></li>';
		}
		echo '</ol></nav>';
		if ( $error ) {
			echo '<div class="homeward-setup-message" role="alert">' . esc_html( $error ) . '</div>';
		}
		if ( $notice ) {
			echo '<p role="status">' . esc_html( $notice ) . '</p>';
		}
	}

	private static function render_completed( $state ) {
		echo '<h2>' . esc_html__( 'Your rescue website is ready', 'homeward-rescue' ) . '</h2><p>'
			. esc_html__( 'Your pages and menu are ready, and your animals have been updated.', 'homeward-rescue' ) . '</p>';
		foreach ( $state['messages'] ?? array() as $message ) {
			echo '<p>' . esc_html( $message ) . '</p>';
		}
		echo '<p><a class="button button-primary" href="' . esc_url( home_url( '/' ) ) . '">'
			. esc_html__( 'Visit your website', 'homeward-rescue' ) . '</a></p></div>';
	}

	private static function render_import() {
		echo '<details class="homeward-setup-import"><summary>' . esc_html__( 'Have a saved setup file?', 'homeward-rescue' )
			. '</summary><form method="post" enctype="multipart/form-data" action="' . esc_url( self::url() ) . '">';
		wp_nonce_field( 'homeward_setup' );
		echo '<input type="hidden" name="setup_action" value="import"><p><label for="homeward-config">'
			. esc_html__( 'Saved setup file', 'homeward-rescue' )
			. '</label><br><input type="file" name="config" id="homeward-config" accept=".json,application/json"'
			. ' required aria-describedby="homeward-config-help"></p>' . '<p class="description" id="homeward-config-help">'
			. esc_html__( 'Choose a .json file prepared using config.example.json, up to 64 KB. You can review the imported choices before finishing.', 'homeward-rescue' )
			. '</p>';
		if ( Settings::source_change_needs_confirmation() ) {
			self::confirm_source_field();
		}
		echo '<p><button class="button" type="submit">' . esc_html__( 'Load saved choices', 'homeward-rescue' )
			. '</button></p></form></details></div>';
	}

	private static function render_actions( $step ) {
		echo '</div>';
		if ( in_array( $step, array( 4, 5 ), true ) ) {
			self::preview();
		}
		echo '</div><div class="homeward-setup-actions">';
		if ( $step > 1 ) {
			echo '<a class="button" href="' . esc_url( self::url( $step - 1 ) ) . '">' . esc_html__( 'Back', 'homeward-rescue' )
				. '</a> ';
		}
		echo '<button class="button button-primary" type="submit">' . esc_html(
			6 === $step ? __( 'Finish setup', 'homeward-rescue' ) : __( 'Save and continue', 'homeward-rescue' )
		) . '</button></div></form>';
	}

	private static function confirm_source_field() {
		$id = wp_unique_id( 'homeward-source-confirm-' );
		echo '<p><label><input type="checkbox" name="confirm_source_change" value="1" aria-describedby="' . esc_attr( $id )
			. '"> ' . esc_html__( 'Replace my current shelter connection', 'homeward-rescue' )
			. '</label></p><p class="description" id="' . esc_attr( $id ) . '">'
			. esc_html__( 'Only needed when changing shelters. Animals from the previous shelter will be moved to drafts.', 'homeward-rescue' )
			. '</p>';
	}
}
