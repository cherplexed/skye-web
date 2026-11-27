<?php
declare(strict_types=1);

namespace Skye\Tests;

use PHPUnit\Framework\TestCase;
use Skye\Fields;
use Skye\Lang;
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

	public function test_every_language_has_every_interface_string_with_the_same_placeholders(): void {
		$source       = self::source_strings();
		$placeholders = static function ( string $s ): array {
			preg_match_all( '/%(?:\d+\$)?[sd]/', $s, $m );
			sort( $m[0] );
			return $m[0];
		};
		$files = glob( self::PLUGIN . '/languages/skye-core-*.l10n.php' ) ?: [];
		$this->assertTrue( count( $files ) >= 2, 'Spanish and Italian at least' );
		foreach ( $files as $path ) {
			$file   = require $path;
			$locale = (string) $file['language'];
			$this->assertSame( 'skye-core', $file['domain'] );
			$this->assertSame( "skye-core-$locale.l10n.php", basename( $path ) );
			$messages = $file['messages'];
			$this->assertSame( [], array_keys( array_diff_key( $source, $messages ) ), "$locale: strings without a translation" );
			$this->assertSame( [], array_keys( array_diff_key( $messages, $source ) ), "$locale: translations of strings that no longer exist" );
			foreach ( $messages as $en => $translation ) {
				$this->assertTrue( '' !== trim( $translation ), "$locale: $en" );
				$this->assertSame( $placeholders( $en ), $placeholders( $translation ), "$locale: $en" );
			}
		}
	}

	public function test_a_translation_overlays_its_original_without_repeating_what_is_not_translated(): void {
		$original = [
			'slug'  => 'tour',
			'title' => 'Tour',
			'price' => 10,
			'tips'  => [ 'One', 'Two', 'Three' ],
			'days'  => [
				[ 'title' => 'Day one', 'stops' => [ [ 'place' => 'a', 'note' => 'Note a' ], [ 'place' => 'b', 'note' => 'Note b' ] ] ],
				[ 'title' => 'Day two', 'stops' => [ [ 'place' => 'c', 'note' => 'Note c' ] ] ],
			],
		];
		$translation = [
			'title' => 'Tour (es)',
			'tips'  => [ 'Uno', 'Dos' ],
			'days'  => [
				[ 'title' => 'Primer día', 'stops' => [ [ 'note' => 'Nota a' ], [ 'note' => 'Nota b' ] ] ],
				[ 'title' => 'Segundo día' ],
			],
		];
		$out = Lang::overlay( $original, $translation );
		$this->assertSame( 'tour', $out['slug'] );
		$this->assertSame( 10, $out['price'] );
		$this->assertSame( 'Tour (es)', $out['title'] );
		$this->assertSame( [ 'Uno', 'Dos' ], $out['tips'], 'a list of texts is replaced whole' );
		$this->assertSame( [ 'place' => 'a', 'note' => 'Nota a' ], $out['days'][0]['stops'][0], 'a translated row keeps what it does not translate' );
		$this->assertSame( 'b', $out['days'][0]['stops'][1]['place'] );
		$this->assertSame( [ [ 'place' => 'c', 'note' => 'Note c' ] ], $out['days'][1]['stops'], 'untouched rows stay as they were' );
	}

	/** @return array<string, mixed> */
	private static function json( string $path ): array {
		return json_decode( (string) file_get_contents( __DIR__ . '/../../seed/' . $path ), true, 512, JSON_THROW_ON_ERROR );
	}

	/** @return list<string> Language codes that have translations in seed/. */
	private static function seed_languages(): array {
		return array_map( 'basename', glob( __DIR__ . '/../../seed/*', GLOB_ONLYDIR ) ?: [] );
	}

	public function test_translated_seeds_follow_their_originals_and_only_translate_translatable_fields(): void {
		$this->assertSame( [ 'es', 'it' ], self::seed_languages() );
		foreach ( self::seed_languages() as $lang ) {
			$this->check_translated_seed( $lang );
		}
		foreach ( [ 'places', 'tours', 'routes', 'spots' ] as $name ) {
			$this->assertSame( array_column( self::json( "$name.json" )[ $name ], 'slug' ), array_keys( self::json( "es/$name.json" )[ $name ] ), "Spanish translates every item of $name, in order" );
		}
	}

	private function check_translated_seed( string $lang ): void {
		$types = [ 'places' => PostTypes::PLACE, 'tours' => PostTypes::TOUR, 'routes' => PostTypes::ROUTE, 'spots' => PostTypes::MOTORHOME ];
		foreach ( $types as $name => $post_type ) {
			if ( ! is_file( __DIR__ . "/../../seed/$lang/$name.json" ) ) {
				continue; // A language can be translated in stages.
			}
			$originals    = array_column( self::json( "$name.json" )[ $name ], null, 'slug' );
			$translations = self::json( "$lang/$name.json" )[ $name ];
			$this->assertSame( [], array_values( array_diff( array_keys( $translations ), array_keys( $originals ) ) ), "$lang/$name: every translation has an original" );

			$schema = Fields::for( $post_type );
			foreach ( $translations as $slug => $row ) {
				$this->assertTrue( '' !== trim( (string) ( $row['title'] ?? '' ) ), "$lang/$name/$slug has a title" );
				foreach ( $row as $field => $value ) {
					if ( in_array( $field, [ 'title', 'content' ], true ) ) {
						continue;
					}
					$this->assertTrue( isset( $schema[ $field ] ), "$lang/$name/$slug.$field is a field of the type" );
					$this->assertTrue( $schema[ $field ]['i18n'], "$lang/$name/$slug.$field is translatable in Fields.php" );
				}
				$merged = Lang::overlay( $originals[ $slug ], $row );
				foreach ( [ 'faq', 'tips', 'opening_hours', 'days' ] as $list ) {
					if ( isset( $originals[ $slug ][ $list ] ) ) {
						$this->assertCount( count( $originals[ $slug ][ $list ] ), $merged[ $list ], "$lang/$name/$slug.$list has as many rows as the original" );
					}
				}
				foreach ( $merged['days'] ?? [] as $d => $day ) {
					$this->assertCount( count( $originals[ $slug ]['days'][ $d ]['stops'] ), $day['stops'], "$lang/$name/$slug day $d keeps its stops" );
				}
				if ( isset( $row['summary'] ) ) {
					$this->assertTrue( mb_strlen( $row['summary'] ) <= 160, "$lang/$name/$slug summary fits in 160 characters" );
				}
			}
		}
	}

	public function test_translated_terms_name_every_canonical_term(): void {
		foreach ( self::seed_languages() as $lang ) {
			$terms = self::json( "$lang/terms.json" );
			foreach ( Taxonomies::terms() as $taxonomy => $canonical ) {
				$this->assertSame( array_keys( $canonical ), array_keys( $terms[ $taxonomy ] ), "$lang/$taxonomy" );
			}
			$this->assertSame( array_keys( Taxonomies::zone_intros() ), array_keys( $terms['zone_intros'] ), $lang );
		}
	}
}
