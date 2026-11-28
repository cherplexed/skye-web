<?php
declare(strict_types=1);

namespace Skye\Checkout;

use Skye\Bokun\BokunException;
use Skye\Bokun\Client as Bokun;
use Skye\Revolut\Client as Revolut;
use Skye\Revolut\RevolutException;

/**
 * Our own checkout (issue #33): Bókun holds the places, Revolut takes the money, and a booking
 * is confirmed only once the order is paid.
 *
 *   calendar → reserve (Bókun RESERVE_FOR_EXTERNAL_PAYMENT + Revolut order) → pay on the page
 *   → complete (Revolut order completed → Bókun confirm-reserved)   or   cancel (release both)
 *
 * The amount charged is always the one Bókun gives for the reservation, never one sent by the
 * page. Pure: the two clients, the store and the clock are passed in.
 */
final class Service {

	public const METHOD      = 'RESERVE_FOR_EXTERNAL_PAYMENT';
	public const HOLD        = 1800;  // Bókun keeps a reservation for at most 30 minutes.
	public const WINDOW_DAYS = 150;
	public const MAX_PEOPLE  = 12;
	public const CONTACT     = [ 'firstName', 'lastName', 'email', 'phoneNumber' ];

	/** @var callable(string): ?array */
	private $load;
	/** @var callable(string, array, int): void */
	private $save;
	/** @var callable(): int */
	private $now;

	public function __construct( private readonly Bokun $bokun, private readonly Revolut $revolut, callable $load, callable $save, ?callable $now = null ) {
		$this->load = $load;
		$this->save = $save;
		$this->now  = $now ?? 'time';
	}

	/**
	 * What can be booked: the ways to travel (Bókun's pricing categories), the questions of the
	 * booking and every open departure with its price per category.
	 *
	 * @return array<string, mixed>
	 * @throws CheckoutException
	 */
	public function calendar( string $product_id ): array {
		$now = ( $this->now )();
		try {
			$activity = $this->bokun->activity( $product_id );
			$days     = $this->bokun->availabilities( $product_id, gmdate( 'Y-m-d', $now ), gmdate( 'Y-m-d', $now + self::WINDOW_DAYS * 86400 ) );
		} catch ( BokunException $e ) {
			throw new CheckoutException( 'unavailable', $e->getMessage(), '', 502, $e );
		}
		$rate_id = (int) ( $activity['defaultRateId'] ?? ( $activity['rates'][0]['id'] ?? 0 ) );
		$allowed = [];
		foreach ( (array) ( $activity['rates'] ?? [] ) as $rate ) {
			if ( (int) ( $rate['id'] ?? 0 ) === $rate_id ) {
				$allowed = array_map( 'intval', (array) ( $rate['pricingCategoryIds'] ?? [] ) );
			}
		}
		$categories = [];
		foreach ( (array) ( $activity['pricingCategories'] ?? [] ) as $c ) {
			$id = (int) ( $c['id'] ?? 0 );
			if ( $id && ( ! $allowed || in_array( $id, $allowed, true ) ) && empty( $c['internalUseOnly'] ) ) {
				$categories[ $id ] = [ 'id' => $id, 'title' => (string) ( $c['title'] ?? $c['fullTitle'] ?? '' ) ];
			}
		}
		if ( $allowed ) {
			// The order of the rate is the order the operator wants them offered in.
			$categories = array_replace( array_intersect_key( array_flip( $allowed ), $categories ), $categories );
		}
		$questions = [];
		foreach ( (array) ( $activity['bookingQuestions'] ?? [] ) as $q ) {
			if ( 'BOOKING' === ( $q['context'] ?? 'BOOKING' ) && isset( $q['id'] ) ) {
				$questions[] = [ 'id' => (string) $q['id'], 'label' => (string) ( $q['label'] ?? '' ), 'help' => (string) ( $q['help'] ?? '' ), 'type' => (string) ( $q['dataType'] ?? 'SHORT_TEXT' ), 'required' => ! empty( $q['required'] ) ];
			}
		}
		$cutoff   = max( 0, (int) ( $activity['bookingCutoff'] ?? 0 ) ) * 60;
		$zone     = new \DateTimeZone( (string) ( $activity['timeZone'] ?? 'Europe/London' ) );
		$dates    = [];
		$currency = '';
		foreach ( $days as $a ) {
			if ( ! is_array( $a ) || ! empty( $a['soldOut'] ) || ! empty( $a['unavailable'] ) ) {
				continue;
			}
			$seats = ! empty( $a['unlimitedAvailability'] ) ? self::MAX_PEOPLE : (int) ( $a['availabilityCount'] ?? 0 );
			$date  = gmdate( 'Y-m-d', (int) ( ( (float) ( $a['date'] ?? 0 ) ) / 1000 ) );
			$time  = 1 === preg_match( '/^\d{1,2}:\d{2}$/', (string) ( $a['startTime'] ?? '' ) ) ? (string) $a['startTime'] : '00:00';
			$start = ( new \DateTimeImmutable( "$date $time", $zone ) )->getTimestamp();
			if ( $seats < 1 || $start - $cutoff <= $now ) {
				continue;
			}
			$prices = [];
			foreach ( (array) ( $a['pricesByRate'] ?? [] ) as $by_rate ) {
				if ( (int) ( $by_rate['activityRateId'] ?? 0 ) !== $rate_id ) {
					continue;
				}
				foreach ( (array) ( $by_rate['pricePerCategoryUnit'] ?? [] ) as $unit ) {
					$id = (int) ( $unit['id'] ?? 0 );
					if ( isset( $categories[ $id ], $unit['amount']['amount'] ) ) {
						$prices[ $id ] = (float) $unit['amount']['amount'];
						$currency      = (string) ( $unit['amount']['currency'] ?? $currency );
					}
				}
			}
			if ( $prices ) {
				$dates[] = [ 'date' => $date, 'time' => $time, 'start_time_id' => (int) ( $a['startTimeId'] ?? 0 ), 'seats' => min( $seats, 99 ), 'prices' => $prices ];
			}
		}
		usort( $dates, static fn( array $a, array $b ) => strcmp( $a['date'], $b['date'] ) );
		return [ 'product_id' => $product_id, 'rate_id' => $rate_id, 'currency' => $currency, 'categories' => array_values( $categories ), 'questions' => $questions, 'dates' => $dates ];
	}

