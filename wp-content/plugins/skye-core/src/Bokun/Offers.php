<?php
declare(strict_types=1);

namespace Skye\Bokun;

/**
 * Cached "from" price and next bookable date per Bókun product (CONTRACTS.md §12).
 *
 * - Fresh answers are cached for 30 minutes.
 * - The last good answer is kept for 7 days and served (flagged `stale`) while Bókun is failing, so a
 *   Bókun outage never removes the price from a tour page.
 * - After a failure, Bókun is not called again for that product for 5 minutes (or Retry-After on a 429).
 * - Bókun is asked in windows of 60 days, up to 6 windows, stopping at the first one with a bookable
 *   departure: the price shown is the lowest of the nearest season on sale.
 *
 * The cache (transients by default) and the clock are injectable for tests.
 */
final class Offers {

	public const TTL          = 1800;
	public const STALE_TTL    = 604800;
	public const FAILURE_TTL  = 300;
	public const WINDOW_DAYS  = 60;
	public const MAX_WINDOWS  = 6;

	/** @var callable(string): mixed */
	private $cache_get;
	/** @var callable(string, mixed, int): void */
	private $cache_set;

	public function __construct( private readonly ?Client $client, ?callable $cache_get = null, ?callable $cache_set = null, private readonly ?int $now = null ) {
		$this->cache_get = $cache_get ?? static fn( string $k ) => get_transient( $k );
		$this->cache_set = $cache_set ?? static function ( string $k, mixed $v, int $ttl ): void {
			set_transient( $k, $v, $ttl );
		};
	}

	public static function default(): self {
		return new self( Client::from_config() );
	}

	/**
	 * @return array{price_from: float, currency: string, next_available: string, departures: int, fetched_at: string, stale: bool}|null
	 *         null when Bókun is not configured, the product has nothing on sale, or it failed with no earlier answer to fall back on.
	 */
	public function price_from( string $product_id, string $currency = 'GBP', ?int $category_id = null ): ?array {
		$product_id = trim( $product_id );
		if ( '' === $product_id || null === $this->client ) {
			return null;
		}
		$currency = strtoupper( $currency );
		$key      = 'skye_bokun_' . md5( $product_id . '|' . $currency . '|' . (string) $category_id );

		$hit = ( $this->cache_get )( $key );
		if ( is_array( $hit ) && array_key_exists( 'offer', $hit ) ) {
			return $hit['offer'];
		}

		try {
			$offer = $this->fetch( $product_id, $currency, $category_id );
		} catch ( BokunException $e ) {
			$this->log( $product_id, $e );
			$last  = ( $this->cache_get )( $key . '_last' );
			$offer = is_array( $last ) ? [ ...$last, 'stale' => true ] : null;
			( $this->cache_set )( $key, [ 'offer' => $offer ], $e->retry_after > 0 ? $e->retry_after : self::FAILURE_TTL );
			return $offer;
		}

		( $this->cache_set )( $key, [ 'offer' => $offer ], self::TTL );
		if ( null !== $offer ) {
			( $this->cache_set )( $key . '_last', $offer, self::STALE_TTL );
		}
		return $offer;
	}

	/** @throws BokunException */
	private function fetch( string $product_id, string $currency, ?int $category_id ): ?array {
		$today = $this->now ?? time();
		for ( $w = 0; $w < self::MAX_WINDOWS; $w++ ) {
			$start = gmdate( 'Y-m-d', $today + $w * self::WINDOW_DAYS * 86400 );
			$end   = gmdate( 'Y-m-d', $today + ( ( $w + 1 ) * self::WINDOW_DAYS - 1 ) * 86400 );
			$sum   = Pricing::summarize( $this->client->availabilities( $product_id, $start, $end, $currency ), $category_id );
			if ( null !== $sum ) {
				return $sum + [ 'fetched_at' => gmdate( 'c', $today ), 'stale' => false ];
			}
		}
		return null;
	}

	private function log( string $product_id, BokunException $e ): void {
		if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			error_log( sprintf( '[skye-core] Bókun product %s: %s', $product_id, $e->getMessage() ) );
		}
	}
}
