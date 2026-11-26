<?php
declare(strict_types=1);

namespace Skye\Api;

use Skye\Bokun\Offers;
use Skye\Brands;
use Skye\Geo;
use Skye\Meta;
use Skye\PostTypes;
use Skye\Taxonomies;
use Skye\Units;
use Skye\Uuid;
use WP_Post;
use WP_Query;
use WP_REST_Request;
use WP_REST_Response;

/**
 * List and detail endpoints for every type except Place (CONTRACTS.md §11), driven by a small
 * per-type config: which query parameters filter by taxonomy, which by a meta field, and whether
 * `near=lat,lng&radius_km=` applies.
 */
final class Collection {

	/**
	 * @return array<string, array{type:string, tax:array<string,string>, meta:array<string,string>, near:bool}> route base => config
	 */
	public static function endpoints(): array {
		return [
			'routes'          => [ 'type' => PostTypes::ROUTE, 'tax' => [ 'profile' => Taxonomies::PROFILE ], 'meta' => [ 'type' => 'route_type', 'vehicle' => 'vehicle', 'days' => 'days_count' ], 'near' => false ],
			'tours'           => [ 'type' => PostTypes::TOUR, 'tax' => [], 'meta' => [ 'days' => 'days_count', 'departure' => 'departure_city' ], 'near' => false ],
			'brands'          => [ 'type' => PostTypes::BRAND, 'tax' => [], 'meta' => [], 'near' => false ],
			'lodging'         => [ 'type' => PostTypes::LODGING, 'tax' => [ 'zone' => Taxonomies::ZONE ], 'meta' => [ 'type' => 'lodging_type' ], 'near' => true ],
			'motorhome-spots' => [ 'type' => PostTypes::MOTORHOME, 'tax' => [ 'zone' => Taxonomies::ZONE ], 'meta' => [ 'type' => 'spot_type', 'overnight' => 'overnight_allowed' ], 'near' => true ],
			'experiences'     => [ 'type' => PostTypes::EXPERIENCE, 'tax' => [ 'zone' => Taxonomies::ZONE, 'suitable_for' => Taxonomies::SUITABILITY ], 'meta' => [ 'type' => 'experience_type' ], 'near' => true ],
		];
	}

	/** @return array<string, array<string, mixed>> REST args for a config. */
	public static function args( array $cfg ): array {
		$args = [ 'search' => [ 'type' => 'string', 'default' => '' ] ];
		foreach ( array_keys( $cfg['tax'] + $cfg['meta'] ) as $param ) {
			$args[ $param ] = [ 'type' => 'string', 'default' => '' ];
		}
		if ( $cfg['near'] ) {
			$args['near']      = [ 'type' => 'string', 'default' => '', 'description' => 'lat,lng' ];
			$args['radius_km'] = [ 'type' => 'number', 'default' => 25 ];
		}
		return $args;
	}

