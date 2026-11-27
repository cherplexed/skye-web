<?php
declare(strict_types=1);

namespace Skye;

/**
 * Front-end glue for the Bricks templates: the shortcodes that place the server-rendered pieces
 * ([skye_place part="…"], [skye_header], [skye_footer]) and the language/market of the current view.
 */
final class Frontend {

	public static function register(): void {
		add_shortcode( 'skye_place', [ self::class, 'place_shortcode' ] );
		add_shortcode( 'skye_header', [ self::class, 'header_shortcode' ] );
		add_shortcode( 'skye_footer', [ self::class, 'footer_shortcode' ] );
		add_action( 'after_setup_theme', static fn() => register_nav_menus( [ 'skye_primary' => __( 'Skye primary navigation', 'skye-core' ) ] ) );
	}

	/** @return array{lang:string, market:string} Language from WPML when present; market from the language (§9). */
	public static function context(): array {
		$lang = (string) apply_filters( 'wpml_current_language', null );
		if ( '' === $lang ) {
			$lang = substr( get_locale(), 0, 2 );
		}
		$lang = strtolower( $lang );
		return [ 'lang' => $lang, 'market' => (string) apply_filters( 'skye_market', Units::market_for_lang( $lang ), $lang ) ];
	}

	/** Archive URL of a content type, or null when it has none. */
	private static function archive( string $post_type ): ?string {
		$url = get_post_type_archive_link( $post_type );
		return $url ? (string) $url : null;
	}

	/**
	 * [skye_place part="facts"]: one block of the current Place (or of `id`). Parts: see Render\PlaceBlocks::PARTS.
	 *
	 * @param array<string, string>|string $atts
	 */
	public static function place_shortcode( $atts ): string {
		$atts = shortcode_atts( [ 'part' => 'facts', 'id' => '' ], is_array( $atts ) ? $atts : [], 'skye_place' );
		$post = get_post( '' !== $atts['id'] ? (int) $atts['id'] : get_the_ID() );
		if ( ! $post || PostTypes::PLACE !== $post->post_type || ! in_array( $atts['part'], Render\PlaceBlocks::PARTS, true ) ) {
			return '';
		}
		[ 'lang' => $lang, 'market' => $market ] = self::context();

		static $items = [];
		$key = $post->ID . '|' . $lang . '|' . $market;
		if ( ! isset( $items[ $key ] ) ) {
			$items[ $key ] = self::with_markup( Api\Places::item( $post, $lang, $market ) );
		}
		return Render\PlaceBlocks::render( $atts['part'], $items[ $key ], $market );
	}

	/** Adds what only the page needs: site links, and responsive <img> markup for every photo shown. */
	private static function with_markup( array $item ): array {
		$item['_links'] = [ 'home' => home_url( '/' ), 'places' => self::archive( PostTypes::PLACE ), 'tours' => self::archive( PostTypes::TOUR ) ];

		$img = static function ( ?array $image, string $sizes, bool $eager = false ): ?array {
			if ( $image && ! empty( $image['id'] ) ) {
				$image['html'] = wp_get_attachment_image( (int) $image['id'], 'large', false, [ 'sizes' => $sizes, 'loading' => $eager ? 'eager' : 'lazy', 'fetchpriority' => $eager ? 'high' : 'auto', 'decoding' => 'async' ] );
			}
			return $image;
		};
		$single        = empty( $item['gallery'] );
		$item['image'] = $img( $item['image'] ?? null, $single ? '(min-width: 1264px) 1200px, 100vw' : '(min-width: 1264px) 800px, 100vw', true );
		foreach ( $item['gallery'] ?? [] as $i => $g ) {
			$item['gallery'][ $i ] = $img( $g, '(min-width: 1264px) 400px, 50vw' );
		}
		foreach ( $item['nearby_places'] ?? [] as $i => $n ) {
			$item['nearby_places'][ $i ]['image'] = $img( Api\PlaceSerializer::image_by_id( (int) get_post_thumbnail_id( (int) $n['id'] ) ), '(min-width: 800px) 260px, 60vw' );
		}
		return $item;
	}

