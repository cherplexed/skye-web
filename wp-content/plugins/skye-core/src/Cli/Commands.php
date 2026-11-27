<?php
declare(strict_types=1);

namespace Skye\Cli;

use Skye\Bokun\BokunException;
use Skye\Bokun\Client;
use Skye\Bokun\Offers;
use Skye\Bokun\Pricing;
use Skye\Fields;
use Skye\Lang;
use Skye\Meta;
use Skye\Photos;
use Skye\PostTypes;
use Skye\Taxonomies;
use Skye\Uuid;
use Skye\Verification;
use WP_CLI;

/**
 * wp skye <command>
 */
final class Commands {

	/** Language being seeded: the default one, or the translation given with --lang. */
	private string $lang = '';

	/**
	 * Creates the canonical taxonomy terms.
	 *
	 * ## EXAMPLES
	 *     wp skye terms
	 */
	public function terms(): void {
		$n = Taxonomies::seed_terms();
		WP_CLI::success( "$n terms created (existing ones kept)." );
	}

	/**
	 * Loads seed data. Idempotent: matches by slug and updates.
	 *
	 * ## OPTIONS
	 * <type>
	 * : places, brands, tours, routes, spots or all (in that order: later types reference earlier ones by slug)
	 *
	 * [--file=<path>]
	 * : JSON file. Default: /seed/<type>.json (docker) or ../../../../seed/<type>.json
	 *
	 * [--verify-as=<user>]
	 * : Mark items as verified by this user login (only for real data checked by a guide).
	 *
	 * [--lang=<code>]
	 * : Load the translations in seed/<code>/ instead: each item of the default language that has a
	 * translation there gets (or updates) its version in that language. Needs WPML with that language.
	 *
	 * ## EXAMPLES
	 *     wp skye seed all
	 *     wp skye seed all --lang=es
	 *     wp skye seed places --file=/seed/places.json
	 */
	public function seed( array $args, array $assoc ): void {
		[ $type ]   = $args;
		$this->lang = strtolower( (string) ( $assoc['lang'] ?? Lang::default() ) );
		if ( $this->translating() && ( ! Lang::active() || ! in_array( $this->lang, Lang::codes(), true ) ) ) {
			WP_CLI::error( "Cannot load '{$this->lang}': WPML is not active or does not have that language. Languages: " . implode( ', ', Lang::codes() ) );
		}
		// Everything below looks posts and terms up per language explicitly; start from the default one.
		Lang::switch( Lang::default() );
		// Order matters for `all`: tours point to places and brands, routes to places and tours.
		$generic = [ 'brands' => PostTypes::BRAND, 'tours' => PostTypes::TOUR, 'routes' => PostTypes::ROUTE, 'spots' => PostTypes::MOTORHOME ];
		if ( 'all' === $type ) {
			foreach ( [ 'places', ...array_keys( $generic ) ] as $t ) {
				if ( 'brands' === $t && $this->translating() ) {
					continue; // Brands are not translated.
				}
				$this->seed( [ $t ], array_diff_key( $assoc, [ 'file' => 1 ] ) );
			}
			return;
		}
		if ( isset( $generic[ $type ] ) ) {
			$this->seed_generic( $type, $generic[ $type ], $assoc );
			return;
		}
		if ( 'places' !== $type ) {
			WP_CLI::error( "Unknown seed type '$type'. Available: places, brands, tours, routes, spots, all" );
		}
		Taxonomies::seed_terms();
		$this->seed_term_translations();
		Lang::switch( $this->lang ); // Fields and terms of each item are written with its language current.

		$json = [ 'places' => $this->rows( 'places', $assoc ) ];
		$verifier = isset( $assoc['verify-as'] ) ? get_user_by( 'login', (string) $assoc['verify-as'] ) : null;

		$created = 0;
		$updated = 0;
		foreach ( $json['places'] as $row ) {
			[ $id, $existing ] = $this->upsert( PostTypes::PLACE, $row );
			if ( ! $id ) {
				continue;
			}
			$existing ? ++$updated : ++$created;
			$this->apply_place( (int) $id, $row );
			if ( $verifier ) {
				Verification::mark_verified( (int) $id, (int) $verifier->ID );
			}
			WP_CLI::log( sprintf( '  %s %s (#%d)', $existing ? '~' : '+', $row['title'], $id ) );
		}
		WP_CLI::success( "Places ({$this->lang}): $created created, $updated updated." . ( $verifier ? '' : ' All left UNVERIFIED: a guide must check them.' ) );
	}

