<?php
/**
 * Contact form with server validation.
 */
defined( 'ABSPATH' ) || exit;

echo \Homeward\Contact_Form::render( $attributes ); // Escaped by the renderer.
