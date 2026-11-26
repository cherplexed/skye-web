<?php
declare(strict_types=1);

namespace Skye\Cli;

use Skye\Bokun\BokunException;
use Skye\Bokun\Client;
use Skye\Bokun\Offers;
use Skye\Bokun\Pricing;
use Skye\Fields;
use Skye\Meta;
use Skye\PostTypes;
use Skye\Taxonomies;
use Skye\Uuid;
use Skye\Verification;
use WP_CLI;

/**
 * wp skye <command>
 */
final class Commands {

	/**
	 * Creates the canonical taxonomy terms.
	 *
	 * ## EXAMPLES
	 *     wp skye terms
	 */
	public function terms(): void {
		$n = Taxonomies::seed_terms();
		WP_CLI::success( "$n terms created (existing ones kept)." );
	}

	/**
	 * Loads seed data. Idempotent: matches by slug and updates.
	 *
	 * ## OPTIONS
	 * <type>
	 * : places, brands, tours, routes or all (in that order: later types reference earlier ones by slug)
	 *
	 * [--file=<path>]
	 * : JSON file. Default: /seed/<type>.json (docker) or ../../../../seed/<type>.json
	 *
	 * [--verify-as=<user>]
	 * : Mark items as verified by this user login (only for real data checked by a guide).
	 *
	 * ## EXAMPLES
	 *     wp skye seed all
	 *     wp skye seed places --file=/seed/places.json
	 */
	public function seed( array $args, array $assoc ): void {
		[ $type ] = $args;
		// Order matters for `all`: tours point to places and brands, routes to places and tours.
		$generic = [ 'brands' => PostTypes::BRAND, 'tours' => PostTypes::TOUR, 'routes' => PostTypes::ROUTE ];
		if ( 'all' === $type ) {
			foreach ( [ 'places', ...array_keys( $generic ) ] as $t ) {
				$this->seed( [ $t ], array_diff_key( $assoc, [ 'file' => 1 ] ) );
			}
			return;
		}
		if ( isset( $generic[ $type ] ) ) {
			$this->seed_generic( $type, $generic[ $type ], $assoc );
			return;
		}
		if ( 'places' !== $type ) {
			WP_CLI::error( "Unknown seed type '$type'. Available: places, brands, tours, routes, all" );
		}
		Taxonomies::seed_terms();

		$file = $assoc['file'] ?? ( is_dir( '/seed' ) ? "/seed/$type.json" : dirname( SKYE_CORE_DIR, 3 ) . "/seed/$type.json" );
		if ( ! is_file( $file ) ) {
			WP_CLI::error( "Seed file not found: $file" );
		}
		$json = json_decode( (string) file_get_contents( $file ), true, 512, JSON_THROW_ON_ERROR );
		$verifier = isset( $assoc['verify-as'] ) ? get_user_by( 'login', (string) $assoc['verify-as'] ) : null;

		$created = 0;
		$updated = 0;
		foreach ( $json['places'] as $row ) {
			$existing = get_page_by_path( $row['slug'], OBJECT, PostTypes::PLACE );
			$postarr  = [
				'post_type'    => PostTypes::PLACE,
				'post_status'  => 'publish',
				'post_name'    => $row['slug'],
				'post_title'   => $row['title'],
				'post_content' => $row['content'] ?? '',
				'post_excerpt' => $row['summary'] ?? '',
			];
			if ( $existing ) {
				$postarr['ID'] = $existing->ID;
				$id            = wp_update_post( $postarr, true );
				++$updated;
			} else {
				$id = wp_insert_post( $postarr, true );
				++$created;
			}
			if ( is_wp_error( $id ) ) {
				WP_CLI::warning( "{$row['slug']}: " . $id->get_error_message() );
				continue;
			}
			$this->apply_place( (int) $id, $row );
			Uuid::ensure( (int) $id );
			if ( $verifier ) {
				Verification::mark_verified( (int) $id, (int) $verifier->ID );
			}
			WP_CLI::log( sprintf( '  %s %s (#%d)', $existing ? '~' : '+', $row['title'], $id ) );
		}
		WP_CLI::success( "Places: $created created, $updated updated." . ( $verifier ? '' : ' All left UNVERIFIED: a guide must check them.' ) );
	}