	/**
	 * Schema-driven seed for types without special handling. Rows use field names from Fields; relations
	 * are written as slugs (a string, or a list) and resolved to IDs here.
	 */
	private function seed_generic( string $name, string $post_type, array $assoc ): void {
		Taxonomies::seed_terms();
		$this->seed_term_translations();
		Lang::switch( $this->lang );
		$json     = [ $name => $this->rows( $name, $assoc ) ];
		$verifier = isset( $assoc['verify-as'] ) ? get_user_by( 'login', (string) $assoc['verify-as'] ) : null;
		$schema   = Fields::for( $post_type );
		$created  = 0;
		$updated  = 0;

		foreach ( $json[ $name ] as $row ) {
			[ $id, $existing ] = $this->upsert( $post_type, $row );
			if ( ! $id ) {
				continue;
			}
			$existing ? ++$updated : ++$created;

			foreach ( $schema as $field => $def ) {
				if ( array_key_exists( $field, $row ) && null !== $row[ $field ] ) {
					Meta::set( $id, $field, $this->seed_value( $def, $row[ $field ], "{$row['slug']}.$field" ) );
				}
			}
			if ( isset( $row['zone'] ) ) {
				wp_set_object_terms( $id, $this->term_ids( Taxonomies::ZONE, [ $row['zone'] ] ), Taxonomies::ZONE );
			}
			if ( isset( $row['profiles'] ) ) {
				wp_set_object_terms( $id, $this->term_ids( Taxonomies::PROFILE, $row['profiles'] ), Taxonomies::PROFILE );
			}
			if ( $verifier ) {
				Verification::mark_verified( $id, (int) $verifier->ID );
			}
			WP_CLI::log( sprintf( '  %s %s (#%d)', $existing ? '~' : '+', $row['title'], $id ) );
		}
		WP_CLI::success( ucfirst( $name ) . " ({$this->lang}): $created created, $updated updated." . ( $verifier ? '' : ' All left UNVERIFIED.' ) );
	}

	private function translating(): bool {
		return '' !== $this->lang && Lang::default() !== $this->lang;
	}

	private function seed_dir(): string {
		return is_dir( '/seed' ) ? '/seed' : dirname( SKYE_CORE_DIR, 3 ) . '/seed';
	}

	private function read( string $file ): array {
		if ( ! is_file( $file ) ) {
			WP_CLI::error( "Seed file not found: $file" );
		}
		return json_decode( (string) file_get_contents( $file ), true, 512, JSON_THROW_ON_ERROR );
	}

	/**
	 * The rows to load. In the default language, the seed file as it is. For a translation, each row
	 * that seed/<lang>/<type>.json translates (keyed by slug), laid over its original: the translation
	 * file only holds the texts, and everything else (coordinates, prices, stops) is written once.
	 *
	 * @return list<array<string, mixed>>
	 */
	private function rows( string $type, array $assoc ): array {
		$rows = $this->read( $assoc['file'] ?? $this->seed_dir() . "/$type.json" )[ $type ];
		if ( ! $this->translating() ) {
			return $rows;
		}
		$file = $this->seed_dir() . "/{$this->lang}/$type.json";
		if ( ! is_file( $file ) ) {
			// A language can be loaded in stages: what has no translation yet keeps showing the original.
			WP_CLI::log( ucfirst( $type ) . " ({$this->lang}): no translation file, nothing to load." );
			return [];
		}
		$translations = $this->read( $file )[ $type ];
		$out          = [];
		foreach ( $rows as $row ) {
			if ( isset( $translations[ $row['slug'] ] ) ) {
				$out[] = Lang::overlay( $row, $translations[ $row['slug'] ] );
			}
		}
		foreach ( array_diff( array_keys( $translations ), array_column( $rows, 'slug' ) ) as $orphan ) {
			WP_CLI::warning( "{$this->lang}/$type.json: '$orphan' translates nothing (no such slug in $type.json)" );
		}
		return $out;
	}

