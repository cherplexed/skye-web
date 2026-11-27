<?php
declare(strict_types=1);

namespace Skye\Render;

use Skye\Units;

/**
 * Server-rendered blocks of a Tour page and of the tours listing. Each takes the API object of the tour
 * (Api\Collection::item, with its `offer` for the market) and returns escaped HTML, or '' when empty.
 * Placed by the Bricks templates with [skye_tour part="…"] and [skye_tours].
 */
final class TourBlocks {

	public const PARTS = [ 'breadcrumb', 'cover', 'eyebrow', 'summary', 'offer', 'departure', 'itinerary', 'includes', 'verification' ];

	private const LANGUAGES = [ 'en' => 'English', 'es' => 'Spanish', 'it' => 'Italian' ];
	private const CITIES    = [ 'edinburgh' => 'Edinburgh', 'glasgow' => 'Glasgow' ];

	public static function render( string $part, array $t, string $market ): string {
		return match ( $part ) {
			'breadcrumb'   => self::breadcrumb( $t ),
			'cover'        => self::cover( $t ),
			'eyebrow'      => self::eyebrow( $t ),
			'summary'      => self::summary( $t ),
			'offer'        => self::offer( $t, $market ),
			'departure'    => self::departure( $t ),
			'itinerary'    => self::itinerary( $t ),
			'includes'     => self::includes( $t ),
			'verification' => PlaceBlocks::verification( $t ),
			default        => '',
		};
	}

	public static function breadcrumb( array $t ): string {
		$links = $t['_links'] ?? [];
		$item  = static fn( string $label, ?string $url ): string => $url ? sprintf( '<a href="%s">%s</a>', Html::e( $url ), Html::e( $label ) ) : Html::e( $label );
		$trail = [ $item( Html::t( 'Home' ), $links['home'] ?? null ), $item( Html::t( 'Tours' ), $links['tours'] ?? null ), '<span aria-current="page">' . Html::e( $t['title'] ?? '' ) . '</span>' ];
		return '<nav class="skye-breadcrumb" aria-label="' . Html::e( Html::t( 'Breadcrumb' ) ) . '">' . implode( ' <span aria-hidden="true">›</span> ', $trail ) . '</nav>';
	}

	public static function cover( array $t ): string {
		$img = $t['image'] ?? null;
		if ( ! $img ) {
			return '';
		}
		$html = ! empty( $img['html'] ) ? (string) $img['html'] : sprintf( '<img src="%s" alt="%s" loading="eager" decoding="async">', Html::e( $img['url'] ?? '' ), Html::e( $img['alt'] ?? '' ) );
		return '<div class="skye-gallery skye-gallery--1"><figure class="skye-gallery__item">' . $html . '</figure></div>';
	}

	/** "3 days · 2 nights" */
	public static function length( array $t ): string {
		$days   = (int) ( $t['days_count'] ?? 0 );
		$nights = (int) ( $t['nights_count'] ?? 0 );
		$parts  = [];
		if ( $days > 0 ) {
			$parts[] = 1 === $days ? Html::t( '1 day' ) : sprintf( Html::t( '%d days' ), $days );
		}
		if ( $nights > 0 ) {
			$parts[] = 1 === $nights ? Html::t( '1 night' ) : sprintf( Html::t( '%d nights' ), $nights );
		}
		return implode( ' · ', $parts );
	}

	/** "FROM EDINBURGH · 3 DAYS · 2 NIGHTS" above the title. */
	public static function eyebrow( array $t ): string {
		$city  = self::CITIES[ (string) ( $t['departure_city'] ?? '' ) ] ?? '';
		$parts = array_filter( [ '' !== $city ? sprintf( Html::t( 'From %s' ), $city ) : '', self::length( $t ) ] );
		return $parts ? '<span class="skye-eyebrow">' . Html::e( implode( ' · ', $parts ) ) . '</span>' : '';
	}

	public static function summary( array $t ): string {
		return ! empty( $t['summary'] ) ? '<div class="skye-summary"><p class="skye-summary__text">' . Html::e( $t['summary'] ) . '</p></div>' : '';
	}

