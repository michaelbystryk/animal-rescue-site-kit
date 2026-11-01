<?php
/**
 * Title: Two sample testimonials
 * Slug: homeward/testimonials-row
 * Categories: homeward, testimonials
 * Description: Fictional quotes that must be replaced with permission before publication.
 */
defined( 'ABSPATH' ) || exit;
?>
<!-- wp:group {"tagName":"section","align":"wide","className":"homeward-section"} -->
<section class="wp-block-group alignwide homeward-section">
	<!-- wp:heading -->
	<h2 class="wp-block-heading"><?php esc_html_e( 'From our community', 'homeward' ); ?></h2>
	<!-- /wp:heading -->
	<!-- wp:paragraph -->
	<p>
		<?php esc_html_e( 'These quotes are fictional. Replace them with real accounts shared with permission.', 'homeward' ); ?>
	</p>
	<!-- /wp:paragraph -->
	<!-- wp:group {"className":"homeward-quotes"} -->
	<div class="wp-block-group homeward-quotes">
		<!-- wp:quote -->
		<blockquote class="wp-block-quote">
			<!-- wp:paragraph -->
			<p><?php esc_html_e( 'The team explained the routine and answered our questions.', 'homeward' ); ?></p>
			<!-- /wp:paragraph -->
			<cite><?php esc_html_e( 'Example adopter, fictional quote', 'homeward' ); ?></cite>
		</blockquote>
		<!-- /wp:quote -->
		<!-- wp:quote -->
		<blockquote class="wp-block-quote">
			<!-- wp:paragraph -->
			<p><?php esc_html_e( 'We knew who to contact when we needed help.', 'homeward' ); ?></p>
			<!-- /wp:paragraph -->
			<cite><?php esc_html_e( 'Example foster carer, fictional quote', 'homeward' ); ?></cite>
		</blockquote>
		<!-- /wp:quote -->
	</div>
	<!-- /wp:group -->
</section>
<!-- /wp:group -->
