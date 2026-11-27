<?php
declare(strict_types=1);

namespace Skye\Render;

/**
 * The home page. Pure: takes the view built by Frontend::home_view() and returns escaped HTML.
 * Everything on it comes from the content already loaded (tours, routes, zones, places), so it
 * never says anything the rest of the site does not.
 */
final class Home {

	private static function photo( ?array $img, bool $eager = false ): string {
		if ( ! $img ) {
			return '';
		}
		return ! empty( $img['html'] ) ? (string) $img['html'] : sprintf( '<img src="%s" alt="%s" loading="%s" decoding="async">', Html::e( $img['url'] ?? '' ), Html::e( $img['alt'] ?? '' ), $eager ? 'eager' : 'lazy' );
	}

	private static function button( ?string $url, string $label, string $style ): string {
		return $url ? sprintf( '<a class="skye-btn skye-btn--%s" href="%s">%s</a>', $style, Html::e( $url ), Html::e( $label ) ) : '';
	}

	private static function heading( string $eyebrow, string $title, ?string $more_url = null, string $more_label = '' ): string {
		return '<div class="skye-home__head"><div><span class="skye-eyebrow">' . Html::e( $eyebrow ) . '</span><h2 class="skye-home__title">' . Html::e( $title ) . '</h2></div>'
			. ( $more_url ? sprintf( '<a class="skye-home__more" href="%s">%s</a>', Html::e( $more_url ), Html::e( $more_label ) ) : '' ) . '</div>';
	}

	public static function hero( array $v ): string {
		$links = $v['links'];
		$photo = self::photo( $v['hero_image'] ?? null, true );
		return '<section class="skye-hero"><div class="skye-hero__text">'
			. '<span class="skye-eyebrow">' . Html::e( Html::t( 'Isle of Skye · Scotland' ) ) . '</span>'
			. '<h1 class="skye-hero__title">' . Html::e( Html::t( 'Everything you need to see the Isle of Skye' ) ) . '</h1>'
			. '<p class="skye-hero__lead">' . Html::e( Html::t( 'Places, routes and how to get there. And if you have no car, we take you from Edinburgh in two or three days.' ) ) . '</p>'
			. '<div class="skye-hero__actions">' . self::button( $links['tours'] ?? null, Html::t( 'Tours from Edinburgh' ), 'primary' ) . self::button( $links['places'] ?? null, Html::t( 'What to see' ), 'outline' ) . '</div>'
			. '</div>' . ( '' !== $photo ? '<div class="skye-hero__photo">' . $photo . '</div>' : '' ) . '</section>';
	}

