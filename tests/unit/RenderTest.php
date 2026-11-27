<?php
declare(strict_types=1);

namespace Skye\Tests;

use PHPUnit\Framework\TestCase;
use Skye\JsonLd;
use Skye\Render\Chrome;
use Skye\Render\PlaceBlocks;
use Skye\Render\RouteBlocks;
use Skye\Render\TourBlocks;

final class RenderTest extends TestCase {

	private static function place( array $over = [] ): array {
		return $over + [
			'id'                => 12,
			'title'             => 'Old Man of Storr',
			'gaelic_name'       => 'Bodach an Stòir',
			'summary'           => 'A steep walk <b>with</b> views.',
			'url'               => 'http://x/places/old-man-of-storr/',
			'zone'              => [ 'slug' => 'trotternish', 'name' => 'Trotternish' ],
			'categories'        => [ [ 'slug' => 'hike', 'name' => 'Hike' ] ],
			'profiles'          => [ [ 'slug' => 'hiking', 'name' => 'Hiking' ] ],
			'location'          => [ 'lat' => 57.5072, 'lng' => -6.1801 ],
			'parking_location'  => [ 'lat' => 57.504, 'lng' => -6.169 ],
			'directions'        => '10 km north of Portree on the A855.',
			'duration_min'      => 60,
			'duration_typical'  => 120,
			'difficulty'        => 'moderate',
			'distance_km'       => 3.8,
			'elevation_gain_m'  => 300.0,
			'price_type'        => 'paid_parking',
			'price_gbp'         => 6.0,
			'best_time'         => 'Sunrise',
			'suitable_for'      => [ 'children', 'dogs' ],
			'services_nearby'   => [ 'toilets' ],
			'motorhome_parking' => 'yes_length_limit',
			'overnight_allowed' => false,
			'opening_hours'     => [ [ 'season' => 'All year', 'hours' => 'Open access' ], [ 'season' => 'x', 'hours' => ' ' ] ],
			'tips'              => [ 'Bring boots', '' ],
			'image'             => null,
			'nearby_places'     => [ [ 'id' => 3, 'title' => 'Kilt Rock', 'url' => 'http://x/places/kilt-rock/', 'distance_km' => 9.6, 'drive_minutes' => 18 ] ],
			'nearby_lodging'    => [],
			'in_tours'          => [ [ 'id' => 9, 'title' => '3-day tour', 'url' => 'http://x/tours/3/' ] ],
			'verification'      => [ 'verified_by' => null, 'verified_at' => null, 'valid_until' => null, 'updated_at' => null, 'status' => 'unverified' ],
		];
	}

	public function test_facts_follow_the_market_units(): void {
		$es = PlaceBlocks::facts( self::place(), 'es' );
		self::assertStringContainsString( '<h2 class="skye-card__title">Practical information</h2>', $es );
		self::assertStringContainsString( '<dt>Area</dt><dd>Trotternish</dd>', $es );
		self::assertStringContainsString( '<dt>Time needed</dt><dd>2h (at least 1h)</dd>', $es );
		self::assertStringContainsString( '<dt>Distance / ascent</dt><dd>3.8 km · 300 m of ascent</dd>', $es );
		self::assertStringContainsString( '<dd>Free · parking £6</dd>', $es );
		self::assertStringContainsString( '<dd>Children · Dogs</dd>', $es );
		self::assertStringContainsString( '<dt>Difficulty</dt><dd>Moderate</dd>', $es );
		self::assertStringContainsString( '<dt>Opening hours</dt><dd>Open access · All year</dd>', $es, 'a single line of hours is a fact' );
		self::assertSame( '', PlaceBlocks::hours( self::place() ), 'and gets no separate block' );

		$us = PlaceBlocks::facts( self::place(), 'en-us' );
		self::assertStringContainsString( '<dd>2.4 mi · 984 ft of ascent</dd>', $us );

		$plain = PlaceBlocks::facts( self::place( [ 'difficulty' => 'none', 'distance_km' => null, 'price_type' => 'free', 'price_gbp' => null, 'duration_typical' => null ] ), 'es' );
		self::assertStringNotContainsString( 'Difficulty', $plain );
		self::assertStringNotContainsString( 'Distance', $plain );
		self::assertStringNotContainsString( 'Time needed', $plain );
		self::assertStringContainsString( '<dt>Price</dt><dd>Free</dd>', $plain );

		$timetable = self::place( [ 'opening_hours' => [ [ 'season' => 'Apr–Oct', 'hours' => '9:30–17:00' ], [ 'season' => 'Nov–Mar', 'hours' => 'Closed' ] ] ] );
		self::assertStringNotContainsString( 'Opening hours', PlaceBlocks::facts( $timetable, 'es' ) );
		self::assertSame( 2, substr_count( PlaceBlocks::hours( $timetable ), '<tr>' ) );
	}

