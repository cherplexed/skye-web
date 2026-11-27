<?php
declare(strict_types=1);

namespace Skye\Tests;

use PHPUnit\Framework\TestCase;
use Skye\Api\Serializer;
use Skye\Api\TourOffer;
use Skye\Brands;
use Skye\Fields;
use Skye\PostTypes;

final class ApiTest extends TestCase {

	private const BRANDS = [
		[ 'id' => 1, 'slug' => 'viajar-por-escocia', 'title' => 'Viajar por Escocia', 'domain' => 'viajarporescocia.com', 'markets' => 'es', 'tour_language' => 'es', 'attribution_param' => '' ],
		[ 'id' => 2, 'slug' => 'scozia-tour', 'title' => 'Scozia Tour', 'domain' => 'scoziatour.com', 'markets' => 'it', 'tour_language' => 'it', 'attribution_param' => '' ],
		[ 'id' => 3, 'slug' => 'loch-ness-bus', 'title' => 'Loch Ness Bus', 'domain' => 'lochnessbus.com', 'markets' => '*', 'tour_language' => 'en', 'attribution_param' => '?utm_source=skye' ],
	];

	public function test_brand_routing_follows_contract_section_3(): void {
		$slug = static fn( string $market ) => Brands::pick( self::BRANDS, $market )['slug'] ?? null;
		self::assertSame( 'viajar-por-escocia', $slug( 'es' ) );
		self::assertSame( 'viajar-por-escocia', $slug( 'ES_mx' ) );
		self::assertSame( 'scozia-tour', $slug( 'it' ) );
		foreach ( [ 'en-gb', 'en-us', 'de', 'fr', 'pt', 'nl', 'zh', 'ja', '' ] as $m ) {
			self::assertSame( 'loch-ness-bus', $slug( $m ), $m );
		}
		self::assertNull( Brands::pick( [ self::BRANDS[0] ], 'de' ) );
		// An exact market beats the language, which beats the wildcard.
		$us = [ 'id' => 9, 'slug' => 'us-only', 'markets' => 'en-us, ca' ];
		self::assertSame( 'us-only', Brands::pick( [ ...self::BRANDS, $us ], 'en-us' )['slug'] );
		self::assertSame( 'loch-ness-bus', Brands::pick( [ ...self::BRANDS, $us ], 'en-gb' )['slug'] );
	}

	public function test_tour_offer_resolves_product_price_and_booking_url_for_the_brand(): void {
		$products = [
			[ 'brand' => [ 'id' => 1 ], 'bokun_product_id' => '111', 'fallback_booking_url' => 'https://viajarporescocia.com/tour-skye?x=1' ],
			[ 'brand' => [ 'id' => 3 ], 'bokun_product_id' => ' 60870 ', 'fallback_booking_url' => null ],
			[ 'brand' => null, 'bokun_product_id' => '999', 'fallback_booking_url' => null ],
		];
		$asked = [];
		$price = static function ( string $id, string $currency ) use ( &$asked ): ?array {
			$asked[] = "$id/$currency";
			return '60870' === $id ? [ 'price_from' => 399.2, 'currency' => 'GBP', 'next_available' => '2026-11-30', 'departures' => 21, 'fetched_at' => 'x', 'stale' => true ] : null;
		};

		$en = TourOffer::build( self::BRANDS[2], $products, 'en-gb', 'GBP', $price );
		self::assertSame( 'loch-ness-bus', $en['brand']['slug'] );
		self::assertSame( 'en', $en['brand']['tour_language'] );
		self::assertSame( '60870', $en['bokun_product_id'] );
		self::assertSame( 399.2, $en['price_from'] );
		self::assertSame( '2026-11-30', $en['next_available'] );
		self::assertTrue( $en['price_stale'] );
		self::assertSame( 'https://lochnessbus.com/?utm_source=skye', $en['booking_url'] );

		$es = TourOffer::build( self::BRANDS[0], $products, 'es', 'EUR', $price );
		self::assertNull( $es['price_from'] );
		self::assertNull( $es['currency'] );
		self::assertFalse( $es['price_stale'] );
		self::assertSame( 'https://viajarporescocia.com/tour-skye?x=1', $es['booking_url'] );

		// Brand without a product row: no Bókun call, booking falls back to the brand's site.
		$it = TourOffer::build( self::BRANDS[1], $products, 'it', 'EUR', $price );
		self::assertNull( $it['bokun_product_id'] );
		self::assertSame( 'https://scoziatour.com/', $it['booking_url'] );

		// The widget needs a valid channel on the brand and a product; anything else leaves the plain link.
		self::assertNull( $es['booking_widget'] );
		$channel = 'BF60449B-9be3-4c80-b568-7d869491a1f4';
		$es      = TourOffer::build( [ 'bokun_channel_uuid' => " $channel " ] + self::BRANDS[0], $products, 'es', 'EUR', $price );
		self::assertSame( [ 'channel_uuid' => strtolower( $channel ), 'product_id' => '111' ], $es['booking_widget'] );
		self::assertNull( TourOffer::build( [ 'bokun_channel_uuid' => 'x"><script>' ] + self::BRANDS[0], $products, 'es', 'EUR', $price )['booking_widget'] );
		self::assertNull( TourOffer::build( [ 'bokun_channel_uuid' => $channel ] + self::BRANDS[1], $products, 'it', 'EUR', $price )['booking_widget'] );

		self::assertSame( [ '60870/GBP', '111/EUR', '111/EUR', '111/EUR' ], $asked );
		self::assertNull( TourOffer::build( null, $products, 'de', 'EUR', $price ) );
	}

