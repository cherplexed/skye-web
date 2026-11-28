<?php
declare(strict_types=1);

namespace Skye\Bokun;

use Skye\Config;

/**
 * Signed HTTP client for the Bókun REST API. Knows nothing about caching (see Offers) or about
 * the checkout flow (see Skye\Checkout).
 *
 * The transport is injectable so the client is unit-tested without WordPress or network:
 * fn( string $method, string $url, array $headers, ?string $body ): array{ status:int, body:string, headers:array<string,string> }
 */
final class Client {

	public const PRODUCTION = 'https://api.bokun.io';
	public const TEST       = 'https://api.bokuntest.com';

	/** @var callable */
	private $transport;

	public function __construct(
		private readonly string $access_key,
		private readonly string $secret_key,
		private readonly string $base_url = self::PRODUCTION,
		?callable $transport = null
	) {
		$this->transport = $transport ?? [ self::class, 'wp_transport' ];
	}

	/**
	 * Client built from configuration; null when not configured. Each value is read from the constant
	 * that docker-compose defines for the web container (SKYE_BOKUN_*), or from the environment variable
	 * of the same name without the SKYE_ prefix (the WP-CLI container only has the latter).
	 */
	public static function from_config(): ?self {
		$access = Config::get( 'BOKUN_ACCESS_KEY' );
		$secret = Config::get( 'BOKUN_SECRET_KEY' );
		if ( '' === $access || '' === $secret ) {
			return null;
		}
		$base = Config::get( 'BOKUN_BASE_URL' );
		return new self( $access, $secret, rtrim( '' !== $base ? $base : self::PRODUCTION, '/' ) );
	}

	/**
	 * Availability and prices of a product for a date range (dates as Y-m-d).
	 *
	 * @return array<int, mixed>
	 * @throws BokunException On transport errors, non-2xx answers or invalid JSON.
	 */
	public function availabilities( string $product_id, string $start, string $end, string $currency = '' ): array {
		$query = [ 'start' => $start, 'end' => $end ];
		if ( '' !== $currency ) {
			$query['currency'] = strtoupper( $currency );
		}
		$data = $this->get( '/activity.json/' . rawurlencode( $product_id ) . '/availabilities', $query );
		return array_is_list( $data ) ? $data : [];
	}

	/**
	 * @param array<string, scalar> $query
	 * @return array<mixed>
	 * @throws BokunException
	 */
	public function get( string $path, array $query = [] ): array {
		return $this->request( 'GET', $path, $query, null );
	}

	/**
	 * Signed POST with a JSON body. Used by the checkout: it creates and changes bookings.
	 *
	 * @param array<string, scalar> $query
	 * @param array<mixed>          $body
	 * @return array<mixed>
	 * @throws BokunException
	 */
	public function post( string $path, array $body = [], array $query = [] ): array {
		return $this->request( 'POST', $path, $query, (string) json_encode( $body ? $body : new \stdClass(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ) );
	}

	/** Product (activity) as Bókun describes it: rates, pricing categories, start times, extras. */
	public function activity( string $product_id, string $currency = '', string $lang = '' ): array {
		return $this->get( '/activity.json/' . rawurlencode( $product_id ), array_filter( [ 'currency' => strtoupper( $currency ), 'lang' => strtoupper( $lang ) ] ) );
	}

	/**
	 * @param array<string, scalar> $query
	 * @return array<mixed>
	 * @throws BokunException
	 */
	private function request( string $method, string $path, array $query, ?string $body ): array {
		// The signature covers path + query exactly as sent, so build the string once and use it for both.
		$path_and_query = $path . ( $query ? '?' . http_build_query( $query, '', '&', PHP_QUERY_RFC3986 ) : '' );
		$headers        = Signer::headers( $this->access_key, $this->secret_key, $method, $path_and_query );
		if ( null !== $body ) {
			$headers['Content-Type'] = 'application/json;charset=UTF-8';
		}

		try {
			$res = ( $this->transport )( $method, $this->base_url . $path_and_query, $headers, $body );
		} catch ( BokunException $e ) {
			throw $e;
		} catch ( \Throwable $e ) {
			throw new BokunException( 'Bókun request failed: ' . $e->getMessage(), 0, 0, $e );
		}

		$status = (int) ( $res['status'] ?? 0 );
		if ( 429 === $status ) {
			$retry = (int) ( array_change_key_case( (array) ( $res['headers'] ?? [] ) )['retry-after'] ?? 60 );
			throw new BokunException( 'Bókun rate limit reached', 429, max( 1, $retry ) );
		}
		if ( $status < 200 || $status >= 300 ) {
			// Never the whole body or the headers. A rejected request (4xx) carries Bókun's own explanation
			// in "message" and "fields": that much is needed to fix the request, and only that is kept.
			throw new BokunException( "Bókun answered HTTP $status for $path" . self::reason( $status, (string) ( $res['body'] ?? '' ), [ $this->access_key, $this->secret_key ] ), $status );
		}

		$data = json_decode( (string) ( $res['body'] ?? '' ), true );
		if ( ! is_array( $data ) ) {
			throw new BokunException( "Bókun returned invalid JSON for $path", $status );
		}
		return $data;
	}

	/**
	 * Bókun's explanation of a rejected request, cut short and with our keys blanked in case it quotes them.
	 *
	 * @param list<string> $secrets
	 */
	public static function reason( int $status, string $body, array $secrets = [] ): string {
		if ( $status < 400 || $status >= 500 ) {
			return '';
		}
		$data  = json_decode( $body, true );
		$parts = [];
		if ( is_array( $data ) ) {
			foreach ( [ 'message', 'error', 'fields' ] as $key ) {
				if ( isset( $data[ $key ] ) && '' !== $data[ $key ] && [] !== $data[ $key ] ) {
					$parts[] = is_scalar( $data[ $key ] ) ? (string) $data[ $key ] : (string) json_encode( $data[ $key ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
				}
			}
		}
		$text = trim( (string) preg_replace( '/\s+/', ' ', implode( ' · ', $parts ) ) );
		foreach ( $secrets as $secret ) {
			// A real key is long; a one-letter stand-in would blank half the message.
			if ( strlen( $secret ) >= 8 ) {
				$text = str_replace( $secret, '[key]', $text );
			}
		}
		return '' === $text ? '' : ': ' . mb_substr( $text, 0, 400 );
	}

	/** Default transport: the WordPress HTTP API. */
	public static function wp_transport( string $method, string $url, array $headers, ?string $body = null ): array {
		$args = [ 'method' => $method, 'headers' => $headers, 'timeout' => null === $body ? 10 : 25, 'redirection' => 0 ];
		if ( null !== $body ) {
			$args['body'] = $body;
		}
		$r = wp_remote_request( $url, $args );
		if ( is_wp_error( $r ) ) {
			throw new BokunException( 'Bókun request failed: ' . $r->get_error_message() );
		}
		$retry = wp_remote_retrieve_header( $r, 'retry-after' );
		return [
			'status'  => (int) wp_remote_retrieve_response_code( $r ),
			'body'    => (string) wp_remote_retrieve_body( $r ),
			'headers' => $retry ? [ 'retry-after' => (string) $retry ] : [],
		];
	}
}
