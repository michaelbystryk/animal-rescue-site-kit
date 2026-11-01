<?php
/**
 * Title: Ways to give with copy-email feedback
 * Slug: homeward/give-cards
 * Categories: homeward, call-to-action
 * Description: Available donation methods from the rescue settings.
 */
defined( 'ABSPATH' ) || exit;
?>
<!-- wp:group {"tagName":"section","anchor":"give","align":"wide","className":"homeward-section"} -->
<section id="give" class="wp-block-group alignwide homeward-section">
	<!-- wp:heading -->
	<h2 class="wp-block-heading"><?php esc_html_e( 'Help with daily care', 'homeward' ); ?></h2>
	<!-- /wp:heading -->
	<!-- wp:paragraph -->
	<p><?php esc_html_e( 'Your gift helps animals receive care while they wait for a home.', 'homeward' ); ?></p>
	<!-- /wp:paragraph -->
	<!-- wp:homeward/donate-panel {"methods":["card","paypal","etransfer"]} /-->
</section>
<!-- /wp:group -->