	/** "From £399" — null when Bókun gave no price. */
	public static function price( array $t, string $market ): ?string {
		$offer = $t['offer'] ?? null;
		if ( ! is_array( $offer ) || ! isset( $offer['price_from'], $offer['currency'] ) ) {
			return null;
		}
		return Units::format_money( (float) $offer['price_from'], (string) $offer['currency'], $market );
	}

	/** Who runs the tour in this market and in which language; the audioguide when the visitor reads another one. */
	private static function language_note( array $t ): string {
		$offer = $t['offer'] ?? [];
		$code  = (string) ( $offer['brand']['tour_language'] ?? '' );
		if ( ! isset( self::LANGUAGES[ $code ] ) ) {
			return '';
		}
		$note = sprintf( Html::t( 'Guided in %s.' ), Html::t( self::LANGUAGES[ $code ] ) );
		$page = substr( strtolower( (string) ( $offer['market'] ?? '' ) ), 0, 2 );
		if ( ! empty( $t['includes_audioguide'] ) ) {
			$note .= ' ' . ( '' !== $page && $page !== $code ? Html::t( 'Audioguide in your language included.' ) : Html::t( 'Audioguide included.' ) );
		}
		return $note;
	}

	/** The booking card: price, next departure, language and the button to the operator's booking page. */
	public static function offer( array $t, string $market ): string {
		$offer = $t['offer'] ?? null;
		if ( ! is_array( $offer ) ) {
			return '';
		}
		$out   = '';
		$price = self::price( $t, $market );
		if ( null !== $price ) {
			$out .= sprintf( '<p class="skye-offer__price"><span class="skye-offer__from">%s</span> <strong>%s</strong> <span class="skye-offer__per">%s</span></p>', Html::e( Html::t( 'From' ) ), Html::e( $price ), Html::e( Html::t( 'per person' ) ) );
		}
		$next = Html::date( $offer['next_available'] ?? null );
		if ( '' !== $next ) {
			$out .= '<p class="skye-offer__next">' . Html::e( sprintf( Html::t( 'Next departure: %s' ), $next ) ) . '</p>';
		}
		$note = self::language_note( $t );
		if ( '' !== $note ) {
			$out .= '<p class="skye-offer__note">' . Html::e( $note ) . '</p>';
		}
		if ( ! empty( $offer['booking_url'] ) ) {
			$out .= sprintf( '<a class="skye-btn skye-btn--primary" href="%s" rel="noopener">%s</a>', Html::e( $offer['booking_url'] ), Html::e( Html::t( 'See dates and book' ) ) );
		}
		if ( ! empty( $offer['brand']['title'] ) ) {
			$out .= '<p class="skye-offer__brand">' . Html::e( sprintf( Html::t( 'Operated by %s. You book directly with the operator.' ), $offer['brand']['title'] ) ) . '</p>';
		}
		return '' === $out ? '' : '<section class="skye-card skye-offer">' . $out . '</section>';
	}

	/** Departure card: city, time and meeting point. */
	public static function departure( array $t ): string {
		$rows = [];
		$city = self::CITIES[ (string) ( $t['departure_city'] ?? '' ) ] ?? '';
		$time = trim( (string) ( $t['departure_time'] ?? '' ) );
		if ( '' !== $city ) {
			$rows[ Html::t( 'Departure' ) ] = '' !== $time ? sprintf( Html::t( '%1$s at %2$s' ), $city, $time ) : $city;
		}
		if ( ! empty( $t['meeting_point'] ) ) {
			$rows[ Html::t( 'Meeting point' ) ] = (string) $t['meeting_point'];
		}
		$length = self::length( $t );
		if ( '' !== $length ) {
			$rows[ Html::t( 'Length' ) ] = $length;
		}
		if ( ! $rows ) {
			return '';
		}
		$html = '';
		foreach ( $rows as $label => $value ) {
			$html .= sprintf( '<div class="skye-fact"><dt>%s</dt><dd>%s</dd></div>', Html::e( $label ), Html::e( $value ) );
		}
		return sprintf( '<section class="skye-card skye-facts"><h2 class="skye-card__title">%s</h2><dl class="skye-facts__list">%s</dl></section>', Html::e( Html::t( 'Practical information' ) ), $html );
	}