	/**
	 * Creates or updates the post of a row in the language being seeded.
	 *
	 * @return array{0:int, 1:bool} Post ID (0 on failure) and whether it already existed.
	 */
	private function upsert( string $post_type, array $row ): array {
		$existing = Lang::find_post( $row['slug'], $post_type, $this->lang );
		$original = $this->translating() ? Lang::find_post( $row['slug'], $post_type, Lang::default() ) : null;
		if ( $this->translating() && ! $original ) {
			WP_CLI::warning( "{$row['slug']}: no original to translate (seed the default language first)" );
			return [ 0, false ];
		}
		$postarr = [
			'post_type'    => $post_type,
			'post_status'  => 'publish',
			'post_name'    => $row['slug'],
			'post_title'   => $row['title'],
			'post_content' => $row['content'] ?? '',
			'post_excerpt' => $row['summary'] ?? '',
		];
		if ( $existing ) {
			$postarr['ID'] = $existing->ID;
		}
		// Saved with the target language current, so WPML files the post under it from the start.
		$id = Lang::with( $this->lang, static fn() => $existing ? wp_update_post( $postarr, true ) : wp_insert_post( $postarr, true ) );
		if ( is_wp_error( $id ) ) {
			WP_CLI::warning( "{$row['slug']}: " . $id->get_error_message() );
			return [ 0, false ];
		}
		$id = (int) $id;
		if ( $original ) {
			Lang::connect_post( $id, $post_type, $original->ID, $this->lang );
			// A translation keeps the slug of its original. WordPress may have added "-2" before it knew the language.
			if ( get_post_field( 'post_name', $id ) !== $row['slug'] ) {
				global $wpdb;
				$wpdb->update( $wpdb->posts, [ 'post_name' => $row['slug'] ], [ 'ID' => $id ] );
				clean_post_cache( $id );
			}
			// One identity for an item, whatever the language.
			update_post_meta( $id, Uuid::META, Uuid::get( $original->ID ) );
		} else {
			// Items created before WPML handled this type have no language on record: file them as originals.
			Lang::claim_post( $id, $post_type );
			Uuid::ensure( $id );
		}
		return [ $id, (bool) $existing ];
	}

	/**
	 * Term IDs, in the language being seeded, for canonical slugs.
	 *
	 * @param list<string> $slugs
	 * @return list<int>
	 */
	private function term_ids( string $taxonomy, array $slugs ): array {
		$ids = [];
		foreach ( $slugs as $slug ) {
			$id = Lang::term_id( $taxonomy, (string) $slug, $this->lang );
			if ( $id ) {
				$ids[] = $id;
			} else {
				WP_CLI::warning( "No term '$slug' in $taxonomy" );
			}
		}
		return $ids;
	}

	/**
	 * Creates or renames the translated terms from seed/<lang>/terms.json: { taxonomy: { slug: name } }
	 * plus "zone_intros": { slug: text }. Does nothing in the default language.
	 */
	private function seed_term_translations(): void {
		static $done = [];
		if ( ! $this->translating() || isset( $done[ $this->lang ] ) ) {
			return;
		}
		$done[ $this->lang ] = true;
		$data                = $this->read( $this->seed_dir() . "/{$this->lang}/terms.json" );
		$lang                = $this->lang;
		$created             = 0;
		$updated             = 0;
		foreach ( Taxonomies::terms() as $taxonomy => $canonical ) {
			foreach ( $data[ $taxonomy ] ?? [] as $slug => $name ) {
				$original = Lang::term_id( $taxonomy, (string) $slug, Lang::default() );
				if ( ! $original ) {
					WP_CLI::warning( "$lang/terms.json: no term '$slug' in $taxonomy" );
					continue;
				}
				$args = Taxonomies::ZONE === $taxonomy && isset( $data['zone_intros'][ $slug ] ) ? [ 'description' => (string) $data['zone_intros'][ $slug ] ] : [];
				$tr   = Lang::term_translation( $taxonomy, (string) $slug, $lang );
				if ( $tr ) {
					$r = Lang::with( $lang, static fn() => wp_update_term( $tr, $taxonomy, [ 'name' => $name ] + $args ) );
					is_wp_error( $r ) ? WP_CLI::warning( "$taxonomy/$slug: " . $r->get_error_message() ) : ++$updated;
					continue;
				}
				// A slug must be unique in its taxonomy: when the translated name gives one that is already a
				// canonical slug ("Portree", "Hotel"), the language code is appended. Nothing depends on it.
				$want = sanitize_title( $name );
				if ( '' === $want || isset( $canonical[ $want ] ) ) {
					$want = $slug . '-' . $lang;
				}
				$r = Lang::with( $lang, static fn() => wp_insert_term( $name, $taxonomy, [ 'slug' => $want ] + $args ) );
				if ( is_wp_error( $r ) ) {
					WP_CLI::warning( "$taxonomy/$slug: " . $r->get_error_message() );
					continue;
				}
				Lang::connect_term( (int) $r['term_id'], $taxonomy, $original, $lang );
				++$created;
			}
		}
		WP_CLI::log( "Terms ($lang): $created created, $updated updated." );
	}

