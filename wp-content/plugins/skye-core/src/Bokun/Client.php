<?php
declare(strict_types=1);

namespace Skye\Bokun;

/**
 * Signed, read-only HTTP client for the Bókun REST API. Knows nothing about caching (see Offers).
 *
 * The transport is injectable so the client is unit-tested without WordPress or network:
 * fn( string $method, string $url, array $headers ): array{ status:int, body:string, headers:array<string,string> }
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
		$access = self::config( 'BOKUN_ACCESS_KEY' );
		$secret = self::config( 'BOKUN_SECRET_KEY' );
		if ( '' === $access || '' === $secret ) {
			return null;
		}
		$base = self::config( 'BOKUN_BASE_URL' );
		return new self( $access, $secret, rtrim( '' !== $base ? $base : self::PRODUCTION, '/' ) );
	}

	private static function config( string $name ): string {
		if ( defined( 'SKYE_' . $name ) && '' !== (string) constant( 'SKYE_' . $name ) ) {
			return trim( (string) constant( 'SKYE_' . $name ) );
		}
		$env = getenv( $name );
		return false === $env ? '' : trim( $env );
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
		// The signature covers path + query exactly as sent, so build the string once and use it for both.
		$path_and_query = $path . ( $query ? '?' . http_build_query( $query, '', '&', PHP_QUERY_RFC3986 ) : '' );
		$headers        = Signer::headers( $this->access_key, $this->secret_key, 'GET', $path_and_query );

		try {
			$res = ( $this->transport )( 'GET', $this->base_url . $path_and_query, $headers );
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
			// Never include the response body or the headers: they can echo keys.
			throw new BokunException( "Bókun answered HTTP $status for $path", $status );
		}

		$data = json_decode( (string) ( $res['body'] ?? '' ), true );
		if ( ! is_array( $data ) ) {
			throw new BokunException( "Bókun returned invalid JSON for $path", $status );
		}
		return $data;
	}

	/** Default transport: the WordPress HTTP API. */
	public static function wp_transport( string $method, string $url, array $headers ): array {
		$r = wp_remote_request( $url, [ 'method' => $method, 'headers' => $headers, 'timeout' => 10, 'redirection' => 0 ] );
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
