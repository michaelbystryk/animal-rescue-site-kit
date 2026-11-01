<?php
/**
 * Title: Contact section
 * Slug: homeward/contact
 * Categories: homeward, text
 * Description: Uses the contact block when installed, or an editable text placeholder.
 */
defined( 'ABSPATH' ) || exit;
?>
<!-- wp:group {
	"tagName": "section",
	"anchor": "contact",
	"className": "homeward-section",
	"layout": {
		"type": "constrained"
	}
} -->
<section id="contact" class="wp-block-group homeward-section">
	<!-- wp:heading -->
	<h2 class="wp-block-heading"><?php esc_html_e( 'Contact the team', 'homeward' ); ?></h2>
	<!-- /wp:heading -->
	<?php if ( WP_Block_Type_Registry::get_instance()->is_registered( 'homeward/contact-form' ) ) : ?>
	<!-- wp:homeward/contact-form /-->
	<?php else : ?>
	<!-- wp:paragraph -->
	<p>
		<?php esc_html_e( 'Sample contact section: Add your public email, phone number, and usual reply times here.', 'homeward' ); ?>
	</p>
	<!-- /wp:paragraph -->
	<?php endif; ?>
</section>
<!-- /wp:group -->
