<?php
declare(strict_types=1);

namespace Skye\Tests;

use PHPUnit\Framework\TestCase;
use Skye\Photos;
use Skye\PostTypes;

final class PhotosTest extends TestCase {

	public function test_plan_groups_by_item_orders_naturally_and_reports_strays(): void {
		$plan = Photos::plan(
			[
				'places/quiraing/10.jpg',
				'places/quiraing/2.JPG',
				'places/quiraing/1.jpeg',
				'places\\portree\\b.webp',
				'/routes/trotternish-loop/cover.png',
				'photos.csv',
				'README.md',
				'places/quiraing/.DS_Store',
				'places/quiraing/notes.txt',
				'places/loose.jpg',
				'places/quiraing/raw/3.jpg',
				'hotels/x/1.jpg',
			]
		);
		self::assertSame( [ 'places/portree', 'places/quiraing', 'routes/trotternish-loop' ], array_keys( $plan['items'] ) );
		self::assertSame( [ 'places/quiraing/1.jpeg', 'places/quiraing/2.JPG', 'places/quiraing/10.jpg' ], $plan['items']['places/quiraing']['files'] );
		self::assertSame( PostTypes::PLACE, $plan['items']['places/portree']['post_type'] );
		self::assertSame( 'trotternish-loop', $plan['items']['routes/trotternish-loop']['slug'] );
		self::assertSame( [ 'places/quiraing/notes.txt', 'places/loose.jpg', 'places/quiraing/raw/3.jpg', 'hotels/x/1.jpg' ], $plan['skipped'] );
	}

	public function test_manifest_accepts_commas_semicolons_quotes_and_a_bom(): void {
		$m = Photos::parse_manifest( "\xEF\xBB\xBFfile,alt,credit\r\nplaces/a/1.jpg,\"Sunrise, from the path\",Ana\r\n\r\n/places/a/2.jpg,,\n" );
		self::assertSame( [ 'alt' => 'Sunrise, from the path', 'credit' => 'Ana' ], $m['places/a/1.jpg'] );
		self::assertSame( [ 'alt' => '', 'credit' => '' ], $m['places/a/2.jpg'] );

		$m = Photos::parse_manifest( "File;Credit\nplaces/a/1.jpg;TA Scotland" );
		self::assertSame( [ 'alt' => '', 'credit' => 'TA Scotland' ], $m['places/a/1.jpg'] );

		self::assertSame( [], Photos::parse_manifest( "name,alt\nx,y" ), 'no file column' );
		self::assertSame( [], Photos::parse_manifest( '' ) );
	}

	public function test_example_manifest_and_seed_folders_are_consistent(): void {
		$root = dirname( SKYE_CORE_DIR, 3 );
		$m    = Photos::parse_manifest( (string) file_get_contents( "$root/docker/photos/photos.example.csv" ) );
		self::assertCount( 3, $m );
		$plan = Photos::plan( array_keys( $m ) );
		self::assertSame( [], $plan['skipped'] );
		foreach ( $plan['items'] as $item ) {
			$slugs = array_column( json_decode( (string) file_get_contents( "$root/seed/{$item['type']}.json" ), true )[ $item['type'] ], 'slug' );
			self::assertContains( $item['slug'], $slugs );
		}
	}

	public function test_upload_name(): void {
		self::assertSame( 'old-man-of-storr-1.jpg', Photos::upload_name( 'old-man-of-storr', 1, 'places/old-man-of-storr/IMG_4471.JPEG' ) );
		self::assertSame( 'portree-3.webp', Photos::upload_name( 'portree', 3, 'places/portree/c.webp' ) );
	}
}
