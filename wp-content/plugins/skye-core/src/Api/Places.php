<?php
declare(strict_types=1);

namespace Skye\Api;

use Skye\Geo;
use Skye\Meta;
use Skye\PostTypes;
use Skye\Taxonomies;
use Skye\Uuid;
use WP_Query;
use WP_REST_Request;
use WP_REST_Response;

final class Places {

	/** @return array<string, array<string, mixed>> */
	public static function args(): array {
		return [
			'zone'         => [ 'type' => 'string', 'default' => '' ],
			'category'     => [ 'type' => 'string', 'default' => '' ],
			'profile'      => [ 'type' => 'string', 'default' => '' ],
			'suitable_for' => [ 'type' => 'string', 'default' => '' ],
			'audioguide'   => [ 'type' => 'boolean', 'default' => false ],
			'near'         => [ 'type' => 'string', 'default' => '', 'description' => 'lat,lng' ],
			'radius_km'    => [ 'type' => 'number', 'default' => 25 ],
			'search'       => [ 'type' => 'string', 'default' => '' ],
		];
	}

	public static function index( WP_REST_Request $r ): WP_REST_Response {
		[ 'lang' => $lang, 'market' => $market ] = Router::context( $r );

		$tax = [];
		foreach ( [ 'zone' => Taxonomies::ZONE, 'category' => Taxonomies::CATEGORY, 'profile' => Taxonomies::PROFILE, 'suitable_for' => Taxonomies::SUITABILITY ] as $param => $taxonomy ) {
			$v = (string) $r->get_param( $param );
			if ( '' !== $v ) {
				// Slugs are canonical (default language); the query needs the terms of the language being served.
				$ids   = array_filter( array_map( static fn( string $s ) => \Skye\Lang::term_id( $taxonomy, trim( $s ) ), explode( ',', $v ) ) );
				$tax[] = [ 'taxonomy' => $taxonomy, 'field' => 'term_id', 'terms' => $ids ?: [ 0 ] ];
			}
		}

		$meta = [];
		if ( $r->get_param( 'audioguide' ) ) {
			$meta[] = [ 'key' => 'audioguide_enabled', 'value' => '1' ];
		}

		$near = Geo::parse_latlng( (string) $r->get_param( 'near' ) );

		$q = new WP_Query(
			[
				'post_type'      => PostTypes::PLACE,
				'post_status'    => 'publish',
				'posts_per_page' => $near ? 500 : (int) $r->get_param( 'per_page' ),
				'paged'          => $near ? 1 : (int) $r->get_param( 'page' ),
				'tax_query'      => $tax ? [ 'relation' => 'AND', ...$tax ] : [],
				'meta_query'     => $meta,
				's'              => (string) $r->get_param( 'search' ),
				'orderby'        => $r->get_param( 'search' ) ? 'relevance' : 'title',
				'order'          => 'ASC',
				'no_found_rows'  => (bool) $near,
			]
		);

		$items = [];
		foreach ( $q->posts as $p ) {
			$item = PlaceSerializer::summary( $p, $lang, $market );
			if ( $near ) {
				if ( ! $item['location'] ) {
					continue;
				}
				$d = Geo::distance_km( $near, $item['location'] );
				if ( $d > (float) $r->get_param( 'radius_km' ) ) {
					continue;
				}
				$item['distance_km'] = round( $d, 1 );
			}
			$items[] = Router::pick( $item, (string) $r->get_param( 'fields' ) );
		}

		if ( $near ) {
			usort( $items, static fn( $a, $b ) => $a['distance_km'] <=> $b['distance_km'] );
			$total    = count( $items );
			$per_page = (int) $r->get_param( 'per_page' );
			$page     = (int) $r->get_param( 'page' );
			$items    = array_slice( $items, ( $page - 1 ) * $per_page, $per_page );
		} else {
			$total = (int) $q->found_posts;
			$page  = (int) $r->get_param( 'page' );
		}

		return Router::ok( $items, [ 'lang' => $lang, 'market' => $market, 'total' => $total, 'page' => $page ] );
	}

