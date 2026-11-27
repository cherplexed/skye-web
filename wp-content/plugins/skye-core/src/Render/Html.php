<?php
declare(strict_types=1);

namespace Skye\Render;

/** Tiny helpers so the block renderers stay pure (no WordPress needed) and always escape. */
final class Html {

	public static function e( mixed $s ): string {
		return htmlspecialchars( (string) $s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8' );
	}

	/** Translate when WordPress is loaded; identity otherwise (unit tests). */
	public static function t( string $s ): string {
		// phpcs:ignore WordPress.WP.I18n.NonSingularStringLiteralText -- strings are literals at every call site.
		return function_exists( '__' ) ? __( $s, 'skye-core' ) : $s;
	}

	/** <section class="skye-block skye-{name}"> with an h2, or '' when there is no body. */
	public static function block( string $name, string $title, string $body ): string {
		if ( '' === trim( $body ) ) {
			return '';
		}
		return sprintf( '<section class="skye-block skye-%s"><h2 class="skye-block__title">%s</h2>%s</section>', self::e( $name ), self::e( $title ), $body );
	}

	public static function date( ?string $ymd ): string {
		$ts = $ymd ? strtotime( $ymd ) : false;
		if ( ! $ts ) {
			return '';
		}
		return function_exists( 'date_i18n' ) ? date_i18n( 'j F Y', $ts ) : gmdate( 'j F Y', $ts );
	}
}