	public function test_serializer_casts_every_schema_type(): void {
		$ref   = static fn( int $id, string $to ) => 404 === $id ? null : [ 'id' => $id, 'type' => $to ];
		$image = static fn( $a ) => $a ? [ 'id' => (int) $a ] : null;
		$cast  = static fn( array $def, mixed $raw ) => Serializer::cast( $def, $raw, $ref, $image );
		$route = Fields::route();
		$tour  = Fields::tour();

		self::assertSame( 80, $cast( $route['total_km'], '80' ) );
		self::assertSame( 12.5, $cast( $route['total_km'], '12.5' ) );
		self::assertNull( $cast( $route['total_km'], '' ) );
		self::assertTrue( $cast( $route['motorhome_ok'], '1' ) );
		self::assertFalse( $cast( $route['motorhome_ok'], '0' ) );
		self::assertSame( 'drive', $cast( $route['route_type'], 'nonsense' ), 'unknown enum falls back to default' );
		self::assertSame( 'walk', $cast( $route['route_type'], 'walk' ) );
		self::assertSame( [ 'dogs', 'parking' ], $cast( Fields::lodging()['amenities'], 'dogs, parking, jacuzzi' ) );
		self::assertSame( [ 'lat' => 57.4, 'lng' => -6.2 ], $cast( Fields::lodging()['location'], [ 'lat' => '57.4', 'lng' => '-6.2' ] ) );
		self::assertNull( $cast( Fields::lodging()['location'], '' ) );
		self::assertNull( $cast( Fields::lodging()['booking_url'], '  ' ) );
		self::assertSame( '', $cast( $route['summary'], null ) );
		self::assertSame( [ 'id' => 7 ], $cast( Fields::brand()['logo'], 7 ) );

		// Relations: single (max 1) gives one reference or null; multi gives a list; dead references are dropped.
		self::assertSame( [ 'id' => 5, 'type' => PostTypes::TOUR ], $cast( $route['equivalent_tour'], [ 5 ] ) );
		self::assertSame( [ 'id' => 5, 'type' => PostTypes::TOUR ], $cast( $route['equivalent_tour'], '5' ) );
		self::assertNull( $cast( $route['equivalent_tour'], [ 404 ] ) );
		self::assertNull( $cast( $route['equivalent_tour'], '' ) );

		// A tour day lists its stops as rows: an optional place, a name, the kind of stop and the minutes there.
		$days = $cast( $tour['days'], [ [ 'stops' => [ [ 'place' => [ 1 ], 'stop_type' => 'photo', 'duration_min' => '15' ], [ 'place' => 404, 'title' => 'Loch Tulla viewpoint', 'stop_type' => 'nonsense' ], 7 ] ], 'junk', [] ] );
		self::assertCount( 2, $days );
		self::assertSame( [ 'place' => [ 'id' => 1, 'type' => PostTypes::PLACE ], 'title' => '', 'stop_type' => 'photo', 'duration_min' => 15, 'note' => '' ], $days[0]['stops'][0] );
		self::assertSame( [ 'place' => null, 'title' => 'Loch Tulla viewpoint', 'stop_type' => 'visit', 'duration_min' => null, 'note' => '' ], $days[0]['stops'][1] );
		self::assertCount( 2, $days[0]['stops'], 'a stop that is not a row is dropped' );
		self::assertSame( [ 1, 2, 3 ], \Skye\Api\Places::stop_place_ids( [ [ 'place' => [ 1 ] ], [ 'title' => 'No page' ], [ 'place' => 2 ], 3 ] ), 'rows, and the plain list of places stored before stops had durations' );

		// Nested repeater (route → days → stops) keeps order and casts sub-fields.
		$out = $cast( $route['days'], [ [ 'stops' => [ [ 'place' => [ 3 ], 'time_hint' => '09:00', 'note' => 'n' ] ], 'night_zone' => 'portree' ] ] );
		self::assertSame( [ 'id' => 3, 'type' => PostTypes::PLACE ], $out[0]['stops'][0]['place'] );
		self::assertSame( 'visit', $out[0]['stops'][0]['stop_type'] );
		self::assertSame( 'portree', $out[0]['night_zone'] );
		self::assertNull( $out[0]['night_lodging'] );
	}

