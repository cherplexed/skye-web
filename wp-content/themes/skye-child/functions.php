<?php
/**
 * Skye Child — Bricks child theme. Intentionally tiny: logic lives in skye-core.
 */
declare(strict_types=1);

add_action(
	'wp_enqueue_scripts',
	static function (): void {
		// Sora (headings) and Inter (text), as in the design. Loaded from Google Fonts for now: before the
		// site goes public they must be self-hosted (EU privacy). Return '' from the filter to switch off.
		$fonts = (string) apply_filters( 'skye_fonts_url', 'https://fonts.googleapis.com/css2?family=Sora:wght@500;600;700&family=Inter:wght@400;500;600&display=swap' );
		if ( '' !== $fonts ) {
			wp_enqueue_style( 'skye-fonts', $fonts, [], null ); // phpcs:ignore WordPress.WP.EnqueuedResourceParameters.MissingVersion
		}
		wp_enqueue_style( 'skye-child', get_stylesheet_uri(), [], wp_get_theme()->get( 'Version' ) );
	}
);
