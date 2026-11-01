<?php
/**
 * Theme supports and local assets.
 *
 * @package Homeward
 */
defined( 'ABSPATH' ) || exit;

/**
 * Register theme supports, translations and editor styles.
 *
 * @return void
 */
function homeward_theme_setup() {
	load_theme_textdomain( 'homeward', get_template_directory() . '/languages' );
	add_theme_support( 'wp-block-styles' );
	add_theme_support( 'editor-styles' );
	add_theme_support( 'responsive-embeds' );
	add_theme_support( 'post-thumbnails' );
	add_theme_support(
		'custom-logo',
		array(
			'height'      => 80,
			'width'       => 240,
			'flex-height' => true,
			'flex-width'  => true,
		)
	);
	add_editor_style( 'assets/css/theme.css' );
}
add_action( 'after_setup_theme', 'homeward_theme_setup' );
/**
 * Enqueue the local theme stylesheet and interactions.
 *
 * @return void
 */
function homeward_theme_assets() {
	$version = wp_get_theme()->get( 'Version' );
	wp_enqueue_style( 'homeward-theme', get_theme_file_uri( 'assets/css/theme.css' ), array(), $version );
	wp_enqueue_script(
		'homeward-theme',
		get_theme_file_uri( 'assets/js/theme.js' ),
		array(),
		$version,
		array( 'strategy' => 'defer', 'in_footer' => true )
	);
}
add_action( 'wp_enqueue_scripts', 'homeward_theme_assets' );
/**
 * Register the rescue pattern category.
 *
 * @return void
 */
function homeward_theme_patterns() {
	register_block_pattern_category( 'homeward', array( 'label' => __( 'Rescue website', 'homeward' ) ) );
}
add_action( 'init', 'homeward_theme_patterns' );
