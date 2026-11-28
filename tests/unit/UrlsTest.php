<?php
declare(strict_types=1);

namespace Skye\Tests;

use PHPUnit\Framework\TestCase;
use Skye\Urls;

final class UrlsTest extends TestCase {

	public function test_an_address_takes_the_base_of_its_language(): void {
		self::assertSame( 'http://localhost:8080/es/lugares/castillo-de-dunvegan/', Urls::localize( 'http://localhost:8080/es/places/castillo-de-dunvegan/', 'es' ) );
		self::assertSame( 'https://skye.test/it/luoghi/', Urls::localize( 'https://skye.test/it/places/', 'it' ) );
		self::assertSame( '/es/zona/trotternish-es/?category=hike#list', Urls::localize( '/es/zone/trotternish-es/?category=hike#list', 'es' ) );
		self::assertSame( '/es/autocaravana/', Urls::localize( '/es/motorhome/', 'es-ES' ) );
		self::assertSame( '/it/tour/x/', Urls::localize( '/it/tours/x/', 'it' ) );
		// From the base of one language to another, and back to the registered one for the rest.
		self::assertSame( '/it/percorsi/skye-in-un-giorno/', Urls::localize( '/it/rutas/skye-in-un-giorno/', 'it' ) );
		self::assertSame( '/places/quiraing/', Urls::localize( '/lugares/quiraing/', 'en' ) );
		self::assertSame( '/de/places/quiraing/', Urls::localize( '/de/luoghi/quiraing/', 'de' ) );
	}

	public function test_only_the_base_segment_is_touched(): void {
		foreach ( [ '/es/', '/', '', '/es/places', 'https://skye.test', '/es/wp-json/skye/v1/places', '/es/blog/places/', '/es/lugares/places/', 'mailto:hola@skye.test', 'https://places.example/routes' ] as $url ) {
			$expected = [ '/es/places' => '/es/lugares', 'https://places.example/routes' => 'https://places.example/rutas' ][ $url ] ?? $url;
			self::assertSame( $expected, Urls::localize( $url, 'es' ), $url );
		}
		// "es" and "it" are language directories; "tour" is a base, not a language.
		self::assertSame( '/tours/skye/', Urls::localize( '/tour/skye/', 'en' ) );
		self::assertSame( 'https://places.example/rutas/', Urls::localize( 'https://places.example/routes/', 'es' ) );
	}

	public function test_every_base_of_every_language_is_known_and_unambiguous(): void {
		foreach ( Urls::BASES as $lang => $bases ) {
			self::assertSame( array_keys( Urls::TARGETS ), array_keys( $bases ), $lang );
			self::assertSame( count( $bases ), count( array_unique( $bases ) ), "$lang: two types share a base" );
			foreach ( $bases as $base => $translated ) {
				self::assertSame( 1, preg_match( '/^[a-z]+$/', $translated ) );
				self::assertSame( $base, Urls::registered( $translated ), "$lang/$translated" );
				self::assertSame( 0, preg_match( '/^[a-z]{2}$/', $translated ), 'a two-letter base would read as a language' );
			}
		}
		self::assertNull( Urls::registered( 'blog' ) );
	}

	public function test_rewrite_rules_cover_archives_items_and_zones_once(): void {
		$rules = Urls::rules();
		self::assertSame( 'index.php?post_type=place', $rules['^lugares/?$'] );
		self::assertSame( 'index.php?place=$matches[1]', $rules['^luoghi/([^/]+)/?$'] );
		self::assertSame( 'index.php?post_type=route&paged=$matches[1]', $rules['^percorsi/page/([0-9]{1,})/?$'] );
		self::assertSame( 'index.php?motorhome_spot=$matches[1]', $rules['^camper/([^/]+)/?$'] );
		self::assertSame( 'index.php?skye_zone=$matches[1]', $rules['^zona/([^/]+)/?$'] );
		self::assertSame( [ '^zona/([^/]+)/?$' ], array_values( array_filter( array_keys( $rules ), static fn( $r ) => str_contains( $r, 'zona' ) ) ), 'the zone has a term page and nothing else, whatever the languages that share the word' );
		self::assertTrue( ! isset( $rules['^tours/?$'] ), 'a base equal to the registered one needs no rule' );
		self::assertTrue( isset( $rules['^tour/?$'] ) );
	}
}
