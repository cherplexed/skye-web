<?php
declare(strict_types=1);

namespace Skye;

/**
 * Pure helpers for `wp skye photos import`: which files belong to which item, in which order, and what
 * the optional manifest says about them. No WordPress here; the command does the uploading.
 *
 * Layout of the photo folder:  <type>/<slug>/<file>   e.g.  places/old-man-of-storr/01.jpg
 * The first file of each item in natural order is the cover (featured image); the rest are its gallery.
 */
final class Photos {

	/** Folder name → post type. */
	public const TYPES = [
		'places'          => PostTypes::PLACE,
		'routes'          => PostTypes::ROUTE,
		'tours'           => PostTypes::TOUR,
		'lodging'         => PostTypes::LODGING,
		'motorhome-spots' => PostTypes::MOTORHOME,
		'experiences'     => PostTypes::EXPERIENCE,
	];

	public const EXTENSIONS = [ 'jpg', 'jpeg', 'png', 'webp' ];

	public const MANIFEST = 'photos.csv';

	/**
	 * Groups relative paths by item. Anything that is not <type>/<slug>/<image> is reported, not guessed.
	 *
	 * @param list<string> $paths Relative paths with forward slashes.
	 * @return array{items: array<string, array{type:string, post_type:string, slug:string, files:list<string>}>, skipped: list<string>}
	 */
	public static function plan( array $paths ): array {
		$items   = [];
		$skipped = [];
		foreach ( $paths as $path ) {
			$path  = ltrim( str_replace( '\\', '/', $path ), '/' );
			$parts = explode( '/', $path );
			$name  = (string) end( $parts );
			if ( self::MANIFEST === $path || 'README.md' === $path || str_starts_with( $name, '.' ) ) {
				continue;
			}
			$ext = strtolower( pathinfo( $name, PATHINFO_EXTENSION ) );
			if ( 3 !== count( $parts ) || ! isset( self::TYPES[ $parts[0] ] ) || ! in_array( $ext, self::EXTENSIONS, true ) ) {
				$skipped[] = $path;
				continue;
			}
			$key = $parts[0] . '/' . $parts[1];
			$items[ $key ] ??= [ 'type' => $parts[0], 'post_type' => self::TYPES[ $parts[0] ], 'slug' => $parts[1], 'files' => [] ];
			$items[ $key ]['files'][] = $path;
		}
		foreach ( $items as &$item ) {
			natcasesort( $item['files'] );
			$item['files'] = array_values( $item['files'] );
		}
		unset( $item );
		ksort( $items );
		return [ 'items' => $items, 'skipped' => $skipped ];
	}

	/**
	 * Parses photos.csv: a header row with `file` plus any of `alt`, `credit`; one row per photo.
	 * `file` is the path relative to the photo folder. Comma or semicolon separated (spreadsheets differ).
	 *
	 * @return array<string, array{alt:string, credit:string}> keyed by file
	 */
	public static function parse_manifest( string $csv ): array {
		$csv   = preg_replace( '/^\xEF\xBB\xBF/', '', $csv ) ?? $csv;
		$lines = preg_split( '/\r\n|\r|\n/', trim( $csv ) ) ?: [];
		if ( count( $lines ) < 2 ) {
			return [];
		}
		$sep    = substr_count( $lines[0], ';' ) > substr_count( $lines[0], ',' ) ? ';' : ',';
		$header = array_map( static fn( $h ) => strtolower( trim( (string) $h ) ), str_getcsv( $lines[0], $sep, '"', '' ) );
		$col    = array_flip( $header );
		if ( ! isset( $col['file'] ) ) {
			return [];
		}
		$out = [];
		foreach ( array_slice( $lines, 1 ) as $line ) {
			if ( '' === trim( $line ) ) {
				continue;
			}
			$row  = str_getcsv( $line, $sep, '"', '' );
			$file = ltrim( str_replace( '\\', '/', trim( (string) ( $row[ $col['file'] ] ?? '' ) ) ), '/' );
			if ( '' === $file ) {
				continue;
			}
			$out[ $file ] = [
				'alt'    => isset( $col['alt'] ) ? trim( (string) ( $row[ $col['alt'] ] ?? '' ) ) : '',
				'credit' => isset( $col['credit'] ) ? trim( (string) ( $row[ $col['credit'] ] ?? '' ) ) : '',
			];
		}
		return $out;
	}

	/** Upload name: <slug>-<n>.<ext>, so files are findable and URLs readable whatever the camera called them. */
	public static function upload_name( string $slug, int $position, string $path ): string {
		$ext = strtolower( pathinfo( $path, PATHINFO_EXTENSION ) );
		return sprintf( '%s-%d.%s', $slug, $position, 'jpeg' === $ext ? 'jpg' : $ext );
	}
}