	public function test_seeds_only_use_schema_fields_and_reference_existing_slugs(): void {
		$load  = static fn( string $n ) => json_decode( (string) file_get_contents( __DIR__ . "/../../seed/$n.json" ), true, 512, JSON_THROW_ON_ERROR )[ $n ];
		$slugs = [
			PostTypes::PLACE => array_column( $load( 'places' ), 'slug' ),
			PostTypes::BRAND => array_column( $load( 'brands' ), 'slug' ),
			PostTypes::TOUR  => array_column( $load( 'tours' ), 'slug' ),
		];
		$check = static function ( array $schema, array $row, string $path ) use ( &$check, $slugs ): void {
			foreach ( $row as $k => $v ) {
				if ( in_array( $k, [ 'slug', 'title', 'content', 'profiles' ], true ) ) {
					continue;
				}
				self::assertArrayHasKey( $k, $schema, "$path: unknown field $k" );
				$def = $schema[ $k ];
				if ( 'relation' === $def['type'] ) {
					foreach ( (array) $v as $slug ) {
						self::assertContains( $slug, $slugs[ $def['to'] ], "$path.$k → $slug" );
					}
				} elseif ( 'repeater' === $def['type'] ) {
					foreach ( $v as $i => $sub ) {
						$check( $def['sub'], $sub, "{$path}.{$k}[{$i}]" );
					}
				} elseif ( 'enum' === $def['type'] ) {
					self::assertArrayHasKey( $v, $def['choices'], "$path.$k" );
				}
			}
		};
		foreach ( [ 'brands' => PostTypes::BRAND, 'tours' => PostTypes::TOUR, 'routes' => PostTypes::ROUTE ] as $name => $type ) {
			foreach ( $load( $name ) as $row ) {
				$check( Fields::for( $type ), $row, $row['slug'] );
				self::assertLessThanOrEqual( 160, mb_strlen( $row['summary'] ?? '' ), "{$row['slug']}: summary" );
			}
		}
		self::assertSame( [ 'es', 'it', '*' ], array_column( $load( 'brands' ), 'markets' ) );
		self::assertSame( [ 'SK3D', 'SK2D' ], array_column( $load( 'tours' ), 'product_code' ) );
	}
}
