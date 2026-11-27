<?php
declare(strict_types=1);

namespace Skye\Tests;

use PHPUnit\Framework\TestCase;
use Skye\Geo;

final class GeoTest extends TestCase {

	public function test_parse_latlng(): void {
		self::assertSame( [ 'lat' => 57.5072, 'lng' => -6.1801 ], Geo::parse_latlng( '57.5072,-6.1801' ) );
		self::assertSame( [ 'lat' => 57.5, 'lng' => -6.2 ], Geo::parse_latlng( ' 57.5 , -6.2 ' ) );
		self::assertNull( Geo::parse_latlng( 'portree' ) );
		self::assertNull( Geo::parse_latlng( '' ) );
	}

	public function test_distance_storr_to_portree_is_about_10_km(): void {
		$storr   = [ 'lat' => 57.5072, 'lng' => -6.1801 ];
		$portree = [ 'lat' => 57.4126, 'lng' => -6.1945 ];
		self::assertEqualsWithDelta( 10.6, Geo::distance_km( $storr, $portree ), 0.3 );
	}

	public function test_drive_estimate_is_conservative_for_single_track_roads(): void {
		// 10 km straight line → ~14 km road → ~19 min at 45 km/h
		self::assertSame( 19, Geo::drive_minutes_estimate( 10 ) );
	}

	public function test_an_unset_coordinate_group_is_not_the_point_zero_zero(): void {
		$this->assertNull( \Skye\Meta::point( [ 'lat' => '', 'lng' => '' ] ) );
		$this->assertNull( \Skye\Meta::point( [ 'lat' => 0, 'lng' => 0 ] ) );
		$this->assertNull( \Skye\Meta::point( null ) );
		$this->assertSame( [ 'lat' => 57.504, 'lng' => -6.169 ], \Skye\Meta::point( [ 'lat' => '57.504', 'lng' => '-6.169' ] ) );
	}
}
