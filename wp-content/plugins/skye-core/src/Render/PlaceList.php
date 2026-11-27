<?php
declare(strict_types=1);

namespace Skye\Render;

use Skye\Fields;
use Skye\Units;

/**
 * The places directory and the zone pages. Pure: takes the view built by Frontend::places_view()
 * and returns escaped HTML. Filters are plain links, so the page works without JavaScript and every
 * filtered view is a real URL.
 */
final class PlaceList {

	/** One place as a card: photo, name, a line of facts and the one-sentence summary. */
	public static function card( array $p, string $heading = 'h3' ): string {
		$img   = $p['image'] ?? null;
		$photo = $img ? ( ! empty( $img['html'] ) ? (string) $img['html'] : sprintf( '<img src="%s" alt="%s" loading="lazy" decoding="async">', Html::e( $img['url'] ?? '' ), Html::e( $img['alt'] ?? '' ) ) ) : '';

		$facts = [];
		if ( (int) ( $p['duration_typical'] ?? 0 ) > 0 ) {
			$facts[] = Units::format_duration( (int) $p['duration_typical'] );
		}
		$difficulty = (string) ( $p['difficulty'] ?? 'none' );
		$choices    = Fields::place()['difficulty']['choices'] ?? [];
		if ( 'none' !== $difficulty && isset( $choices[ $difficulty ] ) ) {
			$facts[] = Html::t( $choices[ $difficulty ] );
		}
		$facts[] = match ( (string) ( $p['price_type'] ?? 'free' ) ) {
			'ticket'       => Html::t( 'Ticket' ),
			'paid_parking' => Html::t( 'Paid parking' ),
			default        => Html::t( 'Free' ),
		};

		$kind  = (string) ( $p['categories'][0]['name'] ?? '' );
		$inner = '<div class="skye-placecard__photo">' . $photo . '</div><div class="skye-placecard__body">'
			. ( '' !== $kind ? '<span class="skye-placecard__kind">' . Html::e( $kind ) . '</span>' : '' )
			. sprintf( '<%1$s class="skye-placecard__title">%2$s</%1$s>', 'h2' === $heading ? 'h2' : 'h3', Html::e( $p['title'] ?? '' ) )
			. '<span class="skye-placecard__meta">' . Html::e( implode( ' · ', $facts ) ) . '</span>'
			. ( ! empty( $p['summary'] ) ? '<p class="skye-placecard__summary">' . Html::e( $p['summary'] ) . '</p>' : '' )
			. '</div>';
		return ! empty( $p['url'] )
			? sprintf( '<a class="skye-card skye-placecard" href="%s">%s</a>', Html::e( $p['url'] ), $inner )
			: '<article class="skye-card skye-placecard">' . $inner . '</article>';
	}

	/**
	 * One row of filter links.
	 *
	 * @param array{label:string, all:array{label:string,url:string,active:bool}, options:list<array{name:string,url:string,count:int,active:bool}>} $f
	 */
	public static function filter( array $f ): string {
		if ( ! $f['options'] ) {
			return '';
		}
		$chip = static fn( string $label, string $url, bool $active, ?int $count = null ): string => sprintf(
			'<li><a class="skye-chip skye-filter__chip" href="%s"%s>%s%s</a></li>',
			Html::e( $url ),
			$active ? ' aria-current="true"' : '',
			Html::e( $label ),
			null !== $count ? ' <span class="skye-filter__count">' . (int) $count . '</span>' : ''
		);
		$html = $chip( $f['all']['label'], $f['all']['url'], $f['all']['active'] );
		foreach ( $f['options'] as $o ) {
			$html .= $chip( $o['name'], $o['url'], $o['active'], $o['count'] );
		}
		return sprintf( '<div class="skye-filter"><span class="skye-filter__label">%s</span><ul class="skye-filter__chips">%s</ul></div>', Html::e( $f['label'] ), $html );
	}

	/**
	 * The whole page.
	 *
	 * @param array{eyebrow?:string, title:string, lead?:string, breadcrumb?:list<array{label:string,url?:?string}>, filters:list<array<string,mixed>>, groups:list<array{name:string,url?:?string,places:list<array<string,mixed>>}>, total:int, filtered:bool, reset_url:string, grouped:bool} $v
	 */
	public static function directory( array $v ): string {
		$html = '';
		if ( ! empty( $v['breadcrumb'] ) ) {
			$trail = [];
			$last  = count( $v['breadcrumb'] ) - 1;
			foreach ( $v['breadcrumb'] as $i => $b ) {
				$trail[] = $i === $last
					? '<span aria-current="page">' . Html::e( $b['label'] ) . '</span>'
					: ( ! empty( $b['url'] ) ? sprintf( '<a href="%s">%s</a>', Html::e( $b['url'] ), Html::e( $b['label'] ) ) : Html::e( $b['label'] ) );
			}
			$html .= '<nav class="skye-breadcrumb" aria-label="' . Html::e( Html::t( 'Breadcrumb' ) ) . '">' . implode( ' <span aria-hidden="true">›</span> ', $trail ) . '</nav>';
		}
		$html .= '<header class="skye-listing__intro">'
			. ( ! empty( $v['eyebrow'] ) ? '<span class="skye-eyebrow">' . Html::e( $v['eyebrow'] ) . '</span>' : '' )
			. '<h1 class="skye-listing__title">' . Html::e( $v['title'] ) . '</h1>'
			. ( ! empty( $v['lead'] ) ? '<p class="skye-listing__lead">' . Html::e( $v['lead'] ) . '</p>' : '' )
			. '</header>';

		$filters = implode( '', array_map( [ self::class, 'filter' ], $v['filters'] ) );
		if ( '' !== $filters ) {
			$html .= '<nav class="skye-filters" aria-label="' . Html::e( Html::t( 'Filter places' ) ) . '">' . $filters . '</nav>';
		}

		$count = 1 === $v['total'] ? Html::t( '1 place' ) : sprintf( Html::t( '%d places' ), $v['total'] );
		$html .= '<p class="skye-listing__count">' . Html::e( $count )
			. ( $v['filtered'] ? ' · <a href="' . Html::e( $v['reset_url'] ) . '">' . Html::e( Html::t( 'Clear filters' ) ) . '</a>' : '' ) . '</p>';

		if ( 0 === $v['total'] ) {
			return '<div class="skye-listing skye-directory">' . $html . '<p>' . Html::e( Html::t( 'No places match these filters.' ) ) . '</p></div>';
		}
		foreach ( $v['groups'] as $g ) {
			$cards = implode( '', array_map( static fn( $p ) => self::card( $p, $v['grouped'] ? 'h3' : 'h2' ), $g['places'] ) );
			if ( $v['grouped'] ) {
				$n     = count( $g['places'] );
				$title = ! empty( $g['url'] ) ? sprintf( '<a href="%s">%s</a>', Html::e( $g['url'] ), Html::e( $g['name'] ) ) : Html::e( $g['name'] );
				$html .= '<section class="skye-zone"><h2 class="skye-zone__title">' . $title . ' <span class="skye-zone__count">' . $n . '</span></h2><div class="skye-placecards">' . $cards . '</div></section>';
			} else {
				$html .= '<div class="skye-placecards">' . $cards . '</div>';
			}
		}
		return '<div class="skye-listing skye-directory">' . $html . '</div>';
	}
}
