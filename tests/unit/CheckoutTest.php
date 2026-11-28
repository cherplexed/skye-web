<?php
declare(strict_types=1);

namespace Skye\Tests;

use PHPUnit\Framework\TestCase;
use Skye\Bokun\Client as Bokun;
use Skye\Bokun\Signer;
use Skye\Revolut\Client as Revolut;
use Skye\Revolut\RevolutException;

final class CheckoutTest extends TestCase {

	public function test_bokun_post_signs_the_path_and_sends_the_json_body(): void {
		$seen   = [];
		$client = new Bokun(
			'access',
			'secret',
			'https://api.example',
			static function ( string $method, string $url, array $headers, ?string $body ) use ( &$seen ): array {
				$seen = compact( 'method', 'url', 'headers', 'body' );
				return [ 'status' => 200, 'body' => '{"options":[]}', 'headers' => [] ];
			}
		);
		$res = $client->post( '/checkout.json/options/booking-request', [ 'activityBookings' => [ [ 'activityId' => 60870, 'date' => '2027-05-14' ] ] ], [ 'currency' => 'EUR' ] );

		self::assertSame( [ 'options' => [] ], $res );
		self::assertSame( 'POST', $seen['method'] );
		self::assertSame( 'https://api.example/checkout.json/options/booking-request?currency=EUR', $seen['url'] );
		self::assertSame( '{"activityBookings":[{"activityId":60870,"date":"2027-05-14"}]}', $seen['body'] );
		self::assertSame( 'application/json;charset=UTF-8', $seen['headers']['Content-Type'] );
		self::assertSame( Signer::sign( $seen['headers']['X-Bokun-Date'], 'access', 'secret', 'POST', '/checkout.json/options/booking-request?currency=EUR' ), $seen['headers']['X-Bokun-Signature'] );

		$client->post( '/booking.json/ABC-1/abort-reserved' );
		self::assertSame( '{}', $seen['body'], 'an empty body is still a JSON object' );
	}

	public function test_revolut_orders_are_created_read_and_cancelled_with_the_secret_key(): void {
		$calls  = [];
		$client = new Revolut(
			'sk_test',
			Revolut::SANDBOX,
			'2026-08-17',
			static function ( string $method, string $url, array $headers, ?string $body ) use ( &$calls ): array {
				$calls[] = compact( 'method', 'url', 'headers', 'body' );
				return [ 'status' => 201, 'body' => '{"id":"6516e61c-d279-a454-a837-bc52ce55ed49","token":"0adc0e3c","state":"pending"}' ];
			}
		);
		$order = $client->create_order( Revolut::minor_units( 330.54 ), 'eur', [ 'description' => 'Skye 3 days', 'customer' => [ 'email' => 'doedole@youdole.com' ] ] );
		$client->order( $order['id'] );
		$client->cancel_order( $order['id'] );

		self::assertSame( 'pending', $order['state'] );
		self::assertSame( [ 'POST', 'GET', 'POST' ], array_column( $calls, 'method' ) );
		self::assertSame( 'https://sandbox-merchant.revolut.com/api/orders', $calls[0]['url'] );
		self::assertSame( '{"amount":33054,"currency":"EUR","description":"Skye 3 days","customer":{"email":"doedole@youdole.com"}}', $calls[0]['body'] );
		self::assertSame( 'Bearer sk_test', $calls[0]['headers']['Authorization'] );
		self::assertSame( '2026-08-17', $calls[0]['headers']['Revolut-Api-Version'] );
		self::assertNull( $calls[1]['body'] );
		self::assertSame( 'https://sandbox-merchant.revolut.com/api/orders/6516e61c-d279-a454-a837-bc52ce55ed49/cancel', $calls[2]['url'] );
		self::assertSame( 1999, Revolut::minor_units( 19.99 ) );
	}

	public function test_key_kind_describes_a_key_without_showing_it(): void {
		self::assertSame( 'a production key, 20 characters', \Skye\Cli\Checkout::key_kind( 'x_prod_AAAAAAAAAAAAA' ) );
		self::assertSame( 'a sandbox key, 19 characters', \Skye\Cli\Checkout::key_kind( 'x_sandbox_BBBBBBBBB' ) );
		self::assertSame( 'a key of unknown type, 8 characters', \Skye\Cli\Checkout::key_kind( 'CCCCCCCC' ) );
		self::assertStringContainsString( 'WITH spaces or quotes', \Skye\Cli\Checkout::key_kind( '"x_sandbox_DDDD"' ) );
		self::assertSame( 'missing', \Skye\Cli\Checkout::key_kind( '' ) );
	}

	public function test_revolut_errors_name_the_status_and_code_but_never_the_key_or_the_body(): void {
		$client = new Revolut( 'sk_secret_value', Revolut::SANDBOX, '2026-08-17', static fn() => [ 'status' => 401, 'body' => '{"code":"unauthorized","message":"key sk_secret_value is wrong"}' ] );
		try {
			$client->order( '6516e61c-d279-a454-a837-bc52ce55ed49' );
			self::assertTrue( false, 'a 401 must throw' );
		} catch ( RevolutException $e ) {
			self::assertSame( 'Revolut answered HTTP 401 (unauthorized) for GET /api/orders/{id}', $e->getMessage() );
			self::assertSame( 401, $e->getCode() );
		}
		try {
			$client->create_order( 0, 'GBP' );
			self::assertTrue( false, 'an order of nothing must throw' );
		} catch ( RevolutException $e ) {
			self::assertStringContainsString( 'greater than zero', $e->getMessage() );
		}
	}
}