	/** Main navigation: the "Skye primary" menu when one is assigned, else the archives that exist. */
	private static function nav_links(): array {
		$links     = [];
		$locations = get_nav_menu_locations();
		$items     = ! empty( $locations['skye_primary'] ) ? wp_get_nav_menu_items( (int) $locations['skye_primary'] ) : [];
		$here      = untrailingslashit( home_url( (string) ( $GLOBALS['wp']->request ?? '' ) ) );
		foreach ( $items ?: [] as $item ) {
			if ( 0 === (int) $item->menu_item_parent ) {
				$links[] = [ 'label' => (string) $item->title, 'url' => (string) $item->url, 'current' => untrailingslashit( (string) $item->url ) === $here ];
			}
		}
		if ( $links ) {
			return $links;
		}
		$defaults = [
			PostTypes::TOUR      => __( 'Tours from Edinburgh', 'skye-core' ),
			PostTypes::ROUTE     => __( 'Plan your route', 'skye-core' ),
			PostTypes::PLACE     => __( 'What to see', 'skye-core' ),
			PostTypes::LODGING   => __( 'Where to stay', 'skye-core' ),
			PostTypes::MOTORHOME => __( 'Car and motorhome', 'skye-core' ),
		];
		$current  = is_singular() ? (string) get_post_type() : ( is_post_type_archive() ? (string) get_query_var( 'post_type' ) : '' );
		foreach ( $defaults as $type => $label ) {
			$url = self::archive( $type );
			if ( $url ) {
				$links[] = [ 'label' => $label, 'url' => $url, 'current' => $type === $current ];
			}
		}
		return $links;
	}

	/** @return list<array{code:string,label:string,url:string,active:bool}> From WPML; empty without it. */
	private static function languages(): array {
		$out = [];
		foreach ( (array) apply_filters( 'wpml_active_languages', [], [ 'skip_missing' => 0, 'orderby' => 'code' ] ) as $l ) {
			if ( is_array( $l ) && isset( $l['language_code'], $l['url'] ) ) {
				$out[] = [ 'code' => (string) $l['language_code'], 'label' => (string) ( $l['native_name'] ?? $l['language_code'] ), 'url' => (string) $l['url'], 'active' => ! empty( $l['active'] ) ];
			}
		}
		return $out;
	}

	public static function header_shortcode(): string {
		$tours = self::archive( PostTypes::TOUR );
		return Render\Chrome::header(
			[
				'home'      => home_url( '/' ),
				'links'     => self::nav_links(),
				'languages' => self::languages(),
				'cta'       => $tours ? [ 'label' => __( 'Book a tour', 'skye-core' ), 'url' => $tours ] : null,
			]
		);
	}

	public static function footer_shortcode(): string {
		$link = static fn( string $type, string $label ): array => ( $url = self::archive( $type ) ) ? [ [ 'label' => $label, 'url' => $url ] ] : [];
		return Render\Chrome::footer(
			[
				'home'      => home_url( '/' ),
				'tagline'   => __( 'Visitor information for the Isle of Skye.', 'skye-core' ),
				'columns'   => [
					[ 'title' => __( 'Explore', 'skye-core' ), 'links' => array_merge( $link( PostTypes::PLACE, __( 'What to see', 'skye-core' ) ), $link( PostTypes::ROUTE, __( 'Routes', 'skye-core' ) ) ) ],
					[ 'title' => __( 'Book', 'skye-core' ), 'links' => array_merge( $link( PostTypes::TOUR, __( 'Tours from Edinburgh', 'skye-core' ) ), $link( PostTypes::LODGING, __( 'Where to stay', 'skye-core' ) ), $link( PostTypes::MOTORHOME, __( 'Car and motorhome', 'skye-core' ) ) ) ],
				],
				'languages' => self::languages(),
				'brands'    => array_column( Brands::all(), 'title' ),
			]
		);
	}
}
