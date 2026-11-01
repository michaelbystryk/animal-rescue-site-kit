<?php
/**
 * Title: Mission introduction
 * Slug: homeward/mission
 * Categories: homeward, text
 */
defined( 'ABSPATH' ) || exit;
?>
<!-- wp:group {
	"tagName": "section",
	"anchor": "mission",
	"className": "homeward-section",
	"layout": {
		"type": "constrained"
	}
} -->
<section id="mission" class="wp-block-group homeward-section">
	<!-- wp:heading -->
	<h2 class="wp-block-heading"><?php esc_html_e( 'What we do', 'homeward' ); ?></h2>
	<!-- /wp:heading -->
	<!-- wp:paragraph {"fontSize":"large"} -->
	<p class="has-large-font-size">
		<?php esc_html_e( 'We help animals find suitable homes and support the people who care for them.', 'homeward' ); ?>
	</p>
	<!-- /wp:paragraph -->
	<!-- wp:paragraph -->
	<p><?php esc_html_e( 'Meet our animals and contact our team to learn how you can help.', 'homeward' ); ?></p>
	<!-- /wp:paragraph -->
</section>
<!-- /wp:group -->