	/** Seed value → stored value: slugs become IDs, booleans become 0/1, repeaters recurse. */
	private function seed_value( array $def, mixed $value, string $path ): mixed {
		switch ( $def['type'] ) {
			case 'relation':
				$ids = [];
				foreach ( (array) $value as $slug ) {
					// The version in the language being loaded; an item not translated (a brand, say) is the same for all.
					$p = Lang::find_post( (string) $slug, $def['to'], $this->lang ) ?? Lang::find_post( (string) $slug, $def['to'], Lang::default() );
					if ( $p ) {
						$ids[] = $p->ID;
					} else {
						WP_CLI::warning( "$path: no {$def['to']} with slug '$slug' (seed that type first)" );
					}
				}
				return $ids;
			case 'repeater':
				$rows = [];
				foreach ( (array) $value as $i => $row ) {
					$out = [];
					foreach ( $def['sub'] as $sub => $sub_def ) {
						if ( array_key_exists( $sub, $row ) && null !== $row[ $sub ] ) {
							$out[ $sub ] = $this->seed_value( $sub_def, $row[ $sub ], "{$path}[{$i}].{$sub}" );
						}
					}
					$rows[] = $out;
				}
				return $rows;
			case 'bool':
				return $value ? 1 : 0;
			case 'latlng':
				return [ 'lat' => (float) $value['lat'], 'lng' => (float) $value['lng'] ];
			default:
				return $value;
		}
	}

	private function apply_place( int $id, array $r ): void {
		wp_set_object_terms( $id, $this->term_ids( Taxonomies::ZONE, [ $r['zone'] ] ), Taxonomies::ZONE );
		wp_set_object_terms( $id, $this->term_ids( Taxonomies::CATEGORY, $r['categories'] ?? [] ), Taxonomies::CATEGORY );
		wp_set_object_terms( $id, $this->term_ids( Taxonomies::PROFILE, $r['profiles'] ?? [] ), Taxonomies::PROFILE );
		wp_set_object_terms( $id, $this->term_ids( Taxonomies::SUITABILITY, array_map( static fn( $s ) => str_replace( '_', '-', $s ), $r['suitable_for'] ?? [] ) ), Taxonomies::SUITABILITY );

		$scalar = [ 'gaelic_name', 'summary', 'directions', 'duration_min', 'duration_typical', 'difficulty', 'distance_km', 'elevation_gain_m', 'price_type', 'price_gbp', 'best_time', 'motorhome_parking', 'overnight_allowed' ];
		foreach ( $scalar as $k ) {
			if ( array_key_exists( $k, $r ) && null !== $r[ $k ] ) {
				Meta::set( $id, $k, is_bool( $r[ $k ] ) ? ( $r[ $k ] ? 1 : 0 ) : $r[ $k ] );
			}
		}
		foreach ( [ 'location', 'parking_location' ] as $k ) {
			if ( ! empty( $r[ $k ] ) ) {
				Meta::set( $id, $k, [ 'lat' => (float) $r[ $k ]['lat'], 'lng' => (float) $r[ $k ]['lng'] ] );
			}
		}
		Meta::set( $id, 'suitable_for', $r['suitable_for'] ?? [] );
		Meta::set( $id, 'services_nearby', $r['services_nearby'] ?? [] );
		Meta::set( $id, 'opening_hours', $r['opening_hours'] ?? [] );
		Meta::set( $id, 'tips', array_map( static fn( $t ) => [ 'tip' => $t ], $r['tips'] ?? [] ) );
		Meta::set( $id, 'faq', $r['faq'] ?? [] );
		Meta::set( $id, 'sources', $r['sources'] ?? [] );

		$ag = $r['audioguide'] ?? [];
		Meta::set( $id, 'audioguide_enabled', ! empty( $ag['enabled'] ) ? 1 : 0 );
		if ( ! empty( $ag['enabled'] ) ) {
			Meta::set( $id, 'audioguide_stop_number', (int) ( $ag['stop_number'] ?? 0 ) );
			Meta::set( $id, 'audioguide_radius_m', (int) ( $ag['radius_m'] ?? 150 ) );
			Meta::set( $id, 'audioguide_tracks', $ag['tracks'] ?? [] );
		}
	}

