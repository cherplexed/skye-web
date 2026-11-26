<?php
declare(strict_types=1);

namespace Skye\Api;

/**
 * The `offer` object of a Tour for one market (CONTRACTS.md §4, §11): which brand sells it there,
 * which Bókun product, the live "from" price and where to book. Pure: everything is passed in.
 */
final class TourOffer {

	/**
	 * @param array<string, mixed>|null       $brand    Brand chosen for the market (Brands::for_market).
	 * @param list<array<string, mixed>>      $products The tour's `products` rows, already serialized.
	 * @param callable(string, string): ?array $price   fn( product_id, currency ) => Offers::price_from() result.
	 * @return array<string, mixed>|null null when no brand serves the market.
	 */
	public static function build( ?array $brand, array $products, string $market, string $currency, callable $price ): ?array {
		if ( null === $brand ) {
			return null;
		}
		$row = null;
		foreach ( $products as $p ) {
			if ( (int) ( $p['brand']['id'] ?? 0 ) === (int) $brand['id'] ) {
				$row = $p;
				break;
			}
		}
		$product_id = trim( (string) ( $row['bokun_product_id'] ?? '' ) );
		$live       = '' !== $product_id ? $price( $product_id, $currency ) : null;

		$url = trim( (string) ( $row['fallback_booking_url'] ?? '' ) );
		if ( '' === $url && '' !== (string) ( $brand['domain'] ?? '' ) ) {
			$url = 'https://' . $brand['domain'] . '/';
		}
		$attr = ltrim( trim( (string) ( $brand['attribution_param'] ?? '' ) ), '?&' );
		if ( '' !== $url && '' !== $attr ) {
			$url .= ( str_contains( $url, '?' ) ? '&' : '?' ) . $attr;
		}

		return [
			'market'           => $market,
			'brand'            => [
				'id'            => (int) $brand['id'],
				'slug'          => (string) ( $brand['slug'] ?? '' ),
				'title'         => (string) ( $brand['title'] ?? '' ),
				'domain'        => (string) ( $brand['domain'] ?? '' ),
				'tour_language' => (string) ( $brand['tour_language'] ?? 'en' ),
			],
			'bokun_product_id' => '' !== $product_id ? $product_id : null,
			'price_from'       => $live['price_from'] ?? null,
			'currency'         => $live['currency'] ?? null,
			'next_available'   => $live['next_available'] ?? null,
			'price_stale'      => (bool) ( $live['stale'] ?? false ),
			'booking_url'      => '' !== $url ? $url : null,
		];
	}
}
