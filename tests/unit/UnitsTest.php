<?php
declare(strict_types=1);

namespace Skye\Tests;

use PHPUnit\Framework\TestCase;
use Skye\Units;

final class UnitsTest extends TestCase {

	public function test_markets_follow_contract_section_9(): void {
		self::assertSame( 'mi', Units::market( 'en-gb' )['distance'] );
		self::assertSame( 'GBP', Units::market( 'en-gb' )['currency'] );
		self::assertSame( 'USD', Units::market( 'en-US' )['currency'] );
		self::assertSame( 'F', Units::market( 'en-us' )['temperature'] );
		self::assertSame( 'km', Units::market( 'es' )['distance'] );
		self::assertSame( 'EUR', Units::market( 'de' )['currency'] );
		self::assertSame( 'CNY', Units::market( 'zh' )['currency'] );
		self::assertSame( 'JPY', Units::market( 'ja' )['currency'] );
	}

	public function test_english_defaults_to_gb(): void {
		self::assertSame( 'en-gb', Units::market_for_lang( 'en' ) );
		self::assertSame( 'es', Units::market_for_lang( 'es' ) );
	}

	public function test_conversions(): void {
		self::assertSame( 45.1, Units::km_to_mi( 72.6 ) );
		self::assertSame( 984, Units::m_to_ft( 300 ) );
		self::assertSame( 50.0, Units::c_to_f( 10 ) );
		// 7 l/100km ≈ 40.4 mpg (UK), 33.6 mpg (US)
		self::assertEqualsWithDelta( 40.4, Units::l_per_100km_to_mpg( 7.0 ), 0.1 );
		self::assertEqualsWithDelta( 33.6, Units::l_per_100km_to_mpg( 7.0, true ), 0.1 );
	}

	public function test_drive_distance_always_shows_both_systems(): void {
		self::assertSame( '72 km (44.7 mi)', Units::format_drive_distance( 72, 'es' ) );
		self::assertSame( '44.7 mi (72 km)', Units::format_drive_distance( 72, 'en-gb' ) );
	}

	public function test_walk_distance_and_altitude_follow_market(): void {
		self::assertSame( '3.8 km', Units::format_distance( 3.8, 'de' ) );
		self::assertSame( '2.4 mi', Units::format_distance( 3.8, 'en-us' ) );
		self::assertSame( '300 m', Units::format_altitude( 300, 'fr' ) );
		self::assertSame( '984 ft', Units::format_altitude( 300, 'en-gb' ) );
	}

	public function test_duration(): void {
		self::assertSame( '45 min', Units::format_duration( 45 ) );
		self::assertSame( '2h', Units::format_duration( 120 ) );
		self::assertSame( '1h 30', Units::format_duration( 90 ) );
	}

	public function test_money_is_written_the_way_each_market_writes_it(): void {
		self::assertSame( '£399.20', Units::format_money( 399.2, 'GBP', 'en-gb' ) );
		self::assertSame( '$1,500', Units::format_money( 1500.0, 'usd', 'en-us' ) );
		self::assertSame( '283 €', Units::format_money( 283.0, 'EUR', 'es' ) );
		self::assertSame( '1.234,50 €', Units::format_money( 1234.5, 'EUR', 'de' ) );
		self::assertSame( 'CHF 100', Units::format_money( 100.0, 'CHF', 'en-gb' ) );
		self::assertSame( '100 CHF', Units::format_money( 100.0, 'CHF', 'it' ) );
	}
}
