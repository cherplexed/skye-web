<?php
declare(strict_types=1);

namespace Skye\Tests;

use PHPUnit\Framework\TestCase;
use Skye\JsonLd;
use Skye\Render\PlaceBlocks;

final class RenderTest extends TestCase {

	private static function place( array $over = [] ): array {
		return $over + [
			'id'                => 12,
			'title'             => 'Old Man of Storr',
			'gaelic_name'       => 'Bodach an Stòir',
			'summary'           => 'A steep walk <b>with</b> views.',
			'url'               => 'http://x/places/old-man-of-storr/',
			'zone'              => [ 'slug' => 'trotternish', 'name' => 'Trotternish' ],
			'categories'        => [ [ 'slug' => 'hike', 'name' => 'Hike' ] ],
			'profiles'          => [ [ 'slug' => 'hiking', 'name' => 'Hiking' ] ],
			'location'          => [ 'lat' => 57.5072, 'lng' => -6.1801 ],
			'parking_location'  => [ 'lat' => 57.504, 'lng' => -6.169 ],
			'directions'        => '10 km north of Portree on the A855.',
			'duration_min'      => 60,
			'duration_typical'  => 120,
			'difficulty'        => 'moderate',
			'distance_km'       => 3.8,
			'elevation_gain_m'  => 300.0,
			'price_type'        => 'paid_parking',
			'price_gbp'         => 6.0,
			'best_time'         => 'Sunrise',
			'suitable_for'      => [ 'children', 'dogs' ],
			'services_nearby'   => [ 'toilets' ],
			'motorhome_parking' => 'yes_length_limit',
			'overnight_allowed' => false,
			'opening_hours'     => [ [ 'season' => 'All year', 'hours' => 'Open access' ], [ 'season' => 'x', 'hours' => ' ' ] ],
			'tips'              => [ 'Bring boots', '' ],
			'image'             => null,
			'nearby_places'     => [ [ 'id' => 3, 'title' => 'Kilt Rock', 'url' => 'http://x/places/kilt-rock/', 'distance_km' => 9.6, 'drive_minutes' => 18 ] ],
			'nearby_lodging'    => [],
			'in_tours'          => [ [ 'id' => 9, 'title' => '3-day tour', 'url' => 'http://x/tours/3/' ] ],
			'verification'      => [ 'verified_by' => null, 'verified_at' => null, 'valid_until' => null, 'updated_at' => null, 'status' => 'unverified' ],
		];
	}

	public function test_facts_follow_the_market_units(): void {
		$es = PlaceBlocks::facts( self::place(), 'es' );
		self::assertStringContainsString( '<dt>Time needed</dt><dd>2h (at least 1h)</dd>', $es );
		self::assertStringContainsString( '<dt>Walk</dt><dd>3.8 km, 300 m of ascent</dd>', $es );
		self::assertStringContainsString( '<dd>Free entry; parking £6</dd>', $es );
		self::assertStringContainsString( '<dd>Children, Dogs</dd>', $es );
		self::assertStringContainsString( '<dd>Yes, with length limit; no overnight stays</dd>', $es );
		self::assertStringContainsString( '<dt>Difficulty</dt><dd>Moderate</dd>', $es );

		$us = PlaceBlocks::facts( self::place(), 'en-us' );
		self::assertStringContainsString( '<dd>2.4 mi, 984 ft of ascent</dd>', $us );

		$plain = PlaceBlocks::facts( self::place( [ 'difficulty' => 'none', 'distance_km' => null, 'price_type' => 'free', 'price_gbp' => null, 'duration_typical' => null ] ), 'es' );
		self::assertStringNotContainsString( 'Difficulty', $plain );
		self::assertStringNotContainsString( 'Walk', $plain );
		self::assertStringNotContainsString( 'Time needed', $plain );
		self::assertStringContainsString( '<dt>Price</dt><dd>Free</dd>', $plain );
	}

	public function test_blocks_escape_and_disappear_when_empty(): void {
		$p = self::place();
		self::assertStringContainsString( 'A steep walk &lt;b&gt;with&lt;/b&gt; views.', PlaceBlocks::intro( $p ) );
		self::assertStringContainsString( 'lang="gd">Bodach an Stòir<', PlaceBlocks::intro( $p ) );
		self::assertSame( 1, substr_count( PlaceBlocks::tips( $p ), '<li>' ) );
		self::assertSame( 1, substr_count( PlaceBlocks::hours( $p ), '<tr>' ) );

		$empty = self::place( [ 'tips' => [], 'opening_hours' => [], 'in_tours' => [], 'nearby_places' => [], 'directions' => '', 'location' => null, 'parking_location' => null ] );
		foreach ( [ 'tips', 'hours', 'tours', 'nearby', 'directions' ] as $part ) {
			self::assertSame( '', PlaceBlocks::render( $part, $empty, 'es' ), $part );
		}
		self::assertSame( '', PlaceBlocks::render( 'nonsense', $p, 'es' ) );
	}

