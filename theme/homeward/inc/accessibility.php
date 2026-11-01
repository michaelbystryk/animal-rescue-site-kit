<?php
/**
 * A skip link works even before JavaScript is available.
 *
 * @package Homeward
 */
defined( 'ABSPATH' ) || exit;

/**
 * Print a keyboard-accessible link to the main content.
 *
 * @return void
 */
function homeward_theme_skip_link() {
	echo '<a class="homeward-skip-link" href="#main-content">' . esc_html__( 'Skip to content', 'homeward' ) . '</a>';
}
add_action( 'wp_body_open', 'homeward_theme_skip_link', 5 );
/**
 * Make the main content target programmatically focusable.
 *
 * @param string $content Rendered group markup.
 * @param array  $block   Parsed group block.
 * @return string
 */
function homeward_theme_main_focus( $content, $block ) {
	if ( 'main-content' !== ( $block['attrs']['anchor'] ?? '' ) || 'main' !== ( $block['attrs']['tagName'] ?? '' ) ) {
		return $content;
	}
	$html = new WP_HTML_Tag_Processor( $content );
	if ( $html->next_tag( 'main' ) ) {
		$html->set_attribute( 'tabindex', '-1' );
	}
	return $html->get_updated_html();
}
add_filter( 'render_block_core/group', 'homeward_theme_main_focus', 10, 2 );
/**
 * Keep hero videos manually playable before motion preferences are applied.
 *
 * @param string $content Rendered cover markup.
 * @param array  $block   Parsed cover block.
 * @return string
 */
function homeward_theme_hero_video( $content, $block ) {
	$classes = explode( ' ', $block['attrs']['className'] ?? '' );
	if ( ! in_array( 'homeward-hero-cover', $classes, true ) ) {
		return $content;
	}
	$html = new WP_HTML_Tag_Processor( $content );
	while ( $html->next_tag( 'video' ) ) {
		$html->set_attribute( 'controls', true );
		if ( null !== $html->get_attribute( 'autoplay' ) ) {
			$html->remove_attribute( 'autoplay' );
			$html->set_attribute( 'data-homeward-autoplay', 'true' );
		}
	}
	return $html->get_updated_html();
}
add_filter( 'render_block_core/cover', 'homeward_theme_hero_video', 10, 2 );
