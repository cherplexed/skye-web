<?php
declare(strict_types=1);

namespace Skye;

/**
 * Wires every module. Keep this file boring: one line per module.
 */
final class Plugin {

	private static bool $booted = false;

	public static function boot(): void {
		if ( self::$booted ) {
			return;
		}
		self::$booted = true;

		load_plugin_textdomain( 'skye-core', false, dirname( plugin_basename( SKYE_CORE_DIR . '/skye-core.php' ) ) . '/languages' );

		add_action( 'init', [ PostTypes::class, 'register' ] );
		add_action( 'init', [ Taxonomies::class, 'register' ] );
		add_action( 'init', [ Uuid::class, 'register' ] );

		Fields::register();        // ACF JSON load point + fallback metaboxes
		Verification::register();  // verified_by / valid_until / change log
		Api\Router::register();    // /wp-json/skye/v1
		Frontend::register();      // [skye_place] blocks for the Bricks templates
		JsonLd::register();        // schema.org in <head>

		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			\WP_CLI::add_command( 'skye', Cli\Commands::class );
		}
	}
}
