<?php
declare(strict_types=1);

namespace Skye\Tests;

use PHPUnit\Framework\TestCase;
use Skye\Bokun\BokunException;
use Skye\Bokun\Client;
use Skye\Bokun\Offers;
use Skye\Bokun\Pricing;
use Skye\Bokun\Signer;

final class BokunTest extends TestCase {

	private const DAY_MS = 86400000;

	/** One departure as Bókun returns it: default rate 10 with adult (1) and child (2) prices. */
	private static function departure( int $day, float $adult, array $over = [] ): array {
		return $over + [
			'date'              => 1795651200000 + $day * self::DAY_MS, // 2026-11-26 + $day
			'availabilityCount' => 12,
			'soldOut'           => false,
			'unavailable'       => false,
			'defaultRateId'     => 10,
			'pricesByRate'      => [
				[ 'activityRateId' => 99, 'pricePerCategoryUnit' => [ [ 'id' => 1, 'amount' => [ 'amount' => 1.0, 'currency' => 'GBP' ] ] ] ],
				[
					'activityRateId'       => 10,
					'pricePerCategoryUnit' => [
						[ 'id' => 2, 'amount' => [ 'amount' => $adult - 40, 'currency' => 'GBP' ] ],
						[ 'id' => 1, 'amount' => [ 'amount' => $adult, 'currency' => 'GBP' ] ],
					],
				],
			],
		];
	}

	public function test_signature_matches_the_worked_example_in_bokun_docs(): void {
		self::assertSame(
			'XrOiTYa9Y34zscnLCsAEh8ieoyo=',
			Signer::sign( '2013-11-09 14:33:46', 'de235a6a15c340b6b1e1cb5f3687d04a', '23e2c7da7f7048e5b46f96bc91324800', 'post', '/activity.json/search?lang=EN&currency=ISK' )
		);
	}

	public function test_headers_use_utc_date_and_sign_it(): void {
		$h = Signer::headers( 'ak', 'sk', 'GET', '/x', 1384007626 );
		self::assertSame( '2013-11-09 14:33:46', $h['X-Bokun-Date'] );
		self::assertSame( 'ak', $h['X-Bokun-AccessKey'] );
		self::assertSame( Signer::sign( '2013-11-09 14:33:46', 'ak', 'sk', 'GET', '/x' ), $h['X-Bokun-Signature'] );
	}

	public function test_price_from_is_lowest_adult_price_of_default_rate_among_bookable_departures(): void {
		$sum = Pricing::summarize(
			[
				self::departure( 9, 150.0, [ 'soldOut' => true ] ),
				self::departure( 8, 140.0, [ 'unavailable' => true ] ),
				self::departure( 7, 130.0, [ 'availabilityCount' => 0 ] ),
				self::departure( 5, 212.0 ),
				self::departure( 3, 229.0 ),
			]
		);
		self::assertSame( [ 'price_from' => 212.0, 'currency' => 'GBP', 'next_available' => '2026-11-29', 'departures' => 2 ], $sum );
	}

	public function test_explicit_category_and_unlimited_availability(): void {
		$list = [ self::departure( 1, 200.0, [ 'availabilityCount' => 0, 'unlimitedAvailability' => true ] ) ];
		self::assertSame( 160.0, Pricing::summarize( $list, 2 )['price_from'] );
		self::assertNull( Pricing::summarize( $list, 777 ) );
		self::assertNull( Pricing::summarize( [] ) );
		self::assertNull( Pricing::summarize( [ 'garbage', [ 'soldOut' => false ] ] ) );
	}

	public function test_client_signs_the_exact_path_and_query_it_sends(): void {
		$seen   = [];
		$client = new Client(
			'ak',
			'sk',
			Client::TEST,
			static function ( string $method, string $url, array $headers ) use ( &$seen ): array {
				$seen = compact( 'method', 'url', 'headers' );
				return [ 'status' => 200, 'body' => '[{"id":1}]', 'headers' => [] ];
			}
		);
		self::assertSame( [ [ 'id' => 1 ] ], $client->availabilities( '4321', '2026-12-01', '2026-12-31', 'eur' ) );
		$path = '/activity.json/4321/availabilities?start=2026-12-01&end=2026-12-31&currency=EUR';
		self::assertSame( Client::TEST . $path, $seen['url'] );
		self::assertSame( Signer::sign( $seen['headers']['X-Bokun-Date'], 'ak', 'sk', 'GET', $path ), $seen['headers']['X-Bokun-Signature'] );
	}

