<?php
declare(strict_types=1);

namespace Skye\Render;

use Skye\Units;

/**
 * The "Skye by car or motorhome" page: what the roads are like, the rules for sleeping in a
 * vehicle, the routes that suit a motorhome and the directory of campsites, overnight stops and
 * service points (CONTRACTS.md §6). Pure: takes the view built by Frontend::motorhome_view().
 */
final class Motorhome {

	/** Order of the directory, and which spot types share a heading. */
	public const GROUPS = [
		'campsite'     => [ 'campsite' ],
		'aire'         => [ 'aire', 'parking' ],
		'dump_station' => [ 'dump_station' ],
		'ev_charger'   => [ 'ev_charger' ],
	];

	/** Sources of the advice printed on the page (the spots carry their own). */
	public const SOURCES = [
		[ 'title' => 'The Highland Council: Highland Campervan and Motorhome Scheme, car park locations and rules', 'url' => 'https://www.highland.gov.uk/tourism-visitor-attractions/highland-campervan-motorhome-scheme/4' ],
		[ 'title' => 'NFU Scotland: Overnight stays in campervans and motorhomes', 'url' => 'https://www.nfus.org.uk/policy/campaigns/access-information-hub/camper-vans.aspx' ],
		[ 'title' => 'SEPA: Campervan and motorhome wastewater disposal', 'url' => 'https://beta.sepa.scot/topics/water/septic-tanks-and-private-sewage-treatment-systems/campervan-and-motorhome-wastewater-disposal/' ],
		[ 'title' => 'CalMac: Driving on Scotland\'s islands', 'url' => 'https://www.calmac.co.uk/blogs/driving-on-scotlands-islands/' ],
		[ 'title' => 'Dunvegan and District Community Council: Facilities for visitors', 'url' => 'https://skye.scot/facilities/' ],
	];

	/** @return array<string, string> Heading of each directory group. */
	private static function group_titles(): array {
		return [
			'campsite'     => Html::t( 'Campsites that take motorhomes' ),
			'aire'         => Html::t( 'Paid overnight stops' ),
			'dump_station' => Html::t( 'Service and waste points' ),
			'ev_charger'   => Html::t( 'EV chargers' ),
		];
	}

	/** @return array<string, string> */
	private static function service_labels(): array {
		return [
			'electricity' => Html::t( 'Electric hook-up' ),
			'water'       => Html::t( 'Fresh water' ),
			'dump'        => Html::t( 'Waste disposal' ),
			'showers'     => Html::t( 'Showers' ),
			'wifi'        => Html::t( 'Wi-Fi' ),
		];
	}

	/** @return list<array{0:string, 1:string}> Title and text of each piece of driving advice. */
	private static function driving(): array {
		return [
			[ Html::t( 'Single-track roads' ), Html::t( 'Many of the roads to the famous sights are one lane wide, with passing places. When a vehicle comes towards you, pull into a passing place on your left, or stop opposite one on your right. Give way to traffic coming uphill and be ready to reverse.' ) ],
			[ Html::t( 'Let others pass' ), Html::t( 'If a vehicle catches you up, pull into the next passing place and let it overtake. Local people use these roads to get to work.' ) ],
			[ Html::t( 'Passing places are not parking' ), Html::t( 'Never stop in one for a photograph or leave the vehicle there, even for a minute. Use the car parks and lay-bys.' ) ],
			[ Html::t( 'Fuel, food and time' ), Html::t( 'Fill the tank and shop in Portree or Broadford: there is no fuel on the roads to Elgol or Kylerhea. Distances are short but slow: the 23 km from Broadford to Elgol take about 45 minutes.' ) ],
		];
	}

	/** @return list<array{0:string, 1:string}> */
	private static function sleeping(): array {
		return [
			[ Html::t( 'The right to camp does not cover vehicles' ), Html::t( 'Scotland\'s access rights allow camping with a tent, on foot or by bike. They do not apply to motor vehicles, so sleeping in a lay-by or a car park is not a right and landowners can forbid it.' ) ],
			[ Html::t( 'No council overnight car parks on Skye' ), Html::t( 'The Highland Council sells a 7-day motorhome pass for £40 that allows a night in 12 designated car parks. None of them is on Skye, so plan on campsites and the paid stops listed below.' ) ],
			[ Html::t( 'Book the campsite in high season' ), Html::t( 'In high season the campsites are often full. Some keep pitches for arrivals on the day, which cannot be booked.' ) ],
			[ Html::t( 'Empty the tanks only at a disposal point' ), Html::t( 'Never empty a chemical toilet into public toilets, a drain, the ground, a stream or the sea. Use your campsite or one of the service points below.' ) ],
		];
	}

