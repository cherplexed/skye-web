<?php
declare(strict_types=1);

namespace Skye;

/**
 * Thin accessor over ACF / post meta so the rest of the plugin never cares whether ACF Pro
 * is installed. With ACF, repeaters come back as arrays; without it, repeaters are stored
 * as JSON in the same meta key (the seed command writes both ways).
 */
final class Meta {

	public static function get( int $post_id, string $field, mixed $default = null ): mixed {
		if ( function_exists( 'get_field' ) ) {
			$v = get_field( $field, $post_id );
			if ( null !== $v && false !== $v && '' !== $v ) {
				return $v;
			}
		}
		$raw = get_post_meta( $post_id, $field, true );
		if ( '' === $raw || null === $raw ) {
			return $default;
		}
		if ( is_string( $raw ) && ( str_starts_with( $raw, '[' ) || str_starts_with( $raw, '{' ) ) ) {
			$decoded = json_decode( $raw, true );
			if ( JSON_ERROR_NONE === json_last_error() ) {
				return $decoded;
			}
		}
		return $raw;
	}

	public static function set( int $post_id, string $field, mixed $value ): void {
		if ( function_exists( 'update_field' ) && self::acf_field_exists( $field, $post_id ) ) {
			update_field( $field, $value, $post_id );
			return;
		}
		update_post_meta( $post_id, $field, is_array( $value ) ? wp_json_encode( $value ) : $value );
	}

	public static function bool( int $post_id, string $field ): bool {
		return (bool) self::get( $post_id, $field, false );
	}

	public static function int( int $post_id, string $field, ?int $default = null ): ?int {
		$v = self::get( $post_id, $field );
		return ( null === $v || '' === $v ) ? $default : (int) $v;
	}

	public static function float( int $post_id, string $field, ?float $default = null ): ?float {
		$v = self::get( $post_id, $field );
		return ( null === $v || '' === $v ) ? $default : (float) $v;
	}

	/** @return array<string, float>|null */
	public static function latlng( int $post_id, string $field ): ?array {
		$v = self::get( $post_id, $field );
		if ( is_array( $v ) && isset( $v['lat'], $v['lng'] ) ) {
			return [ 'lat' => (float) $v['lat'], 'lng' => (float) $v['lng'] ];
		}
		if ( is_string( $v ) && str_contains( $v, ',' ) ) {
			[ $lat, $lng ] = array_map( 'floatval', explode( ',', $v, 2 ) );
			return [ 'lat' => $lat, 'lng' => $lng ];
		}
		return null;
	}

	/** @return list<int> */
	public static function ids( int $post_id, string $field ): array {
		$v = self::get( $post_id, $field, [] );
		if ( ! is_array( $v ) ) {
			return [];
		}
		return array_values( array_filter( array_map( static fn( $x ) => is_object( $x ) ? (int) $x->ID : (int) $x, $v ) ) );
	}

	/**
	 * True only when ACF knows the field AND supports its type (free ACF has no repeater/gallery:
	 * those fall back to JSON meta so local development works without a Pro licence).
	 */
	private static function acf_field_exists( string $field, int $post_id ): bool {
		if ( ! function_exists( 'acf_get_field' ) || ! function_exists( 'acf_get_field_type' ) ) {
			return false;
		}
		$f = acf_get_field( $field );
		return is_array( $f ) && ! empty( $f['type'] ) && false !== acf_get_field_type( $f['type'] );
	}
}
