<?php
declare(strict_types=1);

namespace Skye\Tests;

use PHPUnit\Framework\TestCase;
use Skye\Fields;
use Skye\PostTypes;
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
		self::assertCount( 26, $seed['places'] );
	}

	public function test_acf_json_matches_fields_for_every_type(): void {
		foreach ( Fields::all() as $type => $fields ) {
			$json  = json_decode( (string) file_get_contents( SKYE_CORE_DIR . "/acf-json/group_skye_$type.json" ), true, 512, JSON_THROW_ON_ERROR );
			$names = array_column( $json['fields'], 'name' );
			self::assertSame( array_keys( $fields ), $names, $type );
			self::assertSame( $type, $json['location'][0][0]['value'] );
		}
	}

	public function test_every_content_type_has_a_schema_and_valid_definitions(): void {
		self::assertSame( [], array_diff( PostTypes::all(), array_keys( Fields::all() ) ) );
		$types = [ 'text', 'textarea', 'html', 'number', 'bool', 'enum', 'checkboxes', 'latlng', 'image', 'gallery', 'repeater', 'relation', 'file', 'url' ];
		$check = static function ( array $fields, string $path ) use ( &$check, $types ): void {
			foreach ( $fields as $name => $def ) {
				self::assertMatchesRegularExpression( '/^[a-z][a-z0-9_]*$/', $name, "$path.$name" );
				self::assertContains( $def['type'], $types, "$path.$name" );
				self::assertIsBool( $def['i18n'], "$path.$name" );
				if ( in_array( $def['type'], [ 'enum', 'checkboxes' ], true ) ) {
					self::assertNotEmpty( $def['choices'], "$path.$name" );
				}
				if ( 'enum' === $def['type'] && array_key_exists( 'default', $def ) ) {
					self::assertArrayHasKey( $def['default'], $def['choices'], "$path.$name default" );
				}
				if ( 'relation' === $def['type'] ) {
					self::assertContains( $def['to'], PostTypes::all(), "$path.$name" );
				}
				if ( 'repeater' === $def['type'] ) {
					$check( $def['sub'], "$path.$name" );
				}
			}
		};
		foreach ( Fields::all() as $type => $fields ) {
			$check( $fields, $type );
		}
	}

	public function test_internal_fields_are_not_public(): void {
		self::assertNotContains( 'commission_pct', Fields::public_names( PostTypes::EXPERIENCE ) );
		self::assertContains( 'booking_url', Fields::public_names( PostTypes::EXPERIENCE ) );
	}
}
