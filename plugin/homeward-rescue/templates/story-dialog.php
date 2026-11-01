<?php
/**
 * Shared, progressively enhanced story reader.
 *
 * @package Homeward
 */

defined( 'ABSPATH' ) || exit;
?>
<dialog class="homeward-story-dialog" id="homeward-story-dialog" aria-modal="true" aria-labelledby="homeward-dialog-title">
	<div class="homeward-story-dialog__sheet">
		<button type="button" class="homeward-story-dialog__close" aria-label="<?php esc_attr_e( 'Close story', 'homeward-rescue' ); ?>"><span aria-hidden="true">×</span></button>
		<div class="homeward-story-dialog__photo" hidden></div>
		<div class="homeward-story-dialog__body">
			<p class="homeward-intro" data-story-field="intro" hidden></p>
			<h2 id="homeward-dialog-title" class="homeward-story-dialog__title" tabindex="-1"></h2>
			<p class="homeward-story-dialog__dog" data-story-field="dogLabel" hidden></p>
			<div class="homeward-story-dialog__meta">
				<p data-story-field="origin" hidden></p>
				<p data-story-field="received" hidden></p>
			</div>
			<p class="homeward-card__byline" data-story-field="family" hidden></p>
			<div class="homeward-story-dialog__text"></div>
			<nav class="homeward-story-dialog__nav" aria-label="<?php esc_attr_e( 'Browse stories', 'homeward-rescue' ); ?>">
				<button type="button" class="homeward-reader-button" data-story-prev><?php esc_html_e( '← Previous', 'homeward-rescue' ); ?></button>
				<span class="homeward-story-dialog__position" aria-live="polite" aria-atomic="true" data-position-label="<?php esc_attr_e( '%1$s of %2$s', 'homeward-rescue' ); ?>"></span>
				<button type="button" class="homeward-reader-button" data-story-next><?php esc_html_e( 'Next →', 'homeward-rescue' ); ?></button>
			</nav>
		</div>
	</div>
</dialog>
