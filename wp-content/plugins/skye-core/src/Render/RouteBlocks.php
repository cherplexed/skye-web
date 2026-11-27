<?php
declare(strict_types=1);

namespace Skye\Render;

use Skye\Fields;
use Skye\Taxonomies;
use Skye\Units;

/**
 * Server-rendered blocks of a Route page and of the routes listing. Each takes the API object of the
 * route (Api\Collection::item) and returns escaped HTML, or '' when empty.
 * Placed by the Bricks templates with [skye_route part="…"] and [skye_routes].
 */
final class RouteBlocks {

	public const PARTS = [ 'breadcrumb', 'cover', 'eyebrow', 'summary', 'facts', 'itinerary', 'motorhome', 'tour', 'verification' ];

	public static function render( string $part, array $r, string $market ): string {
		return match ( $part ) {
			'breadcrumb'   => self::breadcrumb( $r ),
			'cover'        => TourBlocks::cover( $r ),
			'eyebrow'      => self::eyebrow( $r ),
			'summary'      => TourBlocks::summary( $r ),
			'facts'        => self::facts( $r, $market ),
			'itinerary'    => self::itinerary( $r ),
			'motorhome'    => self::motorhome( $r ),
			'tour'         => self::tour( $r, $market ),
			'verification' => PlaceBlocks::verification( $r ),
			default        => '',
		};
	}

	public static function breadcrumb( array $r ): string {
		$links = $r['_links'] ?? [];
		$item  = static fn( string $label, ?string $url ): string => $url ? sprintf( '<a href="%s">%s</a>', Html::e( $url ), Html::e( $label ) ) : Html::e( $label );
		$trail = [ $item( Html::t( 'Home' ), $links['home'] ?? null ), $item( Html::t( 'Routes' ), $links['routes'] ?? null ), '<span aria-current="page">' . Html::e( $r['title'] ?? '' ) . '</span>' ];
		return '<nav class="skye-breadcrumb" aria-label="' . Html::e( Html::t( 'Breadcrumb' ) ) . '">' . implode( ' <span aria-hidden="true">›</span> ', $trail ) . '</nav>';
	}

	private static function choice( string $field, array $r ): string {
		$choices = Fields::route()[ $field ]['choices'];
		$value   = (string) ( $r[ $field ] ?? '' );
		return isset( $choices[ $value ] ) ? Html::t( $choices[ $value ] ) : '';
	}

	private static function days_label( array $r ): string {
		$days = max( 1, (int) ( $r['days_count'] ?? 1 ) );
		return 1 === $days ? Html::t( '1 day' ) : sprintf( Html::t( '%d days' ), $days );
	}

	/** "ROUTE BY CAR · 1 DAY" above the title. */
	public static function eyebrow( array $r ): string {
		$kind = match ( (string) ( $r['route_type'] ?? '' ) ) {
			'walk'      => Html::t( 'Walking route' ),
			'itinerary' => Html::t( 'Itinerary' ),
			default     => Html::t( 'Route by car' ),
		};
		return '<span class="skye-eyebrow">' . Html::e( $kind . ' · ' . self::days_label( $r ) ) . '</span>';
	}

	/** "At a glance" card: distance in both systems, time at the wheel, length, vehicle, number of stops. */
	public static function facts( array $r, string $market ): string {
		$rows = [];
		if ( isset( $r['total_km'] ) && (float) $r['total_km'] > 0 ) {
			$rows[ Html::t( 'Distance' ) ] = 'walk' === ( $r['route_type'] ?? '' ) ? Units::format_distance( (float) $r['total_km'], $market ) : Units::format_drive_distance( (float) $r['total_km'], $market );
		}
		if ( ! empty( $r['total_drive_minutes'] ) ) {
			$rows[ 'walk' === ( $r['route_type'] ?? '' ) ? Html::t( 'Walking time' ) : Html::t( 'Driving time' ) ] = sprintf( Html::t( '%s, without stops' ), Units::format_duration( (int) $r['total_drive_minutes'] ) );
		}
		$rows[ Html::t( 'Length' ) ] = self::days_label( $r );
		$stops                       = 0;
		foreach ( $r['days'] ?? [] as $day ) {
			$stops += count( array_filter( $day['stops'] ?? [], static fn( $s ) => is_array( $s['place'] ?? null ) ) );
		}
		if ( $stops > 0 ) {
			$rows[ Html::t( 'Stops' ) ] = (string) $stops;
		}
		$vehicle = self::choice( 'vehicle', $r );
		if ( '' !== $vehicle && 'any' !== ( $r['vehicle'] ?? '' ) ) {
			$rows[ Html::t( 'Getting around' ) ] = $vehicle;
		}
		if ( array_key_exists( 'motorhome_ok', $r ) && 'walk' !== ( $r['route_type'] ?? '' ) ) {
			$rows[ Html::t( 'Motorhomes' ) ] = ! empty( $r['motorhome_ok'] ) ? Html::t( 'Suitable, with care' ) : Html::t( 'Not suitable' );
		}
		$html = '';
		foreach ( $rows as $label => $value ) {
			$html .= sprintf( '<div class="skye-fact"><dt>%s</dt><dd>%s</dd></div>', Html::e( $label ), Html::e( $value ) );
		}
		return sprintf( '<section class="skye-card skye-facts"><h2 class="skye-card__title">%s</h2><dl class="skye-facts__list">%s</dl></section>', Html::e( Html::t( 'At a glance' ) ), $html );
	}

