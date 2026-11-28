<?php
declare(strict_types=1);

namespace Skye;

/**
 * Secrets and settings that come from the environment, never from the database or the repository.
 * Each value is read from the constant docker-compose defines for the web container (SKYE_<NAME>),
 * or from the environment variable <NAME> (the WP-CLI container only has the latter).
 */
final class Config {

	public static function get( string $name ): string {
		if ( defined( 'SKYE_' . $name ) && '' !== (string) constant( 'SKYE_' . $name ) ) {
			return trim( (string) constant( 'SKYE_' . $name ) );
		}
		$env = getenv( $name );
		return false === $env ? '' : trim( $env );
	}
}
