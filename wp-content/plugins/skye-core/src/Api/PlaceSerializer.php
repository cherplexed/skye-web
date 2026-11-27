<?php
declare(strict_types=1);

namespace Skye\Api;

use Skye\Meta;
use Skye\PostTypes;
use Skye\Taxonomies;
use Skye\Units;
use Skye\Uuid;
use Skye\Verification;
use WP_Post;

/**
 * Place → API object. Metric + GBP always (CONTRACTS.md §0); `display` carries market-formatted strings
 * so simple consumers don't need to convert.
 */
final class PlaceSerializer {

	public static function summary( WP_Post $p, string $lang, string $market ): array {
		$id  = $p->ID;
		$loc = Meta::latlng( $id, 'location' );
		return [
			'id'               => $id,
			'uuid'             => Uuid::get( $id ),
			'slug'             => $p->post_name,
			'title'            => \Skye\Text::title( $p ),
			'summary'          => (string) Meta::get( $id, 'summary', '' ),
			'url'              => get_permalink( $p ),
			'zone'             => self::terms( $id, Taxonomies::ZONE )[0] ?? null,
			'categories'       => self::terms( $id, Taxonomies::CATEGORY ),
			'profiles'         => self::terms( $id, Taxonomies::PROFILE ),
			'location'         => $loc,
			'duration_typical' => Meta::int( $id, 'duration_typical' ),
			'difficulty'       => (string) Meta::get( $id, 'difficulty', 'none' ),
			'price_type'       => (string) Meta::get( $id, 'price_type', 'free' ),
			'price_gbp'        => Meta::float( $id, 'price_gbp' ),
			'audioguide'       => Meta::bool( $id, 'audioguide_enabled' ),
			'image'            => self::image( $id ),
			'verification'     => Verification::get( $id ),
		];
	}

	public static function full( WP_Post $p, string $lang, string $market ): array {
		$id   = $p->ID;
		$base = self::summary( $p, $lang, $market );
		$km   = Meta::float( $id, 'distance_km' );
		$elev = Meta::float( $id, 'elevation_gain_m' );

		return $base + [
			'gaelic_name'       => (string) Meta::get( $id, 'gaelic_name', '' ) ?: null,
			'description_html'  => apply_filters( 'the_content', $p->post_content ),
			'parking_location'  => Meta::latlng( $id, 'parking_location' ),
			'directions'        => (string) Meta::get( $id, 'directions', '' ),
			'duration_min'      => Meta::int( $id, 'duration_min' ),
			'distance_km'       => $km,
			'elevation_gain_m'  => $elev,
			'opening_hours'     => self::rows( Meta::get( $id, 'opening_hours', [] ), [ 'season', 'hours' ] ),
			'best_time'         => (string) Meta::get( $id, 'best_time', '' ),
			'suitable_for'      => self::list( Meta::get( $id, 'suitable_for', [] ) ),
			'motorhome_parking' => (string) Meta::get( $id, 'motorhome_parking', 'no' ),
			'overnight_allowed' => Meta::bool( $id, 'overnight_allowed' ),
			'services_nearby'   => self::list( Meta::get( $id, 'services_nearby', [] ) ),
			'gallery'           => array_values( array_filter( array_map( [ self::class, 'image_by_id' ], self::list( Meta::get( $id, 'gallery', [] ) ) ) ) ),
			'tips'              => array_values( array_filter( array_map( static fn( $r ) => is_array( $r ) ? (string) ( $r['tip'] ?? '' ) : (string) $r, (array) Meta::get( $id, 'tips', [] ) ) ) ),
			'audioguide_stop'   => Meta::bool( $id, 'audioguide_enabled' ) ? [
				'number'   => Meta::int( $id, 'audioguide_stop_number' ),
				'radius_m' => Meta::int( $id, 'audioguide_radius_m', 150 ),
				'tracks'   => self::rows( Meta::get( $id, 'audioguide_tracks', [] ), [ 'lang', 'audio_url', 'transcript' ] ),
			] : null,
			'nearby_places'     => self::refs( Meta::ids( $id, 'nearby_places' ) ),
			'nearby_lodging'    => self::refs( Meta::ids( $id, 'nearby_lodging' ) ),
			'experiences_here'  => self::refs( Meta::ids( $id, 'experiences_here' ) ),
			'in_tours'          => self::refs( Meta::ids( $id, 'in_tours' ) ),
			'display'           => [
				'market'           => $market,
				'duration_typical' => $base['duration_typical'] ? Units::format_duration( (int) $base['duration_typical'] ) : null,
				'distance'         => null !== $km ? Units::format_distance( $km, $market ) : null,
				'elevation_gain'   => null !== $elev ? Units::format_altitude( $elev, $market ) : null,
			],
		];
	}

	// ---- helpers -----------------------------------------------------------------------------

	/** @return list<array{slug:string,name:string}> */
	private static function terms( int $id, string $tax ): array {
		$t = get_the_terms( $id, $tax );
		if ( ! is_array( $t ) ) {
			return [];
		}
		return array_values( array_map( static fn( $x ) => [ 'slug' => $x->slug, 'name' => \Skye\Text::plain( $x->name ) ], $t ) );
	}

	/** @return list<array{id:int,uuid:string,slug:string,title:string,type:string}> */
	private static function refs( array $ids ): array {
		$out = [];
		foreach ( $ids as $rid ) {
			$rp = get_post( $rid );
			if ( $rp && 'publish' === $rp->post_status ) {
				$out[] = [ 'id' => $rp->ID, 'uuid' => Uuid::get( $rp->ID ), 'slug' => $rp->post_name, 'title' => \Skye\Text::title( $rp ), 'type' => $rp->post_type ];
			}
		}
		return $out;
	}

	/** Normalise repeater rows (ACF arrays or JSON fallback) to a list of assoc rows with the given keys. */
	private static function rows( mixed $rows, array $keys ): array {
		if ( ! is_array( $rows ) ) {
			return [];
		}
		$out = [];
		foreach ( $rows as $r ) {
			if ( ! is_array( $r ) ) {
				continue;
			}
			$row = [];
			foreach ( $keys as $k ) {
				$row[ $k ] = isset( $r[ $k ] ) ? ( is_scalar( $r[ $k ] ) ? $r[ $k ] : '' ) : '';
			}
			$out[] = $row;
		}
		return $out;
	}

	/** @return list<string> */
	private static function list( mixed $v ): array {
		if ( is_string( $v ) ) {
			$v = array_filter( array_map( 'trim', explode( ',', $v ) ) );
		}
		return is_array( $v ) ? array_values( array_map( 'strval', $v ) ) : [];
	}

	private static function image( int $id ): ?array {
		$thumb = (int) get_post_thumbnail_id( $id );
		return $thumb ? self::image_by_id( $thumb ) : null;
	}

	public static function image_by_id( mixed $att ): ?array {
		$att = is_array( $att ) ? (int) ( $att['ID'] ?? $att['id'] ?? 0 ) : (int) $att;
		if ( ! $att ) {
			return null;
		}
		$src = wp_get_attachment_image_src( $att, 'large' );
		if ( ! $src ) {
			return null;
		}
		return [ 'id' => $att, 'url' => $src[0], 'width' => $src[1], 'height' => $src[2], 'alt' => (string) get_post_meta( $att, '_wp_attachment_image_alt', true ) ];
	}
}
