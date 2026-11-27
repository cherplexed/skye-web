<?php
declare(strict_types=1);

namespace Skye\Tests;

use PHPUnit\Framework\TestCase;
use Skye\JsonLd;
use Skye\Render\Chrome;
use Skye\Render\Home;
use Skye\Directory;
use Skye\Render\PlaceBlocks;
use Skye\Render\PlaceList;
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
		self::assertCount( 9, $files );
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
					self::assertSame( 1, preg_match( '/^\[skye_(header|footer|tours|routes|places|home|(place|tour|route) part="([a-z]+)")\]$/', $el['settings']['shortcode'], $m ), $el['settings']['shortcode'] );
					if ( isset( $m[3] ) ) {
						self::assertContains( $m[3], [ 'place' => PlaceBlocks::PARTS, 'tour' => TourBlocks::PARTS, 'route' => RouteBlocks::PARTS ][ $m[2] ] );
					}
				}
			}
		}
		self::assertSame( [ 'skye-archive-places', 'skye-archive-routes', 'skye-archive-tours', 'skye-footer', 'skye-header', 'skye-home', 'skye-single-place', 'skye-single-route', 'skye-single-tour' ], $slugs );
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
				[
					'title'   => 'A full day on Skye',
					'summary' => '',
					'stops'   => [
						[ 'place' => [ 'id' => 1, 'title' => 'Quiraing', 'url' => 'http://x/places/quiraing/', 'image' => [ 'url' => 'http://x/q.jpg', 'alt' => 'Quiraing cliffs' ] ], 'title' => '', 'stop_type' => 'photo', 'duration_min' => 20, 'note' => 'Or the Fairy <Glen>.' ],
						[ 'place' => [ 'id' => 2, 'title' => 'Dunvegan Castle' ], 'title' => 'Dunvegan: castle & gardens', 'stop_type' => 'visit', 'duration_min' => 130, 'note' => '' ],
						[ 'place' => null, 'title' => 'Gesto Bay viewpoint', 'stop_type' => 'photo', 'duration_min' => 15, 'note' => '' ],
						[ 'place' => [ 'id' => 3, 'title' => 'Kyleakin' ], 'title' => '', 'stop_type' => 'overnight', 'duration_min' => null, 'note' => '' ],
						[ 'place' => null, 'title' => '', 'stop_type' => 'visit', 'duration_min' => 10, 'note' => 'nameless stops are not shown' ],
					],
				],
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
		self::assertSame( 4, substr_count( $it, '<li class="skye-tourstop ' ), 'every named stop, in order' );
		self::assertStringContainsString( '<span class="skye-tourstop__name"><a href="http://x/places/quiraing/">Quiraing</a></span><span class="skye-tourstop__meta">Photo stop · 20 min</span></div><p class="skye-tourstop__note">Or the Fairy &lt;Glen&gt;.</p></div><div class="skye-tourstop__photo"><img src="http://x/q.jpg" alt="Quiraing cliffs" loading="lazy" decoding="async"></div></li>', $it );
		self::assertStringContainsString( '<span class="skye-tourstop__name">Dunvegan: castle &amp; gardens</span><span class="skye-tourstop__meta">Visit · 2h 10</span>', $it, 'its own title wins over the name of the place' );
		self::assertStringContainsString( '<span class="skye-tourstop__name">Gesto Bay viewpoint</span>', $it, 'a stop without a page is plain text' );
		self::assertStringContainsString( 'skye-tourstop--overnight"><div class="skye-tourstop__text"><div class="skye-tourstop__head"><span class="skye-tourstop__name">Kyleakin</span><span class="skye-tourstop__meta">Overnight</span>', $it, 'no duration, no dot' );
		self::assertStringContainsString( '<p class="skye-itinerary__small">The length of each stop is an estimate: it will depend on the weather, the traffic and other factors.</p>', $it );
		self::assertStringNotContainsString( 'skye-itinerary__small', TourBlocks::itinerary( self::tour( [ 'days' => [ [ 'title' => 'Day', 'summary' => '', 'stops' => [] ] ] ] ) ), 'no small print when no times are shown' );

		$inc = TourBlocks::includes( $t );
		self::assertStringContainsString( '<li>Transport in a minibus.</li><li>Professional driver-guide.</li><li>Audioguide</li>', $inc );
		self::assertStringContainsString( '<h3>Not included</h3><ul class="skye-includes__no"><li>Meals.</li><li>Travel insurance.</li>', $inc );
		self::assertSame( '', TourBlocks::includes( self::tour( [ 'includes' => '', 'excludes' => '' ] ) ) );

		self::assertSame( 1, substr_count( $it, 'skye-tourstop__photo' ), 'only stops whose place has a photo show one' );

		$ld = JsonLd::tour( $t + [ 'title' => 'Three days', 'url' => 'http://x/tours/3/', 'summary' => 'S' ] );
		self::assertSame( 'TouristTrip', $ld['@type'] );
		self::assertSame( [ 'Quiraing' ], array_map( static fn( $i ) => $i['item']['name'], $ld['itinerary']['itemListElement'] ), 'only stops with a page and a URL' );
		self::assertSame( [ '@type' => 'Offer', 'price' => 283.0, 'priceCurrency' => 'EUR', 'url' => 'https://lochnessbus.com/', 'seller' => [ '@type' => 'Organization', 'name' => 'Loch Ness Bus' ] ], $ld['offers'] );
		$no_price = JsonLd::tour( [ 'offer' => [ 'price_from' => null, 'brand' => [ 'title' => 'Loch Ness Bus' ] ] ] + $t );
		self::assertArrayNotHasKey( 'offers', $no_price, 'no price from Bókun, no offer' );
		self::assertSame( 'Loch Ness Bus', $no_price['provider']['name'] );

		self::assertSame( '', TourBlocks::cover( $t ) );
		$cover = TourBlocks::cover( [ 'image' => [ 'url' => 'http://x/a.jpg', 'alt' => 'A' ], 'gallery' => [ [ 'url' => 'http://x/b.jpg', 'alt' => 'B' ], [ 'url' => 'http://x/c.jpg', 'alt' => 'C' ], [ 'url' => 'http://x/d.jpg', 'alt' => 'D' ] ] ] );
		self::assertStringContainsString( 'skye-gallery--3', $cover, 'cover and two more' );
		self::assertStringNotContainsString( 'd.jpg', $cover );

		$acc = TourBlocks::accommodation( [ 'accommodation_intro' => 'Two nights.', 'accommodation_options' => [ [ 'name' => 'Tour only', 'description' => 'Book <your> own.' ], [ 'name' => '', 'description' => 'nameless' ] ], 'accommodation_notes' => "Few beds.\nBook early." ] );
		self::assertStringContainsString( '<h2 class="skye-block__title">Accommodation</h2><p>Two nights.</p>', $acc );
		self::assertStringContainsString( '<div class="skye-lodgingopt"><dt>Tour only</dt><dd>Book &lt;your&gt; own.</dd></div></dl>', $acc, 'an option without a name is left out' );
		self::assertStringContainsString( '<ul class="skye-lodgingnotes"><li>Few beds.</li><li>Book early.</li></ul>', $acc );
		self::assertSame( '', TourBlocks::accommodation( $t ), 'nothing to say, no block' );

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
		self::assertStringContainsString( 'Rather not drive? See Skye on our guided tour: Skye &amp; the Highlands, 3 days', $card );
		self::assertStringContainsString( 'From 283 € per person', $card );
		self::assertStringContainsString( '<a class="skye-btn skye-btn--primary" href="http://x/tours/3/">See the tour</a>', $card );

		$list = RouteBlocks::listing( [ self::route() ], 'es' );
		self::assertStringContainsString( '<a class="skye-card skye-tourcard skye-routecard" href="http://x/routes/trotternish-loop/">', $list );
		self::assertStringContainsString( '1 day · 80 km (49.7 mi) · 2h 10 driving', $list );
		self::assertStringContainsString( 'No routes have been published yet.', RouteBlocks::listing( [], 'es' ) );
		self::assertStringContainsString( 'Steep and narrow', RouteBlocks::motorhome( self::route() ) );
	}

	public function test_faq_answers_from_the_facts_first_then_editorial(): void {
		$p     = self::place( [ 'faq' => [ [ 'question' => 'Why "Old Man"?', 'answer' => "A giant's thumb.\n\nOr a face in the cliff." ], [ 'question' => ' ', 'answer' => 'dropped' ] ] ] );
		$items = PlaceBlocks::faq_items( $p, 'en-us' );
		self::assertSame(
			[ 'How long do you need at Old Man of Storr?', 'Do you have to pay to visit Old Man of Storr?', 'How hard is the walk at Old Man of Storr?', 'Can you visit Old Man of Storr without a car?', 'Why "Old Man"?' ],
			array_column( $items, 'question' )
		);
		self::assertSame( 'Allow about 2h. With less time, 1h is the minimum to make the stop worthwhile.', $items[0]['answer'] );
		self::assertSame( 'The visit itself is free. Parking costs £6.', $items[1]['answer'] );
		self::assertSame( 'The walk is 2.4 mi there and back. It climbs 984 ft. We rate it as: moderate.', $items[2]['answer'] );
		self::assertStringContainsString( '3.8 km', PlaceBlocks::faq_items( $p, 'es' )[2]['answer'], 'same facts, the visitor\'s units' );
		self::assertSame( 'Yes. It is a stop on our guided tours from Edinburgh: 3-day tour.', $items[3]['answer'] );

		$html = PlaceBlocks::faq( $p, 'en-us' );
		self::assertSame( 5, substr_count( $html, '<details class="skye-faq__item">' ) );
		self::assertStringNotContainsString( '<details open', $html, 'closed by default' );
		self::assertStringContainsString( '<summary>Why &quot;Old Man&quot;?</summary><div class="skye-faq__answer"><p>A giant&#039;s thumb.</p><p>Or a face in the cliff.</p></div>', $html );

		// The structured data carries exactly the questions that are on the page.
		$ld = JsonLd::faq( $items );
		self::assertSame( 'FAQPage', $ld['@type'] );
		self::assertSame( array_column( $items, 'question' ), array_column( $ld['mainEntity'], 'name' ) );
		self::assertSame( "A giant's thumb.\n\nOr a face in the cliff.", $ld['mainEntity'][4]['acceptedAnswer']['text'] );
		self::assertNull( JsonLd::faq( [] ) );

		$bare = self::place( [ 'duration_typical' => null, 'distance_km' => null, 'in_tours' => [], 'price_type' => 'ticket', 'price_gbp' => null ] );
		self::assertSame( [ 'Do you have to pay to visit Old Man of Storr?' ], array_column( PlaceBlocks::faq_items( $bare, 'es' ), 'question' ) );
		self::assertStringContainsString( 'Check the current price on the official site', PlaceBlocks::faq_items( $bare, 'es' )[0]['answer'] );
	}

	public function test_sources_link_only_real_urls(): void {
		$html = PlaceBlocks::sources( self::place( [ 'sources' => [ [ 'title' => 'The Storr, official site', 'url' => 'https://thestorr.com/parking/' ], [ 'title' => 'A guide on the ground', 'url' => '' ], [ 'title' => 'Bad', 'url' => 'javascript:alert(1)' ], [ 'title' => '', 'url' => 'https://x' ] ] ] ) );
		self::assertStringContainsString( '<a href="https://thestorr.com/parking/" rel="noopener">The Storr, official site</a>', $html );
		self::assertStringContainsString( '<li>A guide on the ground</li><li>Bad</li>', $html );
		self::assertStringNotContainsString( 'javascript:', $html );
		self::assertSame( '', PlaceBlocks::sources( self::place() ) );
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

	/** @return list<array<string, mixed>> */
	private static function directory_places(): array {
		$mk = static fn( string $title, string $zone, array $cats, array $profiles ): array => [
			'title'            => $title,
			'url'              => 'http://x/places/' . strtolower( str_replace( ' ', '-', $title ) ) . '/',
			'summary'          => 'Summary of ' . $title,
			'zone'             => [ 'slug' => $zone, 'name' => ucfirst( $zone ) ],
			'categories'       => array_map( static fn( $c ) => [ 'slug' => $c, 'name' => ucfirst( $c ) ], $cats ),
			'profiles'         => array_map( static fn( $c ) => [ 'slug' => $c, 'name' => ucfirst( $c ) ], $profiles ),
			'duration_typical' => 90,
			'difficulty'       => 'easy',
			'price_type'       => 'free',
		];
		return [
			$mk( 'Stirling', 'en-route', [ 'castle' ], [ 'history' ] ),
			$mk( 'Quiraing', 'trotternish', [ 'hike', 'viewpoint' ], [ 'hiking' ] ),
			$mk( 'Kilt Rock', 'trotternish', [ 'viewpoint' ], [ 'families' ] ),
			$mk( 'Dunvegan', 'duirinish', [ 'castle' ], [ 'history', 'families' ] ),
		];
	}

	public function test_directory_matches_every_active_filter(): void {
		$places = self::directory_places();
		$this->assertCount( 4, Directory::match( $places, [ 'zone' => '', 'category' => '', 'profile' => '' ] ) );
		$this->assertSame( [ 'Quiraing', 'Kilt Rock' ], array_column( Directory::match( $places, [ 'zone' => 'trotternish' ] ), 'title' ) );
		$this->assertSame( [ 'Dunvegan' ], array_column( Directory::match( $places, [ 'category' => 'castle', 'profile' => 'families' ] ), 'title' ) );
		$this->assertSame( [], Directory::match( $places, [ 'zone' => 'trotternish', 'category' => 'castle' ] ) );
	}

	public function test_directory_options_never_lead_to_an_empty_page(): void {
		$places = self::directory_places();
		$order  = [ 'viewpoint' => 'Viewpoint', 'hike' => 'Hike', 'castle' => 'Castle', 'beach' => 'Beach' ];
		$all    = Directory::options( $places, [ 'zone' => '', 'category' => '', 'profile' => '' ], 'category', $order );
		$this->assertSame( [ 'viewpoint', 'hike', 'castle' ], array_column( $all, 'slug' ), 'canonical order, empty terms left out' );
		$this->assertSame( [ 2, 1, 2 ], array_column( $all, 'count' ) );

		$in_zone = Directory::options( $places, [ 'zone' => 'trotternish', 'category' => 'hike', 'profile' => '' ], 'category', $order );
		$this->assertSame( [ 'viewpoint', 'hike' ], array_column( $in_zone, 'slug' ), 'castle has no place in Trotternish' );
		$this->assertSame( [ false, true ], array_column( $in_zone, 'active' ) );

		$zones = Directory::options( $places, [ 'zone' => '', 'category' => 'castle', 'profile' => '' ], 'zone', [ 'trotternish' => 'T', 'duirinish' => 'D', 'en-route' => 'E' ] );
		$this->assertSame( [ 'duirinish', 'en-route' ], array_column( $zones, 'slug' ) );
	}

	public function test_directory_groups_by_zone_in_canonical_order(): void {
		$groups = Directory::by_zone( self::directory_places(), [ 'trotternish' => 'T', 'duirinish' => 'D', 'en-route' => 'E' ] );
		$this->assertSame( [ 'trotternish', 'duirinish', 'en-route' ], array_column( $groups, 'slug' ) );
		$this->assertSame( [ 'Kilt Rock', 'Quiraing' ], array_column( $groups[0]['places'], 'title' ), 'alphabetical inside a zone' );
	}

	public function test_place_card_and_directory_page(): void {
		$places = self::directory_places();
		$card   = PlaceList::card( [ 'title' => 'A <b>', 'summary' => 'S & s', 'url' => 'http://x/a/', 'categories' => [ [ 'slug' => 'hike', 'name' => 'Hike' ] ], 'duration_typical' => 120, 'difficulty' => 'moderate', 'price_type' => 'paid_parking' ] );
		$this->assertStringContainsString( '<a class="skye-card skye-placecard" href="http://x/a/">', $card );
		$this->assertStringContainsString( 'A &lt;b&gt;', $card );
		$this->assertStringContainsString( '2h · Moderate · Paid parking', $card );
		$this->assertStringContainsString( 'skye-placecard__kind">Hike<', $card );

		$view = [
			'eyebrow'   => 'The whole island',
			'title'     => 'What to see',
			'lead'      => 'Lead',
			'filters'   => [ [ 'label' => 'Type', 'all' => [ 'label' => 'All types', 'url' => 'http://x/places/', 'active' => false ], 'options' => [ [ 'name' => 'Castle', 'url' => 'http://x/places/?category=castle', 'count' => 2, 'active' => true ] ] ], [ 'label' => 'Empty', 'all' => [ 'label' => 'All', 'url' => '#', 'active' => true ], 'options' => [] ] ],
			'groups'    => [ [ 'name' => 'Trotternish', 'url' => 'http://x/zone/trotternish/', 'places' => array_slice( $places, 1, 2 ) ] ],
			'total'     => 2,
			'filtered'  => true,
			'reset_url' => 'http://x/places/',
			'grouped'   => true,
		];
		$html = PlaceList::directory( $view );
		$this->assertStringContainsString( '<h1 class="skye-listing__title">What to see</h1>', $html );
		$this->assertStringContainsString( 'href="http://x/places/?category=castle" aria-current="true">Castle <span class="skye-filter__count">2</span>', $html );
		$this->assertStringNotContainsString( 'Empty', $html, 'a filter without options is not shown' );
		$this->assertStringContainsString( '2 places · <a href="http://x/places/">Clear filters</a>', $html );
		$this->assertStringContainsString( '<h2 class="skye-zone__title"><a href="http://x/zone/trotternish/">Trotternish</a>', $html );
		$this->assertSame( 2, substr_count( $html, '<h3 class="skye-placecard__title">' ) );

		$flat = PlaceList::directory( [ 'grouped' => false, 'filtered' => false, 'breadcrumb' => [ [ 'label' => 'Home', 'url' => 'http://x/' ], [ 'label' => 'Trotternish' ] ] ] + $view );
		$this->assertStringContainsString( '<a href="http://x/">Home</a>', $flat );
		$this->assertStringContainsString( '<span aria-current="page">Trotternish</span>', $flat );
		$this->assertStringNotContainsString( 'skye-zone__title', $flat );
		$this->assertSame( 2, substr_count( $flat, '<h2 class="skye-placecard__title">' ) );

		$none = PlaceList::directory( [ 'groups' => [], 'total' => 0 ] + $view );
		$this->assertStringContainsString( 'No places match these filters.', $none );

		$ld = JsonLd::item_list( 'What to see', $places );
		$this->assertSame( 'ItemList', $ld['@type'] );
		$this->assertSame( 4, $ld['numberOfItems'] );
		$this->assertSame( [ 1, 2, 3, 4 ], array_column( $ld['itemListElement'], 'position' ) );
		$this->assertNull( JsonLd::item_list( 'x', [] ) );
	}

	public function test_place_breadcrumb_links_to_its_zone_page(): void {
		$html = PlaceBlocks::breadcrumb( self::place( [ '_links' => [ 'home' => 'http://x/', 'places' => 'http://x/places/', 'zone' => 'http://x/zone/trotternish/' ] ] ) );
		$this->assertStringContainsString( '<a href="http://x/zone/trotternish/">Trotternish</a>', $html );
	}

	public function test_home_page_is_built_from_the_content_that_exists(): void {
		$view = [
			'links'      => [ 'tours' => 'http://x/tours/', 'places' => 'http://x/places/', 'routes' => 'http://x/routes/' ],
			'hero_image' => [ 'url' => 'http://x/h.jpg', 'alt' => 'Storr' ],
			'tours'      => [ self::tour( [ 'title' => 'Two days', 'days_count' => 2, 'url' => 'http://x/tours/2/' ] ), [ 'title' => 'No price', 'days_count' => 3, 'url' => 'http://x/tours/3/' ] ],
			'routes'     => [ [ 'title' => 'Trotternish <loop>', 'url' => 'http://x/routes/t/' ] ],
			'zones'      => [ [ 'name' => 'Minginish & Cuillin', 'count' => 6, 'url' => 'http://x/zone/m/', 'intro' => 'The Cuillin.' ], [ 'name' => 'Raasay', 'count' => 1, 'url' => 'http://x/zone/r/', 'intro' => '' ] ],
			'profiles'   => [ [ 'name' => 'Whisky', 'count' => 2, 'url' => 'http://x/places/?profile=whisky' ] ],
			'places'     => [ self::directory_places()[1] ],
		];
		$html = Home::page( $view, 'de' );
		self::assertSame( 1, substr_count( $html, '<h1' ) );
		self::assertStringContainsString( '<img src="http://x/h.jpg" alt="Storr" loading="eager"', $html );
		self::assertStringContainsString( '<a class="skye-chip" href="http://x/tours/2/">2 days · from 283 €</a>', $html, 'the price Bókun gave, in the market' );
		self::assertStringContainsString( '<a class="skye-chip" href="http://x/tours/3/">3 days</a>', $html, 'no price, no "from"' );
		self::assertStringContainsString( '<a class="skye-chip" href="http://x/routes/t/">Trotternish &lt;loop&gt;</a>', $html );
		self::assertStringContainsString( '<strong>Minginish &amp; Cuillin</strong><span class="skye-zonecard__count">6 places</span><span class="skye-zonecard__intro">The Cuillin.</span>', $html );
		self::assertStringContainsString( '<strong>Raasay</strong><span class="skye-zonecard__count">1 place</span></a>', $html );
		self::assertStringContainsString( 'href="http://x/places/?profile=whisky">Whisky <span class="skye-filter__count">2</span>', $html );
		self::assertStringContainsString( '<h3 class="skye-placecard__title">Quiraing</h3>', $html );

		$empty = Home::page( [ 'links' => [], 'hero_image' => null, 'tours' => [], 'routes' => [], 'zones' => [], 'profiles' => [], 'places' => [] ], 'en-gb' );
		self::assertStringContainsString( '<h1', $empty );
		self::assertStringNotContainsString( 'skye-home__section', $empty, 'a section with nothing to show is left out' );
		self::assertStringNotContainsString( 'skye-btn', $empty );
	}
}