	/**
	 * Holds the places and opens the payment.
	 *
	 * @param array{date:string, start_time_id?:int, rate_id?:int, passengers:array<int|string,int>, contact:array<string,string>, answers?:array<string,mixed>, description?:string} $in
	 * @return array{reference:string, order_token:string, amount:float, currency:string, confirmation_code:string, expires_at:int}
	 * @throws CheckoutException
	 */
	public function reserve( string $product_id, array $in ): array {
		if ( 1 !== preg_match( '/^\d{4}-\d{2}-\d{2}$/', (string) ( $in['date'] ?? '' ) ) ) {
			throw new CheckoutException( 'invalid', 'Choose a date', 'date' );
		}
		$passengers = [];
		foreach ( (array) ( $in['passengers'] ?? [] ) as $category => $count ) {
			for ( $i = 0; $i < max( 0, (int) $count ); $i++ ) {
				$passengers[] = [ 'pricingCategoryId' => (int) $category ];
			}
		}
		if ( ! $passengers || count( $passengers ) > self::MAX_PEOPLE ) {
			throw new CheckoutException( 'invalid', 'Choose between 1 and ' . self::MAX_PEOPLE . ' travellers', 'passengers' );
		}
		$contact = [];
		foreach ( self::CONTACT as $field ) {
			$value = trim( (string) ( $in['contact'][ $field ] ?? '' ) );
			if ( '' === $value || ( 'email' === $field && false === filter_var( $value, FILTER_VALIDATE_EMAIL ) ) ) {
				throw new CheckoutException( 'invalid', 'This field is required', $field );
			}
			$contact[] = [ 'questionId' => $field, 'values' => [ 'phoneNumber' === $field ? (string) preg_replace( '/[^0-9+]/', '', $value ) : mb_substr( $value, 0, 120 ) ] ];
		}
		$answers = [];
		foreach ( (array) ( $in['answers'] ?? [] ) as $id => $value ) {
			$value = is_bool( $value ) ? ( $value ? 'true' : 'false' ) : trim( (string) $value );
			if ( '' !== $value && 1 === preg_match( '/^[A-Za-z0-9_-]{1,40}$/', (string) $id ) ) {
				$answers[] = [ 'questionId' => (string) $id, 'values' => [ mb_substr( $value, 0, 1000 ) ] ];
			}
		}
		$booking = array_filter(
			[
				'activityId'  => (int) $product_id,
				'rateId'      => (int) ( $in['rate_id'] ?? 0 ),
				'date'        => (string) $in['date'],
				'startTimeId' => (int) ( $in['start_time_id'] ?? 0 ),
				'passengers'  => $passengers,
				'answers'     => $answers,
			]
		);
		$request = [ 'mainContactDetails' => $contact, 'activityBookings' => [ $booking ] ];

		try {
			$options = $this->bokun->post( '/checkout.json/options/booking-request', $request );
			$option  = null;
			foreach ( (array) ( $options['options'] ?? [] ) as $o ) {
				if ( in_array( self::METHOD, (array) ( $o['paymentMethods']['allowedMethods'] ?? [] ), true ) ) {
					$option = (string) ( $o['type'] ?? '' );
					break;
				}
			}
			if ( ! $option ) {
				throw new CheckoutException( 'unavailable', 'This booking channel does not allow reserving for external payment', '', 502 );
			}
			$res = $this->bokun->post(
				'/checkout.json/submit',
				[ 'checkoutOption' => $option, 'paymentMethod' => self::METHOD, 'source' => 'DIRECT_REQUEST', 'directBooking' => $request, 'sendNotificationToMainContact' => false ]
			);
		} catch ( BokunException $e ) {
			throw self::from_bokun( $e );
		}
		$code     = (string) ( $res['booking']['confirmationCode'] ?? '' );
		$amount   = (float) ( $res['booking']['totalDue'] ?? $res['booking']['totalPrice'] ?? 0 );
		$currency = strtoupper( (string) ( $res['booking']['currency'] ?? '' ) );
		if ( '' === $code || $amount <= 0 || '' === $currency ) {
			if ( '' !== $code ) {
				$this->release_booking( $code );
			}
			throw new CheckoutException( 'unavailable', 'Bókun did not return a reservation to pay for', '', 502 );
		}

		$email = (string) $contact[2]['values'][0];
		$name  = trim( $contact[0]['values'][0] . ' ' . $contact[1]['values'][0] );
		try {
			$order = $this->revolut->create_order(
				Revolut::minor_units( $amount ),
				$currency,
				[
					'description'         => mb_substr( (string) ( $in['description'] ?? 'Skye tour' ), 0, 200 ) . ' · ' . $code,
					'customer'            => [ 'email' => $email, 'full_name' => $name ],
					'merchant_order_data' => [ 'reference' => $code ],
					'metadata'            => [ 'bokun_confirmation_code' => $code, 'bokun_product_id' => $product_id ],
				]
			);
		} catch ( RevolutException $e ) {
			$this->release_booking( $code );
			throw new CheckoutException( 'payment_unavailable', $e->getMessage(), '', 502, $e );
		}
		if ( empty( $order['id'] ) || empty( $order['token'] ) ) {
			$this->release_booking( $code );
			throw new CheckoutException( 'payment_unavailable', 'Revolut did not return an order', '', 502 );
		}

		$reference = bin2hex( random_bytes( 20 ) );
		$expires   = ( $this->now )() + self::HOLD;
		( $this->save )(
			$reference,
			[ 'status' => 'reserved', 'code' => $code, 'order_id' => (string) $order['id'], 'amount' => $amount, 'currency' => $currency, 'expires_at' => $expires, 'date' => (string) $in['date'], 'name' => $name, 'email' => $email ],
			self::HOLD + 600
		);
		return [ 'reference' => $reference, 'order_token' => (string) $order['token'], 'amount' => $amount, 'currency' => $currency, 'confirmation_code' => $code, 'expires_at' => $expires ];
	}

