<?php
/**
 * Skye Child — Bricks child theme. Intentionally tiny: logic lives in skye-core.
 */
declare(strict_types=1);

add_action(
	'wp_enqueue_scripts',
	static function (): void {
		wp_enqueue_style( 'skye-child', get_stylesheet_uri(), [], wp_get_theme()->get( 'Version' ) );
	}
);
