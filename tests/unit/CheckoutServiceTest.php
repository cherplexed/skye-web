<?php
declare(strict_types=1);

namespace Skye\Tests;

use PHPUnit\Framework\TestCase;
use Skye\Bokun\Client as Bokun;
use Skye\Checkout\CheckoutException;
use Skye\Checkout\Service;
use Skye\Revolut\Client as Revolut;

final class CheckoutServiceTest extends TestCase {

	private const NOW = 1795900000; // 28 Nov 2026

	/** @var list<array{0:string, 1:string, 2:?array}> Every call made to either service, in order. */
	private array $calls = [];
	/** @var array<string, array> */
	private array $store = [];
	private string $order_state = 'pending';
	private int $submit_status = 200;
	private string $submit_body = '';

	private function service(): Service {
		$this->submit_body = (string) json_encode( [ 'booking' => [ 'confirmationCode' => 'TAS-1', 'status' => 'RESERVED', 'currency' => 'GBP', 'totalPrice' => 488, 'totalDue' => 488 ] ] );
		$bokun = new Bokun(
			'a',
			's',
			'https://bokun.test',
			function ( string $method, string $url, array $headers, ?string $body ): array {
				$path          = (string) preg_replace( '#^https://bokun.test#', '', $url );
				$this->calls[] = [ $method, $path, null !== $body ? json_decode( $body, true ) : null ];
				if ( str_contains( $path, '/availabilities' ) ) {
					$day = static fn( int $offset, array $over = [] ) => $over + [
						'date' => ( intdiv( self::NOW, 86400 ) + $offset ) * 86400 * 1000, 'startTime' => '07:30', 'startTimeId' => 166782, 'availabilityCount' => 49,
						'pricesByRate' => [ [ 'activityRateId' => 92027, 'pricePerCategoryUnit' => [ [ 'id' => 87695, 'amount' => [ 'amount' => 244, 'currency' => 'GBP' ] ], [ 'id' => 892493, 'amount' => [ 'amount' => 170, 'currency' => 'GBP' ] ], [ 'id' => 999, 'amount' => [ 'amount' => 1, 'currency' => 'GBP' ] ] ] ] ],
					];
					return [ 'status' => 200, 'headers' => [], 'body' => (string) json_encode( [ $day( 9 ), $day( 1 ), $day( 5, [ 'soldOut' => true ] ), $day( 6, [ 'availabilityCount' => 0 ] ), $day( 3 ) ] ) ];
				}
				if ( str_contains( $path, '/activity.json/' ) ) {
					return [ 'status' => 200, 'headers' => [], 'body' => (string) json_encode( [
						'defaultRateId' => 92027, 'bookingCutoff' => 2880, 'timeZone' => 'Europe/London',
						'rates' => [ [ 'id' => 92027, 'pricingCategoryIds' => [ 892493, 87695 ] ] ],
						'pricingCategories' => [ [ 'id' => 87695, 'title' => 'Hostel' ], [ 'id' => 892493, 'title' => 'Only tour' ], [ 'id' => 5, 'title' => 'Not on this rate' ] ],
						'bookingQuestions' => [ [ 'id' => 130041, 'label' => 'Children?', 'dataType' => 'BOOLEAN', 'required' => true, 'context' => 'BOOKING' ] ],
					] ) ];
				}
				if ( str_contains( $path, '/options/booking-request' ) ) {
					return [ 'status' => 200, 'headers' => [], 'body' => '{"options":[{"type":"AGENT_RESELLER","amount":488,"currency":"GBP","paymentMethods":{"allowedMethods":["CASH","RESERVE_FOR_EXTERNAL_PAYMENT"]}}],"questions":{"activityBookings":[{"questions":[{"questionId":"130041","label":"Children?","dataType":"BOOLEAN","required":true},{"questionId":"130038","label":"Remarks","help":"Allergies","dataType":"LONG_TEXT","required":false}]}]}}' ];
				}
				if ( str_contains( $path, '/checkout.json/submit' ) ) {
					return [ 'status' => $this->submit_status, 'headers' => [], 'body' => $this->submit_body ];
				}
				return [ 'status' => 200, 'headers' => [], 'body' => '{"message":"OK"}' ];
			}
		);
		$revolut = new Revolut(
			'sk',
			'https://revolut.test',
			'v',
			function ( string $method, string $url, array $headers, ?string $body ): array {
				$path          = (string) preg_replace( '#^https://revolut.test#', '', $url );
				$this->calls[] = [ $method, $path, null !== $body ? json_decode( $body, true ) : null ];
				return [ 'status' => 200, 'body' => (string) json_encode( [ 'id' => 'ord-1', 'token' => 'tok-1', 'state' => str_ends_with( $path, '/cancel' ) ? 'cancelled' : $this->order_state, 'payments' => [ [ 'payment_method' => [ 'card_brand' => 'visa', 'card_last_four' => '4242' ] ] ] ] ) ];
			}
		);
		return new Service(
			$bokun,
			$revolut,
			fn( string $ref ): ?array => $this->store[ $ref ] ?? null,
			function ( string $ref, array $record, int $ttl ): void {
				$this->store[ $ref ] = $record;
			},
			static fn(): int => self::NOW
		);
	}