	/**
	 * Prints verification status of all content.
	 *
	 * ## EXAMPLES
	 *     wp skye audit
	 */
	public function audit(): void {
		$rows = [];
		foreach ( PostTypes::all() as $type ) {
			foreach ( get_posts( [ 'post_type' => $type, 'post_status' => 'publish', 'posts_per_page' => -1 ] ) as $p ) {
				$v      = Verification::get( $p->ID );
				$rows[] = [ 'type' => $type, 'title' => $p->post_title, 'status' => $v['status'], 'verified_by' => $v['verified_by']['name'] ?? '', 'valid_until' => $v['valid_until'] ?? '' ];
			}
		}
		WP_CLI\Utils\format_items( 'table', $rows, [ 'type', 'title', 'status', 'verified_by', 'valid_until' ] );
	}

	/**
	 * Uploads the photos in docker/photos/ and attaches them to their items. Safe to repeat.
	 *
	 * Layout: <type>/<slug>/<file>, e.g. places/old-man-of-storr/01.jpg. The first file of each item in
	 * natural order becomes the featured image; the rest become the gallery (Places and Tours). An optional
	 * photos.csv (file, alt, credit) gives each photo its alt text and credit.
	 *
	 * A photo already uploaded is reused; one whose file changed is replaced. Photos removed from the
	 * folder are detached from the item but stay in the media library.
	 *
	 * ## OPTIONS
	 * <action>
	 * : import
	 *
	 * [--dir=<path>]
	 * : Photo folder. Default: /photos (docker/photos on the host).
	 *
	 * [--dry-run]
	 * : Show what would happen without uploading anything.
	 *
	 * ## EXAMPLES
	 *     wp skye photos import --dry-run
	 *     wp skye photos import
	 */
	public function photos( array $args, array $assoc ): void {
		if ( 'import' !== ( $args[0] ?? '' ) ) {
			WP_CLI::error( 'Usage: wp skye photos import [--dry-run]' );
		}
		$dir = rtrim( (string) ( $assoc['dir'] ?? ( is_dir( '/photos' ) ? '/photos' : dirname( SKYE_CORE_DIR, 3 ) . '/docker/photos' ) ), '/' );
		if ( ! is_dir( $dir ) ) {
			WP_CLI::error( "Photo folder not found: $dir" );
		}
		$dry = isset( $assoc['dry-run'] );

		$paths = [];
		$it    = new \RecursiveIteratorIterator( new \RecursiveDirectoryIterator( $dir, \FilesystemIterator::SKIP_DOTS ) );
		foreach ( $it as $file ) {
			if ( $file->isFile() ) {
				$paths[] = substr( $file->getPathname(), strlen( $dir ) + 1 );
			}
		}
		$plan     = Photos::plan( $paths );
		$manifest = is_file( "$dir/" . Photos::MANIFEST ) ? Photos::parse_manifest( (string) file_get_contents( "$dir/" . Photos::MANIFEST ) ) : [];
		foreach ( $plan['skipped'] as $path ) {
			WP_CLI::warning( "Ignored (expected <type>/<slug>/<image>): $path" );
		}
		if ( $manifest ) {
			WP_CLI::log( sprintf( '%s: %d row(s), %d matching a photo in the folder.', Photos::MANIFEST, count( $manifest ), count( array_intersect( array_keys( $manifest ), $paths ) ) ) );
		} else {
			$others = glob( "$dir/*.csv" ) ?: [];
			WP_CLI::log( is_file( "$dir/" . Photos::MANIFEST ) ? Photos::MANIFEST . ': found, but it has no usable rows (it needs a header row with a "file" column).' : 'No ' . Photos::MANIFEST . ' in the folder' . ( $others ? ' (found ' . implode( ', ', array_map( 'basename', $others ) ) . ': rename it to ' . Photos::MANIFEST . ')' : '' ) . '.' );
		}
		foreach ( array_diff( array_keys( $manifest ), $paths ) as $path ) {
			WP_CLI::warning( Photos::MANIFEST . " lists a file that is not in the folder: $path" );
		}
		if ( ! $plan['items'] ) {
			WP_CLI::success( 'No photos found.' );
			return;
		}

		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/media.php';
		require_once ABSPATH . 'wp-admin/includes/image.php';

		$count = [ 'uploaded' => 0, 'replaced' => 0, 'unchanged' => 0, 'failed' => 0 ];
		foreach ( $plan['items'] as $key => $item ) {
			// Photos belong to the default-language item; its translations show them (Lang::thumbnail_id).
			$post = Lang::find_post( $item['slug'], $item['post_type'], Lang::default() );
			if ( ! $post ) {
				WP_CLI::warning( "$key: no {$item['post_type']} with slug '{$item['slug']}', " . count( $item['files'] ) . ' photo(s) skipped' );
				continue;
			}
			$has_gallery = isset( Fields::for( $item['post_type'] )['gallery'] );
			$ids         = [];
			foreach ( $item['files'] as $n => $path ) {
				$info   = $manifest[ $path ] ?? [ 'alt' => '', 'credit' => '' ];
				$hash   = (string) md5_file( "$dir/$path" );
				$found  = get_posts( [ 'post_type' => 'attachment', 'post_status' => 'inherit', 'posts_per_page' => 1, 'fields' => 'ids', 'meta_key' => '_skye_photo_source', 'meta_value' => $path ] );
				$old    = $found ? (int) $found[0] : 0;
				$same   = $old && get_post_meta( $old, '_skye_photo_hash', true ) === $hash;
				$status = $same ? 'unchanged' : ( $old ? 'replaced' : 'uploaded' );
				$role   = 0 === $n ? 'cover' : ( $has_gallery ? 'gallery' : 'extra, not shown: this type has no gallery' );

				if ( $dry ) {
					WP_CLI::log( sprintf( '  %-9s %s → %s (%s)', $status, $path, $post->post_title, $role ) );
					++$count[ $status ];
					continue;
				}

				$id = $old;
				if ( ! $same ) {
					// media_handle_sideload moves the file, and the photo folder is mounted read-only: work on a copy.
					$tmp = wp_tempnam( basename( $path ) );
					if ( ! $tmp || ! copy( "$dir/$path", $tmp ) ) {
						WP_CLI::warning( "$path: could not be read" );
						++$count['failed'];
						continue;
					}
					$id = media_handle_sideload( [ 'name' => Photos::upload_name( $item['slug'], $n + 1, $path ), 'tmp_name' => $tmp ], $post->ID, $post->post_title );
					if ( is_wp_error( $id ) ) {
						if ( is_file( $tmp ) ) {
							unlink( $tmp ); // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink
						}
						WP_CLI::warning( "$path: " . $id->get_error_message() );
						++$count['failed'];
						continue;
					}
					$id = (int) $id;
					update_post_meta( $id, '_skye_photo_source', $path );
					update_post_meta( $id, '_skye_photo_hash', $hash );
					// WordPress fills the caption from the description embedded in the file (IPTC), if any.
					update_post_meta( $id, '_skye_embedded_caption', trim( (string) get_post_field( 'post_excerpt', $id ) ) );
					if ( $old ) {
						wp_delete_attachment( $old, true );
					}
				}
				// Alt and credit are refreshed on every run, so editing photos.csv is enough to change them.
				// Alt: photos.csv, else the description embedded in the file, else the item's title.
				$embedded = get_post_meta( $id, '_skye_embedded_caption', true );
				if ( ! metadata_exists( 'post', $id, '_skye_embedded_caption' ) ) {
					// Uploaded before this meta existed: the caption is still the embedded one unless we set a credit.
					$caption  = trim( (string) get_post_field( 'post_excerpt', $id ) );
					$embedded = str_starts_with( $caption, 'Photo: ' ) ? '' : $caption;
					update_post_meta( $id, '_skye_embedded_caption', $embedded );
				}
				$alt = '' !== $info['alt'] ? $info['alt'] : ( '' !== (string) $embedded ? (string) $embedded : $post->post_title );
				update_post_meta( $id, '_wp_attachment_image_alt', $alt );
				update_post_meta( $id, '_skye_credit', $info['credit'] );
				// The caption shows the credit when there is one; otherwise the embedded description stays.
				wp_update_post( [ 'ID' => $id, 'post_excerpt' => '' !== $info['credit'] ? 'Photo: ' . $info['credit'] : (string) $embedded ] );

				$ids[] = $id;
				++$count[ $status ];
				WP_CLI::log( sprintf( '  %-9s %s → %s (%s, #%d)', $status, $path, $post->post_title, $role, $id ) );
			}
			if ( $dry || ! $ids ) {
				continue;
			}
			set_post_thumbnail( $post->ID, $ids[0] );
			if ( $has_gallery ) {
				Meta::set( $post->ID, 'gallery', array_slice( $ids, 1 ) );
			}
		}
		WP_CLI::success( sprintf( '%s%d uploaded, %d replaced, %d unchanged, %d failed.', $dry ? 'Dry run: ' : '', $count['uploaded'], $count['replaced'], $count['unchanged'], $count['failed'] ) );
	}

