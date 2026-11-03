<?php
namespace Homeward;

defined( 'ABSPATH' ) || exit;

/**
 * Load the rescue services and connect their WordPress hooks.
 */
class Plugin {
	/**
	 * Load plugin classes and source adapters.
	 *
	 * @return void
	 */
	public static function load() {
		require_once HOMEWARD_DIR . 'includes/sources/interface-source.php';
		foreach ( array( 'source-petstablished', 'source-fixture', 'source-demo' ) as $file ) {
			require_once HOMEWARD_DIR . 'includes/sources/class-' . $file . '.php';
		}
		foreach ( array(
			'fonts',
			'settings',
			'settings-page',
			'text',
			'description',
			'pet-data',
			'animal',
			'applications',
			'animal-post-type',
			'content-post-types',
			'meta-boxes',
			'parser',
			'importer',
			'scheduler',
			'sync-lock',
			'sync',
			'media',
			'brand',
			'render',
			'features',
			'kennel-print',
			'content-render',
			'donations',
			'frontend',
			'blocks',
			'templates',
			'seo',
			'site-health',
			'email-guard',
			'redirects',
			'llms-txt',
			'analytics',
			'turnstile',
			'message-email',
			'messages',
			'contact-form',
			'volunteer-guide',
			'dashboard-help',
			'testimonials',
			'config-import',
			'wizard',
			'demo-content',
			) as $file ) {
			require_once HOMEWARD_DIR . 'includes/class-' . $file . '.php';
		}
	}

	/**
	 * Register runtime hooks in their required order.
	 *
	 * @return void
	 */
	public static function init() {
		add_action(
			'init',
			function () {
				load_plugin_textdomain(
					'homeward-rescue',
					false,
					dirname( plugin_basename( HOMEWARD_DIR . 'homeward-rescue.php' ) ) . '/languages'
				);
			}
		);
		add_action( 'homeward_refresh_derived', array( Settings::class, 'refresh_batch' ) );
		add_filter( 'rest_post_search_query', array( Animal_Post_Type::class, 'rest_search' ) );
		add_filter( 'oembed_response_data', array( Animal_Post_Type::class, 'oembed' ), 10, 2 );
		add_filter( 'rest_homeward_animal_query', array( Animal_Post_Type::class, 'rest_query' ) );
		add_filter( 'rest_pre_dispatch', array( Animal_Post_Type::class, 'rest_read' ), 10, 3 );
		add_filter( 'rest_prepare_homeward_animal', array( Animal_Post_Type::class, 'rest_prepare' ), 10, 2 );
		add_action( 'init', array( Frontend::class, 'register_assets' ), 20 );
		add_action( 'init', array( Animal_Post_Type::class, 'register' ) );
		Content_Post_Types::init();
		add_action( 'rest_api_init', array( Content_Render::class, 'rest_hooks' ) );
		add_filter( 'post_thumbnail_html', array( Frontend::class, 'thumbnail' ), 99, 2 );
		add_filter( 'render_block_core/post-featured-image', array( Frontend::class, 'featured_block' ), 99, 3 );
		Sync::init();
		Testimonials::init();
		Brand::init();
		Config_Import::init();
		Wizard::init();
		Demo_Content::init();
		Meta_Boxes::init();
		Importer::init();
		Animal::init();
		Kennel_Print::init();
		Seo::init();
		Email_Guard::init();
		Redirects::init();
		Llms_Txt::init();
		Analytics::init();
		add_action( 'init', array( Blocks::class, 'register' ), 21 );
		Blocks::shortcodes();
		add_action( 'init', array( Templates::class, 'register' ) );
		add_action( 'init', array( Scheduler::class, 'ensure' ), 20 );
		add_action( 'init', array( __CLASS__, 'rewrites' ), 30 );
		self::admin_hooks();
		add_action( 'pre_get_posts', array( Frontend::class, 'public_query' ) );
		add_action( 'wp_enqueue_scripts', array( Frontend::class, 'enqueue' ) );
		add_action( 'template_redirect', array( Frontend::class, 'placeholder_404' ) );
		add_action( 'template_redirect', array( Contact_Form::class, 'recovery_headers' ) );
		add_filter( 'the_content', array( Frontend::class, 'content' ) );
		add_action( 'homeward_sync_listings', array( Sync::class, 'run_listings' ) );
		add_action( 'homeward_sync_details', array( Sync::class, 'process_details' ) );
		add_action( 'homeward_daily_refresh', array( Sync::class, 'daily_refresh' ) );
		add_action( 'homeward_cleanup', array( Sync::class, 'cleanup' ) );
		add_filter( 'site_status_tests', array( Site_Health::class, 'tests' ) );
		self::privacy_hook();
		Messages::init();
		add_action( 'init', array( Contact_Form::class, 'register' ), 20 );
		Volunteer_Guide::init();
		Dashboard_Help::init();
	}