	/**
	 * Schema-driven seed for types without special handling. Rows use field names from Fields; relations
	 * are written as slugs (a string, or a list) and resolved to IDs here.
	 */
	private function seed_generic( string $name, string $post_type, array $assoc ): void {
		Taxonomies::seed_terms();
		$file = $assoc['file'] ?? ( is_dir( '/seed' ) ? "/seed/$name.json" : dirname( SKYE_CORE_DIR, 3 ) . "/seed/$name.json" );
		if ( ! is_file( $file ) ) {
			WP_CLI::error( "Seed file not found: $file" );
		}
		$json     = json_decode( (string) file_get_contents( $file ), true, 512, JSON_THROW_ON_ERROR );
		$verifier = isset( $assoc['verify-as'] ) ? get_user_by( 'login', (string) $assoc['verify-as'] ) : null;
		$schema   = Fields::for( $post_type );
		$created  = 0;
		$updated  = 0;

		foreach ( $json[ $name ] as $row ) {
			$existing = get_page_by_path( $row['slug'], OBJECT, $post_type );
			$postarr  = [
				'post_type'    => $post_type,
				'post_status'  => 'publish',
				'post_name'    => $row['slug'],
				'post_title'   => $row['title'],
				'post_content' => $row['content'] ?? '',
				'post_excerpt' => $row['summary'] ?? '',
			];
			if ( $existing ) {
				$postarr['ID'] = $existing->ID;
			}
			$id = $existing ? wp_update_post( $postarr, true ) : wp_insert_post( $postarr, true );
			if ( is_wp_error( $id ) ) {
				WP_CLI::warning( "{$row['slug']}: " . $id->get_error_message() );
				continue;
			}
			$existing ? ++$updated : ++$created;
			$id = (int) $id;

			foreach ( $schema as $field => $def ) {
				if ( array_key_exists( $field, $row ) && null !== $row[ $field ] ) {
					Meta::set( $id, $field, $this->seed_value( $def, $row[ $field ], "{$row['slug']}.$field" ) );
				}
			}
			if ( isset( $row['profiles'] ) ) {
				wp_set_object_terms( $id, $row['profiles'], Taxonomies::PROFILE );
			}
			Uuid::ensure( $id );
			if ( $verifier ) {
				Verification::mark_verified( $id, (int) $verifier->ID );
			}
			WP_CLI::log( sprintf( '  %s %s (#%d)', $existing ? '~' : '+', $row['title'], $id ) );
		}
		WP_CLI::success( ucfirst( $name ) . ": $created created, $updated updated." . ( $verifier ? '' : ' All left UNVERIFIED.' ) );
	}

	/** Seed value → stored value: slugs become IDs, booleans become 0/1, repeaters recurse. */
	private function seed_value( array $def, mixed $value, string $path ): mixed {
		switch ( $def['type'] ) {
			case 'relation':
				$ids = [];
				foreach ( (array) $value as $slug ) {
					$p = get_page_by_path( (string) $slug, OBJECT, $def['to'] );
					if ( $p ) {
						$ids[] = $p->ID;
					} else {
						WP_CLI::warning( "$path: no {$def['to']} with slug '$slug' (seed that type first)" );
					}
				}
				return $ids;
			case 'repeater':
				$rows = [];
				foreach ( (array) $value as $i => $row ) {
					$out = [];
					foreach ( $def['sub'] as $sub => $sub_def ) {
						if ( array_key_exists( $sub, $row ) && null !== $row[ $sub ] ) {
							$out[ $sub ] = $this->seed_value( $sub_def, $row[ $sub ], "$path[$i].$sub" );
						}
					}
					$rows[] = $out;
				}
				return $rows;
			case 'bool':
				return $value ? 1 : 0;
			case 'latlng':
				return [ 'lat' => (float) $value['lat'], 'lng' => (float) $value['lng'] ];
			default:
				return $value;
		}
	}

