<?php
declare(strict_types=1);

namespace Skye;

/**
 * Stable identifier for every content item (meta `skye_uuid`), so the bot and the app
 * never depend on WordPress post IDs, which change between environments.
 */
final class Uuid {

	public const META = 'skye_uuid';

	public static function register(): void {
		add_action( 'save_post', [ self::class, 'ensure' ], 5, 1 );
	}

	public static function ensure( int $post_id ): string {
		$post = get_post( $post_id );
		if ( ! $post || ! in_array( $post->post_type, PostTypes::all(), true ) ) {
			return '';
		}
		$uuid = (string) get_post_meta( $post_id, self::META, true );
		if ( '' === $uuid ) {
			$uuid = wp_generate_uuid4();
			update_post_meta( $post_id, self::META, $uuid );
		}
		return $uuid;
	}

	public static function get( int $post_id ): string {
		return (string) get_post_meta( $post_id, self::META, true ) ?: self::ensure( $post_id );
	}

	public static function find( string $uuid ): ?int {
		$ids = get_posts(
			[
				'post_type'      => PostTypes::all(),
				'post_status'    => 'any',
				'meta_key'       => self::META,
				'meta_value'     => $uuid,
				'fields'         => 'ids',
				'posts_per_page' => 1,
				'suppress_filters' => false, // the translation in the current language, when there is one
			]
		);
		return $ids ? (int) $ids[0] : null;
	}
}