	public function test_blocks_escape_and_disappear_when_empty(): void {
		$p = self::place();
		self::assertStringContainsString( 'A steep walk &lt;b&gt;with&lt;/b&gt; views.', PlaceBlocks::summary( $p ) );
		self::assertStringContainsString( 'lang="gd">Bodach an Stòir<', PlaceBlocks::summary( $p ) );
		self::assertSame( 1, substr_count( PlaceBlocks::tips( $p ), '<li>' ) );

		$empty = self::place( [ 'tips' => [], 'opening_hours' => [], 'in_tours' => [], 'nearby_places' => [], 'nearby_lodging' => [], 'directions' => '', 'location' => null, 'parking_location' => null, 'image' => null, 'gallery' => [], 'motorhome_parking' => '', 'categories' => [], 'price_type' => 'ticket', 'gaelic_name' => '', 'summary' => '' ] );
		foreach ( [ 'tips', 'hours', 'tours', 'nearby', 'lodging', 'directions', 'gallery', 'motorhome', 'tags', 'summary' ] as $part ) {
			self::assertSame( '', PlaceBlocks::render( $part, $empty, 'es' ), $part );
		}
		self::assertSame( '', PlaceBlocks::render( 'nonsense', $p, 'es' ) );
		foreach ( PlaceBlocks::PARTS as $part ) {
			self::assertIsString( PlaceBlocks::render( $part, $p, 'en-gb' ) );
		}
	}

	public function test_tags_breadcrumb_and_gallery(): void {
		$p = self::place( [ 'audioguide' => true ] );
		self::assertSame( '<ul class="skye-tags"><li class="skye-tag">Hike</li><li class="skye-tag">Free</li><li class="skye-tag">Audioguide</li></ul>', PlaceBlocks::tags( $p ) );
		self::assertStringNotContainsString( 'Free', PlaceBlocks::tags( self::place( [ 'price_type' => 'ticket' ] ) ) );

		self::assertStringContainsString( 'Home <span aria-hidden="true">›</span> What to see', PlaceBlocks::breadcrumb( $p ) );
		$linked = PlaceBlocks::breadcrumb( self::place( [ '_links' => [ 'home' => 'http://x/', 'places' => 'http://x/places/' ] ] ) );
		self::assertStringContainsString( '<a href="http://x/places/">What to see</a>', $linked );
		self::assertStringContainsString( '<span aria-current="page">Old Man of Storr</span>', $linked );

		$cover = [ 'id' => 1, 'url' => 'http://x/a.jpg', 'width' => 1024, 'height' => 683, 'alt' => 'The "Old Man"' ];
		$one   = PlaceBlocks::gallery( self::place( [ 'image' => $cover, 'gallery' => [] ] ) );
		self::assertStringContainsString( 'skye-gallery--1', $one );
		self::assertStringContainsString( 'alt="The &quot;Old Man&quot;" width="1024" height="683" loading="eager"', $one );
		$many = PlaceBlocks::gallery( self::place( [ 'image' => $cover, 'gallery' => [ $cover, [ 'html' => '<img data-ready>' ] + $cover, $cover ] ] ) );
		self::assertStringContainsString( 'skye-gallery--3', $many, 'cover plus two; the rest wait for a lightbox' );
		self::assertSame( 1, substr_count( $many, 'loading="eager"' ) );
		self::assertStringContainsString( '<img data-ready>', $many, 'ready-made markup with srcset is used as is' );
	}