	public function test_client_errors_carry_status_and_retry_after_but_never_the_body(): void {
		$make = static fn( int $status, string $body, array $headers = [] ) => new Client( 'ak', 'sk', Client::TEST, static fn() => [ 'status' => $status, 'body' => $body, 'headers' => $headers ] );
		try {
			$make( 429, '', [ 'Retry-After' => '17' ] )->get( '/x' );
			self::fail( 'expected exception' );
		} catch ( BokunException $e ) {
			self::assertSame( 429, $e->getCode() );
			self::assertSame( 17, $e->retry_after );
		}
		try {
			$make( 401, 'secret-echo' )->get( '/x' );
			self::fail( 'expected exception' );
		} catch ( BokunException $e ) {
			self::assertSame( 401, $e->getCode() );
			self::assertStringNotContainsString( 'secret-echo', $e->getMessage() );
		}
		$this->expectException( BokunException::class );
		$make( 200, '<html>' )->get( '/x' );
	}

	public function test_offers_cache_fall_back_to_last_good_answer_and_back_off(): void {
		$store = [];
		$ttl   = [];
		$get   = static function ( string $k ) use ( &$store ) {
			return $store[ $k ] ?? false;
		};
		$set   = static function ( string $k, mixed $v, int $t ) use ( &$store, &$ttl ): void {
			$store[ $k ] = $v;
			$ttl[ $k ]   = $t;
		};
		$calls = 0;
		$mode  = 'ok';
		$http  = function () use ( &$calls, &$mode ): array {
			++$calls;
			return 'ok' === $mode
				? [ 'status' => 200, 'body' => json_encode( [ self::departure( 4, 212.0 ) ] ), 'headers' => [] ]
				: [ 'status' => 429, 'body' => '', 'headers' => [ 'retry-after' => '42' ] ];
		};
		$now    = 1795651200;
		$offers = new Offers( new Client( 'ak', 'sk', Client::TEST, $http ), $get, $set, $now );

		$first = $offers->price_from( '4321', 'gbp' );
		self::assertSame( 212.0, $first['price_from'] );
		self::assertSame( '2026-11-30', $first['next_available'] );
		self::assertFalse( $first['stale'] );
		self::assertSame( 1, $calls );

		$offers->price_from( '4321', 'GBP' );
		self::assertSame( 1, $calls, 'second read is served from cache' );

		// Fresh entry expires, Bókun starts rate limiting: last good answer, flagged stale, no retry for 42 s.
		$fresh = array_values( array_filter( array_keys( $store ), static fn( $k ) => ! str_ends_with( $k, '_last' ) ) )[0];
		unset( $store[ $fresh ] );
		$mode  = 'limited';
		$stale = $offers->price_from( '4321', 'GBP' );
		self::assertSame( 212.0, $stale['price_from'] );
		self::assertTrue( $stale['stale'] );
		self::assertSame( 42, $ttl[ $fresh ] );
		self::assertSame( 2, $calls );
		$offers->price_from( '4321', 'GBP' );
		self::assertSame( 2, $calls, 'no call while backing off' );

		// Unknown product while failing, and no client at all: null, never an exception.
		self::assertNull( $offers->price_from( '999', 'GBP' ) );
		self::assertNull( ( new Offers( null, $get, $set ) )->price_from( '4321' ) );
	}

	public function test_offers_look_further_ahead_when_the_first_window_is_empty(): void {
		$store   = [];
		$windows = [];
		$http    = function ( string $m, string $url ) use ( &$windows ): array {
			$windows[] = $url;
			$body      = count( $windows ) < 3 ? '[]' : json_encode( [ self::departure( 130, 199.0 ) ] );
			return [ 'status' => 200, 'body' => $body, 'headers' => [] ];
		};
		$offers  = new Offers(
			new Client( 'ak', 'sk', Client::TEST, $http ),
			static function ( string $k ) use ( &$store ) {
				return $store[ $k ] ?? false;
			},
			static function ( string $k, mixed $v ) use ( &$store ): void {
				$store[ $k ] = $v;
			},
			1795651200
		);
		self::assertSame( 199.0, $offers->price_from( '4321' )['price_from'] );
		self::assertCount( 3, $windows );
		self::assertStringContainsString( 'start=2026-11-26&end=2027-01-24', $windows[0] );
		self::assertStringContainsString( 'start=2027-01-25&end=2027-03-25', $windows[1] );
	}
}