	/**
	 * Imports the Bricks templates versioned in skye-core/templates/bricks/. Idempotent: matches by slug.
	 *
	 * Templates are code: edit them in Bricks if you like, but the JSON in the repo is what gets deployed,
	 * and a new import overwrites the template's elements and conditions.
	 *
	 * ## OPTIONS
	 * <action>
	 * : import
	 *
	 * ## EXAMPLES
	 *     wp skye templates import
	 */
	public function templates( array $args ): void {
		if ( 'import' !== ( $args[0] ?? '' ) ) {
			WP_CLI::error( 'Usage: wp skye templates import' );
		}
		if ( ! defined( 'BRICKS_VERSION' ) ) {
			WP_CLI::error( 'Bricks is not active: install it first (docker/licensed/README.md).' );
		}
		// Bricks only lets a user with builder access write its element data, and WP-CLI runs as nobody
		// unless --user is given: act as an administrator, or the content is silently discarded.
		if ( ! get_current_user_id() ) {
			$admins = get_users( [ 'role' => 'administrator', 'number' => 1, 'orderby' => 'ID', 'fields' => 'ID' ] );
			if ( ! $admins ) {
				WP_CLI::error( 'No administrator user found to import the templates as.' );
			}
			wp_set_current_user( (int) $admins[0] );
		}

		// Bricks' own names for its storage, with the documented values as fallback.
		$const     = static fn( string $name, string $fallback ): string => defined( $name ) ? (string) constant( $name ) : $fallback;
		$post_type = $const( 'BRICKS_DB_TEMPLATE_SLUG', 'bricks_template' );
		$areas     = [
			'header' => $const( 'BRICKS_DB_PAGE_HEADER', '_bricks_page_header_2' ),
			'footer' => $const( 'BRICKS_DB_PAGE_FOOTER', '_bricks_page_footer_2' ),
		];
		$content   = $const( 'BRICKS_DB_PAGE_CONTENT', '_bricks_page_content_2' );

		foreach ( glob( SKYE_CORE_DIR . '/templates/bricks/*.json' ) ?: [] as $file ) {
			$t        = json_decode( (string) file_get_contents( $file ), true, 512, JSON_THROW_ON_ERROR );
			$existing = get_page_by_path( $t['slug'], OBJECT, $post_type );
			$postarr  = [ 'post_type' => $post_type, 'post_status' => 'publish', 'post_name' => $t['slug'], 'post_title' => $t['title'] ];
			if ( $existing ) {
				$postarr['ID'] = $existing->ID;
			}
			$id = $existing ? wp_update_post( $postarr, true ) : wp_insert_post( $postarr, true );
			if ( is_wp_error( $id ) ) {
				WP_CLI::warning( basename( $file ) . ': ' . $id->get_error_message() );
				continue;
			}
			$id         = (int) $id;
			$conditions = [];
			foreach ( $t['conditions'] ?? [] as $i => $c ) {
				$conditions[] = [ 'id' => substr( md5( $t['slug'] . $i ), 0, 6 ) ] + $c;
			}
			update_post_meta( $id, $const( 'BRICKS_DB_TEMPLATE_TYPE', '_bricks_template_type' ), $t['type'] );
			$content_key = $areas[ $t['type'] ] ?? $content;
			update_post_meta( $id, $content_key, $t['elements'] );
			update_post_meta( $id, $const( 'BRICKS_DB_TEMPLATE_SETTINGS', '_bricks_template_settings' ), [ 'templateConditions' => $conditions ] );
			update_post_meta( $id, $const( 'BRICKS_DB_EDITOR_MODE', '_bricks_editor_mode' ), 'bricks' );
			$stored = get_post_meta( $id, $content_key, true );
			if ( ! is_array( $stored ) || count( $stored ) !== count( $t['elements'] ) ) {
				WP_CLI::warning( sprintf( '%s: Bricks did not keep the elements (%d of %d stored).', $t['title'], is_array( $stored ) ? count( $stored ) : 0, count( $t['elements'] ) ) );
				continue;
			}
			WP_CLI::log( sprintf( '  %s %s (#%d, %d elements)', $existing ? '~' : '+', $t['title'], $id, count( $t['elements'] ) ) );
		}
		WP_CLI::success( 'Templates imported.' );
	}