	/**
	 * Link that opens the day's stops as one journey in a route planner: no map key, no JavaScript.
	 *
	 * @param list<array{lat:float,lng:float}> $points
	 */
	public static function directions_url( array $points, bool $walking = false ): ?string {
		if ( count( $points ) < 2 ) {
			return null;
		}
		$fmt  = static fn( array $p ): string => sprintf( '%.5F,%.5F', $p['lat'], $p['lng'] );
		$last = array_pop( $points );
		$url  = 'https://www.google.com/maps/dir/?api=1&origin=' . $fmt( array_shift( $points ) ) . '&destination=' . $fmt( $last );
		if ( $points ) {
			// The planner accepts up to nine intermediate points.
			$url .= '&waypoints=' . implode( '%7C', array_map( $fmt, array_slice( $points, 0, 9 ) ) );
		}
		return $url . '&travelmode=' . ( $walking ? 'walking' : 'driving' );
	}

	/** Day by day, stop by stop: time, place (linked, with photo), what to do there. */
	public static function itinerary( array $r ): string {
		$types   = Fields::route()['days']['sub']['stops']['sub']['stop_type']['choices'];
		$zones   = Taxonomies::terms()[ Taxonomies::ZONE ];
		$many    = count( $r['days'] ?? [] ) > 1;
		$walking = 'walk' === ( $r['route_type'] ?? '' );
		$html    = '';

		foreach ( $r['days'] ?? [] as $d => $day ) {
			$stops  = '';
			$points = [];
			foreach ( $day['stops'] ?? [] as $stop ) {
				$place = $stop['place'] ?? null;
				if ( ! is_array( $place ) ) {
					continue;
				}
				if ( ! empty( $place['location'] ) ) {
					$points[] = $place['location'];
				}
				$name  = ! empty( $place['url'] ) ? sprintf( '<a href="%s">%s</a>', Html::e( $place['url'] ), Html::e( $place['title'] ) ) : Html::e( $place['title'] );
				$type  = (string) ( $stop['stop_type'] ?? 'visit' );
				$badge = 'visit' !== $type && isset( $types[ $type ] ) ? ' <span class="skye-tag">' . Html::e( Html::t( $types[ $type ] ) ) . '</span>' : '';
				$photo = ! empty( $place['image']['html'] ) ? (string) $place['image']['html'] : ( ! empty( $place['image']['url'] ) ? sprintf( '<img src="%s" alt="" loading="lazy" decoding="async">', Html::e( $place['image']['url'] ) ) : '' );

				$stops .= '<li class="skye-stop">';
				$stops .= '<div class="skye-stop__time">' . Html::e( $stop['time_hint'] ?? '' ) . '</div>';
				$stops .= '<div class="skye-stop__photo">' . $photo . '</div>';
				$stops .= '<div class="skye-stop__body"><h4 class="skye-stop__title">' . $name . $badge . '</h4>';
				$stops .= ! empty( $stop['note'] ) ? '<p>' . Html::e( $stop['note'] ) . '</p>' : '';
				$stops .= '</div></li>';
			}
			if ( '' === $stops ) {
				continue;
			}
			$html .= '<div class="skye-routeday">';
			$html .= $many ? '<h3 class="skye-day__title"><span class="skye-day__n">' . Html::e( sprintf( Html::t( 'Day %d' ), $d + 1 ) ) . '</span></h3>' : '';
			$html .= '<ol class="skye-stops">' . $stops . '</ol>';

			$foot = '';
			$zone = (string) ( $day['night_zone'] ?? '' );
			if ( '' !== $zone && isset( $zones[ $zone ] ) ) {
				$where = Html::e( sprintf( Html::t( 'Night in %s' ), Html::t( $zones[ $zone ] ) ) );
				$stay  = $day['night_lodging'] ?? null;
				if ( is_array( $stay ) ) {
					$where .= ' · ' . ( ! empty( $stay['url'] ) ? sprintf( '<a href="%s">%s</a>', Html::e( $stay['url'] ), Html::e( $stay['title'] ) ) : Html::e( $stay['title'] ) );
				}
				$foot .= '<span class="skye-routeday__night">' . $where . '</span>';
			}
			$url = self::directions_url( $points, $walking );
			if ( null !== $url ) {
				$foot .= sprintf( '<a class="skye-btn skye-btn--outline" href="%s" rel="noopener nofollow">%s</a>', Html::e( $url ), Html::e( $many ? Html::t( 'Open this day in a route planner' ) : Html::t( 'Open the route in a route planner' ) ) );
			}
			$html .= '' !== $foot ? '<div class="skye-routeday__foot">' . $foot . '</div>' : '';
			$html .= '</div>';
		}
		return '' === $html ? '' : Html::block( 'itinerary', Html::t( 'The route, stop by stop' ), $html );
	}

