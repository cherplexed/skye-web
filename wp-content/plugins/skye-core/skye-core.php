<?php
/**
 * Plugin Name: Skye Core
 * Description: Content model, verification, units and REST API for the Isle of Skye guide. All custom code of skye-web lives here.
 * Version: 0.8.0
 * Requires PHP: 8.2
 * Author: ShyPig
 * Text Domain: skye-core
 */

declare(strict_types=1);

defined( 'ABSPATH' ) || exit;

define( 'SKYE_CORE_VERSION', '0.8.0' );
define( 'SKYE_CORE_DIR', __DIR__ );
define( 'SKYE_CORE_URL', plugin_dir_url( __FILE__ ) );

// PSR-4 autoload for the Skye\ namespace (no Composer needed at runtime).
spl_autoload_register(
	static function ( string $class ): void {
		if ( ! str_starts_with( $class, 'Skye\\' ) ) {
			return;
		}
		$relative = str_replace( '\\', '/', substr( $class, 5 ) );
		$file     = SKYE_CORE_DIR . '/src/' . $relative . '.php';
		if ( is_file( $file ) ) {
			require $file;
		}
	}
);

add_action( 'plugins_loaded', static fn() => Skye\Plugin::boot() );

register_activation_hook(
	__FILE__,
	static function (): void {
		Skye\Plugin::boot();
		Skye\PostTypes::register();
		Skye\Taxonomies::register();
		Skye\Taxonomies::seed_terms();
		flush_rewrite_rules();
	}
);

register_deactivation_hook( __FILE__, static fn() => flush_rewrite_rules() );
