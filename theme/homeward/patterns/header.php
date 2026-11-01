<?php
/**
 * Title: Site header with submenus
 * Slug: homeward/header
 * Categories: homeward, header
 * Block Types: core/template-part/header
 * Inserter: no
 */
defined( 'ABSPATH' ) || exit;
?>
<!-- wp:group {"align":"full","className":"homeward-header","layout":{"type":"constrained"}} -->
<div class="wp-block-group alignfull homeward-header">
	<!-- wp:group {
		"align": "wide",
		"className": "homeward-header__row",
		"layout": {
			"type": "flex",
			"flexWrap": "wrap",
			"justifyContent": "space-between"
		}
	} -->
	<div class="wp-block-group alignwide homeward-header__row">
		<!-- wp:group {"layout":{"type":"flex","flexWrap":"nowrap"}} -->
		<div class="wp-block-group">
			<!-- wp:pattern {"slug":"homeward/logo-animated"} /-->
			<!-- wp:site-title {"level":0} /-->
		</div>
		<!-- /wp:group -->
		<!-- wp:navigation {"className":"homeward-primary-navigation","overlayMenu":"mobile","openSubmenusOnClick":true,"showSubmenuIcon":true,"ariaLabel":"Primary","layout":{"type":"flex","justifyContent":"right"}} -->
		<!-- wp:page-list /-->
		<!-- /wp:navigation -->
	</div>
	<!-- /wp:group -->
</div>
<!-- /wp:group -->
