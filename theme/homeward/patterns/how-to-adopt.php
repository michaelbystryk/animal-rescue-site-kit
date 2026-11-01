<?php
/**
 * Title: How to adopt
 * Slug: homeward/how-to-adopt
 * Categories: homeward, text
 */
defined( 'ABSPATH' ) || exit;
?>
<!-- wp:group {"tagName":"section","anchor":"adopt","className":"homeward-section","layout":{"type":"constrained"}} -->
<section id="adopt" class="wp-block-group homeward-section">
	<!-- wp:heading -->
	<h2 class="wp-block-heading"><?php esc_html_e( 'How adoption works', 'homeward' ); ?></h2>
	<!-- /wp:heading -->
	<!-- wp:paragraph -->
	<p>
		<?php esc_html_e( 'Start with an animal’s profile, then contact our team about the next steps.', 'homeward' ); ?>
	</p>
	<!-- /wp:paragraph -->
	<!-- wp:list {"ordered":true,"className":"homeward-steps"} -->
	<ol class="wp-block-list homeward-steps">
		<!-- wp:list-item -->
		<li>
			<strong><?php esc_html_e( 'Read an animal’s profile.', 'homeward' ); ?></strong>
			<?php esc_html_e( 'Look at their needs and think about your household.', 'homeward' ); ?>
		</li>
		<!-- /wp:list-item -->
		<!-- wp:list-item -->
		<li>
			<strong><?php esc_html_e( 'Send an application.', 'homeward' ); ?></strong>
			<?php esc_html_e( 'Tell the team about your home and experience.', 'homeward' ); ?>
		</li>
		<!-- /wp:list-item -->
		<!-- wp:list-item -->
		<li>
			<strong><?php esc_html_e( 'Talk with the team.', 'homeward' ); ?></strong>
			<?php esc_html_e( 'Ask questions and discuss the next steps together.', 'homeward' ); ?>
		</li>
		<!-- /wp:list-item -->
	</ol>
	<!-- /wp:list -->
	<!-- wp:homeward/apply-button {"type":"adopt"} /-->
</section>
<!-- /wp:group -->
