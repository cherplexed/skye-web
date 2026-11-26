<?php
declare(strict_types=1);

namespace Skye\Tests;

use PHPUnit\Framework\TestCase;
use Skye\Fields;
use Skye\Taxonomies;

/** Guards the contract: seed data and ACF JSON must agree with Fields.php. */
final class SchemaTest extends TestCase {

	public function test_seed_places_only_use_known_fields_and_terms(): void {
		$seed   = json_decode( (string) file_get_contents( __DIR__ . '/../../seed/places.json' ), true, 512, JSON_THROW_ON_ERROR );
		$fields = Fields::place();
		$terms  = Taxonomies::terms();
		$known  = array_keys( $fields );
		$extra  = [ 'slug', 'title', 'content', 'zone', 'categories', 'profiles', 'audioguide' ];

		foreach ( $seed['places'] as $p ) {
			foreach ( array_keys( $p ) as $k ) {
				self::assertTrue( in_array( $k, $known, true ) || in_array( $k, $extra, true ), "Unknown field '$k' in {$p['slug']}" );
			}
			self::assertArrayHasKey( $p['zone'], $terms[ Taxonomies::ZONE ], "{$p['slug']}: zone" );
			foreach ( $p['categories'] as $c ) {
				self::assertArrayHasKey( $c, $terms[ Taxonomies::CATEGORY ], "{$p['slug']}: category $c" );
			}
			foreach ( $p['profiles'] as $c ) {
				self::assertArrayHasKey( $c, $terms[ Taxonomies::PROFILE ], "{$p['slug']}: profile $c" );
			}
			foreach ( $p['suitable_for'] as $s ) {
				self::assertArrayHasKey( $s, $fields['suitable_for']['choices'], "{$p['slug']}: suitable_for $s" );
			}
			foreach ( $p['services_nearby'] as $s ) {
				self::assertArrayHasKey( $s, $fields['services_nearby']['choices'], "{$p['slug']}: services_nearby $s" );
			}
			self::assertArrayHasKey( $p['difficulty'], $fields['difficulty']['choices'] );
			self::assertArrayHasKey( $p['price_type'], $fields['price_type']['choices'] );
			self::assertArrayHasKey( $p['motorhome_parking'], $fields['motorhome_parking']['choices'] );
			self::assertLessThanOrEqual( 160, mb_strlen( $p['summary'] ), "{$p['slug']}: summary > 160 chars" );
			self::assertIsFloat( $p['location']['lat'] );
		}
		self::assertCount( 10, $seed['places'] );
	}

	public function test_acf_json_matches_fields(): void {
		$json  = json_decode( (string) file_get_contents( SKYE_CORE_DIR . '/acf-json/group_skye_place.json' ), true, 512, JSON_THROW_ON_ERROR );
		$names = array_column( $json['fields'], 'name' );
		self::assertSame( array_keys( Fields::place() ), $names );
	}
}