	public function test_directions_nearby_tours_and_motorhome(): void {
		$p = self::place();
		$d = PlaceBlocks::directions( $p );
		self::assertStringContainsString( 'destination=57.50400,-6.16900"', $d, 'links to the car park when there is one' );
		self::assertStringContainsString( 'Directions to the car park', $d );
		self::assertStringContainsString( 'destination=57.50720,-6.18010"', PlaceBlocks::directions( self::place( [ 'parking_location' => null ] ) ) );

		$near = PlaceBlocks::nearby( $p, 'es' );
		self::assertStringContainsString( '<a class="skye-card skye-mini" href="http://x/places/kilt-rock/">', $near );
		self::assertStringContainsString( '<strong>Kilt Rock</strong><span class="skye-mini__meta">18 min by car · 9.6 km (6 mi)</span>', $near );
		self::assertStringContainsString( '6 mi (9.6 km)', PlaceBlocks::nearby( $p, 'en-gb' ) );

		$tours = PlaceBlocks::tours( self::place( [ '_links' => [ 'tours' => 'http://x/tours/' ] ] ) );
		self::assertStringContainsString( '<a href="http://x/tours/3/">3-day tour</a>', $tours );
		self::assertStringContainsString( '<a class="skye-btn skye-btn--primary" href="http://x/tours/">See the tours</a>', $tours );
		self::assertStringNotContainsString( 'skye-btn', PlaceBlocks::tours( $p ), 'no button without a tours page to link to' );

		self::assertStringContainsString( 'length limit', PlaceBlocks::motorhome( $p ) );
		self::assertStringContainsString( 'Overnight stays are not allowed.', PlaceBlocks::motorhome( $p ) );
		self::assertStringNotContainsString( 'Overnight', PlaceBlocks::motorhome( self::place( [ 'motorhome_parking' => 'no' ] ) ) );

		$sleep = PlaceBlocks::lodging( self::place( [ 'nearby_lodging' => [ [ 'title' => 'Staffin B&B', 'url' => 'http://x/l/', 'distance_km' => 5.0, 'drive_minutes' => 9 ] ] ] ), 'es' );
		self::assertStringContainsString( '<a href="http://x/l/">Staffin B&amp;B</a>', $sleep );
	}

	public function test_verification_never_claims_a_check_that_did_not_happen(): void {
		self::assertStringContainsString( 'Not yet checked', PlaceBlocks::verification( self::place() ) );
		self::assertStringContainsString( 'skye-verification--unverified', PlaceBlocks::verification( self::place() ) );

		$v  = [ 'verified_by' => [ 'id' => 2, 'name' => 'Iain M.' ], 'verified_at' => '2026-11-20', 'valid_until' => '2027-11-20', 'updated_at' => '2026-11-25', 'status' => 'verified' ];
		$ok = PlaceBlocks::verification( self::place( [ 'verification' => $v ] ) );
		self::assertStringContainsString( 'Checked by Iain M. on 20 November 2026. Updated on 25 November 2026.', $ok );
		self::assertStringContainsString( 'Due for a new check.', PlaceBlocks::verification( self::place( [ 'verification' => [ 'status' => 'expired' ] + $v ] ) ) );

		$ld = JsonLd::place( self::place() );
		self::assertArrayNotHasKey( 'dateModified', $ld );
		self::assertArrayNotHasKey( 'reviewedBy', $ld );
		$ld = JsonLd::place( self::place( [ 'verification' => $v ] ) );
		self::assertSame( '2026-11-25', $ld['dateModified'] );
		self::assertSame( 'Iain M.', $ld['reviewedBy']['name'] );
	}

	public function test_json_ld_place(): void {
		$ld = JsonLd::place( self::place() );
		self::assertSame( 'TouristAttraction', $ld['@type'] );
		self::assertSame( 'Bodach an Stòir', $ld['alternateName'] );
		self::assertSame( [ '@type' => 'GeoCoordinates', 'latitude' => 57.5072, 'longitude' => -6.1801 ], $ld['geo'] );
		self::assertTrue( $ld['isAccessibleForFree'], 'paid parking is still free entry' );
		self::assertFalse( JsonLd::place( self::place( [ 'price_type' => 'ticket' ] ) )['isAccessibleForFree'] );
		self::assertSame( 'Toilets', $ld['amenityFeature'][0]['name'] );
		self::assertSame( 'Trotternish, Isle of Skye', $ld['containedInPlace']['name'] );
		self::assertArrayNotHasKey( 'image', $ld );
	}

