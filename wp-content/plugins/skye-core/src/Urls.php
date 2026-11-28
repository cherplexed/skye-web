<?php
declare(strict_types=1);

namespace Skye;

/**
 * Addresses in the language of the page (CONTRACTS.md §10): /es/lugares/…, /it/luoghi/….
 *
 * WordPress knows each content type by one base ("places"). This class adds the rewrite rules
 * that make the translated bases resolve, rewrites every link the site prints so it uses the
 * base of its language, and sends a request that arrives on the base of another language to
 * the right one. It needs no WPML add-on: the language itself still comes from `Skye\Lang`.
 */
final class Urls {

	/** Base of each language, by the base WordPress registers. A language not listed keeps the registered ones. */
	public const BASES = [
		'es' => [ 'places' => 'lugares', 'routes' => 'rutas', 'tours' => 'tours', 'stay' => 'alojamiento', 'motorhome' => 'autocaravana', 'experiences' => 'actividades', 'info' => 'informacion', 'zone' => 'zona' ],
		'it' => [ 'places' => 'luoghi', 'routes' => 'percorsi', 'tours' => 'tour', 'stay' => 'alloggi', 'motorhome' => 'camper', 'experiences' => 'attivita', 'info' => 'informazioni', 'zone' => 'zona' ],
	];

	/** Registered base => what it routes to: a post type, or the zone taxonomy. */
	public const TARGETS = [
		'places'      => PostTypes::PLACE,
		'routes'      => PostTypes::ROUTE,
		'tours'       => PostTypes::TOUR,
		'stay'        => PostTypes::LODGING,
		'motorhome'   => PostTypes::MOTORHOME,
		'experiences' => PostTypes::EXPERIENCE,
		'info'        => PostTypes::GUIDE,
		'zone'        => Taxonomies::ZONE,
	];

	private const OPTION = 'skye_rewrite_rules_version';

	public static function register(): void {
		add_action( 'init', [ self::class, 'add_rules' ], 20 );
		add_filter( 'post_type_link', [ self::class, 'post_link' ], 99, 2 );
		add_filter( 'post_type_archive_link', [ self::class, 'current_link' ], 99 );
		add_filter( 'term_link', [ self::class, 'term_link' ], 99, 3 );
		add_filter( 'wpml_hreflangs', [ self::class, 'hreflangs' ] );
		add_action( 'template_redirect', [ self::class, 'redirect' ], 5 );
	}

	/** @return list<string> Every translated base that differs from the registered one, without repeats. */
	private static function translated( string $base ): array {
		$out = [];
		foreach ( self::BASES as $bases ) {
			if ( isset( $bases[ $base ] ) && $bases[ $base ] !== $base ) {
				$out[ $bases[ $base ] ] = true;
			}
		}
		return array_map( 'strval', array_keys( $out ) );
	}

	/**
	 * Rewrite rules for the translated bases: the archive, its pages and the single item of a
	 * content type; the term page of the zone taxonomy.
	 *
	 * @return array<string, string> regex => query
	 */
	public static function rules(): array {
		$rules = [];
		foreach ( self::TARGETS as $base => $target ) {
			foreach ( self::translated( $base ) as $slug ) {
				$slug = preg_quote( $slug, '#' );
				if ( Taxonomies::ZONE === $target ) {
					$rules[ "^$slug/([^/]+)/?$" ] = 'index.php?' . $target . '=$matches[1]';
					continue;
				}
				$rules[ "^$slug/?$" ]                    = 'index.php?post_type=' . $target;
				$rules[ "^$slug/page/([0-9]{1,})/?$" ]   = 'index.php?post_type=' . $target . '&paged=$matches[1]';
				$rules[ "^$slug/([^/]+)/?$" ]            = 'index.php?' . $target . '=$matches[1]';
			}
		}
		return $rules;
	}