	public static function motorhome( array $r ): string {
		$notes = trim( (string) ( $r['motorhome_notes'] ?? '' ) );
		return '' === $notes ? '' : Html::block( 'motorhome', Html::t( 'For motorhomes' ), '<p>' . Html::e( $notes ) . '</p>' );
	}

	/** "No car?" card: the guided tour that covers this route (its API object arrives in `_tour`). */
	public static function tour( array $r, string $market ): string {
		$tour = $r['_tour'] ?? null;
		if ( ! is_array( $tour ) ) {
			return '';
		}
		$out   = '<span class="skye-eyebrow">' . Html::e( Html::t( 'No car?' ) ) . '</span>';
		$out  .= '<strong class="skye-tours__lead">' . Html::e( sprintf( Html::t( 'These stops are part of our guided tour: %s' ), $tour['title'] ?? '' ) ) . '</strong>';
		$price = TourBlocks::price( $tour, $market );
		if ( null !== $price ) {
			$out .= '<span class="skye-offer__next">' . Html::e( sprintf( Html::t( 'From %s per person' ), $price ) ) . '</span>';
		}
		if ( ! empty( $tour['url'] ) ) {
			$out .= sprintf( '<a class="skye-btn skye-btn--primary" href="%s">%s</a>', Html::e( $tour['url'] ), Html::e( Html::t( 'See the tour' ) ) );
		}
		return '<section class="skye-card skye-tours">' . $out . '</section>';
	}

	/** Card for the routes listing. */
	public static function card( array $r, string $market ): string {
		$img   = $r['image'] ?? null;
		$photo = $img ? ( ! empty( $img['html'] ) ? (string) $img['html'] : sprintf( '<img src="%s" alt="%s" loading="lazy" decoding="async">', Html::e( $img['url'] ?? '' ), Html::e( $img['alt'] ?? '' ) ) ) : '';
		$meta  = array_filter( [ self::days_label( $r ), $r['display']['total_drive'] ?? null, ! empty( $r['display']['drive_time'] ) ? sprintf( Html::t( '%s driving' ), $r['display']['drive_time'] ) : null ] );
		$inner = '<div class="skye-tourcard__photo">' . $photo . '</div><div class="skye-tourcard__body">'
			. '<div><h2>' . Html::e( $r['title'] ?? '' ) . '</h2><span class="skye-tourcard__meta">' . Html::e( implode( ' · ', $meta ) ) . '</span></div>'
			. ( ! empty( $r['summary'] ) ? '<p class="skye-tourcard__summary">' . Html::e( $r['summary'] ) . '</p>' : '' )
			. '</div>';
		return ! empty( $r['url'] )
			? sprintf( '<a class="skye-card skye-tourcard skye-routecard" href="%s">%s</a>', Html::e( $r['url'] ), $inner )
			: '<article class="skye-card skye-tourcard skye-routecard">' . $inner . '</article>';
	}

	/** @param list<array<string, mixed>> $routes */
	public static function listing( array $routes, string $market ): string {
		$intro = '<header class="skye-listing__intro"><span class="skye-eyebrow">' . Html::e( Html::t( 'With your own wheels' ) ) . '</span>'
			. '<h1 class="skye-listing__title">' . Html::e( Html::t( 'Routes around the Isle of Skye' ) ) . '</h1>'
			. '<p class="skye-listing__lead">' . Html::e( Html::t( 'Day-by-day routes with every stop in order, how long to allow and where to sleep.' ) ) . '</p></header>';
		$cards = implode( '', array_map( static fn( $r ) => self::card( $r, $market ), $routes ) );
		return '<div class="skye-listing">' . $intro . ( '' !== $cards ? '<div class="skye-tourcards">' . $cards . '</div>' : '<p>' . Html::e( Html::t( 'No routes have been published yet.' ) ) . '</p>' ) . '</div>';
	}
}
