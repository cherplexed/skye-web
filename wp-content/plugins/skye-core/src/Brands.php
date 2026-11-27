<?php
declare(strict_types=1);

namespace Skye;

/**
 * Operating brands and market routing (CONTRACTS.md §3): ES → Viajar por Escocia, IT → Scozia Tour,
 * everything else → the brand holding the `*` wildcard (Loch Ness Bus, tour in English).
 */
final class Brands {

	/**
	 * Pure routing rule. A brand's `markets` is a comma-separated list of codes; `*` is the wildcard.
	 * Order of preference: exact market ("en-us"), its language ("en"), the wildcard.
	 *
	 * @param list<array<string, mixed>> $brands Each with at least a `markets` string.
	 * @return array<string, mixed>|null
	 */
	public static function pick( array $brands, string $market ): ?array {
		$market = strtolower( str_replace( '_', '-', trim( $market ) ) );
		$lang   = substr( $market, 0, 2 );
		$exact  = null;
		$by_lang = null;
		$wild   = null;
		foreach ( $brands as $b ) {
			$codes = array_filter( array_map( 'trim', explode( ',', strtolower( (string) ( $b['markets'] ?? '' ) ) ) ) );
			if ( '' !== $market && in_array( $market, $codes, true ) ) {
				$exact ??= $b;
			}
			if ( '' !== $lang && in_array( $lang, $codes, true ) ) {
				$by_lang ??= $b;
			}
			if ( in_array( '*', $codes, true ) ) {
				$wild ??= $b;
			}
		}
		return $exact ?? $by_lang ?? $wild;
	}

	/** @return list<array<string, mixed>> Published brands, in menu order. */
	public static function all(): array {
		static $cache = null;
		if ( null !== $cache ) {
			return $cache;
		}
		$cache = [];
		foreach ( get_posts( [ 'post_type' => PostTypes::BRAND, 'post_status' => 'publish', 'posts_per_page' => 50, 'orderby' => 'menu_order title', 'order' => 'ASC' ] ) as $p ) {
			$cache[] = [
				'id'                => $p->ID,
				'uuid'              => Uuid::get( $p->ID ),
				'slug'              => $p->post_name,
				'title'             => Text::title( $p ),
				'domain'            => (string) Meta::get( $p->ID, 'domain', '' ),
				'markets'           => (string) Meta::get( $p->ID, 'markets', '' ),
				'tour_language'     => (string) Meta::get( $p->ID, 'tour_language', 'en' ),
				'attribution_param' => (string) Meta::get( $p->ID, 'attribution_param', '' ),
			];
		}
		return $cache;
	}

	/** @return array<string, mixed>|null */
	public static function for_market( string $market ): ?array {
		return self::pick( self::all(), $market );
	}
}
