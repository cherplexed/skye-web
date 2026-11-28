<?php
declare(strict_types=1);

namespace Skye\Api;

use Skye\Bokun\Client as Bokun;
use Skye\Checkout\CheckoutException;
use Skye\Checkout\Service;
use Skye\Config;
use Skye\PostTypes;
use Skye\Revolut\Client as Revolut;
use WP_REST_Request;
use WP_REST_Response;

/**
 * REST face of our own checkout (issue #33): /skye/v1/checkout/…
 * Open to visitors, so it gives nothing away: a reservation is only reachable through the random
 * reference returned when it was made, and the amount to pay is never taken from the request.
 */
final class Checkout {

	private const CACHE = 300;

	/** Both sets of keys present: the page can offer our checkout. */
	public static function enabled(): bool {
		return '' !== Config::get( 'BOKUN_ACCESS_KEY' ) && '' !== Config::get( 'BOKUN_SECRET_KEY' ) && '' !== Config::get( 'REVOLUT_SECRET_KEY' );
	}

	public static function service(): ?Service {
		$bokun   = Bokun::from_config();
		$revolut = Revolut::from_config();
		if ( ! $bokun || ! $revolut ) {
			return null;
		}
		return new Service(
			$bokun,
			$revolut,
			static function ( string $reference ): ?array {
				$r = get_transient( 'skye_co_' . $reference );
				return is_array( $r ) ? $r : null;
			},
			static function ( string $reference, array $record, int $ttl ): void {
				set_transient( 'skye_co_' . $reference, $record, $ttl );
			}
		);
	}

	public static function routes( string $ns ): void {
		$open = [ 'permission_callback' => '__return_true' ];
		register_rest_route( $ns, '/checkout/calendar', $open + [ 'methods' => 'GET', 'callback' => [ self::class, 'calendar' ] ] );
		foreach ( [ 'quote', 'reserve', 'complete', 'cancel' ] as $step ) {
			register_rest_route( $ns, '/checkout/' . $step, $open + [ 'methods' => 'POST', 'callback' => [ self::class, $step ] ] );
		}
	}

	/** The Bókun product the tour sells in the language of the request, with the tour's title. @return array{0:string, 1:string} */
	private static function product( WP_REST_Request $r ): array {
		$post = get_post( (int) $r->get_param( 'tour' ) );
		if ( ! $post || PostTypes::TOUR !== $post->post_type || 'publish' !== $post->post_status ) {
			throw new CheckoutException( 'invalid', 'Unknown tour', 'tour', 404 );
		}
		[ 'market' => $market ] = Router::context( $r );
		$offer = Collection::item( $post, $market, false )['offer'] ?? [];
		$id    = (string) ( $offer['bokun_product_id'] ?? '' );
		if ( '' === $id ) {
			throw new CheckoutException( 'unavailable', 'This tour has no product for this language', '', 404 );
		}
		return [ $id, \Skye\Text::title( $post ) ];
	}

	private static function run( callable $step ): WP_REST_Response {
		$service = self::service();
		if ( ! $service ) {
			return new WP_REST_Response( [ 'error' => [ 'reason' => 'unavailable', 'message' => 'The checkout is not configured' ] ], 503 );
		}
		try {
			$response = new WP_REST_Response( [ 'data' => $step( $service ) ], 200 );
		} catch ( CheckoutException $e ) {
			// The detail of a failed call to Bókun or Revolut is for the log, not for the visitor.
			$public = in_array( $e->reason, [ 'invalid', 'expired' ], true ) ? $e->getMessage() : '';
			if ( '' === $public ) {
				error_log( 'skye checkout: ' . $e->reason . ' · ' . $e->getMessage() ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			}
			$response = new WP_REST_Response( [ 'error' => array_filter( [ 'reason' => $e->reason, 'message' => $public, 'field' => $e->field ] ) ], $e->getCode() ?: 400 );
		}
		$response->header( 'Cache-Control', 'no-store' );
		return $response;
	}

	public static function calendar( WP_REST_Request $r ): WP_REST_Response {
		return self::run(
			static function ( Service $service ) use ( $r ): array {
				[ $product ] = self::product( $r );
				$key         = 'skye_cal_' . md5( $product );
				$data        = get_transient( $key );
				if ( ! is_array( $data ) ) {
					$data = $service->calendar( $product );
					set_transient( $key, $data, self::CACHE );
				}
				return $data + [ 'environment' => Revolut::environment(), 'max_people' => Service::MAX_PEOPLE ];
			}
		);
	}

	public static function quote( WP_REST_Request $r ): WP_REST_Response {
		return self::run(
			static function ( Service $service ) use ( $r ): array {
				[ $product ] = self::product( $r );
				$in          = (array) $r->get_json_params();
				return $service->quote( $product, [ 'date' => (string) ( $in['date'] ?? '' ), 'start_time_id' => (int) ( $in['start_time_id'] ?? 0 ), 'rate_id' => (int) ( $in['rate_id'] ?? 0 ), 'passengers' => is_array( $in['passengers'] ?? null ) ? $in['passengers'] : [] ] );
			}
		);
	}

	public static function reserve( WP_REST_Request $r ): WP_REST_Response {
		return self::run(
			static function ( Service $service ) use ( $r ): array {
				// A handful of attempts per address: every one holds real places for half an hour.
				$ip   = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( (string) $_SERVER['REMOTE_ADDR'] ) ) : '';
				$key  = 'skye_co_rate_' . md5( $ip );
				$seen = (int) get_transient( $key );
				if ( $seen >= 8 ) {
					throw new CheckoutException( 'too_many', 'Too many attempts', '', 429 );
				}
				set_transient( $key, $seen + 1, 600 );

				[ $product, $title ] = self::product( $r );
				$in                  = (array) $r->get_json_params();
				$out                 = $service->reserve(
					$product,
					[
						'date'          => (string) ( $in['date'] ?? '' ),
						'start_time_id' => (int) ( $in['start_time_id'] ?? 0 ),
						'rate_id'       => (int) ( $in['rate_id'] ?? 0 ),
						'passengers'    => is_array( $in['passengers'] ?? null ) ? $in['passengers'] : [],
						'contact'       => is_array( $in['contact'] ?? null ) ? array_map( 'strval', $in['contact'] ) : [],
						'answers'       => is_array( $in['answers'] ?? null ) ? $in['answers'] : [],
						'description'   => $title . ' · ' . (string) ( $in['date'] ?? '' ),
					]
				);
				delete_transient( 'skye_cal_' . md5( $product ) );
				return $out;
			}
		);
	}

	public static function complete( WP_REST_Request $r ): WP_REST_Response {
		return self::run( static fn( Service $service ): array => $service->complete( (string) ( ( (array) $r->get_json_params() )['reference'] ?? '' ) ) );
	}

	public static function cancel( WP_REST_Request $r ): WP_REST_Response {
		return self::run( static fn( Service $service ): array => $service->cancel( (string) ( ( (array) $r->get_json_params() )['reference'] ?? '' ) ) );
	}
}
