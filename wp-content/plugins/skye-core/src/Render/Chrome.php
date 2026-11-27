<?php
declare(strict_types=1);

namespace Skye\Render;

/**
 * Site header and footer. Pure: the front end passes the links, languages and brand names.
 * No JavaScript: on small screens the navigation becomes a horizontally scrollable row and the
 * language switcher is a native <details>.
 */
final class Chrome {

	private static function logo( string $home ): string {
		return sprintf( '<a class="skye-logo" href="%s">skye<span>guide</span></a>', Html::e( $home ) );
	}

	/**
	 * @param array{home:string, links:list<array{label:string,url:string,current?:bool}>, languages:list<array{code:string,label:string,url:string,active?:bool}>, cta:?array{label:string,url:string}} $d
	 */
	public static function header( array $d ): string {
		$nav = '';
		foreach ( $d['links'] as $link ) {
			$nav .= sprintf( '<a href="%s"%s>%s</a>', Html::e( $link['url'] ), ! empty( $link['current'] ) ? ' aria-current="page"' : '', Html::e( $link['label'] ) );
		}

		$lang = '';
		if ( count( $d['languages'] ) > 1 ) {
			$active = '';
			$items  = '';
			foreach ( $d['languages'] as $l ) {
				if ( ! empty( $l['active'] ) ) {
					$active = strtoupper( $l['code'] );
					continue;
				}
				$items .= sprintf( '<li><a href="%s" lang="%s" hreflang="%s">%s</a></li>', Html::e( $l['url'] ), Html::e( $l['code'] ), Html::e( $l['code'] ), Html::e( $l['label'] ) );
			}
			$lang = sprintf( '<details class="skye-lang"><summary class="skye-chip" aria-label="%s">%s</summary><ul>%s</ul></details>', Html::e( Html::t( 'Change language' ) ), Html::e( $active ), $items );
		}
		$cta = ! empty( $d['cta'] ) ? sprintf( '<a class="skye-btn skye-btn--primary" href="%s">%s</a>', Html::e( $d['cta']['url'] ), Html::e( $d['cta']['label'] ) ) : '';

		return '<div class="skye-header"><div class="skye-wrap skye-header__inner">'
			. self::logo( $d['home'] )
			. ( '' !== $nav ? '<nav class="skye-nav" aria-label="' . Html::e( Html::t( 'Main' ) ) . '">' . $nav . '</nav>' : '' )
			. '<div class="skye-header__actions">' . $lang . $cta . '</div>'
			. '</div></div>';
	}

	/**
	 * @param array{home:string, tagline:string, columns:list<array{title:string, links:list<array{label:string,url:string}>}>, languages:list<array{label:string,url:string}>, brands:list<string>} $d
	 */
	public static function footer( array $d ): string {
		$about = '<span class="skye-footer__logo">skyeguide</span><span>' . Html::e( $d['tagline'] ) . '</span>';
		if ( $d['brands'] ) {
			$about .= '<span>' . Html::e( sprintf( Html::t( 'Tours operated by TA Scotland (%s).' ), implode( ', ', $d['brands'] ) ) ) . '</span>';
		}
		$cols = '<div class="skye-footer__col">' . $about . '</div>';
		foreach ( $d['columns'] as $col ) {
			if ( ! $col['links'] ) {
				continue;
			}
			$links = '';
			foreach ( $col['links'] as $link ) {
				$links .= sprintf( '<a href="%s">%s</a>', Html::e( $link['url'] ), Html::e( $link['label'] ) );
			}
			$cols .= '<div class="skye-footer__col"><strong>' . Html::e( $col['title'] ) . '</strong>' . $links . '</div>';
		}
		if ( $d['languages'] ) {
			$links = '';
			foreach ( $d['languages'] as $l ) {
				$links .= sprintf( '<a href="%s">%s</a>', Html::e( $l['url'] ), Html::e( $l['label'] ) );
			}
			$cols .= '<div class="skye-footer__col"><strong>' . Html::e( Html::t( 'Languages' ) ) . '</strong>' . $links . '</div>';
		}
		return '<div class="skye-footer"><div class="skye-wrap skye-footer__inner">' . $cols . '</div></div>';
	}
}
