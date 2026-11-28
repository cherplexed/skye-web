<?php
declare(strict_types=1);

namespace Skye\Cli;

use Skye\Bokun\BokunException;
use Skye\Bokun\Client as Bokun;
use Skye\Config;
use Skye\Meta;
use Skye\PostTypes;
use Skye\Revolut\Client as Revolut;
use Skye\Revolut\RevolutException;
use WP_CLI;

/**
 * Checks and discovery for our own checkout (issue #33). Nothing here prints a key, and nothing
 * creates a booking: `options` only asks Bókun what a booking would need and cost.
 */
final class Checkout {

	/** Name and address every test booking is made under, so they are easy to find and cancel. */
	// The phone number is from the range Ofcom reserves for fiction (07700 900xxx): it belongs to nobody.
	public const TEST_CONTACT = [ 'firstName' => 'Joe', 'lastName' => 'Dole', 'email' => 'doedole@youdole.com', 'phoneNumber' => '+447700900123' ];

	/**
	 * The shape of a value without its bulk: every key, one sample of each list, strings cut short.
	 *
	 * @return list<string> Lines "path: sample".
	 */
	public static function outline( mixed $value, string $path = '', int $depth = 0, int $max_depth = 5 ): array {
		if ( is_array( $value ) ) {
			if ( ! $value ) {
				return [ ( '' === $path ? '(root)' : $path ) . ': ' . '[]' ];
			}
			if ( $depth >= $max_depth ) {
				return [ $path . ': ' . ( array_is_list( $value ) ? 'list of ' . count( $value ) : '{' . implode( ', ', array_slice( array_map( 'strval', array_keys( $value ) ), 0, 12 ) ) . '}' ) ];
			}
			if ( array_is_list( $value ) ) {
				return self::outline( $value[0], $path . '[' . count( $value ) . ']', $depth + 1, $max_depth );
			}
			$lines = [];
			foreach ( $value as $key => $item ) {
				array_push( $lines, ...self::outline( $item, '' === $path ? (string) $key : $path . '.' . $key, $depth + 1, $max_depth ) );
			}
			return $lines;
		}
		$sample = is_string( $value ) ? '"' . mb_substr( trim( (string) preg_replace( '/\s+/', ' ', wp_strip_all_tags( $value ) ) ), 0, 70 ) . '"' : (string) json_encode( $value );
		return [ $path . ': ' . $sample ];
	}

	/**
	 * What kind of key a value looks like, without showing any of it: the environment its prefix
	 * names, its length, and whether it was pasted with quotes or spaces.
	 */
	public static function key_kind( string $key ): string {
		if ( '' === $key ) {
			return 'missing';
		}
		$head = strtolower( substr( $key, 0, 12 ) );
		$type = str_contains( $head, 'prod' ) || str_contains( $head, 'live' ) ? 'a production key' : ( str_contains( $head, 'sand' ) || str_contains( $head, 'test' ) ? 'a sandbox key' : 'a key of unknown type' );
		return sprintf( '%s, %d characters%s', $type, strlen( $key ), 1 === preg_match( '/\s|["\']/', $key ) ? ', WITH spaces or quotes inside' : '' );
	}

	/** @return list<array{tour:string, brand:string, product_id:string}> The Bókun products of the tours, default language. */
	private static function products(): array {
		$out = [];
		foreach ( get_posts( [ 'post_type' => PostTypes::TOUR, 'post_status' => 'publish', 'posts_per_page' => 20, 'orderby' => 'title', 'order' => 'ASC', 'suppress_filters' => true ] ) as $tour ) {
			foreach ( (array) Meta::get( $tour->ID, 'products', [] ) as $row ) {
				$id = is_array( $row ) ? trim( (string) ( $row['bokun_product_id'] ?? '' ) ) : '';
				if ( '' === $id || isset( $out[ $id ] ) ) {
					continue;
				}
				$brand      = is_array( $row['brand'] ?? null ) ? (int) ( $row['brand'][0] ?? 0 ) : (int) ( $row['brand'] ?? 0 );
				$out[ $id ] = [ 'tour' => $tour->post_name, 'brand' => $brand ? (string) get_post_field( 'post_name', $brand ) : '?', 'product_id' => $id ];
			}
		}
		return array_values( $out );
	}