	public static function index( WP_REST_Request $r, array $cfg ): WP_REST_Response {
		[ 'lang' => $lang, 'market' => $market ] = Router::context( $r );

		$tax = [];
		foreach ( $cfg['tax'] as $param => $taxonomy ) {
			$v = (string) $r->get_param( $param );
			if ( '' !== $v ) {
				$tax[] = [ 'taxonomy' => $taxonomy, 'field' => 'slug', 'terms' => array_map( 'trim', explode( ',', $v ) ) ];
			}
		}
		$meta = [];
		foreach ( $cfg['meta'] as $param => $key ) {
			$v = (string) $r->get_param( $param );
			if ( '' !== $v ) {
				$meta[] = [ 'key' => $key, 'value' => array_map( 'trim', explode( ',', $v ) ), 'compare' => 'IN' ];
			}
		}

		$near     = $cfg['near'] ? Geo::parse_latlng( (string) $r->get_param( 'near' ) ) : null;
		$per_page = (int) $r->get_param( 'per_page' );
		$page     = (int) $r->get_param( 'page' );

		$q = new WP_Query(
			[
				'post_type'      => $cfg['type'],
				'post_status'    => 'publish',
				'posts_per_page' => $near ? 500 : $per_page,
				'paged'          => $near ? 1 : $page,
				'tax_query'      => $tax ? [ 'relation' => 'AND', ...$tax ] : [],
				'meta_query'     => $meta ? [ 'relation' => 'AND', ...$meta ] : [],
				's'              => (string) $r->get_param( 'search' ),
				'orderby'        => $r->get_param( 'search' ) ? 'relevance' : 'title',
				'order'          => 'ASC',
				'no_found_rows'  => (bool) $near,
			]
		);

		$items = [];
		foreach ( $q->posts as $p ) {
			$item = self::decorate( Serializer::summary( $p ), $p, $market, false );
			if ( $near ) {
				if ( empty( $item['location'] ) ) {
					continue;
				}
				$d = Geo::distance_km( $near, $item['location'] );
				if ( $d > (float) $r->get_param( 'radius_km' ) ) {
					continue;
				}
				$item['distance_km'] = round( $d, 1 );
			}
			$items[] = $item;
		}

		if ( $near ) {
			usort( $items, static fn( $a, $b ) => $a['distance_km'] <=> $b['distance_km'] );
			$total = count( $items );
			$items = array_slice( $items, ( $page - 1 ) * $per_page, $per_page );
		} else {
			$total = (int) $q->found_posts;
		}

		$fields = (string) $r->get_param( 'fields' );
		$items  = array_map( static fn( array $i ) => Router::pick( $i, $fields ), $items );
		return Router::ok( $items, [ 'lang' => $lang, 'market' => $market, 'total' => $total, 'page' => $page ] );
	}

	public static function show( WP_REST_Request $r, array $cfg ): WP_REST_Response {
		[ 'lang' => $lang, 'market' => $market ] = Router::context( $r );
		$p = self::resolve( (string) $r->get_param( 'id' ), $cfg['type'] );
		if ( ! $p ) {
			return Router::not_found( str_replace( '_', ' ', $cfg['type'] ) );
		}
		$item = self::decorate( Serializer::full( $p ), $p, $market, true );
		return Router::ok( Router::pick( $item, (string) $r->get_param( 'fields' ) ), [ 'lang' => $lang, 'market' => $market ] );
	}

	/** Per-type computed extras: the tour's offer for the market, display strings, nearest places. */
	private static function decorate( array $item, WP_Post $p, string $market, bool $full ): array {
		switch ( $p->post_type ) {
			case PostTypes::TOUR:
				$products = $full ? $item['products'] : Serializer::cast( \Skye\Fields::tour()['products'], Meta::get( $p->ID, 'products' ), [ Serializer::class, 'ref' ], '__return_null' );
				$offers   = Offers::default();
				$item['offer'] = TourOffer::build(
					Brands::for_market( $market ),
					$products,
					$market,
					Units::market( $market )['currency'],
					static fn( string $id, string $currency ) => $offers->price_from( $id, $currency )
				);
				unset( $item['products'] );
				break;

			case PostTypes::ROUTE:
				$km = $item['total_km'] ?? null;
				$min = $item['total_drive_minutes'] ?? null;
				$item['display'] = [
					'market'      => $market,
					'total_drive' => null !== $km ? Units::format_drive_distance( (float) $km, $market ) : null,
					'drive_time'  => null !== $min ? Units::format_duration( (int) $min ) : null,
				];
				break;

			case PostTypes::LODGING:
				if ( $full && ! empty( $item['location'] ) ) {
					$item['nearby_places'] = Geo::nearest( PostTypes::PLACE, $item['location'], 3 );
				}
				break;
		}
		return $item;
	}

	/** Accepts numeric ID, UUID or slug. */
	public static function resolve( string $key, string $post_type ): ?WP_Post {
		if ( ctype_digit( $key ) ) {
			$p = get_post( (int) $key );
		} elseif ( wp_is_uuid( $key ) ) {
			$id = Uuid::find( $key );
			$p  = $id ? get_post( $id ) : null;
		} else {
			$p = get_page_by_path( $key, OBJECT, $post_type );
		}
		return ( $p && $post_type === $p->post_type && 'publish' === $p->post_status ) ? $p : null;
	}
}