	/** Two ways in: with us from Edinburgh, or on your own wheels. */
	public static function paths( array $v, string $market ): string {
		$links = $v['links'];
		$chips = '';
		foreach ( $v['tours'] as $t ) {
			$label = TourBlocks::length( [ 'days_count' => $t['days_count'] ?? 0 ] );
			$price = TourBlocks::price( $t, $market );
			$label = null !== $price ? sprintf( Html::t( '%1$s · from %2$s' ), $label, $price ) : $label;
			if ( '' !== $label && ! empty( $t['url'] ) ) {
				$chips .= sprintf( '<a class="skye-chip" href="%s">%s</a>', Html::e( $t['url'] ), Html::e( $label ) );
			}
		}
		$tours = '';
		if ( $v['tours'] ) {
			$photo = self::photo( $v['tours'][0]['image'] ?? null );
			$tours = '<article class="skye-card skye-path">' . ( '' !== $photo ? '<div class="skye-path__photo">' . $photo . '</div>' : '' ) . '<div class="skye-path__body">'
				. '<span class="skye-eyebrow">' . Html::e( Html::t( 'No car' ) ) . '</span>'
				. '<h3>' . Html::e( Html::t( 'We take you from Edinburgh' ) ) . '</h3>'
				. '<p>' . Html::e( Html::t( 'Tours of two and three days with a driver-guide. No single-track roads to drive and no hunting for a bed.' ) ) . '</p>'
				. ( '' !== $chips ? '<div class="skye-path__chips">' . $chips . '</div>' : '' )
				. self::button( $links['tours'] ?? null, Html::t( 'See tours and dates' ), 'primary' )
				. '</div></article>';
		}
		$own = '';
		if ( $v['routes'] ) {
			$chips = '';
			foreach ( array_slice( $v['routes'], 0, 3 ) as $r ) {
				$chips .= ! empty( $r['url'] ) ? sprintf( '<a class="skye-chip" href="%s">%s</a>', Html::e( $r['url'] ), Html::e( $r['title'] ?? '' ) ) : '';
			}
			$photo = self::photo( $v['own_image'] ?? null );
			$own   = '<article class="skye-card skye-path">' . ( '' !== $photo ? '<div class="skye-path__photo">' . $photo . '</div>' : '' ) . '<div class="skye-path__body">'
				. '<span class="skye-eyebrow">' . Html::e( Html::t( 'Car or motorhome' ) ) . '</span>'
				. '<h3>' . Html::e( Html::t( 'Plan it yourself, with what you need to know' ) ) . '</h3>'
				. '<p>' . Html::e( Html::t( 'Day-by-day routes with every stop in order, how long to allow, where to park and what the roads are like.' ) ) . '</p>'
				. ( '' !== $chips ? '<div class="skye-path__chips">' . $chips . '</div>' : '' )
				. self::button( $links['routes'] ?? null, Html::t( 'See the routes' ), 'outline' )
				. '</div></article>';
		}
		return '' === $tours . $own ? '' : '<section class="skye-home__section"><h2 class="skye-home__title">' . Html::e( Html::t( 'Two ways to see Skye' ) ) . '</h2><div class="skye-paths">' . $tours . $own . '</div></section>';
	}

	/** The island by area: one card per zone that has places. */
	public static function zones( array $v ): string {
		$cards = '';
		foreach ( $v['zones'] as $z ) {
			$count  = 1 === $z['count'] ? Html::t( '1 place' ) : sprintf( Html::t( '%d places' ), $z['count'] );
			$cards .= sprintf(
				'<a class="skye-card skye-zonecard" href="%s"><strong>%s</strong><span class="skye-zonecard__count">%s</span>%s</a>',
				Html::e( $z['url'] ),
				Html::e( $z['name'] ),
				Html::e( $count ),
				'' !== $z['intro'] ? '<span class="skye-zonecard__intro">' . Html::e( $z['intro'] ) . '</span>' : ''
			);
		}
		return '' === $cards ? '' : '<section class="skye-home__section">' . self::heading( Html::t( 'By area' ), Html::t( 'The island, area by area' ), $v['links']['places'] ?? null, Html::t( 'See every place' ) ) . '<div class="skye-zonecards">' . $cards . '</div></section>';
	}

	/** Places by what the visitor likes: links into the directory, already filtered. */
	public static function profiles( array $v ): string {
		$chips = '';
		foreach ( $v['profiles'] as $p ) {
			$chips .= sprintf( '<li><a class="skye-chip" href="%s">%s <span class="skye-filter__count">%d</span></a></li>', Html::e( $p['url'] ), Html::e( $p['name'] ), (int) $p['count'] );
		}
		return '' === $chips ? '' : '<section class="skye-home__section">' . self::heading( Html::t( 'By interest' ), Html::t( 'Skye for what you like' ) ) . '<ul class="skye-profilechips">' . $chips . '</ul></section>';
	}

	public static function places( array $v ): string {
		$cards = implode( '', array_map( static fn( $p ) => PlaceList::card( $p ), $v['places'] ) );
		return '' === $cards ? '' : '<section class="skye-home__section">' . self::heading( Html::t( 'Not to be missed' ), Html::t( 'The places everyone comes for' ), $v['links']['places'] ?? null, Html::t( 'See every place' ) ) . '<div class="skye-placecards">' . $cards . '</div></section>';
	}

	public static function page( array $v, string $market ): string {
		return '<div class="skye-home">' . self::hero( $v ) . self::paths( $v, $market ) . self::places( $v ) . self::zones( $v ) . self::profiles( $v ) . '</div>';
	}
}