	public function test_bricks_templates_are_consistent_trees_using_known_shortcodes(): void {
		$files = glob( SKYE_CORE_DIR . '/templates/bricks/*.json' );
		self::assertCount( 7, $files );
		$slugs = [];
		foreach ( $files as $file ) {
			$t       = json_decode( (string) file_get_contents( $file ), true, 512, JSON_THROW_ON_ERROR );
			$slugs[] = $t['slug'];
			self::assertContains( $t['type'], [ 'header', 'footer', 'content', 'archive' ] );
			self::assertNotEmpty( $t['conditions'] );
			$ids = array_column( $t['elements'], 'id' );
			self::assertSame( $ids, array_unique( $ids ) );
			$by = array_column( $t['elements'], null, 'id' );
			foreach ( $t['elements'] as $el ) {
				self::assertMatchesRegularExpression( '/^[a-z0-9]{6}$/', $el['id'] );
				if ( 0 !== $el['parent'] ) {
					self::assertContains( $el['id'], $by[ $el['parent'] ]['children'], $el['id'] );
				}
				foreach ( $el['children'] as $child ) {
					self::assertSame( $el['id'], $by[ $child ]['parent'], $child );
				}
				if ( 'shortcode' === $el['name'] ) {
					self::assertSame( 1, preg_match( '/^\[skye_(header|footer|tours|routes|(place|tour|route) part="([a-z]+)")\]$/', $el['settings']['shortcode'], $m ), $el['settings']['shortcode'] );
					if ( isset( $m[3] ) ) {
						self::assertContains( $m[3], [ 'place' => PlaceBlocks::PARTS, 'tour' => TourBlocks::PARTS, 'route' => RouteBlocks::PARTS ][ $m[2] ] );
					}
				}
			}
		}
		self::assertSame( [ 'skye-archive-routes', 'skye-archive-tours', 'skye-footer', 'skye-header', 'skye-single-place', 'skye-single-route', 'skye-single-tour' ], $slugs );
	}

	public function test_names_from_wordpress_are_decoded_once_then_escaped_once(): void {
		self::assertSame( 'Minginish & Cuillin', \Skye\Text::plain( 'Minginish &amp; Cuillin' ) );
		self::assertSame( "Skye\u{2019}s \"best\"", \Skye\Text::plain( 'Skye&#8217;s &quot;best&quot;' ) );
		$p = self::place( [ 'zone' => [ 'slug' => 'minginish', 'name' => \Skye\Text::plain( 'Minginish &amp; Cuillin' ) ] ] );
		self::assertStringContainsString( ' Minginish &amp; Cuillin ', PlaceBlocks::breadcrumb( $p ) );
		self::assertStringNotContainsString( '&amp;amp;', PlaceBlocks::breadcrumb( $p ) . PlaceBlocks::facts( $p, 'es' ) );
		self::assertSame( 'Minginish & Cuillin, Isle of Skye', JsonLd::place( $p )['containedInPlace']['name'] );
	}

	private static function tour( array $over = [] ): array {
		return $over + [
			'id'                  => 40,
			'title'               => 'Skye & the Highlands, 3 days',
			'url'                 => 'http://x/tours/3/',
			'summary'             => 'Three days with a guide.',
			'days_count'          => 3,
			'nights_count'        => 2,
			'departure_city'      => 'edinburgh',
			'departure_time'      => '07:30',
			'meeting_point'       => '190 High Street (Royal Mile).',
			'includes'            => "Transport in a minibus. Professional driver-guide.\nAudioguide",
			'excludes'            => 'Meals. Travel insurance.',
			'includes_audioguide' => true,
			'image'               => null,
			'days'                => [
				[ 'title' => 'Edinburgh to Skye', 'summary' => 'Through Glencoe.', 'stops' => [] ],
				[ 'title' => 'A full day on Skye', 'summary' => '', 'stops' => [ [ 'id' => 1, 'title' => 'Quiraing', 'url' => 'http://x/places/quiraing/' ], [ 'id' => 2, 'title' => 'Kilt Rock' ] ] ],
				[ 'title' => '', 'summary' => '', 'stops' => [] ],
			],
			'offer'               => [
				'market'           => 'de',
				'brand'            => [ 'id' => 3, 'slug' => 'loch-ness-bus', 'title' => 'Loch Ness Bus', 'domain' => 'lochnessbus.com', 'tour_language' => 'en' ],
				'bokun_product_id' => '318823',
				'price_from'       => 283.0,
				'currency'         => 'EUR',
				'next_available'   => '2026-11-30',
				'price_stale'      => false,
				'booking_url'      => 'https://lochnessbus.com/',
			],
			'verification'        => [ 'status' => 'unverified' ],
		];
	}

