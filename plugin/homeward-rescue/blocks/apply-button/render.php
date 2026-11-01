<?php
/**
 * Dynamic Homeward block.
 */
defined( 'ABSPATH' ) || exit;

echo \Homeward\Blocks::render(
	'apply-button',
	$attributes,
	$block,
	get_block_wrapper_attributes( array( 'class' => \Homeward\Blocks::wrapper_class( $attributes ) ) )
);
// Escaped by the renderer.
