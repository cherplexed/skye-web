<?php
declare(strict_types=1);

namespace Skye;

/**
 * The seven content types of CONTRACTS.md. Slugs are public URLs and must stay stable.
 */
final class PostTypes {

	public const PLACE      = 'place';
	public const ROUTE      = 'route';
	public const TOUR       = 'tour';
	public const BRAND      = 'brand';
	public const LODGING    = 'lodging';
	public const MOTORHOME  = 'motorhome_spot';
	public const EXPERIENCE = 'experience';

	/** @return list<string> */
	public static function all(): array {
		return [ self::PLACE, self::ROUTE, self::TOUR, self::BRAND, self::LODGING, self::MOTORHOME, self::EXPERIENCE ];
	}

	/** @return list<string> Types that carry a geographic location. */
	public static function located(): array {
		return [ self::PLACE, self::LODGING, self::MOTORHOME, self::EXPERIENCE ];
	}

	public static function register(): void {
		$types = [
			self::PLACE      => [ 'Place', 'Places', 'places', 'location', 20, [ 'skye_zone', 'skye_category', 'skye_profile', 'skye_suitability' ] ],
			self::ROUTE      => [ 'Route', 'Routes', 'routes', 'randomize', 21, [ 'skye_profile' ] ],
			self::TOUR       => [ 'Tour', 'Tours', 'tours', 'tickets-alt', 22, [] ],
			self::BRAND      => [ 'Brand', 'Brands', 'brands', 'store', 23, [] ],
			self::LODGING    => [ 'Lodging', 'Lodging', 'stay', 'building', 24, [ 'skye_zone', 'skye_suitability' ] ],
			self::MOTORHOME  => [ 'Motorhome spot', 'Motorhome spots', 'motorhome', 'car', 25, [ 'skye_zone' ] ],
			self::EXPERIENCE => [ 'Experience', 'Experiences', 'experiences', 'star-filled', 26, [ 'skye_zone', 'skye_suitability' ] ],
		];

		foreach ( $types as $type => [ $singular, $plural, $slug, $icon, $position, $taxonomies ] ) {
			register_post_type(
				$type,
				[
					'labels'        => [
						'name'          => __( $plural, 'skye-core' ),
						'singular_name' => __( $singular, 'skye-core' ),
						'add_new_item'  => sprintf( __( 'Add %s', 'skye-core' ), $singular ),
						'edit_item'     => sprintf( __( 'Edit %s', 'skye-core' ), $singular ),
					],
					'public'        => self::BRAND !== $type,
					'show_ui'       => true,
					'show_in_rest'  => true,
					'has_archive'   => self::BRAND !== $type,
					'rewrite'       => [ 'slug' => $slug, 'with_front' => false ],
					'menu_icon'     => 'dashicons-' . $icon,
					'menu_position' => $position,
					'supports'      => [ 'title', 'editor', 'excerpt', 'thumbnail', 'revisions', 'custom-fields' ],
					'taxonomies'    => $taxonomies,
				]
			);
		}
	}
}
