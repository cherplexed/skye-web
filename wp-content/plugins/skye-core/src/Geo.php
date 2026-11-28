<?php
declare(strict_types=1);

namespace Skye;

use WP_Query;

final class Geo {

	private const EARTH_KM = 6371.0;

	/** @return array{lat:float,lng:float}|null */
	public static function parse_latlng( string $s ): ?array {
		if ( ! preg_match( '/^\s*(-?\d+(?:\.\d+)?)\s*,\s*(-?\d+(?:\.\d+)?)\s*$/', $s, $m ) ) {
			return null;
		}
		return [ 'lat' => (float) $m[1], 'lng' => (float) $m[2] ];
	}

	/** Haversine. @param array{lat:float,lng:float} $a @param array{lat:float,lng:float} $b */
	public static function distance_km( array $a, array $b ): float {
		$dlat = deg2rad( $b['lat'] - $a['lat'] );
		$dlng = deg2rad( $b['lng'] - $a['lng'] );
		$h    = sin( $dlat / 2 ) ** 2 + cos( deg2rad( $a['lat'] ) ) * cos( deg2rad( $b['lat'] ) ) * sin( $dlng / 2 ) ** 2;
		return 2 * self::EARTH_KM * asin( min( 1.0, sqrt( $h ) ) );
	}

	/** Rough driving time on Skye roads (single-track, 25–35 mph): straight-line km × 1.4 at 45 km/h. */
	public static function drive_minutes_estimate( float $straight_km ): int {
		return (int) round( $straight_km * 1.4 / 45 * 60 );
	}

	/**
	 * Nearest published items of a type to a point.
	 * @param array{lat:float,lng:float} $from
	 * @param list<int> $exclude
	 * @return list<array{id:int,uuid:string,slug:string,title:string,type:string,distance_km:float,drive_minutes:int}>
	 */
	public static function nearest( string $post_type, array $from, int $limit = 3, array $exclude = [] ): array {
		$q = new WP_Query(
			[
				'post_type'      => $post_type,
				'post_status'    => 'publish',
				'posts_per_page' => 500,
				'post__not_in'   => $exclude,
				'no_found_rows'  => true,
				'fields'         => 'ids',
			]
		);
		$rows = [];
		foreach ( $q->posts as $id ) {
			$loc = Meta::latlng( (int) $id, 'location' );
			if ( ! $loc ) {
				continue;
			}
			$d      = self::distance_km( $from, $loc );
			$rows[] = [
				'id'            => (int) $id,
				'uuid'          => Uuid::get( (int) $id ),
				'slug'          => Lang::slug( get_post( (int) $id ) ),
				'title'         => Text::title( $id ),
				'type'          => $post_type,
				'distance_km'   => round( $d, 1 ),
				'drive_minutes' => self::drive_minutes_estimate( $d ),
			];
		}
		usort( $rows, static fn( $a, $b ) => $a['distance_km'] <=> $b['distance_km'] );
		return array_slice( $rows, 0, $limit );
	}
}