	private static function admin_hooks() {
		add_action( 'admin_init', array( Settings_Page::class, 'register' ) );
		add_action( 'admin_init', array( __CLASS__, 'maybe_upgrade' ) );
		add_action(
			'admin_menu',
			function () {
				add_options_page(
					__( 'Homeward Rescue', 'homeward-rescue' ),
					__( 'Homeward Rescue', 'homeward-rescue' ),
					'manage_options',
					'homeward',
					array( Settings_Page::class, 'screen' )
				);
			}
		);
		add_action( 'admin_post_homeward_remove_demo', array( Settings_Page::class, 'remove_demo' ) );
		add_action( 'admin_post_homeward_sync_now', array( Settings_Page::class, 'sync_now' ) );
		add_action( 'admin_post_homeward_save_key', array( Settings_Page::class, 'save_key' ) );
		add_action( 'update_option_homeward_settings', array( Settings::class, 'saved' ), 10, 2 );
		add_action(
			'add_meta_boxes',
			function () {
				add_meta_box(
					'homeward-animal-overrides',
					__( 'Animal overrides', 'homeward-rescue' ),
					array( Animal_Post_Type::class, 'box' ),
					Animal_Post_Type::TYPE,
					'normal',
					'high'
				);
			}
		);
		add_action( 'save_post_' . Animal_Post_Type::TYPE, array( Animal_Post_Type::class, 'save' ) );
		add_action( 'rest_after_insert_' . Animal_Post_Type::TYPE, array( Animal_Post_Type::class, 'rest_saved' ) );
		add_filter(
			'use_block_editor_for_post_type',
			function ( $use, $type ) {
				return Animal_Post_Type::TYPE === $type ? false : $use;
			},
			100,
			2
		);
	}

	private static function privacy_hook() {
		add_action(
			'admin_init',
			function () {
				if ( function_exists( 'wp_add_privacy_policy_content' ) ) {
					wp_add_privacy_policy_content(
						'Homeward Rescue',
						__( 'Contact messages are stored privately for 24 months. When Cloudflare Turnstile is enabled, the visitor IP address and verification token are sent to Cloudflare for spam checking. Animal photos may load directly from Petstablished storage, which receives the visitor IP address when images are hotlinked. YouTube videos load only after a visitor activates them. Public animal fields are allow-listed; source-only notes and location coordinates are not stored. Optional imported covers are stored in the Media Library.', 'homeward-rescue' )
					);
				}
			}
		);
	}

	/**
	 * Clear scheduled work and refresh rewrite rules.
	 *
	 * @return void
	 */
	public static function deactivate() {
		Scheduler::clear();
		wp_clear_scheduled_hook( Messages::CRON );
		flush_rewrite_rules();
	}

	/**
	 * Install defaults, capabilities and initial schedules.
	 *
	 * @return void
	 */
	public static function activate() {
		$defaults = Settings::defaults();
		unset( $defaults['archive_slug'], $defaults['single_slug'] );
		add_option( 'homeward_settings', $defaults, '', true );
		Wizard::activate();
		add_option( 'homeward_badge_baseline', time(), '', false );
		self::grant_caps();
		Animal_Post_Type::register();
		Content_Post_Types::register();
		Scheduler::ensure();
		flush_rewrite_rules();
	}

	/**
	 * Restore capabilities when the stored installation version changes.
	 *
	 * @return void
	 */
	public static function maybe_upgrade() {
		if ( get_option( 'homeward_db_version' ) !== HOMEWARD_VERSION || ! get_option( 'homeward_message_caps' ) ) {
			self::grant_caps();
		}
	}

	/**
	 * Grant the rescue editing capabilities to site administrators and editors.
	 *
	 * @return void
	 */
	public static function grant_caps() {
		foreach ( array( 'administrator', 'editor' ) as $name ) {
			$role = get_role( $name );
			if ( ! $role ) {
				continue;
			}
			foreach ( array( 'homeward_animals', 'homeward_stories', 'homeward_events', 'homeward_messages' ) as $plural ) {
				foreach ( array(
					'edit',
					'edit_others',
					'edit_private',
					'edit_published',
					'publish',
					'read_private',
					'delete',
					'delete_private',
					'delete_published',
					'delete_others',
					) as $verb ) {
					$role->add_cap( $verb . '_' . $plural );
				}
			}
			$role->add_cap( 'homeward_edit_overrides' );
		}
		update_option( 'homeward_db_version', HOMEWARD_VERSION, true );
		update_option( 'homeward_message_caps', 1, false );
	}

	/**
	 * Flush rewrite rules after a stored slug change.
	 *
	 * @return void
	 */
	public static function rewrites() {
		if ( get_option( 'homeward_flush_rewrites' ) ) {
			delete_option( 'homeward_flush_rewrites' );
			flush_rewrite_rules( false );
		}
	}
}
