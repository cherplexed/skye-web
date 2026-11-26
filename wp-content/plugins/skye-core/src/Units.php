<?php
declare(strict_types=1);

namespace Skye;

/**
 * CONTRACTS.md §9. Storage is metric + GBP; presentation converts by market.
 * Pure functions, no WordPress dependency, so they are unit-tested and reusable by the bot.
 */
final class Units {

	public const MI_PER_KM   = 0.621371;
	public const FT_PER_M    = 3.28084;
	public const L_PER_US_GAL = 3.78541;
	public const L_PER_UK_GAL = 4.54609;

	/** @return array{distance:string, altitude:string, fuel:string, temperature:string, currency:string, brand_market:string} */
	public static function market( string $code ): array {
		$code = strtolower( str_replace( '_', '-', $code ) );
		return match ( true ) {
			'en-us' === $code                                   => [ 'distance' => 'mi', 'altitude' => 'ft', 'fuel' => 'us_gal', 'temperature' => 'F', 'currency' => 'USD', 'brand_market' => 'en' ],
			str_starts_with( $code, 'en' )                      => [ 'distance' => 'mi', 'altitude' => 'ft', 'fuel' => 'l_mpg_uk', 'temperature' => 'C', 'currency' => 'GBP', 'brand_market' => 'en' ],
			'zh' === $code || str_starts_with( $code, 'zh-' )   => [ 'distance' => 'km', 'altitude' => 'm', 'fuel' => 'l', 'temperature' => 'C', 'currency' => 'CNY', 'brand_market' => 'zh' ],
			'ja' === $code                                      => [ 'distance' => 'km', 'altitude' => 'm', 'fuel' => 'l', 'temperature' => 'C', 'currency' => 'JPY', 'brand_market' => 'ja' ],
			default                                             => [ 'distance' => 'km', 'altitude' => 'm', 'fuel' => 'l_100km', 'temperature' => 'C', 'currency' => 'EUR', 'brand_market' => substr( $code, 0, 2 ) ],
		};
	}

	/** Default market for a language code (en → en-gb). */
	public static function market_for_lang( string $lang ): string {
		$lang = strtolower( $lang );
		return 'en' === $lang ? 'en-gb' : $lang;
	}

	public static function km_to_mi( float $km ): float {
		return round( $km * self::MI_PER_KM, 1 );
	}

	public static function m_to_ft( float $m ): int {
		return (int) round( $m * self::FT_PER_M );
	}

	public static function c_to_f( float $c ): float {
		return round( $c * 9 / 5 + 32, 1 );
	}

	public static function l_per_100km_to_mpg( float $l100, bool $us = false ): float {
		$gal = $us ? self::L_PER_US_GAL : self::L_PER_UK_GAL;
		return $l100 > 0 ? round( 100 * $gal / ( $l100 * 1.609344 ), 1 ) : 0.0;
	}

	/** Driving distances are always shown in both systems (road signs in Scotland are in miles). */
	public static function format_drive_distance( float $km, string $market ): string {
		$mi = self::km_to_mi( $km );
		return 'mi' === self::market( $market )['distance']
			? sprintf( '%s mi (%s km)', self::num( $mi ), self::num( $km ) )
			: sprintf( '%s km (%s mi)', self::num( $km ), self::num( $mi ) );
	}

	public static function format_distance( float $km, string $market ): string {
		return 'mi' === self::market( $market )['distance'] ? self::num( self::km_to_mi( $km ) ) . ' mi' : self::num( $km ) . ' km';
	}

	public static function format_altitude( float $m, string $market ): string {
		return 'ft' === self::market( $market )['altitude'] ? self::m_to_ft( $m ) . ' ft' : (int) round( $m ) . ' m';
	}

	public static function format_duration( int $minutes ): string {
		if ( $minutes < 60 ) {
			return $minutes . ' min';
		}
		$h = intdiv( $minutes, 60 );
		$m = $minutes % 60;
		return $m ? sprintf( '%dh %02d', $h, $m ) : $h . 'h';
	}

	private static function num( float $n ): string {
		return rtrim( rtrim( number_format( $n, 1, '.', '' ), '0' ), '.' );
	}
}