	public function test_tour_offer_card_speaks_the_market(): void {
		$de = TourBlocks::offer( self::tour(), 'de' );
		self::assertStringContainsString( '<strong>283 €</strong>', $de );
		self::assertStringContainsString( 'Next departure: 30 November 2026', $de );
		self::assertStringContainsString( 'Guided in English. Audioguide in your language included.', $de );
		self::assertStringContainsString( '<a class="skye-btn skye-btn--primary" href="https://lochnessbus.com/" rel="noopener">See dates and book</a>', $de );
		self::assertStringContainsString( 'Operated by Loch Ness Bus.', $de );

		$gb = self::tour();
		$gb['offer'] = [ 'market' => 'en-gb', 'price_from' => 399.2, 'currency' => 'GBP' ] + $gb['offer'];
		self::assertStringContainsString( '<strong>£399.20</strong>', TourBlocks::offer( $gb, 'en-gb' ) );
		self::assertStringContainsString( 'Guided in English. Audioguide included.', TourBlocks::offer( $gb, 'en-gb' ), 'same language: no "in your language"' );

		$none = self::tour();
		$none['offer'] = [ 'price_from' => null, 'currency' => null, 'next_available' => null, 'booking_url' => null ] + $none['offer'];
		$html          = TourBlocks::offer( $none, 'de' );
		self::assertStringNotContainsString( 'skye-offer__price', $html, 'no price is shown rather than a made-up one' );
		self::assertStringNotContainsString( 'skye-btn', $html );
		self::assertSame( '', TourBlocks::offer( self::tour( [ 'offer' => null ] ), 'de' ) );
	}

	public function test_tour_itinerary_includes_and_departure(): void {
		$t  = self::tour();
		$it = TourBlocks::itinerary( $t );
		self::assertSame( 2, substr_count( $it, '<li class="skye-day">' ), 'a day with nothing to say is skipped' );
		self::assertStringContainsString( '<span class="skye-day__n">Day 2</span> A full day on Skye</h3>', $it );
		self::assertStringContainsString( '<li><a href="http://x/places/quiraing/">Quiraing</a></li><li>Kilt Rock</li>', $it );

		$inc = TourBlocks::includes( $t );
		self::assertStringContainsString( '<li>Transport in a minibus.</li><li>Professional driver-guide.</li><li>Audioguide</li>', $inc );
		self::assertStringContainsString( '<h3>Not included</h3><ul class="skye-includes__no"><li>Meals.</li><li>Travel insurance.</li>', $inc );
		self::assertSame( '', TourBlocks::includes( self::tour( [ 'includes' => '', 'excludes' => '' ] ) ) );

		$dep = TourBlocks::departure( $t );
		self::assertStringContainsString( '<dt>Departure</dt><dd>Edinburgh at 07:30</dd>', $dep );
		self::assertStringContainsString( '<dt>Length</dt><dd>3 days · 2 nights</dd>', $dep );
		self::assertSame( '<span class="skye-eyebrow">From Edinburgh · 3 days · 2 nights</span>', TourBlocks::eyebrow( $t ) );
		self::assertSame( '1 day', TourBlocks::length( [ 'days_count' => 1, 'nights_count' => 0 ] ) );
		foreach ( TourBlocks::PARTS as $part ) {
			self::assertIsString( TourBlocks::render( $part, $t, 'de' ) );
		}
		self::assertStringContainsString( 'Skye &amp; the Highlands, 3 days', TourBlocks::breadcrumb( $t ) );
	}

	public function test_tours_listing_orders_by_length_and_features_the_longest(): void {
		$two  = self::tour( [ 'title' => 'Two days', 'days_count' => 2, 'nights_count' => 1, 'url' => 'http://x/tours/2/', 'day_titles' => [ 'Up', 'Back' ] ] );
		$html = TourBlocks::listing( [ self::tour(), $two ], 'de' );
		self::assertStringContainsString( '<h1 class="skye-listing__title">Isle of Skye tours of 2 and 3 days</h1>', $html );
		self::assertLessThanOrEqual( strpos( $html, 'Skye &amp; the Highlands, 3 days' ), strpos( $html, 'Two days' ) );
		self::assertSame( 1, substr_count( $html, 'skye-tourcard--featured' ) );
		self::assertStringContainsString( '<strong>Day 2</strong><span>Back</span>', $html );
		self::assertStringContainsString( '<a class="skye-btn skye-btn--outline" href="http://x/tours/2/">Full itinerary</a>', $html );
		self::assertStringContainsString( '<strong>283 €</strong>', $html );

		self::assertStringNotContainsString( 'featured', TourBlocks::listing( [ $two ], 'de' ), 'a single tour is not "the most complete"' );
		self::assertStringContainsString( 'No tours are on sale right now.', TourBlocks::listing( [], 'de' ) );
	}

