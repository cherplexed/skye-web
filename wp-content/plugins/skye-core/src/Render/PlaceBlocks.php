<?php
declare(strict_types=1);

namespace Skye\Render;

use Skye\Fields;
use Skye\Units;

/**
 * Server-rendered blocks of a Place page. Each takes the API object of the place
 * (Api\PlaceSerializer::full + computed nearby) and returns escaped HTML, or '' when it has nothing to say.
 * The Bricks template places them with [skye_place part="…"]; nothing here needs JavaScript.
 */
final class PlaceBlocks {

	public const PARTS = [ 'breadcrumb', 'gallery', 'tags', 'summary', 'facts', 'hours', 'tips', 'directions', 'motorhome', 'nearby', 'tours', 'lodging', 'verification' ];

	public static function render( string $part, array $p, string $market ): string {
		return match ( $part ) {
			'breadcrumb'   => self::breadcrumb( $p ),
			'gallery'      => self::gallery( $p ),
			'tags'         => self::tags( $p ),
			'summary'      => self::summary( $p ),
			'facts'        => self::facts( $p, $market ),
			'hours'        => self::hours( $p ),
			'tips'         => self::tips( $p ),
			'directions'   => self::directions( $p ),
			'motorhome'    => self::motorhome( $p ),
			'nearby'       => self::nearby( $p, $market ),
			'tours'        => self::tours( $p ),
			'lodging'      => self::lodging( $p, $market ),
			'verification' => self::verification( $p ),
			default        => '',
		};
	}

	/** Home › What to see › Zone › Place. Links come in `_links` (set by the front end); without them it is plain text. */
	public static function breadcrumb( array $p ): string {
		$links = $p['_links'] ?? [];
		$item  = static fn( string $label, ?string $url ): string => $url ? sprintf( '<a href="%s">%s</a>', Html::e( $url ), Html::e( $label ) ) : Html::e( $label );
		$trail = [ $item( Html::t( 'Home' ), $links['home'] ?? null ), $item( Html::t( 'What to see' ), $links['places'] ?? null ) ];
		if ( ! empty( $p['zone']['name'] ) ) {
			$trail[] = Html::e( $p['zone']['name'] );
		}
		$trail[] = '<span aria-current="page">' . Html::e( $p['title'] ?? '' ) . '</span>';
		return '<nav class="skye-breadcrumb" aria-label="' . Html::e( Html::t( 'Breadcrumb' ) ) . '">' . implode( ' <span aria-hidden="true">›</span> ', $trail ) . '</nav>';
	}

	/** Cover plus up to two gallery photos, as one large tile and two small ones. One photo fills the row. */
	public static function gallery( array $p ): string {
		$images = array_values( array_filter( array_merge( [ $p['image'] ?? null ], array_slice( $p['gallery'] ?? [], 0, 2 ) ) ) );
		if ( ! $images ) {
			return '';
		}
		$html = '';
		foreach ( $images as $i => $img ) {
			$html .= '<figure class="skye-gallery__item">' . self::img( $img, 0 === $i ) . '</figure>';
		}
		return sprintf( '<div class="skye-gallery skye-gallery--%d">%s</div>', count( $images ), $html );
	}

	/** An image object from the API as <img>. The front end may attach ready-made markup with srcset in `html`. */
	private static function img( array $img, bool $eager = false ): string {
		if ( ! empty( $img['html'] ) ) {
			return (string) $img['html'];
		}
		return sprintf(
			'<img src="%s" alt="%s"%s%s loading="%s" decoding="async">',
			Html::e( $img['url'] ?? '' ),
			Html::e( $img['alt'] ?? '' ),
			! empty( $img['width'] ) ? ' width="' . (int) $img['width'] . '"' : '',
			! empty( $img['height'] ) ? ' height="' . (int) $img['height'] . '"' : '',
			$eager ? 'eager' : 'lazy'
		);
	}

	/** Small tags above the title: categories, up to two profiles, "Free" and "Audioguide" when they apply. */
	public static function tags( array $p ): string {
		$tags = array_map( static fn( $c ) => (string) $c['name'], $p['categories'] ?? [] );
		foreach ( array_slice( $p['profiles'] ?? [], 0, 2 ) as $profile ) {
			$tags[] = (string) $profile['name'];
		}
		if ( 'ticket' !== ( $p['price_type'] ?? 'free' ) ) {
			$tags[] = Html::t( 'Free' );
		}
		if ( ! empty( $p['audioguide'] ) ) {
			$tags[] = Html::t( 'Audioguide' );
		}
		$tags = array_unique( array_filter( $tags ) );
		if ( ! $tags ) {
			return '';
		}
		return '<ul class="skye-tags">' . implode( '', array_map( static fn( $t ) => '<li class="skye-tag">' . Html::e( $t ) . '</li>', $tags ) ) . '</ul>';
	}