	/**
	 * Asks Bókun for the "from" price and next bookable date of a product. Checks credentials end to end.
	 *
	 * ## OPTIONS
	 * <product_id>
	 * : Bókun product (activity) ID.
	 *
	 * [--currency=<code>]
	 * : Default GBP.
	 *
	 * [--category=<id>]
	 * : Adult pricing category ID. Without it, the highest per-person price of the default rate is used.
	 *
	 * ## EXAMPLES
	 *     wp skye bokun 123456 --currency=EUR
	 */
	public function bokun( array $args, array $assoc ): void {
		$client = Client::from_config();
		if ( ! $client ) {
			WP_CLI::error( 'Bókun is not configured: set BOKUN_ACCESS_KEY and BOKUN_SECRET_KEY in .env and restart the stack.' );
		}
		$currency = strtoupper( (string) ( $assoc['currency'] ?? 'GBP' ) );
		$category = isset( $assoc['category'] ) ? (int) $assoc['category'] : null;
		try {
			$start = gmdate( 'Y-m-d' );
			$end   = gmdate( 'Y-m-d', time() + ( Offers::WINDOW_DAYS - 1 ) * DAY_IN_SECONDS );
			$list  = $client->availabilities( (string) $args[0], $start, $end, $currency );
		} catch ( BokunException $e ) {
			WP_CLI::error( $e->getMessage() );
		}
		WP_CLI::log( sprintf( '%d departures returned for %s → %s', count( $list ), $start, $end ) );
		$sum = Pricing::summarize( $list, $category );
		if ( ! $sum ) {
			WP_CLI::warning( 'Nothing bookable in this window.' );
			return;
		}
		WP_CLI::success( sprintf( 'From %s %s · next departure %s · %d bookable departures', $sum['price_from'], $sum['currency'], $sum['next_available'], $sum['departures'] ) );
	}
}