	public function test_directions_nearby_and_tours(): void {
		$p = self::place();
		$d = PlaceBlocks::directions( $p );
		self::assertStringContainsString( 'destination=57.50400,-6.16900"', $d, 'links to the car park when there is one' );
		self::assertStringContainsString( 'Directions to the car park', $d );
		self::assertStringContainsString( 'destination=57.50720,-6.18010"', PlaceBlocks::directions( self::place( [ 'parking_location' => null ] ) ) );

		self::assertStringContainsString( '<a href="http://x/places/kilt-rock/">Kilt Rock</a> <span class="skye-nearby__meta">9.6 km (6 mi) · about 18 min by car</span>', PlaceBlocks::nearby( $p, 'es' ) );
		self::assertStringContainsString( '6 mi (9.6 km)', PlaceBlocks::nearby( $p, 'en-gb' ) );
		self::assertStringContainsString( '<a href="http://x/tours/3/">3-day tour</a>', PlaceBlocks::tours( $p ) );
	}

	public function test_verification_never_claims_a_check_that_did_not_happen(): void {
		self::assertStringContainsString( 'Not yet checked', PlaceBlocks::verification( self::place() ) );
		self::assertStringContainsString( 'skye-verification--unverified', PlaceBlocks::verification( self::place() ) );

		$v  = [ 'verified_by' => [ 'id' => 2, 'name' => 'Iain M.' ], 'verified_at' => '2026-11-20', 'valid_until' => '2027-11-20', 'updated_at' => '2026-11-25', 'status' => 'verified' ];
		$ok = PlaceBlocks::verification( self::place( [ 'verification' => $v ] ) );
		self::assertStringContainsString( 'Checked by Iain M. on 20 November 2026. Updated on 25 November 2026.', $ok );
		self::assertStringContainsString( 'Due for a new check.', PlaceBlocks::verification( self::place( [ 'verification' => [ 'status' => 'expired' ] + $v ] ) ) );

		$ld = JsonLd::place( self::place() );
		self::assertArrayNotHasKey( 'dateModified', $ld );
		self::assertArrayNotHasKey( 'reviewedBy', $ld );
		$ld = JsonLd::place( self::place( [ 'verification' => $v ] ) );
		self::assertSame( '2026-11-25', $ld['dateModified'] );
		self::assertSame( 'Iain M.', $ld['reviewedBy']['name'] );
	}

	public function test_json_ld_place(): void {
		$ld = JsonLd::place( self::place() );
		self::assertSame( 'TouristAttraction', $ld['@type'] );
		self::assertSame( 'Bodach an Stòir', $ld['alternateName'] );
		self::assertSame( [ '@type' => 'GeoCoordinates', 'latitude' => 57.5072, 'longitude' => -6.1801 ], $ld['geo'] );
		self::assertTrue( $ld['isAccessibleForFree'], 'paid parking is still free entry' );
		self::assertFalse( JsonLd::place( self::place( [ 'price_type' => 'ticket' ] ) )['isAccessibleForFree'] );
		self::assertSame( 'Toilets', $ld['amenityFeature'][0]['name'] );
		self::assertSame( 'Trotternish, Isle of Skye', $ld['containedInPlace']['name'] );
		self::assertArrayNotHasKey( 'image', $ld );
	}

	public function test_bricks_template_is_a_consistent_tree_using_known_parts(): void {
		$t   = json_decode( (string) file_get_contents( SKYE_CORE_DIR . '/templates/bricks/single-place.json' ), true, 512, JSON_THROW_ON_ERROR );
		$ids = array_column( $t['elements'], 'id' );
		self::assertSame( $ids, array_unique( $ids ) );
		$by = array_column( $t['elements'], null, 'id' );
		foreach ( $t['elements'] as $el ) {
			self::assertMatchesRegularExpression( '/^[a-z0-9]{6}$/', $el['id'] );
			if ( 0 !== $el['parent'] ) {
				self::assertContains( $el['id'], $by[ $el['parent'] ]['children'], $el['id'] );
			}
			foreach ( $el['children'] as $child ) {
				self::assertSame( $el['id'], $by[ $child ]['parent'], $child );
			}
			if ( 'shortcode' === $el['name'] ) {
				self::assertSame( 1, preg_match( '/^\[skye_place part="([a-z]+)"\]$/', $el['settings']['shortcode'], $m ) );
				self::assertContains( $m[1], PlaceBlocks::PARTS );
			}
		}
		self::assertSame( [ 'place' ], $t['conditions'][0]['postType'] );
	}
}