	/** Gaelic name and the one-sentence summary under the title. */
	public static function summary( array $p ): string {
		$out = '';
		if ( ! empty( $p['gaelic_name'] ) ) {
			$out .= sprintf( '<p class="skye-summary__gaelic" lang="gd">%s</p>', Html::e( $p['gaelic_name'] ) );
		}
		if ( ! empty( $p['summary'] ) ) {
			$out .= sprintf( '<p class="skye-summary__text">%s</p>', Html::e( $p['summary'] ) );
		}
		return '' === $out ? '' : '<div class="skye-summary">' . $out . '</div>';
	}

	/** "Practical information" card: the structured facts, converted to the visitor's units. */
	public static function facts( array $p, string $market ): string {
		$schema = Fields::place();
		$rows   = [];

		if ( ! empty( $p['zone']['name'] ) ) {
			$rows[ Html::t( 'Area' ) ] = (string) $p['zone']['name'];
		}
		$typical = (int) ( $p['duration_typical'] ?? 0 );
		$min     = (int) ( $p['duration_min'] ?? 0 );
		if ( $typical > 0 ) {
			$time = Units::format_duration( $typical );
			if ( $min > 0 && $min < $typical ) {
				$time = sprintf( Html::t( '%1$s (at least %2$s)' ), $time, Units::format_duration( $min ) );
			}
			$rows[ Html::t( 'Time needed' ) ] = $time;
		}
		if ( isset( $p['distance_km'] ) && (float) $p['distance_km'] > 0 ) {
			$walk = Units::format_distance( (float) $p['distance_km'], $market );
			if ( isset( $p['elevation_gain_m'] ) && (float) $p['elevation_gain_m'] > 0 ) {
				$walk .= ' · ' . sprintf( Html::t( '%s of ascent' ), Units::format_altitude( (float) $p['elevation_gain_m'], $market ) );
			}
			$rows[ Html::t( 'Distance / ascent' ) ] = $walk;
		}
		$difficulty = (string) ( $p['difficulty'] ?? 'none' );
		if ( 'none' !== $difficulty && isset( $schema['difficulty']['choices'][ $difficulty ] ) ) {
			$rows[ Html::t( 'Difficulty' ) ] = Html::t( $schema['difficulty']['choices'][ $difficulty ] );
		}

		$price = isset( $p['price_gbp'] ) && (float) $p['price_gbp'] > 0 ? '£' . rtrim( rtrim( number_format( (float) $p['price_gbp'], 2, '.', '' ), '0' ), '.' ) : '';
		$rows[ Html::t( 'Price' ) ] = match ( (string) ( $p['price_type'] ?? 'free' ) ) {
			'ticket'       => '' !== $price ? sprintf( Html::t( 'Ticket from %s' ), $price ) : Html::t( 'Ticket required' ),
			'paid_parking' => '' !== $price ? sprintf( Html::t( 'Free · parking %s' ), $price ) : Html::t( 'Free · paid parking' ),
			default        => Html::t( 'Free' ),
		};

		// A single line of opening hours is a fact; a timetable gets its own block (see hours()).
		$hours = self::hour_rows( $p );
		if ( 1 === count( $hours ) ) {
			$rows[ Html::t( 'Opening hours' ) ] = trim( $hours[0]['hours'] . ( '' !== $hours[0]['season'] ? ' · ' . $hours[0]['season'] : '' ) );
		}
		if ( ! empty( $p['best_time'] ) ) {
			$rows[ Html::t( 'Best time' ) ] = (string) $p['best_time'];
		}
		$labels = static fn( array $values, array $choices ): string => implode( ' · ', array_map( static fn( $v ) => Html::t( $choices[ $v ] ?? (string) $v ), $values ) );
		if ( ! empty( $p['suitable_for'] ) ) {
			$rows[ Html::t( 'Suitable for' ) ] = $labels( $p['suitable_for'], $schema['suitable_for']['choices'] );
		}
		if ( ! empty( $p['services_nearby'] ) ) {
			$rows[ Html::t( 'On site or nearby' ) ] = $labels( $p['services_nearby'], $schema['services_nearby']['choices'] );
		}

		$html = '';
		foreach ( $rows as $label => $value ) {
			$html .= sprintf( '<div class="skye-fact"><dt>%s</dt><dd>%s</dd></div>', Html::e( $label ), Html::e( $value ) );
		}
		return sprintf( '<section class="skye-card skye-facts"><h2 class="skye-card__title">%s</h2><dl class="skye-facts__list">%s</dl></section>', Html::e( Html::t( 'Practical information' ) ), $html );
	}

