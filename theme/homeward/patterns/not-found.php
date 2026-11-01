<?php
/**
 * Title: Page not found
 * Slug: homeward/not-found
 * Categories: homeward
 * Inserter: no
 */
defined( 'ABSPATH' ) || exit;
?>
<!-- wp:heading {"level":1} -->
<h1 class="wp-block-heading"><?php esc_html_e( 'We could not find that page', 'homeward' ); ?></h1>
<!-- /wp:heading -->
<!-- wp:paragraph -->
<p><?php esc_html_e( 'The link may have changed. Search the site or return to the home page.', 'homeward' ); ?></p>
<!-- /wp:paragraph -->
<!-- wp:search <?php echo wp_json_encode( array(
'label' => __( 'Search this site', 'homeward' ),
'showLabel' => true,
'buttonText' => __( 'Search', 'homeward' ),
) ); ?> /-->
<!-- wp:paragraph -->
<p><a href="<?php echo esc_url( home_url( '/' ) ); ?>"><?php esc_html_e( 'Go to the home page', 'homeward' ); ?></a></p>
<!-- /wp:paragraph -->
