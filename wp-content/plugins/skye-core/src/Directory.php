<?php
declare(strict_types=1);

namespace Skye;

/**
 * The places directory as data: which places match the chosen filters, how they are grouped, and
 * which filter options are worth offering. Pure (place summaries in, arrays out) so it can be tested
 * without WordPress; Frontend adds the URLs and Render\PlaceList turns it into HTML.
 */
final class Directory {

	/** Filter name => key of the place summary that holds its terms. `zone` holds one term, the others a list. */
	public const FILTERS = [ 'zone' => 'zone', 'category' => 'categories', 'profile' => 'profiles' ];

	/** @return list<string> Slugs of the place for one filter. */
	public static function slugs( array $place, string $filter ): array {
		$v = $place[ self::FILTERS[ $filter ] ] ?? null;
		if ( 'zone' === $filter ) {
			return isset( $v['slug'] ) ? [ (string) $v['slug'] ] : [];
		}
		return is_array( $v ) ? array_values( array_map( static fn( $t ) => (string) $t['slug'], $v ) ) : [];
	}

	/**
	 * Places that carry every active filter.
	 *
	 * @param list<array<string, mixed>> $places
	 * @param array<string, string>      $active filter => slug ('' = not set)
	 * @return list<array<string, mixed>>
	 */
	public static function match( array $places, array $active ): array {
		return array_values(
			array_filter(
				$places,
				static function ( array $p ) use ( $active ): bool {
					foreach ( $active as $filter => $slug ) {
						if ( '' !== $slug && isset( self::FILTERS[ $filter ] ) && ! in_array( $slug, self::slugs( $p, $filter ), true ) ) {
							return false;
						}
					}
					return true;
				}
			)
		);
	}

	/**
	 * Options of one filter: every term that would give at least one result with the other filters as they
	 * are, in the canonical order, with that count. A filter never offers a dead end.
	 *
	 * @param list<array<string, mixed>> $places
	 * @param array<string, string>      $active
	 * @param array<string, string>      $order  slug => label, canonical order (labels are a fallback only)
	 * @return list<array{slug:string, name:string, count:int, active:bool}>
	 */
	public static function options( array $places, array $active, string $filter, array $order ): array {
		$others = $active;
		unset( $others[ $filter ] );
		$count = [];
		$name  = [];
		foreach ( self::match( $places, $others ) as $p ) {
			$terms = 'zone' === $filter ? array_filter( [ $p['zone'] ?? null ] ) : ( $p[ self::FILTERS[ $filter ] ] ?? [] );
			foreach ( $terms as $t ) {
				$slug           = (string) $t['slug'];
				$count[ $slug ] = ( $count[ $slug ] ?? 0 ) + 1;
				$name[ $slug ]  = (string) $t['name'];
			}
		}
		$out = [];
		foreach ( array_unique( array_merge( array_keys( $order ), array_keys( $count ) ) ) as $slug ) {
			if ( ! empty( $count[ $slug ] ) ) {
				$out[] = [ 'slug' => (string) $slug, 'name' => $name[ $slug ], 'count' => $count[ $slug ], 'active' => ( $active[ $filter ] ?? '' ) === (string) $slug ];
			}
		}
		return $out;
	}

	/**
	 * Places grouped by zone, zones in canonical order, places by title. Places without a zone go last.
	 *
	 * @param list<array<string, mixed>> $places
	 * @param array<string, string>      $zone_order slug => label
	 * @return list<array{slug:string, name:string, places:list<array<string, mixed>>}>
	 */
	public static function by_zone( array $places, array $zone_order ): array {
		$groups = [];
		foreach ( $places as $p ) {
			$slug              = (string) ( $p['zone']['slug'] ?? '' );
			$groups[ $slug ] ??= [ 'slug' => $slug, 'name' => (string) ( $p['zone']['name'] ?? '' ), 'places' => [] ];
			$groups[ $slug ]['places'][] = $p;
		}
		$rank = array_flip( array_keys( $zone_order ) );
		uksort( $groups, static fn( $a, $b ) => ( '' === $a ? PHP_INT_MAX : ( $rank[ $a ] ?? PHP_INT_MAX - 1 ) ) <=> ( '' === $b ? PHP_INT_MAX : ( $rank[ $b ] ?? PHP_INT_MAX - 1 ) ) );
		foreach ( $groups as &$g ) {
			usort( $g['places'], static fn( $a, $b ) => strcasecmp( (string) $a['title'], (string) $b['title'] ) );
		}
		return array_values( $groups );
	}
}
