<?php
declare(strict_types=1);

namespace Skye\Api;

use WP_REST_Request;
use WP_REST_Response;

/**
 * /wp-json/skye/v1 — CONTRACTS.md §11. Public read-only in v1.
 */
final class Router {

	public const NS = 'skye/v1';

	public static function register(): void {
		add_action( 'rest_api_init', [ self::class, 'routes' ] );
	}

	public static function routes(): void {
		$read = [ 'methods' => 'GET', 'permission_callback' => '__return_true' ];

		register_rest_route( self::NS, '/places', $read + [ 'callback' => [ Places::class, 'index' ], 'args' => self::common_args() + Places::args() ] );
		register_rest_route( self::NS, '/places/(?P<id>[A-Za-z0-9\-]+)', $read + [ 'callback' => [ Places::class, 'show' ], 'args' => self::common_args() ] );
		register_rest_route( self::NS, '/audioguide/stops', $read + [ 'callback' => [ Audioguide::class, 'stops' ], 'args' => self::common_args() ] );
		foreach ( Collection::endpoints() as $base => $cfg ) {
			register_rest_route(
				self::NS,
				'/' . $base,
				$read + [
					'callback' => static fn( WP_REST_Request $r ) => Collection::index( $r, $cfg ),
					'args'     => self::common_args() + Collection::args( $cfg ),
				]
			);
			register_rest_route(
				self::NS,
				'/' . $base . '/(?P<id>[A-Za-z0-9\-]+)',
				$read + [
					'callback' => static fn( WP_REST_Request $r ) => Collection::show( $r, $cfg ),
					'args'     => self::common_args(),
				]
			);
		}
		Checkout::routes( self::NS );
		register_rest_route( self::NS, '/health', $read + [ 'callback' => static fn() => self::ok( [ 'version' => SKYE_CORE_VERSION ] ) ] );
	}

	/** @return array<string, array<string, mixed>> */
	public static function common_args(): array {
		return [
			'lang'     => [ 'type' => 'string', 'default' => '' ],
			'market'   => [ 'type' => 'string', 'default' => '' ],
			'page'     => [ 'type' => 'integer', 'default' => 1, 'minimum' => 1 ],
			'per_page' => [ 'type' => 'integer', 'default' => 20, 'minimum' => 1, 'maximum' => 100 ],
			'fields'   => [ 'type' => 'string', 'default' => '' ],
		];
	}

	/** Resolves lang/market from the request (WPML-aware when present). @return array{lang:string, market:string} */
	public static function context( WP_REST_Request $r ): array {
		$lang = (string) $r->get_param( 'lang' );
		if ( '' === $lang ) {
			$lang = function_exists( 'apply_filters' ) ? (string) apply_filters( 'wpml_current_language', null ) : '';
			$lang = $lang ?: substr( get_locale(), 0, 2 );
		}
		$lang = strtolower( $lang );
		// ?lang=es on the default-language endpoint must return the Spanish items, not just say so in `meta`.
		if ( \Skye\Lang::active() && in_array( $lang, \Skye\Lang::codes(), true ) && \Skye\Lang::current() !== $lang ) {
			\Skye\Lang::switch( $lang );
		}
		$market = (string) $r->get_param( 'market' ) ?: \Skye\Units::market_for_lang( $lang );
		return [ 'lang' => strtolower( $lang ), 'market' => strtolower( $market ) ];
	}

	/** @param mixed $data */
	public static function ok( mixed $data, array $meta = [], int $status = 200 ): WP_REST_Response {
		$res = new WP_REST_Response( [ 'data' => $data, 'meta' => $meta + [ 'generated_at' => gmdate( 'c' ) ] ], $status );
		$res->header( 'Cache-Control', 'public, max-age=300' );
		return $res;
	}

	public static function not_found( string $what = 'item' ): WP_REST_Response {
		return new WP_REST_Response( [ 'error' => [ 'code' => 'not_found', 'message' => ucfirst( $what ) . ' not found' ] ], 404 );
	}

	/** Reduce an object to the requested top-level fields (`fields=title,slug,location`). */
	public static function pick( array $obj, string $fields ): array {
		if ( '' === trim( $fields ) ) {
			return $obj;
		}
		$keep = array_flip( array_map( 'trim', explode( ',', $fields ) ) );
		$keep += [ 'id' => 1, 'uuid' => 1, 'slug' => 1 ];
		return array_intersect_key( $obj, $keep );
	}
}
