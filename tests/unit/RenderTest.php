<?php
declare(strict_types=1);

namespace Skye\Tests;

use PHPUnit\Framework\TestCase;
use Skye\JsonLd;
use Skye\Render\Chrome;
use Skye\Render\PlaceBlocks;

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

		$empty = self::place( [ 'tips' => [], 'opening_hours' => [], 'in_tours' => [], 'nearby_places' => [], 'nearby_lodging' => [], 'directions' => '', 'location' => null, 'parking_location' => null, 'image' => null, 'gallery' => [], 'motorhome_parking' => '', 'categories' => [], 'profiles' => [], 'price_type' => 'ticket', 'gaelic_name' => '', 'summary' => '' ] );
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
		self::assertSame( '<ul class="skye-tags"><li class="skye-tag">Hike</li><li class="skye-tag">Hiking</li><li class="skye-tag">Free</li><li class="skye-tag">Audioguide</li></ul>', PlaceBlocks::tags( $p ) );
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
		self::assertCount( 3, $files );
		$slugs = [];
		foreach ( $files as $file ) {
			$t       = json_decode( (string) file_get_contents( $file ), true, 512, JSON_THROW_ON_ERROR );
			$slugs[] = $t['slug'];
			self::assertContains( $t['type'], [ 'header', 'footer', 'content' ] );
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
					self::assertSame( 1, preg_match( '/^\[skye_(header|footer|place part="([a-z]+)")\]$/', $el['settings']['shortcode'], $m ), $el['settings']['shortcode'] );
					if ( isset( $m[2] ) ) {
						self::assertContains( $m[2], PlaceBlocks::PARTS );
					}
				}
			}
		}
		self::assertSame( [ 'skye-footer', 'skye-header', 'skye-single-place' ], $slugs );
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