	/**
	 * Checks that Bókun and Revolut answer with the configured keys, and lists the products the checkout will sell.
	 *
	 * In sandbox it creates a Revolut order of 1.00 GBP and cancels it at once. It never creates a booking.
	 *
	 * ## EXAMPLES
	 *     wp skye checkout doctor
	 */
	public function doctor(): void {
		$bokun = Bokun::from_config();
		if ( ! $bokun ) {
			WP_CLI::warning( 'Bókun: not configured (BOKUN_ACCESS_KEY, BOKUN_SECRET_KEY).' );
		} else {
			$base = Config::get( 'BOKUN_BASE_URL' );
			WP_CLI::log( 'Bókun: keys present · ' . ( '' === $base || str_contains( $base, 'api.bokun.io' ) ? 'PRODUCTION (bookings take real seats)' : 'test environment' ) );
			$products = self::products();
			WP_CLI::log( sprintf( 'Bókun: %d products on the tours', count( $products ) ) );
			foreach ( $products as $p ) {
				try {
					$a = $bokun->activity( $p['product_id'] );
				} catch ( BokunException $e ) {
					// Prices can be readable where the product itself is not: then the key's booking channel does not carry it.
					try {
						$days = $bokun->availabilities( $p['product_id'], gmdate( 'Y-m-d' ), gmdate( 'Y-m-d', time() + 30 * DAY_IN_SECONDS ) );
						$hint = sprintf( ' · availability answers (%d departures in 30 days), so the product exists but this key cannot read its details', count( $days ) );
					} catch ( BokunException $e2 ) {
						$hint = ' · availability fails too: ' . $e2->getMessage();
					}
					WP_CLI::warning( "  {$p['product_id']} ({$p['tour']} / {$p['brand']}): " . $e->getMessage() . $hint );
					continue;
				}
				WP_CLI::log( sprintf( '  %s  %s / %s  "%s"  bookingType=%s', $p['product_id'], $p['tour'], $p['brand'], (string) ( $a['title'] ?? '?' ), (string) ( $a['bookingType'] ?? '?' ) ) );
				foreach ( (array) ( $a['rates'] ?? [] ) as $rate ) {
					WP_CLI::log( sprintf( '      rate %s "%s" categories=%s', (string) ( $rate['id'] ?? '?' ), (string) ( $rate['title'] ?? '' ), (string) json_encode( $rate['pricingCategoryIds'] ?? null ) ) );
				}
				foreach ( (array) ( $a['pricingCategories'] ?? [] ) as $c ) {
					WP_CLI::log( sprintf( '      category %s "%s" ages %s-%s', (string) ( $c['id'] ?? '?' ), (string) ( $c['title'] ?? $c['fullTitle'] ?? '' ), (string) ( $c['minAge'] ?? '?' ), (string) ( $c['maxAge'] ?? '?' ) ) );
				}
				foreach ( (array) ( $a['startTimes'] ?? [] ) as $s ) {
					WP_CLI::log( sprintf( '      start time %s  %02d:%02d', (string) ( $s['id'] ?? '?' ), (int) ( $s['hour'] ?? 0 ), (int) ( $s['minute'] ?? 0 ) ) );
				}
			}
		}

		$env    = Revolut::environment();
		$public = '' !== Config::get( 'REVOLUT_PUBLIC_KEY' );
		$client = Revolut::from_config();
		WP_CLI::log( sprintf( 'Revolut: environment=%s · public key %s · secret key %s', $env, $public ? 'present' : 'MISSING', $client ? 'present' : 'MISSING' ) );
		foreach ( [ 'public' => 'REVOLUT_PUBLIC_KEY', 'secret' => 'REVOLUT_SECRET_KEY' ] as $which => $name ) {
			$kind = self::key_kind( Config::get( $name ) );
			WP_CLI::log( "Revolut: the $which key is $kind" );
			if ( 'sandbox' === $env && str_starts_with( $kind, 'a production' ) ) {
				WP_CLI::warning( "Revolut: the $which key is a production key but REVOLUT_ENV is sandbox. The sandbox refuses production keys." );
			}
		}
		if ( $public && Config::get( 'REVOLUT_PUBLIC_KEY' ) === Config::get( 'REVOLUT_SECRET_KEY' ) ) {
			WP_CLI::warning( 'Revolut: the public and the secret key are the same value. They are two different keys.' );
		}
		if ( ! $client ) {
			WP_CLI::warning( 'Revolut: set REVOLUT_SECRET_KEY and REVOLUT_PUBLIC_KEY in .env and restart the stack.' );
			return;
		}
		if ( 'sandbox' !== $env ) {
			WP_CLI::warning( 'Revolut: production keys. The doctor does not create test orders outside the sandbox.' );
			return;
		}
		try {
			$order = $client->create_order( 100, 'GBP', [ 'description' => 'skye-web checkout doctor' ] );
			WP_CLI::log( sprintf( 'Revolut: test order created · state=%s · token %s', (string) ( $order['state'] ?? '?' ), empty( $order['token'] ) ? 'MISSING' : 'returned' ) );
			$cancelled = $client->cancel_order( (string) ( $order['id'] ?? '' ) );
			WP_CLI::success( 'Revolut: test order cancelled · state=' . (string) ( $cancelled['state'] ?? '?' ) );
		} catch ( RevolutException $e ) {
			WP_CLI::warning( $e->getMessage() );
			if ( 401 === $e->getCode() || 403 === $e->getCode() ) {
				self::probe_revolut();
			}
		}
	}