	/** @return list<array{season:string, hours:string}> */
	private static function hour_rows( array $p ): array {
		$rows = [];
		foreach ( $p['opening_hours'] ?? [] as $row ) {
			$hours = trim( (string) ( $row['hours'] ?? '' ) );
			if ( '' !== $hours ) {
				$rows[] = [ 'season' => trim( (string) ( $row['season'] ?? '' ) ), 'hours' => $hours ];
			}
		}
		return $rows;
	}

	/** Timetable, only when there is more than one line (a single line is shown among the facts). */
	public static function hours( array $p ): string {
		$rows = self::hour_rows( $p );
		if ( count( $rows ) < 2 ) {
			return '';
		}
		$html = '';
		foreach ( $rows as $row ) {
			$html .= sprintf( '<tr><th scope="row">%s</th><td>%s</td></tr>', Html::e( $row['season'] ), Html::e( $row['hours'] ) );
		}
		return Html::block( 'hours', Html::t( 'Opening hours' ), '<table class="skye-hours__table"><tbody>' . $html . '</tbody></table>' );
	}

	public static function tips( array $p ): string {
		$html = '';
		foreach ( $p['tips'] ?? [] as $tip ) {
			if ( '' !== trim( (string) $tip ) ) {
				$html .= '<li>' . Html::e( $tip ) . '</li>';
			}
		}
		return '' === $html ? '' : Html::block( 'tips', Html::t( 'Tips from our guides' ), '<ul class="skye-tips__list">' . $html . '</ul>' );
	}

	/** Written directions plus a plain link to a route planner: works without JavaScript or a map key. */
	public static function directions( array $p ): string {
		$html = '';
		if ( ! empty( $p['directions'] ) ) {
			$html .= '<p>' . Html::e( $p['directions'] ) . '</p>';
		}
		$target = $p['parking_location'] ?? null;
		$label  = Html::t( 'Directions to the car park' );
		if ( ! is_array( $target ) ) {
			$target = $p['location'] ?? null;
			$label  = Html::t( 'Directions' );
		}
		if ( is_array( $target ) && isset( $target['lat'], $target['lng'] ) ) {
			$coords = sprintf( '%.5F,%.5F', $target['lat'], $target['lng'] );
			$html  .= sprintf(
				'<p class="skye-directions__links"><a class="skye-btn skye-btn--outline" href="https://www.google.com/maps/dir/?api=1&amp;destination=%1$s" rel="noopener nofollow">%2$s</a> <span class="skye-directions__coords">%3$s</span></p>',
				Html::e( $coords ),
				Html::e( $label ),
				Html::e( str_replace( ',', ', ', $coords ) )
			);
		}
		return Html::block( 'directions', Html::t( 'Getting there' ), $html );
	}

	/** "For motorhomes": where a long vehicle can park and whether it can stay the night. */
	public static function motorhome( array $p ): string {
		$choice = (string) ( $p['motorhome_parking'] ?? '' );
		$text   = match ( $choice ) {
			'yes'              => Html::t( 'Motorhomes can park here.' ),
			'yes_length_limit' => Html::t( 'Motorhomes can park here, but there is a length limit: check the signs at the entrance.' ),
			'no'               => Html::t( 'There is no parking suitable for motorhomes here.' ),
			default            => '',
		};
		if ( '' === $text ) {
			return '';
		}
		if ( 'no' !== $choice ) {
			$text .= ' ' . ( ! empty( $p['overnight_allowed'] ) ? Html::t( 'Overnight stays are allowed.' ) : Html::t( 'Overnight stays are not allowed.' ) );
		}
		return Html::block( 'motorhome', Html::t( 'For motorhomes' ), '<p>' . Html::e( $text ) . '</p>' );
	}

	/** Distance in both systems and the drive time of a nearby reference. */
	private static function away( array $n, string $market ): string {
		if ( ! isset( $n['distance_km'] ) ) {
			return '';
		}
		$meta = Units::format_drive_distance( (float) $n['distance_km'], $market );
		if ( ! empty( $n['drive_minutes'] ) ) {
			$meta = sprintf( Html::t( '%1$s by car · %2$s' ), Units::format_duration( (int) $n['drive_minutes'] ), $meta );
		}
		return $meta;
	}

