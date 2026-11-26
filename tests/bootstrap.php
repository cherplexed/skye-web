<?php
/**
 * Unit tests run without WordPress. Only pure classes (Units, Geo::distance_km, Fields, Taxonomies::terms)
 * are tested here; anything touching WP goes through docker/scripts/smoke.sh.
 */
declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

if ( ! defined( 'SKYE_CORE_DIR' ) ) {
	define( 'SKYE_CORE_DIR', __DIR__ . '/../wp-content/plugins/skye-core' );
}