	/**
	 * A refused key is usually the right key in the wrong place. Asks both Revolut hosts to list one
	 * order with each of the two keys (a read; nothing is created) and says which pair is accepted.
	 */
	private static function probe_revolut(): void {
		WP_CLI::log( 'Revolut: trying each key against each environment (read-only) to find the pair that is accepted…' );
		$found = false;
		foreach ( [ 'secret' => 'REVOLUT_SECRET_KEY', 'public' => 'REVOLUT_PUBLIC_KEY' ] as $which => $name ) {
			$key = Config::get( $name );
			if ( '' === $key ) {
				continue;
			}
			foreach ( [ 'sandbox' => Revolut::SANDBOX, 'production' => Revolut::PRODUCTION ] as $env => $host ) {
				try {
					( new Revolut( $key, $host ) )->orders( 1 );
					$found = true;
					WP_CLI::log( "  the $which key at $env: ACCEPTED" );
				} catch ( RevolutException $e ) {
					WP_CLI::log( "  the $which key at $env: " . $e->getMessage() );
				}
			}
		}
		if ( ! $found ) {
			WP_CLI::warning( 'Revolut: neither key is accepted anywhere. Create a new API key with the Merchant permission in the API Console, in Sandbox, and copy it again.' );
		}
	}

	/**
	 * Prints the shape of a Bókun product and of its next departures: what the checkout has to offer and ask.
	 *
	 * ## OPTIONS
	 * <product_id>
	 * : Bókun product (activity) ID.
	 *
	 * [--currency=<code>]
	 * : Default GBP.
	 *
	 * [--depth=<n>]
	 * : How deep to print nested data. Default 4.
	 *
	 * ## EXAMPLES
	 *     wp skye checkout product 60870 --currency=EUR
	 */
	public function product( array $args, array $assoc ): void {
		$bokun = Bokun::from_config();
		if ( ! $bokun ) {
			WP_CLI::error( 'Bókun is not configured.' );
		}
		$currency = strtoupper( (string) ( $assoc['currency'] ?? 'GBP' ) );
		$depth    = max( 1, (int) ( $assoc['depth'] ?? 4 ) );
		try {
			$activity = $bokun->activity( (string) $args[0], $currency );
			$days     = $bokun->availabilities( (string) $args[0], gmdate( 'Y-m-d' ), gmdate( 'Y-m-d', time() + 60 * DAY_IN_SECONDS ), $currency );
		} catch ( BokunException $e ) {
			WP_CLI::error( $e->getMessage() );
		}
		// Long texts and photos say nothing about how to book.
		foreach ( [ 'description', 'excerpt', 'photos', 'keyPhoto', 'videos', 'agendaItems', 'included', 'excluded', 'requirements', 'attention', 'knowBeforeYouGoItems', 'googlePlace', 'tripadvisorReview', 'vendor' ] as $skip ) {
			unset( $activity[ $skip ] );
		}
		WP_CLI::log( '== PRODUCT ==' );
		WP_CLI::log( implode( "\n", self::outline( $activity, '', 0, $depth ) ) );
		WP_CLI::log( sprintf( '== AVAILABILITY == %d departures in the next 60 days; the first one:', count( $days ) ) );
		WP_CLI::log( $days ? implode( "\n", self::outline( $days[0], '', 0, $depth + 1 ) ) : '(none)' );
	}