	/** @param list<array{0:string, 1:string}> $items */
	private static function advice( string $title, array $items ): string {
		$cards = '';
		foreach ( $items as [ $heading, $text ] ) {
			$cards .= '<article class="skye-card skye-advice__item"><h3>' . Html::e( $heading ) . '</h3><p>' . Html::e( $text ) . '</p></article>';
		}
		return '<section class="skye-mh__section"><h2 class="skye-block__title">' . Html::e( $title ) . '</h2><div class="skye-advice">' . $cards . '</div></section>';
	}

	/** Where the map link of a spot goes: its coordinates when recorded, else a search by name. */
	public static function map_url( array $s ): string {
		$loc = $s['location'] ?? null;
		if ( is_array( $loc ) && isset( $loc['lat'], $loc['lng'] ) && ( 0.0 !== (float) $loc['lat'] || 0.0 !== (float) $loc['lng'] ) ) {
			return 'https://www.google.com/maps/dir/?api=1&destination=' . rawurlencode( $loc['lat'] . ',' . $loc['lng'] );
		}
		return 'https://www.google.com/maps/search/?api=1&query=' . rawurlencode( ( $s['title'] ?? '' ) . ', Isle of Skye' );
	}

	/** Price line of a spot, in pounds as charged on site: per use at a service point, per night elsewhere. */
	public static function price( array $s, string $market ): string {
		if ( ! isset( $s['price_gbp'] ) || (float) $s['price_gbp'] <= 0 ) {
			return '';
		}
		$money = Units::format_money( (float) $s['price_gbp'], 'GBP', $market );
		return sprintf( 'dump_station' === ( $s['spot_type'] ?? '' ) ? Html::t( '%s per use' ) : Html::t( 'From %s a night' ), $money );
	}

	public static function card( array $s, string $market ): string {
		$labels = self::service_labels();
		$chips  = '';
		foreach ( (array) ( $s['services'] ?? [] ) as $service ) {
			if ( isset( $labels[ $service ] ) ) {
				$chips .= '<li>' . Html::e( $labels[ $service ] ) . '</li>';
			}
		}
		$facts = [];
		if ( ! empty( $s['zone_name'] ) ) {
			$facts[] = [ Html::t( 'Area' ), (string) $s['zone_name'] ];
		}
		if ( '' !== trim( (string) ( $s['season'] ?? '' ) ) ) {
			$facts[] = [ Html::t( 'Open' ), (string) $s['season'] ];
		}
		$price = self::price( $s, $market );
		if ( '' !== $price ) {
			$facts[] = [ Html::t( 'Price' ), $price ];
		}
		if ( isset( $s['max_length_m'] ) && (float) $s['max_length_m'] > 0 ) {
			/* translators: %s: length in metres. */
			$facts[] = [ Html::t( 'Maximum length' ), sprintf( Html::t( '%s m' ), rtrim( rtrim( number_format( (float) $s['max_length_m'], 1, str_starts_with( strtolower( $market ), 'en' ) ? '.' : ',', '' ), '0' ), '.,' ) ) ];
		}
		if ( ! empty( $s['no_booking'] ) ) {
			$facts[] = [ Html::t( 'Booking' ), Html::t( 'No booking: first come, first served' ) ];
		}
		$rows = '';
		foreach ( $facts as [ $label, $value ] ) {
			$rows .= '<div><dt>' . Html::e( $label ) . '</dt><dd>' . Html::e( $value ) . '</dd></div>';
		}
		$links = '';
		if ( ! empty( $s['booking_url'] ) ) {
			$links .= sprintf( '<a class="skye-btn skye-btn--outline" href="%s" rel="noopener">%s</a>', Html::e( $s['booking_url'] ), Html::e( Html::t( 'Website and booking' ) ) );
		}
		$links .= sprintf( '<a class="skye-spot__map" href="%s" rel="noopener">%s</a>', Html::e( self::map_url( $s ) ), Html::e( Html::t( 'Open in maps' ) ) );

		$sources = '';
		foreach ( (array) ( $s['sources'] ?? [] ) as $src ) {
			if ( ! empty( $src['url'] ) ) {
				$sources .= ( '' !== $sources ? ' · ' : '' ) . sprintf( '<a href="%s" rel="noopener nofollow">%s</a>', Html::e( $src['url'] ), Html::e( $src['title'] ?? $src['url'] ) );
			}
		}
		$checked = 'verified' === ( $s['verification']['status'] ?? '' );

		return '<article class="skye-card skye-spot" id="' . Html::e( $s['slug'] ?? '' ) . '">'
			. '<h3>' . Html::e( $s['title'] ?? '' ) . '</h3>'
			. ( '' !== $chips ? '<ul class="skye-spot__services">' . $chips . '</ul>' : '' )
			. ( '' !== $rows ? '<dl class="skye-spot__facts">' . $rows . '</dl>' : '' )
			. ( '' !== trim( (string) ( $s['notes'] ?? '' ) ) ? '<p class="skye-spot__notes">' . Html::e( $s['notes'] ) . '</p>' : '' )
			. '<div class="skye-spot__links">' . $links . '</div>'
			. '<p class="skye-spot__meta">' . ( $checked ? '' : Html::e( Html::t( 'Not yet checked on site by our team.' ) ) . ' ' )
			. ( '' !== $sources ? Html::e( Html::t( 'Sources' ) ) . ': ' . $sources : '' ) . '</p>'
			. '</article>';
	}

