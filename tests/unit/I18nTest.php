<?php
declare(strict_types=1);

namespace Skye\Tests;

use PHPUnit\Framework\TestCase;
use Skye\Fields;
use Skye\PostTypes;
use Skye\Taxonomies;

/** The language set-up is code too: WPML's configuration and the interface translations must follow the plugin. */
final class I18nTest extends TestCase {

	private const PLUGIN = __DIR__ . '/../../wp-content/plugins/skye-core';

	public function test_wpml_config_declares_every_content_type_and_taxonomy(): void {
		$xml = simplexml_load_string( (string) file_get_contents( self::PLUGIN . '/wpml-config.xml' ) );
		$this->assertTrue( false !== $xml, 'wpml-config.xml is well-formed' );

		$types = [];
		foreach ( $xml->{'custom-types'}->{'custom-type'} as $t ) {
			$types[ (string) $t ] = (string) $t['translate'];
		}
		$expected = PostTypes::all();
		sort( $expected );
		$declared = array_keys( $types );
		sort( $declared );
		$this->assertSame( $expected, $declared );
		$this->assertSame( '0', $types[ PostTypes::BRAND ], 'brands are the same in every language' );
		foreach ( array_diff( $expected, [ PostTypes::BRAND ] ) as $type ) {
			$this->assertContains( $types[ $type ], [ '1', '2' ], $type );
		}

		$taxonomies = [];
		foreach ( $xml->taxonomies->taxonomy as $t ) {
			$taxonomies[] = (string) $t;
			$this->assertSame( '1', (string) $t['translate'] );
		}
		$this->assertSame( array_keys( Taxonomies::terms() ), $taxonomies );

		$fields = [];
		foreach ( $xml->{'custom-fields'}->{'custom-field'} as $f ) {
			$fields[ (string) $f ] = (string) $f['action'];
		}
		$this->assertSame( 'copy', $fields['skye_uuid'] ?? null );
		$this->assertSame( 'copy', $fields['skye_verified_at'] ?? null );
	}

	/** @return array<string, true> Every interface string of the plugin, as written in the code. */
	private static function source_strings(): array {
		$found = [];
		$files = new \RecursiveIteratorIterator( new \RecursiveDirectoryIterator( self::PLUGIN . '/src', \FilesystemIterator::SKIP_DOTS ) );
		foreach ( $files as $file ) {
			if ( 'php' !== $file->getExtension() || str_contains( $file->getPathname(), '/Cli/' ) ) {
				continue; // The command line is for developers and stays in English.
			}
			preg_match_all( "/(?:Html::t|__|esc_html__|esc_attr__)\\(\\s*'((?:[^'\\\\]|\\\\.)*)'/", (string) file_get_contents( $file->getPathname() ), $m );
			foreach ( $m[1] as $s ) {
				$found[ str_replace( "\\'", "'", $s ) ] = true;
			}
		}
		// Labels of the choice fields are translated when shown (Html::t( $choices[ $value ] )).
		$walk = static function ( array $fields ) use ( &$walk, &$found ): void {
			foreach ( $fields as $def ) {
				foreach ( $def['choices'] ?? [] as $label ) {
					$found[ (string) $label ] = true;
				}
				$walk( $def['sub'] ?? [] );
			}
		};
		array_map( $walk, Fields::all() );
		// Zone names offered as choices are taxonomy terms: WPML translates those, not this file.
		return array_diff_key( $found, array_flip( Taxonomies::terms()[ Taxonomies::ZONE ] ) );
	}

	public function test_spanish_has_every_interface_string_with_the_same_placeholders(): void {
		$file = require self::PLUGIN . '/languages/skye-core-es_ES.l10n.php';
		$this->assertSame( 'skye-core', $file['domain'] );
		$this->assertSame( 'es_ES', $file['language'] );
		$es = $file['messages'];

		$source  = self::source_strings();
		$missing = array_keys( array_diff_key( $source, $es ) );
		$this->assertSame( [], $missing, 'strings without a Spanish translation' );
		$this->assertSame( [], array_keys( array_diff_key( $es, $source ) ), 'translations of strings that no longer exist' );

		$placeholders = static function ( string $s ): array {
			preg_match_all( '/%(?:\d+\$)?[sd]/', $s, $m );
			sort( $m[0] );
			return $m[0];
		};
		foreach ( $es as $en => $translation ) {
			$this->assertTrue( '' !== trim( $translation ), $en );
			$this->assertSame( $placeholders( $en ), $placeholders( $translation ), $en );
		}
	}
}