	/** Day by day: title, what the day covers, and links to the stops that have their own page. */
	public static function itinerary( array $t ): string {
		$html = '';
		foreach ( $t['days'] ?? [] as $i => $day ) {
			$title   = trim( (string) ( $day['title'] ?? '' ) );
			$summary = trim( (string) ( $day['summary'] ?? '' ) );
			$stops   = '';
			foreach ( $day['stops'] ?? [] as $stop ) {
				$name   = ! empty( $stop['url'] ) ? sprintf( '<a href="%s">%s</a>', Html::e( $stop['url'] ), Html::e( $stop['title'] ) ) : Html::e( $stop['title'] );
				$stops .= '<li>' . $name . '</li>';
			}
			if ( '' === $title && '' === $summary && '' === $stops ) {
				continue;
			}
			$html .= '<li class="skye-day">';
			$html .= '<h3 class="skye-day__title"><span class="skye-day__n">' . Html::e( sprintf( Html::t( 'Day %d' ), $i + 1 ) ) . '</span>' . ( '' !== $title ? ' ' . Html::e( $title ) : '' ) . '</h3>';
			$html .= '' !== $summary ? '<p>' . Html::e( $summary ) . '</p>' : '';
			$html .= '' !== $stops ? '<ul class="skye-day__stops" aria-label="' . Html::e( Html::t( 'Stops with their own guide' ) ) . '">' . $stops . '</ul>' : '';
			$html .= '</li>';
		}
		return '' === $html ? '' : Html::block( 'itinerary', Html::t( 'Itinerary' ), '<ol class="skye-days">' . $html . '</ol>' );
	}

	/** One sentence per line in the editor, or sentences in a row: both become a list. @return list<string> */
	private static function lines( string $text ): array {
		$parts = preg_split( '/\R+|(?<=[.!?])\s+(?=\p{Lu})/u', trim( $text ) ) ?: [];
		return array_values( array_filter( array_map( static fn( $s ) => trim( $s ), $parts ) ) );
	}

	public static function includes( array $t ): string {
		$list = static function ( string $title, string $class, array $items ): string {
			if ( ! $items ) {
				return '';
			}
			return '<div class="skye-includes__col"><h3>' . Html::e( $title ) . '</h3><ul class="' . $class . '">' . implode( '', array_map( static fn( $i ) => '<li>' . Html::e( $i ) . '</li>', $items ) ) . '</ul></div>';
		};
		$html  = $list( Html::t( 'Included' ), 'skye-includes__yes', self::lines( (string) ( $t['includes'] ?? '' ) ) );
		$html .= $list( Html::t( 'Not included' ), 'skye-includes__no', self::lines( (string) ( $t['excludes'] ?? '' ) ) );
		return '' === $html ? '' : Html::block( 'includes', Html::t( 'What is included' ), '<div class="skye-includes__cols">' . $html . '</div>' );
	}

