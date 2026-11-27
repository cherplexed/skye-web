<?php
declare(strict_types=1);

namespace Skye;

/**
 * Front-end glue: the [skye_place] shortcode that Bricks templates use to place the server-rendered
 * blocks of a Place, and the language/market of the current page view.
 */
final class Frontend {

	public static function register(): void {
		add_shortcode( 'skye_place', [ self::class, 'place_shortcode' ] );
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
		$key            = $post->ID . '|' . $lang . '|' . $market;
		$items[ $key ] ??= Api\Places::item( $post, $lang, $market );
		return Render\PlaceBlocks::render( $atts['part'], $items[ $key ], $market );
	}
}
