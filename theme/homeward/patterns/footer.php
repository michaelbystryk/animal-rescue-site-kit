<?php
/**
 * Title: Simple rescue footer
 * Slug: homeward/footer
 * Categories: homeward, footer
 * Block Types: core/template-part/footer
 */
defined( 'ABSPATH' ) || exit;
?>
<!-- wp:group {"align":"full","className":"homeward-footer","layout":{"type":"constrained"}} -->
<div class="wp-block-group alignfull homeward-footer">
	<!-- wp:group {
		"align": "wide",
		"className": "homeward-footer__row",
		"layout": {
			"type": "flex",
			"flexWrap": "wrap",
			"justifyContent": "space-between"
		}
	} -->
	<div class="wp-block-group alignwide homeward-footer__row">
		<!-- wp:group -->
		<div class="wp-block-group">
			<!-- wp:site-title {"level":0} /-->
			<!-- wp:site-tagline /-->
		</div>
		<!-- /wp:group -->
		<!-- wp:navigation {
			"className": "homeward-footer-navigation",
			"overlayMenu": "never",
			"ariaLabel": "Footer",
			"layout": {
				"type": "flex",
				"flexWrap": "wrap"
			}
		} -->
		<!-- wp:page-list /-->
		<!-- /wp:navigation -->
		<?php if ( get_privacy_policy_url() ) : ?>
		<!-- wp:paragraph -->
		<p><a href="<?php echo esc_url( get_privacy_policy_url() ); ?>"><?php esc_html_e( 'Privacy', 'homeward' ); ?></a></p>
		<!-- /wp:paragraph -->
		<?php endif; ?>
	</div>
	<!-- /wp:group -->
</div>
<!-- /wp:group -->
