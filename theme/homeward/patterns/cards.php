<?php
/**
 * Title: Foster and volunteer cards
 * Slug: homeward/cards
 * Categories: homeward, call-to-action
 */
defined( 'ABSPATH' ) || exit;
?>
<!-- wp:group {"tagName":"section","align":"wide","className":"homeward-section"} -->
<section class="wp-block-group alignwide homeward-section">
	<!-- wp:heading -->
	<h2 class="wp-block-heading"><?php esc_html_e( 'Other ways to help', 'homeward' ); ?></h2>
	<!-- /wp:heading -->
	<!-- wp:group {"className":"homeward-two-up"} -->
	<div class="wp-block-group homeward-two-up">
		<!-- wp:group {"className":"homeward-card"} -->
		<div class="wp-block-group homeward-card">
			<!-- wp:heading {"level":3} -->
			<h3 class="wp-block-heading"><?php esc_html_e( 'Foster an animal', 'homeward' ); ?></h3>
			<!-- /wp:heading -->
			<!-- wp:paragraph -->
			<p>
				<?php esc_html_e( 'Offer an animal a temporary home. Contact us to learn about fostering and support.', 'homeward' ); ?>
			</p>
			<!-- /wp:paragraph -->
			<!-- wp:homeward/apply-button {"type":"foster"} /-->
		</div>
		<!-- /wp:group -->
		<!-- wp:group {"className":"homeward-card"} -->
		<div class="wp-block-group homeward-card">
			<!-- wp:heading {"level":3} -->
			<h3 class="wp-block-heading"><?php esc_html_e( 'Volunteer your time', 'homeward' ); ?></h3>
			<!-- /wp:heading -->
			<!-- wp:paragraph -->
			<p>
				<?php esc_html_e( 'Share your time and skills. Ask our team about current volunteer opportunities.', 'homeward' ); ?>
			</p>
			<!-- /wp:paragraph -->
			<!-- wp:homeward/apply-button {"type":"volunteer"} /-->
		</div>
		<!-- /wp:group -->
	</div>
	<!-- /wp:group -->
</section>
<!-- /wp:group -->