	/** Comparison card for the tours listing. */
	public static function card( array $t, string $market, bool $featured = false ): string {
		$img   = $t['image'] ?? null;
		$photo = $img ? ( ! empty( $img['html'] ) ? (string) $img['html'] : sprintf( '<img src="%s" alt="%s" loading="lazy" decoding="async">', Html::e( $img['url'] ?? '' ), Html::e( $img['alt'] ?? '' ) ) ) : '';
		$price = self::price( $t, $market );
		$days  = '';
		foreach ( $t['day_titles'] ?? array_column( $t['days'] ?? [], 'title' ) as $i => $title ) {
			if ( '' !== trim( (string) $title ) ) {
				$days .= '<div class="skye-tourcard__day"><strong>' . Html::e( sprintf( Html::t( 'Day %d' ), $i + 1 ) ) . '</strong><span>' . Html::e( $title ) . '</span></div>';
			}
		}
		$buttons = '';
		if ( ! empty( $t['offer']['booking_url'] ) ) {
			$buttons .= sprintf( '<a class="skye-btn skye-btn--primary" href="%s" rel="noopener">%s</a>', Html::e( $t['offer']['booking_url'] ), Html::e( Html::t( 'See dates and book' ) ) );
		}
		if ( ! empty( $t['url'] ) ) {
			$buttons .= sprintf( '<a class="skye-btn skye-btn--outline" href="%s">%s</a>', Html::e( $t['url'] ), Html::e( Html::t( 'Full itinerary' ) ) );
		}
		$note = self::language_note( $t );

		return '<article class="skye-card skye-tourcard' . ( $featured ? ' skye-tourcard--featured' : '' ) . '">'
			. '<div class="skye-tourcard__photo">' . $photo . '</div>'
			. '<div class="skye-tourcard__body">'
			. '<div class="skye-tourcard__head"><div>'
			. ( $featured ? '<span class="skye-eyebrow">' . Html::e( Html::t( 'The most complete' ) ) . '</span>' : '' )
			. '<h2>' . Html::e( $t['title'] ?? '' ) . '</h2><span class="skye-tourcard__meta">' . Html::e( self::length( $t ) ) . '</span></div>'
			. ( null !== $price ? '<div class="skye-tourcard__price"><span>' . Html::e( Html::t( 'From' ) ) . '</span><strong>' . Html::e( $price ) . '</strong><span>' . Html::e( Html::t( 'per person' ) ) . '</span></div>' : '' )
			. '</div>'
			. ( ! empty( $t['summary'] ) ? '<p class="skye-tourcard__summary">' . Html::e( $t['summary'] ) . '</p>' : '' )
			. ( '' !== $days ? '<div class="skye-tourcard__days">' . $days . '</div>' : '' )
			. ( '' !== $note ? '<p class="skye-tourcard__note">' . Html::e( $note ) . '</p>' : '' )
			. ( '' !== $buttons ? '<div class="skye-tourcard__actions">' . $buttons . '</div>' : '' )
			. '</div></article>';
	}

	/**
	 * The tours listing: intro and one card per tour, shortest first; the longest is the featured one.
	 *
	 * @param list<array<string, mixed>> $tours
	 */
	public static function listing( array $tours, string $market ): string {
		usort( $tours, static fn( $a, $b ) => (int) ( $a['days_count'] ?? 0 ) <=> (int) ( $b['days_count'] ?? 0 ) );
		$lengths = array_values( array_unique( array_filter( array_map( static fn( $t ) => (int) ( $t['days_count'] ?? 0 ), $tours ) ) ) );
		$title   = match ( count( $lengths ) ) {
			0       => Html::t( 'Isle of Skye tours' ),
			1       => sprintf( Html::t( 'Isle of Skye tours of %d days' ), $lengths[0] ),
			default => sprintf( Html::t( 'Isle of Skye tours of %1$s and %2$d days' ), implode( ', ', array_slice( $lengths, 0, -1 ) ), end( $lengths ) ),
		};
		$intro = '<header class="skye-listing__intro"><span class="skye-eyebrow">' . Html::e( Html::t( 'Departing from Edinburgh' ) ) . '</span>'
			. '<h1 class="skye-listing__title">' . Html::e( $title ) . '</h1>'
			. '<p class="skye-listing__lead">' . Html::e( Html::t( 'No car, no single-track roads, no hunting for a bed. We pick you up in the centre of Edinburgh and take you through the Highlands to Skye with a guide.' ) ) . '</p></header>';
		$cards = '';
		$last  = count( $tours ) - 1;
		foreach ( $tours as $i => $t ) {
			$cards .= self::card( $t, $market, $last > 0 && $i === $last );
		}
		return '<div class="skye-listing">' . $intro . ( '' !== $cards ? '<div class="skye-tourcards">' . $cards . '</div>' : '<p>' . Html::e( Html::t( 'No tours are on sale right now.' ) ) . '</p>' ) . '</div>';
	}
}
