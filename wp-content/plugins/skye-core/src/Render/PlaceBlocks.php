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

	public const PARTS = [ 'intro', 'facts', 'hours', 'tips', 'directions', 'nearby', 'tours', 'verification' ];

	public static function render( string $part, array $p, string $market ): string {
		return match ( $part ) {
			'intro'        => self::intro( $p ),
			'facts'        => self::facts( $p, $market ),
			'hours'        => self::hours( $p ),
			'tips'         => self::tips( $p ),
			'directions'   => self::directions( $p ),
			'nearby'       => self::nearby( $p, $market ),
			'tours'        => self::tours( $p ),
			'verification' => self::verification( $p ),
			default        => '',
		};
	}

	/** Gaelic name, one-sentence summary and zone/category chips. The h1 is the template's job. */
	public static function intro( array $p ): string {
		$out = '';
		if ( ! empty( $p['gaelic_name'] ) ) {
			$out .= sprintf( '<p class="skye-intro__gaelic" lang="gd">%s</p>', Html::e( $p['gaelic_name'] ) );
		}
		if ( ! empty( $p['summary'] ) ) {
			$out .= sprintf( '<p class="skye-intro__summary">%s</p>', Html::e( $p['summary'] ) );
		}
		$chips = [];
		if ( ! empty( $p['zone']['name'] ) ) {
			$chips[] = sprintf( '<li class="skye-chip skye-chip--zone">%s</li>', Html::e( $p['zone']['name'] ) );
		}
		foreach ( $p['categories'] ?? [] as $c ) {
			$chips[] = sprintf( '<li class="skye-chip">%s</li>', Html::e( $c['name'] ) );
		}
		if ( $chips ) {
			$out .= '<ul class="skye-chips">' . implode( '', $chips ) . '</ul>';
		}
		return '' === $out ? '' : '<div class="skye-intro">' . $out . '</div>';
	}

	/** "Practical information": the structured facts, converted to the visitor's units. */
	public static function facts( array $p, string $market ): string {
		$schema = Fields::place();
		$rows   = [];

		$typical = (int) ( $p['duration_typical'] ?? 0 );
		$min     = (int) ( $p['duration_min'] ?? 0 );
		if ( $typical > 0 ) {
			$time = Units::format_duration( $typical );
			if ( $min > 0 && $min < $typical ) {
				$time = sprintf( Html::t( '%1$s (at least %2$s)' ), $time, Units::format_duration( $min ) );
			}
			$rows[ Html::t( 'Time needed' ) ] = $time;
		}

		$difficulty = (string) ( $p['difficulty'] ?? 'none' );
		if ( 'none' !== $difficulty && isset( $schema['difficulty']['choices'][ $difficulty ] ) ) {
			$rows[ Html::t( 'Difficulty' ) ] = Html::t( $schema['difficulty']['choices'][ $difficulty ] );
		}
		if ( isset( $p['distance_km'] ) && (float) $p['distance_km'] > 0 ) {
			$walk = Units::format_distance( (float) $p['distance_km'], $market );
			if ( isset( $p['elevation_gain_m'] ) && (float) $p['elevation_gain_m'] > 0 ) {
				$walk = sprintf( Html::t( '%1$s, %2$s of ascent' ), $walk, Units::format_altitude( (float) $p['elevation_gain_m'], $market ) );
			}
			$rows[ Html::t( 'Walk' ) ] = $walk;
		}

		$price = isset( $p['price_gbp'] ) && (float) $p['price_gbp'] > 0 ? '£' . rtrim( rtrim( number_format( (float) $p['price_gbp'], 2, '.', '' ), '0' ), '.' ) : '';
		$rows[ Html::t( 'Price' ) ] = match ( (string) ( $p['price_type'] ?? 'free' ) ) {
			'ticket'       => '' !== $price ? sprintf( Html::t( 'Ticket from %s' ), $price ) : Html::t( 'Ticket required' ),
			'paid_parking' => '' !== $price ? sprintf( Html::t( 'Free entry; parking %s' ), $price ) : Html::t( 'Free entry; paid parking' ),
			default        => Html::t( 'Free' ),
		};

		if ( ! empty( $p['best_time'] ) ) {
			$rows[ Html::t( 'Best time' ) ] = (string) $p['best_time'];
		}
		$labels = static fn( array $values, array $choices ): string => implode( ', ', array_map( static fn( $v ) => Html::t( $choices[ $v ] ?? (string) $v ), $values ) );
		if ( ! empty( $p['suitable_for'] ) ) {
			$rows[ Html::t( 'Suitable for' ) ] = $labels( $p['suitable_for'], $schema['suitable_for']['choices'] );
		}
		if ( ! empty( $p['services_nearby'] ) ) {
			$rows[ Html::t( 'Nearby' ) ] = $labels( $p['services_nearby'], $schema['services_nearby']['choices'] );
		}
		$motorhome = (string) ( $p['motorhome_parking'] ?? '' );
		if ( isset( $schema['motorhome_parking']['choices'][ $motorhome ] ) ) {
			$text = Html::t( $schema['motorhome_parking']['choices'][ $motorhome ] );
			if ( 'no' !== $motorhome ) {
				$text .= ! empty( $p['overnight_allowed'] ) ? Html::t( '; overnight stays allowed' ) : Html::t( '; no overnight stays' );
			}
			$rows[ Html::t( 'Motorhome parking' ) ] = $text;
		}

		$html = '';
		foreach ( $rows as $label => $value ) {
			$html .= sprintf( '<div class="skye-facts__row"><dt>%s</dt><dd>%s</dd></div>', Html::e( $label ), Html::e( $value ) );
		}
		return Html::block( 'facts', Html::t( 'Practical information' ), '<dl class="skye-facts__list">' . $html . '</dl>' );
	}

	public static function hours( array $p ): string {
		$html = '';
		foreach ( $p['opening_hours'] ?? [] as $row ) {
			if ( '' === trim( (string) ( $row['hours'] ?? '' ) ) ) {
				continue;
			}
			$html .= sprintf( '<tr><th scope="row">%s</th><td>%s</td></tr>', Html::e( $row['season'] ?? '' ), Html::e( $row['hours'] ) );
		}
		return '' === $html ? '' : Html::block( 'hours', Html::t( 'Opening hours' ), '<table class="skye-hours__table"><tbody>' . $html . '</tbody></table>' );
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
				'<p class="skye-directions__links"><a class="skye-button" href="https://www.google.com/maps/dir/?api=1&amp;destination=%1$s" rel="noopener nofollow">%2$s</a> <span class="skye-directions__coords">%3$s</span></p>',
				Html::e( $coords ),
				Html::e( $label ),
				Html::e( str_replace( ',', ', ', $coords ) )
			);
		}
		return Html::block( 'directions', Html::t( 'Getting there' ), $html );
	}

	public static function nearby( array $p, string $market ): string {
		$list = static function ( array $items ) use ( $market ): string {
			$html = '';
			foreach ( $items as $n ) {
				$meta = '';
				if ( isset( $n['distance_km'] ) ) {
					$meta = Units::format_drive_distance( (float) $n['distance_km'], $market );
					if ( ! empty( $n['drive_minutes'] ) ) {
						$meta .= ' · ' . sprintf( Html::t( 'about %s by car' ), Units::format_duration( (int) $n['drive_minutes'] ) );
					}
				}
				$name  = ! empty( $n['url'] ) ? sprintf( '<a href="%s">%s</a>', Html::e( $n['url'] ), Html::e( $n['title'] ) ) : Html::e( $n['title'] );
				$html .= sprintf( '<li>%s%s</li>', $name, '' !== $meta ? ' <span class="skye-nearby__meta">' . Html::e( $meta ) . '</span>' : '' );
			}
			return '' === $html ? '' : '<ul class="skye-nearby__list">' . $html . '</ul>';
		};
		$html  = $list( $p['nearby_places'] ?? [] );
		$sleep = $list( $p['nearby_lodging'] ?? [] );
		if ( '' !== $sleep ) {
			$html .= '<h3 class="skye-nearby__subtitle">' . Html::e( Html::t( 'Where to sleep nearby' ) ) . '</h3>' . $sleep;
		}
		return Html::block( 'nearby', Html::t( 'Nearby' ), $html );
	}

	/** "No car?" hook: the guided tours that stop here. */
	public static function tours( array $p ): string {
		$html = '';
		foreach ( $p['in_tours'] ?? [] as $t ) {
			$name  = ! empty( $t['url'] ) ? sprintf( '<a href="%s">%s</a>', Html::e( $t['url'] ), Html::e( $t['title'] ) ) : Html::e( $t['title'] );
			$html .= '<li>' . $name . '</li>';
		}
		if ( '' === $html ) {
			return '';
		}
		$lead = '<p>' . Html::e( Html::t( 'This stop is part of our guided tours from Edinburgh:' ) ) . '</p>';
		return Html::block( 'tours', Html::t( 'No car? Visit it on a tour' ), $lead . '<ul class="skye-tours__list">' . $html . '</ul>' );
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
