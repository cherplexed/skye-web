<?php
declare(strict_types=1);

namespace Skye\Api;

use Skye\Fields;
use Skye\Meta;
use Skye\PostTypes;
use Skye\Uuid;
use Skye\Verification;
use WP_Post;

/**
 * Schema-driven serializer for every type except Place (which has its own, richer one).
 * Walks Fields::for( type ) so a field added to the schema appears in the API with no extra code,
 * and a field flagged `internal` can never leak. Metric + GBP always (CONTRACTS.md §0).
 */
final class Serializer {

	/** Field types left out of list views to keep them small. */
	private const HEAVY = [ 'repeater', 'relation', 'gallery' ];

	public static function summary( WP_Post $p ): array {
		return self::base( $p ) + self::fields( $p, false );
	}

	public static function full( WP_Post $p ): array {
		return self::base( $p ) + self::fields( $p, true ) + [ 'description_html' => apply_filters( 'the_content', $p->post_content ) ];
	}

	private static function base( WP_Post $p ): array {
		return [
			'id'           => $p->ID,
			'uuid'         => Uuid::get( $p->ID ),
			'slug'         => $p->post_name,
			'type'         => $p->post_type,
			'title'        => \Skye\Text::title( $p ),
			'url'          => PostTypes::BRAND === $p->post_type ? null : get_permalink( $p ),
			'image'        => PlaceSerializer::image_by_id( \Skye\Lang::thumbnail_id( $p->ID ) ),
			'verification' => Verification::get( $p->ID ),
		];
	}

	/** @return array<string, mixed> */
	public static function fields( WP_Post $p, bool $full ): array {
		$schema = Fields::for( $p->post_type );
		$out    = [];
		foreach ( Fields::public_names( $p->post_type ) as $name ) {
			$def = $schema[ $name ];
			if ( ! $full && in_array( $def['type'], self::HEAVY, true ) ) {
				continue;
			}
			$raw = Meta::get( $p->ID, $name );
			if ( 'gallery' === $def['type'] && ! $raw ) {
				// Photos are uploaded once, to the default-language item: a translation shows its original's.
				$original = \Skye\Lang::original_post( $p->ID, $p->post_type );
				$raw      = $original !== $p->ID ? Meta::get( $original, $name ) : $raw;
			}
			$out[ $name ] = self::cast( $def, $raw, [ self::class, 'ref' ], [ PlaceSerializer::class, 'image_by_id' ] );
		}
		return $out;
	}

	/**
	 * Raw stored value → API value, by schema type. Pure: references and images are resolved by the callables.
	 *
	 * @param array<string, mixed>            $def   Field definition from Fields.
	 * @param callable(int, string): ?array   $ref   fn( post_id, expected_post_type ) => reference or null.
	 * @param callable(mixed): ?array         $image fn( attachment ) => image object or null.
	 */
	public static function cast( array $def, mixed $raw, callable $ref, callable $image ): mixed {
		switch ( $def['type'] ) {
			case 'number':
				if ( ! is_numeric( $raw ) ) {
					return null;
				}
				$n = (float) $raw;
				return floor( $n ) === $n && abs( $n ) < PHP_INT_MAX ? (int) $n : $n;

			case 'bool':
				return (bool) ( is_string( $raw ) ? (int) $raw : $raw );

			case 'enum':
				$v = is_scalar( $raw ) ? (string) $raw : '';
				if ( '' === $v || ! array_key_exists( $v, $def['choices'] ) ) {
					$v = (string) ( $def['default'] ?? '' );
				}
				return '' === $v ? null : $v;

			case 'checkboxes':
				if ( is_string( $raw ) ) {
					$raw = array_filter( array_map( 'trim', explode( ',', $raw ) ) );
				}
				return is_array( $raw ) ? array_values( array_filter( array_map( 'strval', $raw ), static fn( $v ) => array_key_exists( $v, $def['choices'] ) ) ) : [];

			case 'latlng':
				if ( is_array( $raw ) && is_numeric( $raw['lat'] ?? null ) && is_numeric( $raw['lng'] ?? null ) ) {
					return [ 'lat' => (float) $raw['lat'], 'lng' => (float) $raw['lng'] ];
				}
				return null;

			case 'image':
				return $image( $raw );

			case 'gallery':
				return is_array( $raw ) ? array_values( array_filter( array_map( $image, $raw ) ) ) : [];

			case 'relation':
				$refs = [];
				foreach ( self::ids( $raw ) as $id ) {
					$r = $ref( $id, (string) $def['to'] );
					if ( null !== $r ) {
						$refs[] = $r;
					}
				}
				return 1 === ( $def['max'] ?? 0 ) ? ( $refs[0] ?? null ) : $refs;

			case 'repeater':
				$rows = [];
				foreach ( is_array( $raw ) ? $raw : [] as $row ) {
					if ( ! is_array( $row ) ) {
						continue;
					}
					$item = [];
					foreach ( $def['sub'] as $sub => $sub_def ) {
						$item[ $sub ] = self::cast( $sub_def, $row[ $sub ] ?? null, $ref, $image );
					}
					$rows[] = $item;
				}
				return $rows;

			case 'url':
			case 'file':
				$v = is_scalar( $raw ) ? trim( (string) $raw ) : '';
				return '' === $v ? null : $v;

			default: // text, textarea, html.
				return is_scalar( $raw ) ? (string) $raw : '';
		}
	}

	/** Relationship values come as an ID, a list of IDs, or post objects/arrays depending on who wrote them. @return list<int> */
	public static function ids( mixed $raw ): array {
		if ( is_object( $raw ) || ( is_array( $raw ) && ( isset( $raw['ID'] ) || isset( $raw['id'] ) ) ) ) {
			$raw = [ $raw ];
		}
		if ( ! is_array( $raw ) ) {
			$raw = is_numeric( $raw ) ? [ $raw ] : [];
		}
		$ids = [];
		foreach ( $raw as $x ) {
			$id = is_object( $x ) ? (int) ( $x->ID ?? 0 ) : ( is_array( $x ) ? (int) ( $x['ID'] ?? $x['id'] ?? 0 ) : (int) $x );
			if ( $id > 0 ) {
				$ids[] = $id;
			}
		}
		return $ids;
	}

	/** Reference to a published post of the expected type; located types carry their coordinates. */
	public static function ref( int $id, string $post_type ): ?array {
		$p = get_post( $id );
		if ( ! $p || 'publish' !== $p->post_status || $post_type !== $p->post_type ) {
			return null;
		}
		$r = [ 'id' => $p->ID, 'uuid' => Uuid::get( $p->ID ), 'slug' => $p->post_name, 'title' => \Skye\Text::title( $p ), 'type' => $p->post_type ];
		if ( in_array( $p->post_type, PostTypes::located(), true ) ) {
			$r['location'] = Meta::latlng( $p->ID, 'location' );
		}
		return $r;
	}
}