	private const INPUT = [
		'date' => '2026-12-03', 'start_time_id' => 166782, 'rate_id' => 92027, 'passengers' => [ '87695' => 2, '892493' => 0 ],
		'contact' => [ 'firstName' => ' Joe ', 'lastName' => 'Dole', 'email' => 'doedole@youdole.com', 'phoneNumber' => '+44 131 629 0189' ],
		'answers' => [ '130041' => 'false', '1181526' => true, '130038' => '', 'bad id!' => 'x' ], 'description' => 'Skye 3 days',
	];

	/** @return list<string> "METHOD path" of every call so far. */
	private function trail(): array {
		return array_map( static fn( array $c ) => $c[0] . ' ' . $c[1], $this->calls );
	}

	public function test_calendar_lists_bookable_departures_with_prices_of_the_default_rate(): void {
		$cal = $this->service()->calendar( '60870' );
		self::assertSame( [ [ 'id' => 892493, 'title' => 'Only tour' ], [ 'id' => 87695, 'title' => 'Hostel' ] ], $cal['categories'], 'the categories of the rate, in its order' );
		self::assertSame( 'GBP', $cal['currency'] );
		self::assertSame( 92027, $cal['rate_id'] );
		self::assertTrue( ! isset( $cal['questions'] ), 'questions depend on the travellers: they come with the quote' );
		// Tomorrow is inside the two-day cutoff, one day is sold out and one has no places: two departures remain, in date order.
		self::assertSame( [ gmdate( 'Y-m-d', self::NOW + 3 * 86400 ), gmdate( 'Y-m-d', self::NOW + 9 * 86400 ) ], array_column( $cal['dates'], 'date' ) );
		self::assertSame( [ 87695 => 244.0, 892493 => 170.0 ], $cal['dates'][0]['prices'] );
		self::assertSame( 166782, $cal['dates'][0]['start_time_id'] );
	}

	public function test_quote_asks_bokun_for_the_price_and_the_questions_of_these_travellers_without_reserving(): void {
		$quote = $this->service()->quote( '60870', [ 'date' => '2026-12-03', 'start_time_id' => 166782, 'rate_id' => 92027, 'passengers' => [ '87695' => 2 ] ] );
		self::assertSame( [ 'POST /checkout.json/options/booking-request' ], $this->trail() );
		self::assertSame( [ [ 'pricingCategoryId' => 87695 ], [ 'pricingCategoryId' => 87695 ] ], $this->calls[0][2]['activityBookings'][0]['passengers'] );
		self::assertSame( [ 488.0, 'GBP' ], [ $quote['amount'], $quote['currency'] ] );
		self::assertSame( [ [ 'id' => '130041', 'label' => 'Children?', 'help' => '', 'type' => 'BOOLEAN', 'required' => true ], [ 'id' => '130038', 'label' => 'Remarks', 'help' => 'Allergies', 'type' => 'LONG_TEXT', 'required' => false ] ], $quote['questions'] );
	}

	public function test_reserve_holds_the_places_then_opens_an_order_for_the_amount_bokun_gives(): void {
		$out = $this->service()->reserve( '60870', self::INPUT );

		self::assertSame( [ 'POST /checkout.json/options/booking-request', 'POST /checkout.json/submit', 'POST /api/orders' ], $this->trail() );
		$submit = $this->calls[1][2];
		self::assertSame( [ 'AGENT_RESELLER', 'RESERVE_FOR_EXTERNAL_PAYMENT', 'DIRECT_REQUEST', false ], [ $submit['checkoutOption'], $submit['paymentMethod'], $submit['source'], $submit['sendNotificationToMainContact'] ] );
		$booking = $submit['directBooking']['activityBookings'][0];
		self::assertSame( [ [ 'pricingCategoryId' => 87695 ], [ 'pricingCategoryId' => 87695 ] ], $booking['passengers'] );
		self::assertSame( [ 60870, 92027, '2026-12-03', 166782 ], [ $booking['activityId'], $booking['rateId'], $booking['date'], $booking['startTimeId'] ] );
		self::assertSame( [ [ 'questionId' => '130041', 'values' => [ 'false' ] ], [ 'questionId' => '1181526', 'values' => [ 'true' ] ] ], $booking['answers'] );
		self::assertSame( [ 'Joe', 'Dole', 'doedole@youdole.com', '+441316290189' ], array_map( static fn( $a ) => $a['values'][0], $submit['directBooking']['mainContactDetails'] ) );

		$order = $this->calls[2][2];
		self::assertSame( [ 48800, 'GBP', 'TAS-1' ], [ $order['amount'], $order['currency'], $order['merchant_order_data']['reference'] ] );
		self::assertSame( [ 'tok-1', 488.0, 'GBP', 'TAS-1', self::NOW + 1800 ], [ $out['order_token'], $out['amount'], $out['currency'], $out['confirmation_code'], $out['expires_at'] ] );
		self::assertSame( 1, preg_match( '/^[a-f0-9]{40}$/', $out['reference'] ) );
		self::assertSame( 'reserved', $this->store[ $out['reference'] ]['status'] );
	}