	/** "Nearby": the closest places as cards with their photo. */
	public static function nearby( array $p, string $market ): string {
		$html = '';
		foreach ( $p['nearby_places'] ?? [] as $n ) {
			$photo = ! empty( $n['image'] ) ? self::img( $n['image'] ) : '';
			$body  = sprintf( '<div class="skye-mini__body"><strong>%s</strong><span class="skye-mini__meta">%s</span></div>', Html::e( $n['title'] ), Html::e( self::away( $n, $market ) ) );
			$inner = '<div class="skye-mini__photo">' . $photo . '</div>' . $body;
			$html .= ! empty( $n['url'] )
				? sprintf( '<li><a class="skye-card skye-mini" href="%s">%s</a></li>', Html::e( $n['url'] ), $inner )
				: '<li><div class="skye-card skye-mini">' . $inner . '</div></li>';
		}
		return '' === $html ? '' : Html::block( 'nearby', Html::t( 'Nearby' ), '<ul class="skye-nearby__grid">' . $html . '</ul>' );
	}

	/** "No car?" card: the guided tours that stop here. */
	public static function tours( array $p ): string {
		$html = '';
		foreach ( $p['in_tours'] ?? [] as $t ) {
			$name  = ! empty( $t['url'] ) ? sprintf( '<a href="%s">%s</a>', Html::e( $t['url'] ), Html::e( $t['title'] ) ) : Html::e( $t['title'] );
			$html .= '<li>' . $name . '</li>';
		}
		if ( '' === $html ) {
			return '';
		}
		$out  = '<span class="skye-eyebrow">' . Html::e( Html::t( 'No car?' ) ) . '</span>';
		$out .= '<strong class="skye-tours__lead">' . Html::e( Html::t( 'This place is a stop on our guided tours from Edinburgh' ) ) . '</strong>';
		$out .= '<ul class="skye-tours__list">' . $html . '</ul>';
		if ( ! empty( $p['_links']['tours'] ) ) {
			$out .= sprintf( '<a class="skye-btn skye-btn--primary" href="%s">%s</a>', Html::e( $p['_links']['tours'] ), Html::e( Html::t( 'See the tours' ) ) );
		}
		return '<section class="skye-card skye-tours">' . $out . '</section>';
	}

	/** "Where to sleep nearby" card. Empty until lodging is loaded. */
	public static function lodging( array $p, string $market ): string {
		$html = '';
		foreach ( $p['nearby_lodging'] ?? [] as $n ) {
			$name  = ! empty( $n['url'] ) ? sprintf( '<a href="%s">%s</a>', Html::e( $n['url'] ), Html::e( $n['title'] ) ) : Html::e( $n['title'] );
			$html .= sprintf( '<li>%s<span class="skye-mini__meta">%s</span></li>', $name, Html::e( self::away( $n, $market ) ) );
		}
		if ( '' === $html ) {
			return '';
		}
		return sprintf( '<section class="skye-card skye-lodging"><h2 class="skye-card__title">%s</h2><ul class="skye-lodging__list">%s</ul></section>', Html::e( Html::t( 'Where to sleep nearby' ) ), $html );
	}

	/** Always rendered: an unverified page must say so (CLAUDE.md, principle 4). */
	public static function verification( array $p ): string {
		$v      = $p['verification'] ?? [];
		$status = (string) ( $v['status'] ?? 'unverified' );
		$when   = Html::date( $v['verified_at'] ?? null );
		if ( 'unverified' === $status || '' === $when ) {
			$text = Html::t( 'Not yet checked on the ground by one of our guides.' );
		} else {
			$who  = (string) ( $v['verified_by']['name'] ?? '' );
			$text = '' !== $who ? sprintf( Html::t( 'Checked by %1$s on %2$s.' ), $who, $when ) : sprintf( Html::t( 'Checked on %s.' ), $when );
			if ( 'expired' === $status ) {
				$text .= ' ' . Html::t( 'Due for a new check.' );
			}
			$updated = Html::date( $v['updated_at'] ?? null );
			if ( '' !== $updated ) {
				$text .= ' ' . sprintf( Html::t( 'Updated on %s.' ), $updated );
			}
		}
		return sprintf( '<p class="skye-verification skye-verification--%s">%s</p>', Html::e( $status ), Html::e( $text ) );
	}
}