	/**
	 * After the page says the payment went through: checks it with Revolut and, only if the order
	 * is paid, confirms the booking in Bókun. Safe to call again.
	 *
	 * @return array{status:string, confirmation_code?:string, amount?:float, currency?:string, date?:string, email?:string}
	 * @throws CheckoutException
	 */
	public function complete( string $reference ): array {
		$r = $this->record( $reference );
		if ( in_array( $r['status'], [ 'confirmed', 'paid_unconfirmed', 'failed' ], true ) ) {
			return self::outcome( $r );
		}
		try {
			$order = $this->revolut->order( $r['order_id'] );
		} catch ( RevolutException $e ) {
			throw new CheckoutException( 'payment_unavailable', $e->getMessage(), '', 502, $e );
		}
		$state = strtolower( (string) ( $order['state'] ?? '' ) );
		if ( in_array( $state, [ 'cancelled', 'failed', 'declined' ], true ) ) {
			$this->release_booking( $r['code'] );
			$r['status'] = 'failed';
			( $this->save )( $reference, $r, 3600 );
			return self::outcome( $r );
		}
		if ( 'completed' !== $state ) {
			return [ 'status' => 'pending' ];
		}
		$payment = is_array( $order['payments'][0] ?? null ) ? $order['payments'][0] : [];
		$card    = is_array( $payment['payment_method'] ?? null ) ? $payment['payment_method'] : [];
		$details = array_filter(
			[
				'transactionId'   => $r['order_id'],
				'transactionDate' => gmdate( 'Y-m-d H:i:s', ( $this->now )() ),
				'cardBrand'       => (string) ( $card['card_brand'] ?? '' ),
				'last4'           => (string) ( $card['card_last_four'] ?? '' ),
			]
		);
		try {
			$this->bokun->post(
				'/checkout.json/confirm-reserved/' . rawurlencode( $r['code'] ),
				[ 'amount' => $r['amount'], 'currency' => $r['currency'], 'transactionDetails' => $details, 'externalBookingReference' => $r['order_id'], 'sendNotificationToMainContact' => true, 'showPricesInNotification' => true ]
			);
			$r['status'] = 'confirmed';
		} catch ( BokunException $e ) {
			// The customer has paid and the booking is not confirmed: someone has to finish it by hand.
			$r['status'] = 'paid_unconfirmed';
			$r['error']  = $e->getMessage();
		}
		( $this->save )( $reference, $r, 7 * 86400 );
		return self::outcome( $r );
	}

