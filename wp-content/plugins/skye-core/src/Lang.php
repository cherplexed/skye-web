<?php
declare(strict_types=1);

namespace Skye;

/**
 * The one place that talks to WPML, through its documented hooks only. Without WPML every method
 * degrades to plain WordPress, so the plugin runs the same on a single-language install.
 *
 * Two rules the rest of the code relies on:
 *  - Slugs of taxonomy terms in the API, in URLs parameters and in the seed files are always the
 *    slugs of the default-language terms ("canonical"), whatever the language being shown.
 *  - A translation keeps the slug of its original post.
 */
final class Lang {

	public static function active(): bool {
		return defined( 'ICL_SITEPRESS_VERSION' );
	}

	public static function default(): string {
		$l = self::active() ? (string) apply_filters( 'wpml_default_language', null ) : '';
		return '' !== $l ? $l : 'en';
	}

	public static function current(): string {
		$l = self::active() ? (string) apply_filters( 'wpml_current_language', null ) : '';
		return '' !== $l && 'all' !== $l ? $l : self::default();
	}

	/** @return list<string> Codes of the site's languages. */
	public static function codes(): array {
		$langs = self::active() ? (array) apply_filters( 'wpml_active_languages', [], [ 'skip_missing' => 0 ] ) : [];
		return $langs ? array_map( 'strval', array_keys( $langs ) ) : [ self::default() ];
	}

	public static function switch( string $lang ): void {
		if ( self::active() && '' !== $lang ) {
			do_action( 'wpml_switch_language', $lang );
		}
	}

	/** Runs $fn with $lang as the current language, then restores the previous one. */
	public static function with( string $lang, callable $fn ): mixed {
		$previous = self::current();
		self::switch( $lang );
		try {
			return $fn();
		} finally {
			self::switch( $previous );
		}
	}

	// ---- posts -------------------------------------------------------------------------------

	/** WPML's translation-group id of a post, or 0 when WPML has no record of the post. */
	private static function post_trid( int $id, string $post_type ): int {
		return self::active() ? (int) apply_filters( 'wpml_element_trid', null, $id, 'post_' . $post_type ) : 0;
	}

	/**
	 * Language of a post. A post WPML has no record of (created before the type was translatable) is an
	 * original in the default language; asking WPML about one gives an answer that follows the language
	 * being viewed, so the record is checked first.
	 */
	public static function of_post( int $id, string $post_type ): string {
		if ( ! self::post_trid( $id, $post_type ) ) {
			return self::default();
		}
		$l = apply_filters( 'wpml_element_language_code', null, [ 'element_id' => $id, 'element_type' => $post_type ] );
		return is_string( $l ) && '' !== $l ? $l : self::default();
	}

	/** Gives a post without a language record its place as an original in the default language. */
	public static function claim_post( int $id, string $post_type ): void {
		if ( ! self::active() || self::post_trid( $id, $post_type ) ) {
			return;
		}
		do_action(
			'wpml_set_element_language_details',
			[
				'element_id'           => $id,
				'element_type'         => 'post_' . $post_type,
				'trid'                 => false,
				'language_code'        => self::default(),
				'source_language_code' => null,
			]
		);
	}

	/**
	 * The post with this slug in exactly this language. Translations share the slug of their original,
	 * so a lookup by slug alone (get_page_by_path) would return any of them.
	 *
	 * @param list<string> $statuses
	 */
	public static function find_post( string $slug, string $post_type, ?string $lang = null, array $statuses = [ 'publish', 'draft', 'pending', 'private', 'future' ] ): ?\WP_Post {
		$lang ??= self::current();
		$ids    = get_posts(
			[
				'name'             => $slug,
				'post_type'        => $post_type,
				'post_status'      => $statuses,
				'posts_per_page'   => 20,
				'orderby'          => 'ID',
				'order'            => 'ASC',
				'fields'           => 'ids',
				'suppress_filters' => true,
			]
		);
		foreach ( $ids as $id ) {
			if ( ! self::active() || self::of_post( (int) $id, $post_type ) === $lang ) {
				return get_post( (int) $id );
			}
		}
		return null;
	}

	/** The post to show for this slug: the translation in the current language, else the original. */
	public static function resolve_post( string $slug, string $post_type ): ?\WP_Post {
		return self::find_post( $slug, $post_type, self::current(), [ 'publish' ] )
			?? self::find_post( $slug, $post_type, self::default(), [ 'publish' ] );
	}

	/** ID of the translation of a post, or null. With $or_original, the post's own ID when there is none. */
	public static function post_in( int $id, string $post_type, string $lang, bool $or_original = false ): ?int {
		if ( ! self::active() ) {
			return $or_original || $lang === self::default() ? $id : null;
		}
		$t = apply_filters( 'wpml_object_id', $id, $post_type, $or_original, $lang );
		return $t ? (int) $t : null;
	}

	/** ID of the default-language version of a post (the post itself when it is the original). */
	public static function original_post( int $id, string $post_type ): int {
		return self::post_in( $id, $post_type, self::default(), true ) ?? $id;
	}

	/** Records that $id is the $lang translation of $original. */
	public static function connect_post( int $id, string $post_type, int $original, string $lang ): void {
		self::connect( $id, 'post_' . $post_type, $original, $lang );
	}

