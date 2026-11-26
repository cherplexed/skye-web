<?php
declare(strict_types=1);

namespace Skye;

/**
 * Field schema (CONTRACTS.md). ACF reads the JSON in acf-json/; this class is the single place
 * the rest of the plugin asks "which fields exist, of what type, translatable or not".
 *
 * Field types here are our own vocabulary, not ACF's: text, textarea, html, number, bool, enum,
 * checkboxes, latlng, image, gallery, repeater, relation, file, url.
 */
final class Fields {

	public static function register(): void {
		// ACF: load our JSON field groups from the plugin, save edits back there (dev only).
		add_filter( 'acf/settings/load_json', static fn( array $paths ) => [ ...$paths, SKYE_CORE_DIR . '/acf-json' ] );
		add_filter(
			'acf/settings/save_json',
			static fn( string $path ) => ( defined( 'WP_ENVIRONMENT_TYPE' ) && 'local' === WP_ENVIRONMENT_TYPE ) ? SKYE_CORE_DIR . '/acf-json' : $path
		);
	}

	/**
	 * @return array<string, array{type:string, i18n:bool, choices?:array<string,string>, sub?:array<string,array{type:string,i18n:bool}>, to?:string, default?:mixed}>
	 */
	public static function place(): array {
		return [
			'gaelic_name'           => [ 'type' => 'text', 'i18n' => false ],
			'summary'               => [ 'type' => 'textarea', 'i18n' => true ],
			'location'              => [ 'type' => 'latlng', 'i18n' => false ],
			'parking_location'      => [ 'type' => 'latlng', 'i18n' => false ],
			'directions'            => [ 'type' => 'textarea', 'i18n' => true ],
			'duration_min'          => [ 'type' => 'number', 'i18n' => false ],
			'duration_typical'      => [ 'type' => 'number', 'i18n' => false ],
			'difficulty'            => [ 'type' => 'enum', 'i18n' => false, 'choices' => [ 'none' => 'None', 'easy' => 'Easy', 'moderate' => 'Moderate', 'hard' => 'Hard', 'alpine' => 'Alpine' ], 'default' => 'none' ],
			'distance_km'           => [ 'type' => 'number', 'i18n' => false ],
			'elevation_gain_m'      => [ 'type' => 'number', 'i18n' => false ],
			'price_type'            => [ 'type' => 'enum', 'i18n' => false, 'choices' => [ 'free' => 'Free', 'ticket' => 'Ticket', 'paid_parking' => 'Paid parking' ], 'default' => 'free' ],
			'price_gbp'             => [ 'type' => 'number', 'i18n' => false ],
			'opening_hours'         => [ 'type' => 'repeater', 'i18n' => true, 'sub' => [ 'season' => [ 'type' => 'text', 'i18n' => true ], 'hours' => [ 'type' => 'text', 'i18n' => true ] ] ],
			'best_time'             => [ 'type' => 'text', 'i18n' => true ],
			'suitable_for'          => [ 'type' => 'checkboxes', 'i18n' => false, 'choices' => [ 'children' => 'Children', 'stroller' => 'Stroller', 'dogs' => 'Dogs', 'reduced_mobility' => 'Reduced mobility' ] ],
			'motorhome_parking'     => [ 'type' => 'enum', 'i18n' => false, 'choices' => [ 'yes' => 'Yes', 'yes_length_limit' => 'Yes, with length limit', 'no' => 'No' ], 'default' => 'no' ],
			'overnight_allowed'     => [ 'type' => 'bool', 'i18n' => false ],
			'services_nearby'       => [ 'type' => 'checkboxes', 'i18n' => false, 'choices' => [ 'toilets' => 'Toilets', 'cafe' => 'Café', 'restaurant' => 'Restaurant', 'shop' => 'Shop', 'fuel' => 'Fuel' ] ],
			'gallery'               => [ 'type' => 'gallery', 'i18n' => false ],
			'tips'                  => [ 'type' => 'repeater', 'i18n' => true, 'sub' => [ 'tip' => [ 'type' => 'text', 'i18n' => true ] ] ],
			'audioguide_enabled'    => [ 'type' => 'bool', 'i18n' => false ],
			'audioguide_stop_number' => [ 'type' => 'number', 'i18n' => false ],
			'audioguide_tracks'     => [ 'type' => 'repeater', 'i18n' => false, 'sub' => [ 'lang' => [ 'type' => 'text', 'i18n' => false ], 'audio_url' => [ 'type' => 'url', 'i18n' => false ], 'transcript' => [ 'type' => 'textarea', 'i18n' => false ] ] ],
			'audioguide_radius_m'   => [ 'type' => 'number', 'i18n' => false, 'default' => 150 ],
			'nearby_places'         => [ 'type' => 'relation', 'i18n' => false, 'to' => PostTypes::PLACE ],
			'nearby_lodging'        => [ 'type' => 'relation', 'i18n' => false, 'to' => PostTypes::LODGING ],
			'experiences_here'      => [ 'type' => 'relation', 'i18n' => false, 'to' => PostTypes::EXPERIENCE ],
			'in_tours'              => [ 'type' => 'relation', 'i18n' => false, 'to' => PostTypes::TOUR ],
		];
	}

	/** Fields of a type, by post type. Other types get their full schema in Phase 1. */
	public static function for( string $post_type ): array {
		return match ( $post_type ) {
			PostTypes::PLACE => self::place(),
			default          => [],
		};
	}
}
