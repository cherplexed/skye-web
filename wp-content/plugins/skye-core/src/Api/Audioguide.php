<?php
declare(strict_types=1);

namespace Skye\Api;

use Skye\Meta;
use Skye\PostTypes;
use Skye\Uuid;
use WP_Query;
use WP_REST_Request;
use WP_REST_Response;

/**
 * GET /audioguide/stops?lang=xx — what the audioguide app downloads: every Place with
 * audioguide_enabled, ordered by stop number, with the track for the requested language
 * (falling back to English) and the GPS trigger radius.
 */
final class Audioguide {

	public static function stops( WP_REST_Request $r ): WP_REST_Response {
		[ 'lang' => $lang, 'market' => $market ] = Router::context( $r );

		$q = new WP_Query(
			[
				'post_type'      => PostTypes::PLACE,
				'post_status'    => 'publish',
				'posts_per_page' => 500,
				'meta_query'     => [ [ 'key' => 'audioguide_enabled', 'value' => '1' ] ],
				'no_found_rows'  => true,
			]
		);

		$stops = [];
		foreach ( $q->posts as $p ) {
			$id     = $p->ID;
			$tracks = (array) Meta::get( $id, 'audioguide_tracks', [] );
			$track  = self::pick_track( $tracks, $lang );
			$stops[] = [
				'number'    => Meta::int( $id, 'audioguide_stop_number', 0 ),
				'id'        => $id,
				'uuid'      => Uuid::get( $id ),
				'slug'      => $p->post_name,
				'title'     => get_the_title( $p ),
				'summary'   => (string) Meta::get( $id, 'summary', '' ),
				'location'  => Meta::latlng( $id, 'location' ),
				'radius_m'  => Meta::int( $id, 'audioguide_radius_m', 150 ),
				'track'     => $track,
				'languages' => array_values( array_unique( array_filter( array_map( static fn( $t ) => is_array( $t ) ? (string) ( $t['lang'] ?? '' ) : '', $tracks ) ) ) ),
				'image'     => PlaceSerializer::image_by_id( get_post_thumbnail_id( $id ) ),
			];
		}
		usort( $stops, static fn( $a, $b ) => $a['number'] <=> $b['number'] );

		return Router::ok( $stops, [ 'lang' => $lang, 'market' => $market, 'total' => count( $stops ) ] );
	}

	/** @return array{lang:string,audio_url:string,transcript:string}|null */
	private static function pick_track( array $tracks, string $lang ): ?array {
		$by = [];
		foreach ( $tracks as $t ) {
			if ( is_array( $t ) && ! empty( $t['lang'] ) ) {
				$by[ strtolower( (string) $t['lang'] ) ] = [ 'lang' => strtolower( (string) $t['lang'] ), 'audio_url' => (string) ( $t['audio_url'] ?? '' ), 'transcript' => (string) ( $t['transcript'] ?? '' ) ];
			}
		}
		return $by[ $lang ] ?? $by[ substr( $lang, 0, 2 ) ] ?? $by['en'] ?? ( $by ? reset( $by ) : null );
	}
}