	/**
	 * Featured image of a post, falling back to its original's: photos are uploaded once, for the
	 * default language, and every translation shows them.
	 */
	public static function thumbnail_id( int $id ): int {
		$thumb = (int) get_post_thumbnail_id( $id );
		if ( ! $thumb && self::active() ) {
			$original = self::original_post( $id, (string) get_post_type( $id ) );
			$thumb    = $original !== $id ? (int) get_post_thumbnail_id( $original ) : 0;
		}
		return $thumb;
	}

	// ---- terms -------------------------------------------------------------------------------

	/** term_id of the default-language term with this slug. Straight from the tables: term queries are filtered by language. */
	private static function canonical_term_id( string $taxonomy, string $slug ): ?int {
		static $cache = [];
		$key = $taxonomy . '|' . $slug;
		if ( ! array_key_exists( $key, $cache ) ) {
			global $wpdb;
			$id            = $wpdb->get_var( $wpdb->prepare( "SELECT t.term_id FROM {$wpdb->terms} t JOIN {$wpdb->term_taxonomy} tt ON tt.term_id = t.term_id WHERE tt.taxonomy = %s AND t.slug = %s ORDER BY t.term_id ASC LIMIT 1", $taxonomy, $slug ) );
			$cache[ $key ] = $id ? (int) $id : null;
		}
		return $cache[ $key ];
	}

	/**
	 * term_id for a canonical slug in a language (the current one by default); the original term when
	 * it has no translation; null when the slug does not exist.
	 */
	public static function term_id( string $taxonomy, string $slug, ?string $lang = null ): ?int {
		$id = self::canonical_term_id( $taxonomy, $slug );
		if ( null === $id || ! self::active() ) {
			return $id;
		}
		$t = apply_filters( 'wpml_object_id', $id, $taxonomy, true, $lang ?? self::current() );
		return $t ? (int) $t : $id;
	}

	/** ID of the translation of a term in a language, or null when there is none. */
	public static function term_translation( string $taxonomy, string $slug, string $lang ): ?int {
		$id = self::canonical_term_id( $taxonomy, $slug );
		if ( null === $id || ! self::active() ) {
			return null;
		}
		$t = apply_filters( 'wpml_object_id', $id, $taxonomy, false, $lang );
		return $t && (int) $t !== $id ? (int) $t : null;
	}

	/** The canonical slug of a term: its own in the default language, its original's for a translation. */
	public static function term_slug( \WP_Term $term ): string {
		if ( ! self::active() ) {
			return $term->slug;
		}
		static $cache = [];
		if ( ! isset( $cache[ $term->term_id ] ) ) {
			$original = (int) apply_filters( 'wpml_object_id', $term->term_id, $term->taxonomy, true, self::default() );
			$slug     = $term->slug;
			if ( $original && $original !== (int) $term->term_id ) {
				global $wpdb;
				$slug = (string) ( $wpdb->get_var( $wpdb->prepare( "SELECT slug FROM {$wpdb->terms} WHERE term_id = %d", $original ) ) ?: $term->slug );
			}
			$cache[ $term->term_id ] = $slug;
		}
		return $cache[ $term->term_id ];
	}

	/** Records that term $id is the $lang translation of term $original. WPML keys terms by term_taxonomy_id. */
	public static function connect_term( int $id, string $taxonomy, int $original, string $lang ): void {
		global $wpdb;
		$ttid = static fn( int $term_id ): int => (int) $wpdb->get_var( $wpdb->prepare( "SELECT term_taxonomy_id FROM {$wpdb->term_taxonomy} WHERE term_id = %d AND taxonomy = %s", $term_id, $taxonomy ) );
		self::connect( $ttid( $id ), 'tax_' . $taxonomy, $ttid( $original ), $lang );
	}

	private static function connect( int $element, string $wpml_type, int $original_element, string $lang ): void {
		if ( ! self::active() || ! $element || ! $original_element ) {
			return;
		}
		$trid = apply_filters( 'wpml_element_trid', null, $original_element, $wpml_type );
		do_action(
			'wpml_set_element_language_details',
			[
				'element_id'           => $element,
				'element_type'         => $wpml_type,
				'trid'                 => $trid ?: false,
				'language_code'        => $lang,
				'source_language_code' => self::default(),
			]
		);
	}

	/**
	 * A translation laid over its original: scalars and lists of scalars are replaced; a list of rows
	 * is taken row by row, so each translated row keeps what it does not translate (a day keeps its
	 * stops, a stop keeps its place and time). The translation decides how many rows there are.
	 */
	public static function overlay( mixed $base, mixed $translation ): mixed {
		if ( ! is_array( $translation ) || ! is_array( $base ) ) {
			return $translation;
		}
		if ( array_is_list( $translation ) ) {
			$out = [];
			foreach ( $translation as $i => $row ) {
				$out[] = is_array( $row ) ? self::overlay( $base[ $i ] ?? [], $row ) : $row;
			}
			return $out;
		}
		foreach ( $translation as $k => $v ) {
			$base[ $k ] = array_key_exists( $k, $base ) ? self::overlay( $base[ $k ], $v ) : $v;
		}
		return $base;
	}
}
