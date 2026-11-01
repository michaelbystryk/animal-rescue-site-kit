<?php
/**
 * Title: Welcome with a centred photo slot
 * Slug: homeward/hero
 * Categories: homeward, banner
 * Description: Sample introduction with a replaceable cover image and motion control.
 * Viewport Width: 1200
 */
defined( 'ABSPATH' ) || exit;
$photo = isset( $homeward_pattern_uri ) ? $homeward_pattern_uri
	. '/assets/images/hero-placeholder.svg' : get_theme_file_uri( 'assets/images/hero-placeholder.svg' );
?>
<!-- wp:group {"align":"wide","className":"homeward-hero"} -->
<div class="wp-block-group alignwide homeward-hero">
	<!-- wp:group {"className":"homeward-hero__layout"} -->
	<div class="wp-block-group homeward-hero__layout">
		<!-- wp:group -->
		<div class="wp-block-group">
			<!-- wp:paragraph {"className":"homeward-sample-label"} -->
			<p class="homeward-sample-label"><?php echo esc_html( get_option( 'blogname' ) ); ?></p>
			<!-- /wp:paragraph -->
			<!-- wp:heading {"level":1} -->
			<h1 class="wp-block-heading"><?php esc_html_e( 'Meet the animals', 'homeward' ); ?></h1>
			<!-- /wp:heading -->
			<!-- wp:paragraph {"fontSize":"large"} -->
			<p class="has-large-font-size">
				<?php esc_html_e( 'Find a companion, offer a foster home or help care for animals waiting for their next chapter.', 'homeward' ); ?>
			</p>
			<!-- /wp:paragraph -->
			<!-- wp:buttons -->
			<div class="wp-block-buttons">
				<!-- wp:button -->
				<div class="wp-block-button">
					<a
						class="wp-block-button__link wp-element-button"
						href="<?php echo esc_url(
							get_post_type_archive_link( 'homeward_animal' ) ?: home_url( '/#animals' )
						); ?>"
					><?php esc_html_e( 'See animal profiles', 'homeward' ); ?></a>
				</div>
				<!-- /wp:button -->
			</div>
			<!-- /wp:buttons -->
		</div>
		<!-- /wp:group -->
		<!-- wp:group {"className":"homeward-hero__media"} -->
		<div class="wp-block-group homeward-hero__media">
			<!-- wp:cover <?php
			echo wp_json_encode(
				array(
					'url'        => $photo,
					'dimRatio'   => 0,
					'focalPoint' => array( 'x' => 0.5, 'y' => 0.5 ),
					'minHeight'  => 400,
					'isDark'     => false,
					'className'  => 'homeward-hero-cover',
				)
			);
			?> -->
			<div class="wp-block-cover is-light homeward-hero-cover" style="min-height:400px">
				<span
					aria-hidden="true"
					class="wp-block-cover__background has-background-dim-0 has-background-dim"
				></span>
				<img
					class="wp-block-cover__image-background"
					alt=""
					src="<?php echo esc_url( $photo ); ?>"
					style="object-position:50% 50%"
					data-object-fit="cover"
					data-object-position="50% 50%"
				/>
				<div class="wp-block-cover__inner-container">
				</div>
			</div>
			<!-- /wp:cover -->
			<!-- wp:html -->
			<button
				class="homeward-control"
				type="button"
				data-homeward-motion
				data-pause-label="<?php esc_attr_e( 'Pause motion', 'homeward' ); ?>"
				data-resume-label="<?php esc_attr_e( 'Resume motion', 'homeward' ); ?>"
				data-reduced-label="<?php esc_attr_e( 'Motion off: reduced motion preference', 'homeward' ); ?>"
				aria-pressed="false"
				hidden
			><?php esc_html_e( 'Pause motion', 'homeward' ); ?></button>
			<!-- /wp:html -->
		</div>
		<!-- /wp:group -->
	</div>
	<!-- /wp:group -->
</div>
<!-- /wp:group -->