	/** The customer left before paying: frees the places and closes the order. Does nothing once paid. */
	public function cancel( string $reference ): array {
		$r = $this->record( $reference );
		if ( 'reserved' !== $r['status'] ) {
			return self::outcome( $r );
		}
		try {
			$state = strtolower( (string) ( $this->revolut->order( $r['order_id'] )['state'] ?? '' ) );
		} catch ( RevolutException $e ) {
			$state = '';
		}
		if ( in_array( $state, [ 'completed', 'authorised', 'processing' ], true ) ) {
			return $this->complete( $reference );
		}
		$this->release_booking( $r['code'] );
		try {
			$this->revolut->cancel_order( $r['order_id'] );
		} catch ( RevolutException $e ) {
			unset( $e ); // An unpaid order expires by itself.
		}
		$r['status'] = 'failed';
		( $this->save )( $reference, $r, 3600 );
		return self::outcome( $r );
	}

	/** @return array<string, mixed> */
	private function record( string $reference ): array {
		$r = 1 === preg_match( '/^[a-f0-9]{40}$/', $reference ) ? ( $this->load )( $reference ) : null;
		if ( ! is_array( $r ) || empty( $r['code'] ) || empty( $r['order_id'] ) ) {
			throw new CheckoutException( 'expired', 'This reservation no longer exists', '', 404 );
		}
		return $r;
	}

	private function release_booking( string $code ): void {
		try {
			$this->bokun->get( '/booking.json/' . rawurlencode( $code ) . '/abort-reserved' );
		} catch ( BokunException $e ) {
			unset( $e ); // Bókun releases an unpaid reservation by itself within 30 minutes.
		}
	}

	/** @param array<string, mixed> $r */
	private static function outcome( array $r ): array {
		$out = [ 'status' => (string) $r['status'] ];
		if ( 'failed' !== $r['status'] ) {
			$out += [ 'confirmation_code' => (string) $r['code'], 'amount' => (float) $r['amount'], 'currency' => (string) $r['currency'], 'date' => (string) ( $r['date'] ?? '' ), 'email' => (string) ( $r['email'] ?? '' ) ];
		}
		return $out;
	}

	/** Bókun's refusal of an answer becomes an error on that field; anything else is "try again". */
	private static function from_bokun( BokunException $e ): CheckoutException {
		$message = $e->getMessage();
		if ( 400 === $e->getCode() && 1 === preg_match( '/"questionId":"([A-Za-z0-9_-]+)","message":"([^"]{1,160})"/', $message, $m ) ) {
			return new CheckoutException( 'invalid', $m[2], $m[1], 422, $e );
		}
		if ( 400 === $e->getCode() || 422 === $e->getCode() ) {
			return new CheckoutException( 'not_bookable', $message, '', 409, $e );
		}
		return new CheckoutException( 'unavailable', $message, '', 502, $e );
	}
}