	/** The Booking Request both commands send: one traveller, the test contact. */
	private static function booking_request( array $args, array $assoc ): array {
		$booking = array_filter(
			[
				'activityId'  => (int) $args[0],
				'rateId'      => isset( $assoc['rate'] ) ? (int) $assoc['rate'] : null,
				'date'        => (string) $args[1],
				'startTimeId' => isset( $assoc['start-time'] ) ? (int) $assoc['start-time'] : null,
				'passengers'  => [ [ 'pricingCategoryId' => (int) $assoc['category'] ] ],
			],
			static fn( $v ) => null !== $v
		);
		$contact = [];
		foreach ( self::TEST_CONTACT as $question => $value ) {
			if ( 'phoneNumber' === $question && ! empty( $assoc['phone'] ) ) {
				$value = (string) preg_replace( '/[^0-9+]/', '', (string) $assoc['phone'] );
			}
			$contact[] = [ 'questionId' => $question, 'values' => [ $value ] ];
		}
		return [ 'mainContactDetails' => $contact, 'activityBookings' => [ $booking ] ];
	}

	/** What the checkout needs from the options answer, in full: ways to pay, amounts and every question. */
	private static function print_options( array $res ): void {
		foreach ( (array) ( $res['options'] ?? [] ) as $o ) {
			WP_CLI::log( sprintf( 'OPTION %s "%s": %s %s · methods=%s · providers=%d', (string) ( $o['type'] ?? '?' ), (string) ( $o['label'] ?? '' ), (string) json_encode( $o['amount'] ?? null ), (string) ( $o['currency'] ?? '' ), (string) json_encode( $o['paymentMethods']['allowedMethods'] ?? null ), count( (array) ( $o['paymentMethods']['paymentProviders'] ?? [] ) ) ) );
			foreach ( (array) ( $o['questions'] ?? [] ) as $q ) {
				WP_CLI::log( '   option question: ' . self::question_line( $q ) );
			}
		}
		foreach ( (array) ( $res['questions']['mainContactDetails'] ?? [] ) as $q ) {
			WP_CLI::log( 'CONTACT question: ' . self::question_line( $q ) );
		}
		foreach ( (array) ( $res['questions']['activityBookings'] ?? [] ) as $b ) {
			foreach ( (array) ( $b['questions'] ?? [] ) as $q ) {
				WP_CLI::log( 'BOOKING question: ' . self::question_line( $q ) );
			}
			foreach ( (array) ( $b['passengers'] ?? [] ) as $p ) {
				WP_CLI::log( sprintf( 'PASSENGER %s "%s" · details=%d · questions=%d · extras=%d', (string) ( $p['pricingCategoryId'] ?? '?' ), (string) ( $p['pricingCategoryTitle'] ?? '' ), count( (array) ( $p['passengerDetails'] ?? [] ) ), count( (array) ( $p['questions'] ?? [] ) ), count( (array) ( $p['extras'] ?? [] ) ) ) );
				foreach ( array_merge( (array) ( $p['passengerDetails'] ?? [] ), (array) ( $p['questions'] ?? [] ) ) as $q ) {
					WP_CLI::log( '   passenger question: ' . self::question_line( $q ) );
				}
			}
		}
	}

	private static function question_line( mixed $q ): string {
		if ( ! is_array( $q ) ) {
			return (string) json_encode( $q );
		}
		$options = array_map( static fn( $o ) => is_array( $o ) ? (string) ( $o['value'] ?? $o['label'] ?? '' ) : (string) $o, (array) ( $q['answerOptions'] ?? $q['options'] ?? [] ) );
		return sprintf( '%s "%s" %s%s%s', (string) ( $q['questionId'] ?? $q['id'] ?? '?' ), (string) ( $q['label'] ?? '' ), (string) ( $q['dataType'] ?? '' ), ! empty( $q['required'] ) ? ' REQUIRED' : '', $options ? ' options=' . json_encode( array_slice( $options, 0, 12 ), JSON_UNESCAPED_UNICODE ) : '' );
	}