	private static function route( array $over = [] ): array {
		$stop = static fn( int $id, string $title, float $lat, string $time, string $type = 'visit', string $note = '' ) => [
			'place'     => [ 'id' => $id, 'title' => $title, 'url' => "http://x/places/$id/", 'location' => [ 'lat' => $lat, 'lng' => -6.2 ] ],
			'time_hint' => $time,
			'note'      => $note,
			'stop_type' => $type,
		];
		return $over + [
			'title'               => 'Trotternish loop',
			'url'                 => 'http://x/routes/trotternish-loop/',
			'summary'             => 'The classic drive.',
			'route_type'          => 'drive',
			'days_count'          => 1,
			'vehicle'             => 'car',
			'total_km'            => 80,
			'total_drive_minutes' => 130,
			'motorhome_ok'        => true,
			'motorhome_notes'     => 'Steep and narrow near the Quiraing.',
			'image'               => null,
			'display'             => [ 'total_drive' => '80 km (49.7 mi)', 'drive_time' => '2h 10' ],
			'days'                => [
				[
					'stops'         => [ $stop( 1, 'Portree', 57.41, '08:30', 'meal', 'Fuel & food first.' ), $stop( 2, 'Old Man of Storr', 57.50, '09:00' ), $stop( 3, 'Quiraing', 57.64, '12:30' ), [ 'place' => null, 'time_hint' => '', 'note' => '', 'stop_type' => 'visit' ] ],
					'night_zone'    => 'portree',
					'night_lodging' => null,
				],
			],
			'verification'        => [ 'status' => 'unverified' ],
		];
	}

	public function test_route_facts_and_itinerary(): void {
		$r     = self::route();
		$facts = RouteBlocks::facts( $r, 'es' );
		self::assertStringContainsString( '<dt>Distance</dt><dd>80 km (49.7 mi)</dd>', $facts );
		self::assertStringContainsString( '<dt>Driving time</dt><dd>2h 10, without stops</dd>', $facts );
		self::assertStringContainsString( '<dt>Stops</dt><dd>3</dd>', $facts );
		self::assertStringContainsString( '<dt>Getting around</dt><dd>Car</dd>', $facts );
		self::assertStringContainsString( '<dd>Suitable, with care</dd>', $facts );
		self::assertStringContainsString( '49.7 mi (80 km)', RouteBlocks::facts( $r, 'en-gb' ) );
		self::assertSame( '<span class="skye-eyebrow">Route by car · 1 day</span>', RouteBlocks::eyebrow( $r ) );

		$it = RouteBlocks::itinerary( $r );
		self::assertSame( 3, substr_count( $it, '<li class="skye-stop">' ), 'a stop whose place is gone is skipped' );
		self::assertStringContainsString( '<div class="skye-stop__time">08:30</div>', $it );
		self::assertStringContainsString( '<a href="http://x/places/1/">Portree</a> <span class="skye-tag">Meal</span>', $it );
		self::assertStringContainsString( '<p>Fuel &amp; food first.</p>', $it );
		self::assertStringNotContainsString( 'Day 1', $it, 'a one-day route needs no day heading' );
		self::assertStringContainsString( 'Night in Portree', $it );
		self::assertStringContainsString( 'origin=57.41000,-6.20000&amp;destination=57.64000,-6.20000&amp;waypoints=57.50000,-6.20000&amp;travelmode=driving', $it );

		$two = self::route( [ 'days' => [ self::route()['days'][0], self::route()['days'][0] ], 'days_count' => 2 ] );
		self::assertSame( 2, substr_count( RouteBlocks::itinerary( $two ), 'skye-day__n' ) );
		self::assertStringContainsString( 'Open this day in a route planner', RouteBlocks::itinerary( $two ) );

		self::assertNull( RouteBlocks::directions_url( [ [ 'lat' => 57.0, 'lng' => -6.0 ] ] ) );
		$long = RouteBlocks::directions_url( array_fill( 0, 14, [ 'lat' => 57.0, 'lng' => -6.0 ] ), true );
		self::assertSame( 8, substr_count( (string) $long, '%7C' ), 'nine waypoints at most' );
		self::assertStringContainsString( 'travelmode=walking', (string) $long );
		foreach ( RouteBlocks::PARTS as $part ) {
			self::assertIsString( RouteBlocks::render( $part, $r, 'en-gb' ) );
		}
	}

