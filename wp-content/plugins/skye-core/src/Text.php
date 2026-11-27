<?php
declare(strict_types=1);

namespace Skye;

/**
 * WordPress hands out titles and term names already HTML-encoded ("Minginish &amp; Cuillin",
 * "Skye&#8217;s"). The API speaks plain text and each renderer escapes for its own output, so
 * decode once at the boundary; otherwise HTML shows "&amp;" and JSON clients get entities.
 */
final class Text {

	public static function plain( mixed $s ): string {
		return html_entity_decode( (string) $s, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
	}

	/** Plain-text title of a post. */
	public static function title( mixed $post ): string {
		return self::plain( get_the_title( $post ) );
	}
}