	/**
	 * Reserves one place for the test traveller the way the checkout will, prints what Bókun answers and releases it at once.
	 *
	 * This is the one command that touches real seats: the place is held for a few seconds, as
	 * Joe Dole, with no notification sent. It stops before reserving if Bókun does not offer
	 * "reserve for external payment" on this channel.
	 *
	 * ## OPTIONS
	 * <product_id>
	 * : Bókun product (activity) ID.
	 *
	 * <date>
	 * : Departure date, YYYY-MM-DD.
	 *
	 * --category=<id>
	 * : Pricing category ID of the one traveller.
	 *
	 * [--rate=<id>]
	 * : Rate ID.
	 *
	 * [--start-time=<id>]
	 * : Start time ID.
	 *
	 * [--currency=<code>]
	 * : Default GBP.
	 *
	 * [--phone=<number>]
	 * : Phone number of the test traveller, in international format (+34600000000), if Bókun rejects the default one.
	 *
	 * [--keep]
	 * : Do not release the reservation (it expires by itself after at most 30 minutes).
	 *
	 * @subcommand reserve-test
	 */
	public function reserve_test( array $args, array $assoc ): void {
		$bokun = Bokun::from_config();
		if ( ! $bokun ) {
			WP_CLI::error( 'Bókun is not configured.' );
		}
		if ( 1 !== preg_match( '/^\d{4}-\d{2}-\d{2}$/', (string) ( $args[1] ?? '' ) ) ) {
			WP_CLI::error( 'The date must be YYYY-MM-DD.' );
		}
		$request  = self::booking_request( $args, $assoc );
		$currency = strtoupper( (string) ( $assoc['currency'] ?? 'GBP' ) );
		$method   = 'RESERVE_FOR_EXTERNAL_PAYMENT';
		try {
			$options = $bokun->post( '/checkout.json/options/booking-request', $request, [ 'currency' => $currency ] );
		} catch ( BokunException $e ) {
			WP_CLI::error( 'Options: ' . $e->getMessage() );
		}
		self::print_options( $options );
		$option = null;
		foreach ( (array) ( $options['options'] ?? [] ) as $o ) {
			if ( in_array( $method, (array) ( $o['paymentMethods']['allowedMethods'] ?? [] ), true ) ) {
				$option = $o;
				break;
			}
		}
		if ( ! $option ) {
			WP_CLI::error( "Bókun does not offer $method on this booking channel, so nothing was reserved. The methods it offers are listed above." );
		}
		$missing = [];
		foreach ( (array) ( $options['questions']['mainContactDetails'] ?? [] ) as $q ) {
			if ( ! empty( $q['required'] ) && ! isset( self::TEST_CONTACT[ (string) ( $q['questionId'] ?? '' ) ] ) ) {
				$missing[] = (string) ( $q['questionId'] ?? '?' );
			}
		}
		if ( $missing ) {
			WP_CLI::error( 'Bókun requires contact answers the test traveller does not have (' . implode( ', ', $missing ) . '), so nothing was reserved.' );
		}
		// Required questions of the booking itself: a yes/no gets "no" (no children), a box to tick gets ticked.
		$answers = [];
		foreach ( (array) ( $options['questions']['activityBookings'][0]['questions'] ?? [] ) as $q ) {
			if ( empty( $q['required'] ) ) {
				continue;
			}
			$type = (string) ( $q['dataType'] ?? '' );
			if ( ! in_array( $type, [ 'BOOLEAN', 'CHECKBOX_TOGGLE' ], true ) ) {
				WP_CLI::error( sprintf( 'Bókun requires an answer to "%s" (%s) that the test cannot make up, so nothing was reserved.', (string) ( $q['label'] ?? '' ), $type ) );
			}
			$answers[] = [ 'questionId' => (string) $q['questionId'], 'values' => [ 'BOOLEAN' === $type ? 'false' : 'true' ] ];
		}
		if ( $answers ) {
			$request['activityBookings'][0]['answers'] = $answers;
		}
		$body = [
			'checkoutOptionAnswers'         => [ [ 'questionId' => 'sendNotificationToMainContact', 'values' => [ 'false' ] ] ],
			'checkoutOption'                => (string) $option['type'],
			'paymentMethod'                 => $method,
			'source'                        => 'DIRECT_REQUEST',
			'directBooking'                 => $request,
			'sendNotificationToMainContact' => false,
		];
		try {
			$res = $bokun->post( '/checkout.json/submit', $body, [ 'currency' => $currency ] );
		} catch ( BokunException $e ) {
			WP_CLI::error( 'Submit: ' . $e->getMessage() . ' · nothing was reserved.' );
		}
		unset( $res['travelDocuments'] );
		WP_CLI::log( '== RESERVED ==' );
		WP_CLI::log( implode( "\n", self::outline( $res, '', 0, 3 ) ) );
		$code = (string) ( $res['booking']['confirmationCode'] ?? '' );
		if ( '' === $code ) {
			WP_CLI::warning( 'No confirmation code where expected (booking.confirmationCode): find the Joe Dole booking in Bókun and cancel it by hand.' );
			return;
		}
		if ( isset( $assoc['keep'] ) ) {
			WP_CLI::success( "Reserved as $code and kept. It expires by itself; cancel it in Bókun if you do not use it." );
			return;
		}
		try {
			// The API reference (rest-v1) gives this operation as a GET.
			$aborted = $bokun->get( '/booking.json/' . rawurlencode( $code ) . '/abort-reserved' );
			WP_CLI::log( '== RELEASED ==' );
			WP_CLI::log( implode( "\n", self::outline( $aborted, '', 0, 2 ) ) );
			WP_CLI::success( "Reservation $code released." );
		} catch ( BokunException $e ) {
			WP_CLI::warning( "Reserved as $code but the release failed (" . $e->getMessage() . '): cancel the Joe Dole booking in Bókun by hand.' );
		}
	}

