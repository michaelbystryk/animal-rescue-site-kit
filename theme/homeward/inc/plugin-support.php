<?php
/**
 * Optional blocks never prevent ordinary content from rendering.
 *
 * @package Homeward
 */
defined( 'ABSPATH' ) || exit;

/**
 * Check whether the companion rescue plugin is available.
 *
 * @return bool
 */
function homeward_theme_plugin_available() {
	return defined( 'HOMEWARD_VERSION' ) || class_exists( '\\Homeward\\Plugin' );
}

/**
 * Render an explanatory fallback for an unavailable rescue block.
 *
 * @param string $content Existing block markup.
 * @param array  $block   Parsed block.
 * @return string
 */
function homeward_theme_block_fallback( $content, $block ) {
	$name = $block['blockName'] ?? '';
	$messages = array(
		'homeward/animal-grid'     => __( 'Animal listings are not available yet. Please check back soon.', 'homeward' ),
		'homeward/animal-carousel' => __( 'Featured animals are not available yet.', 'homeward' ),
		'homeward/animal-profile'  => __( 'Details for this animal are not available yet.', 'homeward' ),
		'homeward/story-feature'   => __( 'A featured story will appear here when it is available.', 'homeward' ),
		'homeward/story-cards'     => __( 'Stories will appear here when they are available.', 'homeward' ),
		'homeward/story-tabs'      => __( 'Stories are not available yet.', 'homeward' ),
		'homeward/donate-panel'    => __( 'Donation details are not available yet.', 'homeward' ),
		'homeward/contact-form'    => __( 'Contact details are not available yet. Please check back soon.', 'homeward' ),
		'homeward/apply-button'    => __( 'Application details are not available yet.', 'homeward' ),
		'homeward/upcoming-events' => __( 'Event details are not available yet.', 'homeward' ),
	);
	if ( ! isset( $messages[ $name ] ) || WP_Block_Type_Registry::get_instance()->is_registered( $name ) ) {
		return $content;
	}
	$title = 'homeward/animal-profile' === $name ? '<h1>' . esc_html( get_the_title() ) . '</h1>' : '';
	return $title . '<p class="homeward-unavailable">' . esc_html( $messages[ $name ] ) . '</p>';
}
add_filter( 'render_block', 'homeward_theme_block_fallback', 10, 2 );
/**
 * Bind theme navigation to setup content or explicit public links.
 *
 * @param array $block Parsed block and its descendants.
 * @return array
 */
function homeward_theme_navigation( $block ) {
	foreach ( $block['innerBlocks'] ?? array() as $index => $child ) {
		$block['innerBlocks'][ $index ] = homeward_theme_navigation( $child );
	}
	if ( 'core/navigation' !== ( $block['blockName'] ?? '' ) || ! preg_match(
		'/\bhomeward-(?:primary|footer)-navigation\b/',
		$block['attrs']['className'] ?? ''
	) ) {
		return $block;
	}
	$id = (int) get_option( 'homeward_navigation_id', 0 );
	$post = $id ? get_post( $id ) : null;
	if ( $post && 'wp_navigation' === $post->post_type && 'publish' === $post->post_status ) {
		$block['attrs']['ref'] = $id;
		$block['innerBlocks'] = array();
		$block['innerContent'] = array();
		$block['innerHTML'] = '';
	} else {
		unset( $block['attrs']['ref'] );
		// Explicit public links avoid core's automatic alphabetical page-list fallback.
		$links = homeward_theme_navigation_links();
		$block['innerBlocks'] = array_map(
			static fn ( $attrs ) => array(
				'blockName'    => 'core/navigation-link',
				'attrs'        => $attrs,
				'innerBlocks'  => array(),
				'innerHTML'    => '',
				'innerContent' => array(),
			),
			$links
		);
		// A harmless empty block prevents core from creating a page list on a bare install.
		if ( ! $links ) {
			$block['innerBlocks'][] = array(
				'blockName'    => 'core/html',
				'attrs'        => array(),
				'innerBlocks'  => array(),
				'innerHTML'    => '',
				'innerContent' => array(),
			);
		}
		$block['innerContent'] = array_fill( 0, count( $block['innerBlocks'] ), null );
		$block['innerHTML'] = '';
	}
	return $block;
}
add_filter( 'render_block_data', 'homeward_theme_navigation' );

/**
 * Build explicit public links when setup has not saved a navigation block.
 *
 * @return array
 */
function homeward_theme_navigation_links() {
	$links = array();
	if ( post_type_exists( 'homeward_animal' ) ) {
		$links[] = array(
			'label' => __( 'Animals', 'homeward' ),
			'url'   => get_post_type_archive_link( 'homeward_animal' ),
			'kind'  => 'custom',
		);
	}
	foreach ( array( 'adopt', 'foster', 'volunteer', 'donate', 'about', 'contact' ) as $slug ) {
		$page = get_page_by_path( $slug );
		if ( $page && 'publish' === $page->post_status && ! $page->post_password ) {
			$links[] = array(
				'label'     => get_the_title( $page->ID ),
				'url'       => get_permalink( $page->ID ),
				'kind'      => 'post-type',
				'type'      => 'page',
				'id'        => $page->ID,
				'className' => 'donate' === $slug ? 'homeward-navigation-donate' : '',
			);
		}
	}
	return $links;
}