	public function test_a_booking_is_confirmed_only_when_the_order_is_paid_and_only_once(): void {
		$service = $this->service();
		$ref     = $service->reserve( '60870', self::INPUT )['reference'];

		self::assertSame( [ 'status' => 'pending' ], $service->complete( $ref ) );
		self::assertNotContains( 'POST /checkout.json/confirm-reserved/TAS-1', $this->trail() );

		$this->order_state = 'completed';
		$done              = $service->complete( $ref );
		self::assertSame( [ 'confirmed', 'TAS-1', 488.0, 'GBP' ], [ $done['status'], $done['confirmation_code'], $done['amount'], $done['currency'] ] );
		$confirm = $this->calls[ count( $this->calls ) - 1 ];
		self::assertSame( 'POST /checkout.json/confirm-reserved/TAS-1', $confirm[0] . ' ' . $confirm[1] );
		self::assertSame( [ 488, 'GBP', 'ord-1', 'visa', '4242', true ], [ $confirm[2]['amount'], $confirm[2]['currency'], $confirm[2]['transactionDetails']['transactionId'], $confirm[2]['transactionDetails']['cardBrand'], $confirm[2]['transactionDetails']['last4'], $confirm[2]['sendNotificationToMainContact'] ] );

		$before = count( $this->calls );
		self::assertSame( 'confirmed', $service->complete( $ref )['status'] );
		self::assertSame( 'confirmed', $service->cancel( $ref )['status'], 'a paid booking is not released by a late cancel' );
		self::assertCount( $before, $this->calls, 'asked again, it answers from its record' );
	}

	public function test_leaving_before_paying_releases_the_places_and_closes_the_order(): void {
		$service = $this->service();
		$ref     = $service->reserve( '60870', self::INPUT )['reference'];
		self::assertSame( [ 'status' => 'failed' ], $service->cancel( $ref ) );
		self::assertSame( [ 'GET /api/orders/ord-1', 'GET /booking.json/TAS-1/abort-reserved', 'POST /api/orders/ord-1/cancel' ], array_slice( $this->trail(), 3 ) );
		self::assertSame( [ 'status' => 'failed' ], $service->complete( $ref ) );
	}

	public function test_a_declined_order_releases_the_places(): void {
		$service           = $this->service();
		$ref               = $service->reserve( '60870', self::INPUT )['reference'];
		$this->order_state = 'failed';
		self::assertSame( [ 'status' => 'failed' ], $service->complete( $ref ) );
		self::assertContains( 'GET /booking.json/TAS-1/abort-reserved', $this->trail() );
	}

	public function test_bad_input_never_reaches_bokun_and_bokuns_refusal_names_the_field(): void {
		$service = $this->service();
		foreach ( [ [ 'date' => 'tomorrow' ], [ 'passengers' => [] ], [ 'passengers' => [ '87695' => 13 ] ], [ 'contact' => [ 'email' => 'not-an-address' ] + self::INPUT['contact'] ], [ 'contact' => [ 'phoneNumber' => ' ' ] + self::INPUT['contact'] ] ] as $bad ) {
			try {
				$service->reserve( '60870', $bad + self::INPUT );
				self::assertTrue( false, 'must be refused: ' . json_encode( $bad ) );
			} catch ( CheckoutException $e ) {
				self::assertSame( 'invalid', $e->reason );
			}
		}
		self::assertSame( [], $this->calls );

		$this->submit_status = 400;
		try {
			$this->service()->reserve( '60870', self::INPUT );
			self::assertTrue( false, 'a refused submit must throw' );
		} catch ( CheckoutException $e ) {
			self::assertSame( 'not_bookable', $e->reason );
		}
		$this->submit_body = '{"message":"Invalid or missing answers","fields":{"errors":[{"reason":"INVALID","path":"/mainContactDetails/3","questionId":"phoneNumber","message":"Not a valid phone number"}]}}';
		$service           = $this->service();
		$this->submit_body = '{"message":"Invalid or missing answers","fields":{"errors":[{"reason":"INVALID","path":"/mainContactDetails/3","questionId":"phoneNumber","message":"Not a valid phone number"}]}}';
		try {
			$service->reserve( '60870', self::INPUT );
			self::assertTrue( false, 'a refused answer must throw' );
		} catch ( CheckoutException $e ) {
			self::assertSame( [ 'invalid', 'phoneNumber', 'Not a valid phone number', 422 ], [ $e->reason, $e->field, $e->getMessage(), $e->getCode() ] );
		}
		self::assertNotContains( 'POST /api/orders', $this->trail(), 'no order without a reservation' );

		try {
			$service->complete( str_repeat( 'a', 40 ) );
			self::assertTrue( false, 'an unknown reference must throw' );
		} catch ( CheckoutException $e ) {
			self::assertSame( 'expired', $e->reason );
		}
	}
}
