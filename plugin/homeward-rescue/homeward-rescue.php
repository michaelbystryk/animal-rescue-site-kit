<?php
/**
 * Plugin Name: Homeward Rescue
 * Description: Animals, stories, events and donation blocks for animal rescues. Works with Petstablished.
 * Version: 0.3.0
 * Requires at least: 6.7
 * Requires PHP: 8.1
 * License: GPL-2.0-or-later
 * Text Domain: homeward-rescue
 * Plugin URI: https://github.com/michaelbystryk/animal-rescue-site-kit
 * Update URI: https://github.com/michaelbystryk/animal-rescue-site-kit
 */
namespace Homeward;

defined( 'ABSPATH' ) || exit;

define( 'HOMEWARD_VERSION', '0.3.0' );
define( 'HOMEWARD_DIR', plugin_dir_path( __FILE__ ) );
define( 'HOMEWARD_URL', plugin_dir_url( __FILE__ ) );
require_once HOMEWARD_DIR . 'includes/class-plugin.php';
Plugin::load();
Plugin::init();
register_activation_hook( __FILE__, array( Plugin::class, 'activate' ) );
register_deactivation_hook( __FILE__, array( Plugin::class, 'deactivate' ) );