	private function apply_place( int $id, array $r ): void {
		wp_set_object_terms( $id, [ $r['zone'] ], Taxonomies::ZONE );
		wp_set_object_terms( $id, $r['categories'] ?? [], Taxonomies::CATEGORY );
		wp_set_object_terms( $id, $r['profiles'] ?? [], Taxonomies::PROFILE );
		wp_set_object_terms( $id, array_map( static fn( $s ) => str_replace( '_', '-', $s ), $r['suitable_for'] ?? [] ), Taxonomies::SUITABILITY );

		$scalar = [ 'gaelic_name', 'summary', 'directions', 'duration_min', 'duration_typical', 'difficulty', 'distance_km', 'elevation_gain_m', 'price_type', 'price_gbp', 'best_time', 'motorhome_parking', 'overnight_allowed' ];
		foreach ( $scalar as $k ) {
			if ( array_key_exists( $k, $r ) && null !== $r[ $k ] ) {
				Meta::set( $id, $k, is_bool( $r[ $k ] ) ? ( $r[ $k ] ? 1 : 0 ) : $r[ $k ] );
			}
		}
		foreach ( [ 'location', 'parking_location' ] as $k ) {
			if ( ! empty( $r[ $k ] ) ) {
				Meta::set( $id, $k, [ 'lat' => (float) $r[ $k ]['lat'], 'lng' => (float) $r[ $k ]['lng'] ] );
			}
		}
		Meta::set( $id, 'suitable_for', $r['suitable_for'] ?? [] );
		Meta::set( $id, 'services_nearby', $r['services_nearby'] ?? [] );
		Meta::set( $id, 'opening_hours', $r['opening_hours'] ?? [] );
		Meta::set( $id, 'tips', array_map( static fn( $t ) => [ 'tip' => $t ], $r['tips'] ?? [] ) );

		$ag = $r['audioguide'] ?? [];
		Meta::set( $id, 'audioguide_enabled', ! empty( $ag['enabled'] ) ? 1 : 0 );
		if ( ! empty( $ag['enabled'] ) ) {
			Meta::set( $id, 'audioguide_stop_number', (int) ( $ag['stop_number'] ?? 0 ) );
			Meta::set( $id, 'audioguide_radius_m', (int) ( $ag['radius_m'] ?? 150 ) );
			Meta::set( $id, 'audioguide_tracks', $ag['tracks'] ?? [] );
		}
	}

	/**
	 * Prints verification status of all content.
	 *
	 * ## EXAMPLES
	 *     wp skye audit
	 */
	public function audit(): void {
		$rows = [];
		foreach ( PostTypes::all() as $type ) {
			foreach ( get_posts( [ 'post_type' => $type, 'post_status' => 'publish', 'posts_per_page' => -1 ] ) as $p ) {
				$v      = Verification::get( $p->ID );
				$rows[] = [ 'type' => $type, 'title' => $p->post_title, 'status' => $v['status'], 'verified_by' => $v['verified_by']['name'] ?? '', 'valid_until' => $v['valid_until'] ?? '' ];
			}
		}
		WP_CLI\Utils\format_items( 'table', $rows, [ 'type', 'title', 'status', 'verified_by', 'valid_until' ] );
	}

	/**
	 * Asks Bókun for the "from" price and next bookable date of a product. Checks credentials end to end.
	 *
	 * ## OPTIONS
	 * <product_id>
	 * : Bókun product (activity) ID.
	 *
	 * [--currency=<code>]
	 * : Default GBP.
	 *
	 * [--category=<id>]
	 * : Adult pricing category ID. Without it, the highest per-person price of the default rate is used.
	 *
	 * ## EXAMPLES
	 *     wp skye bokun 123456 --currency=EUR
	 */
	public function bokun( array $args, array $assoc ): void {
		$client = Client::from_config();
		if ( ! $client ) {
			WP_CLI::error( 'Bókun is not configured: set BOKUN_ACCESS_KEY and BOKUN_SECRET_KEY in .env and restart the stack.' );
		}
		$currency = strtoupper( (string) ( $assoc['currency'] ?? 'GBP' ) );
		$category = isset( $assoc['category'] ) ? (int) $assoc['category'] : null;
		try {
			$start = gmdate( 'Y-m-d' );
			$end   = gmdate( 'Y-m-d', time() + ( Offers::WINDOW_DAYS - 1 ) * DAY_IN_SECONDS );
			$list  = $client->availabilities( (string) $args[0], $start, $end, $currency );
		} catch ( BokunException $e ) {
			WP_CLI::error( $e->getMessage() );
		}
		WP_CLI::log( sprintf( '%d departures returned for %s → %s', count( $list ), $start, $end ) );
		$sum = Pricing::summarize( $list, $category );
		if ( ! $sum ) {
			WP_CLI::warning( 'Nothing bookable in this window.' );
			return;
		}
		WP_CLI::success( sprintf( 'From %s %s · next departure %s · %d bookable departures', $sum['price_from'], $sum['currency'], $sum['next_available'], $sum['departures'] ) );
	}
}