	public function test_route_tour_card_and_listing(): void {
		self::assertSame( '', RouteBlocks::tour( self::route(), 'es' ) );
		$card = RouteBlocks::tour( self::route( [ '_tour' => self::tour() ] ), 'de' );
		self::assertStringContainsString( 'These stops are part of our guided tour: Skye &amp; the Highlands, 3 days', $card );
		self::assertStringContainsString( 'From 283 € per person', $card );
		self::assertStringContainsString( '<a class="skye-btn skye-btn--primary" href="http://x/tours/3/">See the tour</a>', $card );

		$list = RouteBlocks::listing( [ self::route() ], 'es' );
		self::assertStringContainsString( '<a class="skye-card skye-tourcard skye-routecard" href="http://x/routes/trotternish-loop/">', $list );
		self::assertStringContainsString( '1 day · 80 km (49.7 mi) · 2h 10 driving', $list );
		self::assertStringContainsString( 'No routes have been published yet.', RouteBlocks::listing( [], 'es' ) );
		self::assertStringContainsString( 'Steep and narrow', RouteBlocks::motorhome( self::route() ) );
	}

	public function test_header_and_footer(): void {
		$langs = [ [ 'code' => 'en', 'label' => 'English', 'url' => 'http://x/', 'active' => true ], [ 'code' => 'es', 'label' => 'Español', 'url' => 'http://x/es/', 'active' => false ] ];
		$h     = Chrome::header(
			[
				'home'      => 'http://x/',
				'links'     => [ [ 'label' => 'Tours & more', 'url' => 'http://x/tours/', 'current' => true ], [ 'label' => 'What to see', 'url' => 'http://x/places/' ] ],
				'languages' => $langs,
				'cta'       => [ 'label' => 'Book a tour', 'url' => 'http://x/tours/' ],
			]
		);
		self::assertStringContainsString( '<a class="skye-logo" href="http://x/">skye<span>guide</span></a>', $h );
		self::assertStringContainsString( '<a href="http://x/tours/" aria-current="page">Tours &amp; more</a>', $h );
		self::assertStringContainsString( '<summary class="skye-chip" aria-label="Change language">EN</summary>', $h );
		self::assertStringContainsString( '<a href="http://x/es/" lang="es" hreflang="es">Español</a>', $h );
		self::assertStringNotContainsString( '>English<', $h, 'the active language is the button, not an option' );

		$bare = Chrome::header( [ 'home' => '/', 'links' => [], 'languages' => [ $langs[0] ], 'cta' => null ] );
		self::assertStringNotContainsString( '<nav', $bare );
		self::assertStringNotContainsString( 'skye-lang', $bare, 'no switcher with a single language' );
		self::assertStringNotContainsString( 'skye-btn', $bare );

		$f = Chrome::footer(
			[
				'home'      => '/',
				'tagline'   => 'Visitor information.',
				'columns'   => [ [ 'title' => 'Explore', 'links' => [ [ 'label' => 'What to see', 'url' => 'http://x/places/' ] ] ], [ 'title' => 'Empty', 'links' => [] ] ],
				'languages' => $langs,
				'brands'    => [ 'Viajar por Escocia', 'Scozia Tour', 'Loch Ness Bus' ],
			]
		);
		self::assertStringContainsString( 'Tours operated by TA Scotland (Viajar por Escocia, Scozia Tour, Loch Ness Bus).', $f );
		self::assertStringContainsString( '<strong>Explore</strong><a href="http://x/places/">What to see</a>', $f );
		self::assertStringNotContainsString( 'Empty', $f );
		self::assertStringContainsString( '<strong>Languages</strong>', $f );
	}
}