	/** Adds the rules, and rebuilds WordPress's stored set once whenever they change. */
	public static function add_rules(): void {
		$rules = self::rules();
		foreach ( $rules as $regex => $query ) {
			add_rewrite_rule( $regex, $query, 'top' );
		}
		$version = md5( (string) wp_json_encode( $rules ) );
		if ( get_option( self::OPTION ) !== $version ) {
			flush_rewrite_rules( false );
			update_option( self::OPTION, $version );
		}
	}

	/**
	 * The same address with the base of $lang. Only the first segment of the path is looked at,
	 * or the second when the first is a language directory; a base of any language is accepted.
	 * Anything else comes back untouched.
	 */
	public static function localize( string $url, string $lang ): string {
		// Scheme and host, then the path, then whatever follows the first "?" or "#".
		if ( 1 !== preg_match( '~^((?:[a-z][a-z0-9+.-]*:)?//[^/?#]*)?(/[^?#]*)(.*)$~is', $url, $m ) ) {
			return $url;
		}
		[ , $origin, $path, $rest ] = $m;
		$segments                   = explode( '/', $path );
		// $segments[0] is the empty string before the leading slash.
		$i = isset( $segments[1] ) && 1 === preg_match( '/^[a-z]{2}(-[a-z]{2})?$/', $segments[1] ) && null === self::registered( $segments[1] ) ? 2 : 1;
		if ( ! isset( $segments[ $i ] ) ) {
			return $url;
		}
		$base = self::registered( $segments[ $i ] );
		if ( null === $base ) {
			return $url;
		}
		$wanted = self::BASES[ strtolower( substr( $lang, 0, 2 ) ) ][ $base ] ?? $base;
		if ( $wanted === $segments[ $i ] ) {
			return $url;
		}
		$segments[ $i ] = $wanted;
		return $origin . implode( '/', $segments ) . $rest;
	}

	/** The registered base a path segment stands for, in whatever language it is written; null when it is none. */
	public static function registered( string $segment ): ?string {
		if ( isset( self::TARGETS[ $segment ] ) ) {
			return $segment;
		}
		foreach ( self::BASES as $bases ) {
			$base = array_search( $segment, $bases, true );
			if ( false !== $base ) {
				return (string) $base;
			}
		}
		return null;
	}

	public static function post_link( string $url, \WP_Post $post ): string {
		return in_array( $post->post_type, self::TARGETS, true ) ? self::localize( $url, Lang::of_post( $post->ID, $post->post_type ) ) : $url;
	}

	public static function current_link( string $url ): string {
		return self::localize( $url, Lang::current() );
	}

	public static function term_link( string $url, \WP_Term $term, string $taxonomy ): string {
		return Taxonomies::ZONE === $taxonomy ? self::localize( $url, Lang::current() ) : $url;
	}

	/**
	 * @param mixed $links language code => address, from WPML.
	 * @return mixed
	 */
	public static function hreflangs( mixed $links ): mixed {
		if ( is_array( $links ) ) {
			foreach ( $links as $code => $url ) {
				if ( is_string( $url ) ) {
					$links[ $code ] = self::localize( $url, (string) $code );
				}
			}
		}
		return $links;
	}

	/** A listing, a zone page or an item asked for on the base of another language goes to its own, for good. */
	public static function redirect(): void {
		if ( ! ( is_post_type_archive( array_values( self::TARGETS ) ) || is_tax( Taxonomies::ZONE ) || is_singular( array_values( self::TARGETS ) ) ) ) {
			return;
		}
		$request = isset( $_SERVER['REQUEST_URI'] ) ? esc_url_raw( wp_unslash( (string) $_SERVER['REQUEST_URI'] ) ) : '';
		$wanted  = '' !== $request ? self::localize( $request, Lang::current() ) : '';
		if ( '' !== $wanted && $wanted !== $request ) {
			wp_safe_redirect( $wanted, 301 );
			exit;
		}
	}
}