	public static function show( WP_REST_Request $r ): WP_REST_Response {
		[ 'lang' => $lang, 'market' => $market ] = Router::context( $r );
		$p = self::resolve( (string) $r->get_param( 'id' ) );
		if ( ! $p ) {
			return Router::not_found( 'place' );
		}
		$item = self::item( $p, $lang, $market );

		return Router::ok( Router::pick( $item, (string) $r->get_param( 'fields' ) ), [ 'lang' => $lang, 'market' => $market ] );
	}

	/**
	 * The full Place object with everything computed: nearest places and lodging when the editor left
	 * the relations empty, links on every reference, and the tours that stop here when `in_tours` is empty.
	 * Shared by the API and the front-end blocks so both always say the same.
	 */
	public static function item( \WP_Post $p, string $lang, string $market ): array {
		$item = PlaceSerializer::full( $p, $lang, $market );

		if ( ! $item['nearby_places'] && $item['location'] ) {
			$item['nearby_places'] = Geo::nearest( PostTypes::PLACE, $item['location'], 3, [ $p->ID ] );
		}
		if ( ! $item['nearby_lodging'] && $item['location'] ) {
			$item['nearby_lodging'] = Geo::nearest( PostTypes::LODGING, $item['location'], 3 );
		}
		if ( ! $item['in_tours'] ) {
			$item['in_tours'] = self::tours_stopping_at( $p->ID );
		}
		foreach ( [ 'nearby_places', 'nearby_lodging', 'experiences_here', 'in_tours' ] as $rel ) {
			foreach ( $item[ $rel ] as $i => $ref ) {
				$item[ $rel ][ $i ]['url'] = get_permalink( (int) $ref['id'] );
			}
		}
		return $item;
	}

	/** Tours whose itinerary (days → stops) includes the place. @return list<array{id:int,uuid:string,slug:string,title:string,type:string}> */
	public static function tours_stopping_at( int $place_id ): array {
		$out = [];
		foreach ( get_posts( [ 'post_type' => PostTypes::TOUR, 'post_status' => 'publish', 'suppress_filters' => false, 'posts_per_page' => 50, 'orderby' => 'title', 'order' => 'ASC' ] ) as $tour ) {
			foreach ( (array) Meta::get( $tour->ID, 'days', [] ) as $day ) {
				if ( is_array( $day ) && in_array( $place_id, self::stop_place_ids( $day['stops'] ?? [] ), true ) ) {
					$out[] = [ 'id' => $tour->ID, 'uuid' => Uuid::get( $tour->ID ), 'slug' => \Skye\Lang::slug( $tour ), 'title' => \Skye\Text::title( $tour ), 'type' => $tour->post_type ];
					break;
				}
			}
		}
		return $out;
	}

	/**
	 * Place IDs of a day's stops. A stop is a row with an optional `place`; data saved before stops had
	 * durations is a plain list of places.
	 *
	 * @return list<int>
	 */
	public static function stop_place_ids( mixed $stops ): array {
		$ids = [];
		foreach ( is_array( $stops ) ? $stops : [] as $stop ) {
			$ids = array_merge( $ids, Serializer::ids( is_array( $stop ) && array_key_exists( 'place', $stop ) ? $stop['place'] : [ $stop ] ) );
		}
		return $ids;
	}

	/** Accepts numeric ID, UUID or slug. */
	public static function resolve( string $key ): ?\WP_Post {
		if ( ctype_digit( $key ) ) {
			$p = get_post( (int) $key );
		} elseif ( wp_is_uuid( $key ) ) {
			$id = Uuid::find( $key );
			$p  = $id ? get_post( $id ) : null;
		} else {
			$p = \Skye\Lang::resolve_post( $key, PostTypes::PLACE );
		}
		return ( $p && PostTypes::PLACE === $p->post_type && 'publish' === $p->post_status ) ? $p : null;
	}
}
