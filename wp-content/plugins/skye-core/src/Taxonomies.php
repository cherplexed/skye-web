<?php
declare(strict_types=1);

namespace Skye;

final class Taxonomies {

	public const ZONE        = 'skye_zone';
	public const CATEGORY    = 'skye_category';
	public const PROFILE     = 'skye_profile';
	public const SUITABILITY = 'skye_suitability';

	/**
	 * Canonical terms (CONTRACTS.md §1, §8). Slugs are stable identifiers used by the API and the bot;
	 * labels are translated by WPML.
	 *
	 * @return array<string, array<string, string>> taxonomy => [ slug => label ]
	 */
	public static function terms(): array {
		return [
			self::ZONE        => [
				'trotternish'         => 'Trotternish',
				'portree'             => 'Portree',
				'duirinish-waternish' => 'Duirinish & Waternish',
				'minginish-cuillin'   => 'Minginish & Cuillin',
				'sleat'               => 'Sleat',
				'broadford'           => 'Broadford',
				'raasay'              => 'Raasay',
				'en-route'            => 'En route (off the island)',
			],
			self::CATEGORY    => [
				'viewpoint'  => 'Viewpoint',
				'hike'       => 'Hike',
				'castle'     => 'Castle',
				'distillery' => 'Distillery',
				'beach'      => 'Beach',
				'waterfall'  => 'Waterfall',
				'village'    => 'Village',
				'wildlife'   => 'Wildlife',
				'museum'     => 'Museum',
				'restaurant' => 'Restaurant',
				'cafe'       => 'Café',
				'shop'       => 'Shop',
				'fuel'       => 'Fuel station',
			],
			self::PROFILE     => [
				'instagram' => 'Instagram / TikTok',
				'nature'    => 'Nature',
				'hiking'    => 'Hiking',
				'history'   => 'History',
				'whisky'    => 'Whisky',
				'wildlife'  => 'Wildlife',
				'families'  => 'Families',
				'rainy-day' => 'Rainy day',
			],
			self::SUITABILITY => [
				'children'         => 'Children',
				'stroller'         => 'Stroller',
				'dogs'             => 'Dogs',
				'reduced-mobility' => 'Reduced mobility',
			],
		];
	}

	public static function register(): void {
		$defs = [
			self::ZONE        => [ 'Zone', 'Zones', 'zone', false ],
			self::CATEGORY    => [ 'Category', 'Categories', 'category', false ],
			self::PROFILE     => [ 'Profile', 'Profiles', 'profile', false ],
			self::SUITABILITY => [ 'Suitability', 'Suitability', 'suitable-for', false ],
		];
		$objects = [
			self::ZONE        => [ PostTypes::PLACE, PostTypes::LODGING, PostTypes::MOTORHOME, PostTypes::EXPERIENCE ],
			self::CATEGORY    => [ PostTypes::PLACE ],
			self::PROFILE     => [ PostTypes::PLACE, PostTypes::ROUTE ],
			self::SUITABILITY => [ PostTypes::PLACE, PostTypes::LODGING, PostTypes::EXPERIENCE ],
		];

		foreach ( $defs as $tax => [ $singular, $plural, $slug, $hierarchical ] ) {
			register_taxonomy(
				$tax,
				$objects[ $tax ],
				[
					'labels'            => [
						'name'          => __( $plural, 'skye-core' ),
						'singular_name' => __( $singular, 'skye-core' ),
					],
					'public'            => true,
					'show_in_rest'      => true,
					'show_admin_column' => true,
					'hierarchical'      => $hierarchical,
					'rewrite'           => [ 'slug' => $slug, 'with_front' => false ],
				]
			);
		}
	}

	/** Creates the canonical terms if missing. Safe to run repeatedly. */
	public static function seed_terms(): int {
		$created = 0;
		foreach ( self::terms() as $tax => $terms ) {
			foreach ( $terms as $slug => $label ) {
				if ( ! term_exists( $slug, $tax ) ) {
					$r = wp_insert_term( $label, $tax, [ 'slug' => $slug ] );
					if ( ! is_wp_error( $r ) ) {
						++$created;
					}
				}
			}
		}
		return $created;
	}
}
