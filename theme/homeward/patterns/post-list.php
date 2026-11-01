<?php
/**
 * Title: Posts with pagination
 * Slug: homeward/post-list
 * Categories: homeward, posts
 * Inserter: no
 */
defined( 'ABSPATH' ) || exit;
?>
<!-- wp:query {
	"query": {
		"perPage": 10,
		"pages": 0,
		"offset": 0,
		"postType": "post",
		"order": "desc",
		"orderBy": "date",
		"author": "",
		"search": "",
		"exclude": [],
		"sticky": "",
		"inherit": true
	},
	"className": "homeward-posts"
} -->
<div class="wp-block-query homeward-posts">
	<!-- wp:post-template -->
	<!-- wp:post-title {"isLink":true,"level":2} /-->
	<!-- wp:post-date /-->
	<!-- wp:post-excerpt /-->
	<!-- /wp:post-template -->
	<!-- wp:query-pagination {"layout":{"type":"flex","justifyContent":"space-between"}} -->
	<!-- wp:query-pagination-previous /-->
	<!-- wp:query-pagination-numbers /-->
	<!-- wp:query-pagination-next /-->
	<!-- /wp:query-pagination -->
	<!-- wp:query-no-results -->
	<!-- wp:paragraph -->
	<p><?php esc_html_e( 'No posts found. Try another search or come back soon.', 'homeward' ); ?></p>
	<!-- /wp:paragraph -->
	<!-- /wp:query-no-results -->
</div>
<!-- /wp:query -->
