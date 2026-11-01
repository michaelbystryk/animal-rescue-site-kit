<?php
/** Standalone staff print view, selected by Kennel_Print::template(). */
defined( 'ABSPATH' ) || exit;

$animal = \Homeward\Kennel_Print::animal( get_queried_object_id() );
echo \Homeward\Kennel_Print::page( $animal ? array( $animal ) : array() ); // Escaped by the renderer.