	/**
	 * @param array{breadcrumb?:list<array<string,mixed>>, routes:list<array<string,mixed>>, spots:list<array<string,mixed>>, links?:array<string,?string>} $v
	 */
	public static function page( array $v, string $market ): string {
		$html = '';
		if ( ! empty( $v['links']['home'] ) ) {
			$html .= '<nav class="skye-breadcrumb" aria-label="' . Html::e( Html::t( 'Breadcrumb' ) ) . '">'
				. sprintf( '<a href="%s">%s</a>', Html::e( $v['links']['home'] ), Html::e( Html::t( 'Home' ) ) )
				. ' <span aria-hidden="true">›</span> <span aria-current="page">' . Html::e( Html::t( 'Car and motorhome' ) ) . '</span></nav>';
		}
		$html .= '<header class="skye-listing__intro"><span class="skye-eyebrow">' . Html::e( Html::t( 'On your own wheels' ) ) . '</span>'
			. '<h1 class="skye-listing__title">' . Html::e( Html::t( 'Skye by car or motorhome' ) ) . '</h1>'
			. '<p class="skye-listing__lead">' . Html::e( Html::t( 'What the roads are like, where a motorhome can spend the night and where to empty and refill, so you can plan before you cross the bridge.' ) ) . '</p></header>';

		$html .= self::advice( Html::t( 'Driving on Skye' ), self::driving() );

		$routes = '';
		foreach ( $v['routes'] as $r ) {
			if ( empty( $r['url'] ) ) {
				continue;
			}
			$routes .= sprintf( '<a class="skye-card skye-mhroute" href="%s"><h3>%s</h3>', Html::e( $r['url'] ), Html::e( $r['title'] ?? '' ) )
				. ( ! empty( $r['motorhome_notes'] ) ? '<p>' . Html::e( $r['motorhome_notes'] ) . '</p>' : ( ! empty( $r['summary'] ) ? '<p>' . Html::e( $r['summary'] ) . '</p>' : '' ) )
				. '</a>';
		}
		if ( '' !== $routes ) {
			$html .= '<section class="skye-mh__section"><h2 class="skye-block__title">' . Html::e( Html::t( 'Routes that work with a motorhome' ) ) . '</h2><div class="skye-advice">' . $routes . '</div>'
				. ( ! empty( $v['links']['routes'] ) ? sprintf( '<p class="skye-mh__more"><a href="%s">%s</a></p>', Html::e( $v['links']['routes'] ), Html::e( Html::t( 'See all the routes' ) ) ) : '' )
				. '</section>';
		}

		$html .= self::advice( Html::t( 'Sleeping in a motorhome' ), self::sleeping() );

		$titles = self::group_titles();
		$any    = false;
		foreach ( self::GROUPS as $group => $types ) {
			$cards = '';
			foreach ( $v['spots'] as $s ) {
				if ( in_array( (string) ( $s['spot_type'] ?? '' ), $types, true ) ) {
					$cards .= self::card( $s, $market );
				}
			}
			if ( '' !== $cards ) {
				$any   = true;
				$html .= '<section class="skye-mh__section" id="' . Html::e( str_replace( '_', '-', $group ) ) . '"><h2 class="skye-block__title">' . Html::e( $titles[ $group ] ) . '</h2><div class="skye-spots">' . $cards . '</div></section>';
			}
		}
		if ( $any ) {
			$html .= '<p class="skye-mh__small">' . Html::e( Html::t( 'Dates, prices and rules change from one season to the next. Confirm with each site before you travel.' ) ) . '</p>';
		}

		$sources = '';
		foreach ( self::SOURCES as $src ) {
			$sources .= sprintf( '<li><a href="%s" rel="noopener nofollow">%s</a></li>', Html::e( $src['url'] ), Html::e( $src['title'] ) );
		}
		$html .= '<section class="skye-mh__section skye-mh__sources"><h2 class="skye-block__title">' . Html::e( Html::t( 'Sources' ) ) . '</h2><ul>' . $sources . '</ul></section>';

		return '<div class="skye-listing skye-mh">' . $html . '</div>';
	}
}
