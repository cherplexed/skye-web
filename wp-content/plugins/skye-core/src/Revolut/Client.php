<?php
declare(strict_types=1);

namespace Skye\Revolut;

use Skye\Config;

/**
 * Server side of the Revolut Merchant API: orders. An order is what the customer pays; its token
 * goes to the page, where Revolut's own script collects the card, and its id stays on the server.
 *
 * The transport is injectable so the client is unit-tested without WordPress or network:
 * fn( string $method, string $url, array $headers, ?string $body ): array{ status:int, body:string }
 */
final class Client {

	public const PRODUCTION  = 'https://merchant.revolut.com';
	public const SANDBOX     = 'https://sandbox-merchant.revolut.com';
	public const API_VERSION = '2026-08-17';

	/** @var callable */
	private $transport;

	public function __construct(
		private readonly string $secret_key,
		private readonly string $base_url = self::SANDBOX,
		private readonly string $api_version = self::API_VERSION,
		?callable $transport = null
	) {
		$this->transport = $transport ?? [ self::class, 'wp_transport' ];
	}

	/** 'sandbox' unless REVOLUT_ENV says 'production': a missing or misspelt setting must never charge real cards. */
	public static function environment(): string {
		return in_array( strtolower( Config::get( 'REVOLUT_ENV' ) ), [ 'production', 'prod', 'live' ], true ) ? 'production' : 'sandbox';
	}

	/** Client built from configuration; null when there is no secret key. */
	public static function from_config(): ?self {
		$secret = Config::get( 'REVOLUT_SECRET_KEY' );
		if ( '' === $secret ) {
			return null;
		}
		$version = Config::get( 'REVOLUT_API_VERSION' );
		return new self( $secret, 'production' === self::environment() ? self::PRODUCTION : self::SANDBOX, '' !== $version ? $version : self::API_VERSION );
	}

	/** Amount in the minor unit Revolut expects: pence, cents. */
	public static function minor_units( float $amount ): int {
		return (int) round( $amount * 100 );
	}

	/**
	 * @param array<string, mixed> $extra Other fields of the order: description, customer, metadata, merchant_order_data…
	 * @return array<string, mixed> The order: id, token, state, amount, currency…
	 * @throws RevolutException
	 */
	public function create_order( int $amount_minor, string $currency, array $extra = [] ): array {
		if ( $amount_minor <= 0 ) {
			throw new RevolutException( 'An order needs an amount greater than zero' );
		}
		return $this->request( 'POST', '/api/orders', [ 'amount' => $amount_minor, 'currency' => strtoupper( $currency ) ] + $extra );
	}

	/** @return array<string, mixed> */
	public function order( string $order_id ): array {
		return $this->request( 'GET', '/api/orders/' . rawurlencode( $order_id ), null );
	}

	/**
	 * The most recent orders. Reads only: also the cheapest way to learn whether a key is accepted.
	 *
	 * @return array<mixed>
	 */
	public function orders( int $limit = 1 ): array {
		return $this->request( 'GET', '/api/orders?limit=' . max( 1, $limit ), null );
	}

	/** Only an order nobody has paid yet can be cancelled. @return array<string, mixed> */
	public function cancel_order( string $order_id ): array {
		return $this->request( 'POST', '/api/orders/' . rawurlencode( $order_id ) . '/cancel', [] );
	}

	/**
	 * @param array<string, mixed>|null $body
	 * @return array<string, mixed>
	 * @throws RevolutException
	 */
	private function request( string $method, string $path, ?array $body ): array {
		$headers = [
			'Authorization'       => 'Bearer ' . $this->secret_key,
			'Revolut-Api-Version' => $this->api_version,
			'Accept'              => 'application/json',
		];
		$json = null;
		if ( null !== $body ) {
			$headers['Content-Type'] = 'application/json';
			$json                    = (string) json_encode( $body ? $body : new \stdClass(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
		}
		try {
			$res = ( $this->transport )( $method, $this->base_url . $path, $headers, $json );
		} catch ( RevolutException $e ) {
			throw $e;
		} catch ( \Throwable $e ) {
			throw new RevolutException( 'Revolut request failed: ' . $e->getMessage(), 0, $e );
		}
		$status = (int) ( $res['status'] ?? 0 );
		$data   = json_decode( (string) ( $res['body'] ?? '' ), true );
		if ( $status < 200 || $status >= 300 ) {
			// Revolut's own error code helps to fix a setup; its message and the rest of the body stay out.
			$code = is_array( $data ) && is_string( $data['code'] ?? null ) ? ' (' . preg_replace( '/[^A-Za-z0-9_\-]/', '', $data['code'] ) . ')' : '';
			throw new RevolutException( "Revolut answered HTTP $status$code for $method " . preg_replace( '#/[0-9a-f-]{20,}#i', '/{id}', $path ), $status );
		}
		if ( ! is_array( $data ) ) {
			throw new RevolutException( "Revolut returned invalid JSON for $method $path", $status );
		}
		return $data;
	}

	/** Default transport: the WordPress HTTP API. */
	public static function wp_transport( string $method, string $url, array $headers, ?string $body = null ): array {
		$args = [ 'method' => $method, 'headers' => $headers, 'timeout' => 20, 'redirection' => 0 ];
		if ( null !== $body ) {
			$args['body'] = $body;
		}
		$r = wp_remote_request( $url, $args );
		if ( is_wp_error( $r ) ) {
			throw new RevolutException( 'Revolut request failed: ' . $r->get_error_message() );
		}
		return [ 'status' => (int) wp_remote_retrieve_response_code( $r ), 'body' => (string) wp_remote_retrieve_body( $r ) ];
	}
}