	/**
	 * Asks Bókun what a booking would need and cost, without making it: checkout options and questions.
	 *
	 * The request is for the test traveller (Joe Dole). Nothing is reserved.
	 *
	 * ## OPTIONS
	 * <product_id>
	 * : Bókun product (activity) ID.
	 *
	 * <date>
	 * : Departure date, YYYY-MM-DD.
	 *
	 * --category=<id>
	 * : Pricing category ID of the one traveller (see `wp skye checkout doctor`).
	 *
	 * [--rate=<id>]
	 * : Rate ID.
	 *
	 * [--start-time=<id>]
	 * : Start time ID, for products booked by date and time.
	 *
	 * [--currency=<code>]
	 * : Default GBP.
	 *
	 * [--depth=<n>]
	 * : Also print the outline of the whole answer, this deep.
	 *
	 * ## EXAMPLES
	 *     wp skye checkout options 60870 2027-05-14 --category=12345 --rate=678 --start-time=910 --currency=EUR
	 */
	public function options( array $args, array $assoc ): void {
		$bokun = Bokun::from_config();
		if ( ! $bokun ) {
			WP_CLI::error( 'Bókun is not configured.' );
		}
		if ( 1 !== preg_match( '/^\d{4}-\d{2}-\d{2}$/', (string) ( $args[1] ?? '' ) ) ) {
			WP_CLI::error( 'The date must be YYYY-MM-DD.' );
		}
		$request  = self::booking_request( $args, $assoc );
		$currency = strtoupper( (string) ( $assoc['currency'] ?? 'GBP' ) );
		try {
			$res = $bokun->post( '/checkout.json/options/booking-request', $request, [ 'currency' => $currency ] );
		} catch ( BokunException $e ) {
			WP_CLI::error( $e->getMessage() );
		}
		self::print_options( $res );
		if ( isset( $assoc['depth'] ) ) {
			WP_CLI::log( implode( "\n", self::outline( $res, '', 0, max( 1, (int) $assoc['depth'] ) ) ) );
		}
		WP_CLI::success( 'Nothing was booked.' );
	}
}
