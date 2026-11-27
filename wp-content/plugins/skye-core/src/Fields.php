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
			'faq'                   => [ 'type' => 'repeater', 'i18n' => true, 'sub' => [ 'question' => [ 'type' => 'text', 'i18n' => true ], 'answer' => [ 'type' => 'textarea', 'i18n' => true ] ] ],
			'sources'               => [ 'type' => 'repeater', 'i18n' => false, 'sub' => [ 'title' => [ 'type' => 'text', 'i18n' => false ], 'url' => [ 'type' => 'url', 'i18n' => false ] ] ],
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

	/** CONTRACTS.md §2. `days` is a repeater of days, each with its own repeater of ordered stops. */
	public static function route(): array {
		return [
			'summary'             => [ 'type' => 'textarea', 'i18n' => true ],
			'route_type'          => [ 'type' => 'enum', 'i18n' => false, 'choices' => [ 'itinerary' => 'Itinerary (several days)', 'drive' => 'Drive (one day)', 'walk' => 'Walk' ], 'default' => 'drive' ],
			'days_count'          => [ 'type' => 'number', 'i18n' => false, 'default' => 1 ],
			'vehicle'             => [ 'type' => 'enum', 'i18n' => false, 'choices' => [ 'no_car' => 'No car', 'car' => 'Car', 'motorhome' => 'Motorhome', 'any' => 'Any' ], 'default' => 'any' ],
			'days'                => [
				'type' => 'repeater',
				'i18n' => true,
				'sub'  => [
					'stops'         => [
						'type' => 'repeater',
						'i18n' => true,
						'sub'  => [
							'place'     => [ 'type' => 'relation', 'i18n' => false, 'to' => PostTypes::PLACE, 'max' => 1 ],
							'time_hint' => [ 'type' => 'text', 'i18n' => false ],
							'note'      => [ 'type' => 'text', 'i18n' => true ],
							'stop_type' => [ 'type' => 'enum', 'i18n' => false, 'choices' => [ 'visit' => 'Visit', 'meal' => 'Meal', 'night' => 'Night', 'en_route' => 'En route' ], 'default' => 'visit' ],
						],
					],
					'night_zone'    => [ 'type' => 'enum', 'i18n' => false, 'choices' => [ '' => '—' ] + Taxonomies::terms()[ Taxonomies::ZONE ], 'default' => '' ],
					'night_lodging' => [ 'type' => 'relation', 'i18n' => false, 'to' => PostTypes::LODGING, 'max' => 1 ],
				],
			],
			'total_km'            => [ 'type' => 'number', 'i18n' => false ],
			'total_drive_minutes' => [ 'type' => 'number', 'i18n' => false ],
			'gpx_file'            => [ 'type' => 'file', 'i18n' => false ],
			'equivalent_tour'     => [ 'type' => 'relation', 'i18n' => false, 'to' => PostTypes::TOUR, 'max' => 1 ],
			'motorhome_ok'        => [ 'type' => 'bool', 'i18n' => false ],
			'motorhome_notes'     => [ 'type' => 'textarea', 'i18n' => true ],
		];
	}

	/** CONTRACTS.md §3. `markets` is a comma-separated list of market codes; `*` is the wildcard. */
	public static function brand(): array {
		return [
			'domain'              => [ 'type' => 'text', 'i18n' => false ],
			'markets'             => [ 'type' => 'text', 'i18n' => false ],
			'tour_language'       => [ 'type' => 'enum', 'i18n' => false, 'choices' => [ 'en' => 'English', 'es' => 'Spanish', 'it' => 'Italian' ], 'default' => 'en' ],
			'bokun_vendor_id'     => [ 'type' => 'text', 'i18n' => false ],
			'attribution_param'   => [ 'type' => 'text', 'i18n' => false ],
			'ga4_property'        => [ 'type' => 'text', 'i18n' => false ],
			'logo'                => [ 'type' => 'image', 'i18n' => false ],
			'rating'              => [ 'type' => 'number', 'i18n' => false ],
			'review_count'        => [ 'type' => 'number', 'i18n' => false ],
			'operator_legal_name' => [ 'type' => 'text', 'i18n' => false, 'default' => 'TA Scotland Limited' ],
		];
	}

	/** CONTRACTS.md §4. No stored price: `products` maps each brand to its Bókun product. */
	public static function tour(): array {
		return [
			'summary'            => [ 'type' => 'textarea', 'i18n' => true ],
			'includes'           => [ 'type' => 'textarea', 'i18n' => true ],
			'excludes'           => [ 'type' => 'textarea', 'i18n' => true ],
			'product_code'       => [ 'type' => 'text', 'i18n' => false ],
			'days_count'         => [ 'type' => 'number', 'i18n' => false ],
			'nights_count'       => [ 'type' => 'number', 'i18n' => false ],
			'departure_city'     => [ 'type' => 'enum', 'i18n' => false, 'choices' => [ 'edinburgh' => 'Edinburgh', 'glasgow' => 'Glasgow' ], 'default' => 'edinburgh' ],
			'departure_time'     => [ 'type' => 'text', 'i18n' => false ],
			'meeting_point'      => [ 'type' => 'textarea', 'i18n' => true ],
			'days'               => [
				'type' => 'repeater',
				'i18n' => true,
				'sub'  => [
					'title'   => [ 'type' => 'text', 'i18n' => true ],
					'summary' => [ 'type' => 'textarea', 'i18n' => true ],
					// Every stop of the day in order. `place` links the ones that have their own page; `title` names
					// the others ("Loch Tulla viewpoint") or rewords one that has ("Glencoe: Three Sisters viewpoint").
					'stops'   => [
						'type' => 'repeater',
						'i18n' => true,
						'sub'  => [
							'place'        => [ 'type' => 'relation', 'i18n' => false, 'to' => PostTypes::PLACE, 'max' => 1 ],
							'title'        => [ 'type' => 'text', 'i18n' => true ],
							'stop_type'    => [ 'type' => 'enum', 'i18n' => false, 'choices' => [ 'visit' => 'Visit', 'photo' => 'Photo stop', 'break' => 'Break', 'free_time' => 'Free time', 'overnight' => 'Overnight' ], 'default' => 'visit' ],
							'duration_min' => [ 'type' => 'number', 'i18n' => false ],
							'note'         => [ 'type' => 'text', 'i18n' => true ],
						],
					],
				],
			],
			// How you can sleep on the tour. No prices here: each option is a rate in Bókun.
			'accommodation_intro'   => [ 'type' => 'textarea', 'i18n' => true ],
			'accommodation_options' => [
				'type' => 'repeater',
				'i18n' => true,
				'sub'  => [
					'name'        => [ 'type' => 'text', 'i18n' => true ],
					'description' => [ 'type' => 'textarea', 'i18n' => true ],
				],
			],
			'accommodation_notes'   => [ 'type' => 'textarea', 'i18n' => true ],
			'products'           => [
				'type' => 'repeater',
				'i18n' => false,
				'sub'  => [
					'brand'                => [ 'type' => 'relation', 'i18n' => false, 'to' => PostTypes::BRAND, 'max' => 1 ],
					'bokun_product_id'     => [ 'type' => 'text', 'i18n' => false ],
					'fallback_booking_url' => [ 'type' => 'url', 'i18n' => false ],
				],
			],
			'rating'             => [ 'type' => 'number', 'i18n' => false ],
			'review_count'       => [ 'type' => 'number', 'i18n' => false ],
			'includes_audioguide' => [ 'type' => 'bool', 'i18n' => false ],
		];
	}

	/** CONTRACTS.md §5. */
	public static function lodging(): array {
		return [
			'summary'        => [ 'type' => 'textarea', 'i18n' => true ],
			'lodging_type'   => [ 'type' => 'enum', 'i18n' => false, 'choices' => [ 'hotel' => 'Hotel', 'bnb' => 'B&B', 'cabin' => 'Cabin', 'hostel' => 'Hostel', 'campsite' => 'Campsite' ], 'default' => 'hotel' ],
			'location'       => [ 'type' => 'latlng', 'i18n' => false ],
			'price_from_gbp' => [ 'type' => 'number', 'i18n' => false ],
			'amenities'      => [ 'type' => 'checkboxes', 'i18n' => false, 'choices' => [ 'breakfast' => 'Breakfast', 'parking' => 'Parking', 'dogs' => 'Dogs', 'family' => 'Family', 'kitchen' => 'Kitchen', 'free_cancellation' => 'Free cancellation' ] ],
			'good_for'       => [ 'type' => 'text', 'i18n' => true ],
			'booking_url'    => [ 'type' => 'url', 'i18n' => false ],
			'provider'       => [ 'type' => 'enum', 'i18n' => false, 'choices' => [ 'booking' => 'Booking.com', 'direct' => 'Direct' ], 'default' => 'booking' ],
		];
	}

	/** CONTRACTS.md §6. */
	public static function motorhome_spot(): array {
		return [
			'spot_type'         => [ 'type' => 'enum', 'i18n' => false, 'choices' => [ 'campsite' => 'Campsite', 'aire' => 'Overnight area', 'parking' => 'Parking', 'dump_station' => 'Dump station', 'ev_charger' => 'EV charger' ], 'default' => 'campsite' ],
			'location'          => [ 'type' => 'latlng', 'i18n' => false ],
			'services'          => [ 'type' => 'checkboxes', 'i18n' => false, 'choices' => [ 'electricity' => 'Electricity', 'water' => 'Water', 'dump' => 'Waste disposal', 'showers' => 'Showers', 'wifi' => 'Wi-Fi' ] ],
			'ev_kw'             => [ 'type' => 'number', 'i18n' => false ],
			'overnight_allowed' => [ 'type' => 'bool', 'i18n' => false ],
			'max_length_m'      => [ 'type' => 'number', 'i18n' => false ],
			'price_gbp'         => [ 'type' => 'number', 'i18n' => false ],
			'booking_url'       => [ 'type' => 'url', 'i18n' => false ],
			'no_booking'        => [ 'type' => 'bool', 'i18n' => false ],
			'season'            => [ 'type' => 'text', 'i18n' => true ],
			'notes'             => [ 'type' => 'textarea', 'i18n' => true ],
		];
	}

	/** CONTRACTS.md §7. `commission_pct` is internal and must never reach the public API. */
	public static function experience(): array {
		return [
			'summary'         => [ 'type' => 'textarea', 'i18n' => true ],
			'experience_type' => [ 'type' => 'enum', 'i18n' => false, 'choices' => [ 'tasting' => 'Tasting', 'cruise' => 'Cruise', 'wildlife' => 'Wildlife', 'kayak' => 'Kayak', 'fishing' => 'Fishing', 'mountain_guide' => 'Mountain guide', 'private_tour' => 'Private tour', 'ticket' => 'Ticket' ], 'default' => 'ticket' ],
			'departure_place' => [ 'type' => 'relation', 'i18n' => false, 'to' => PostTypes::PLACE, 'max' => 1 ],
			'duration_min'    => [ 'type' => 'number', 'i18n' => false ],
			'price_from_gbp'  => [ 'type' => 'number', 'i18n' => false ],
			'languages'       => [ 'type' => 'checkboxes', 'i18n' => false, 'choices' => [ 'en' => 'English', 'es' => 'Spanish', 'it' => 'Italian', 'de' => 'German', 'fr' => 'French', 'pt' => 'Portuguese', 'nl' => 'Dutch', 'zh' => 'Chinese', 'ja' => 'Japanese' ] ],
			'season'          => [ 'type' => 'text', 'i18n' => true ],
			'suitable_for'    => [ 'type' => 'checkboxes', 'i18n' => false, 'choices' => [ 'children' => 'Children', 'stroller' => 'Stroller', 'dogs' => 'Dogs', 'reduced_mobility' => 'Reduced mobility' ] ],
			'provider'        => [ 'type' => 'text', 'i18n' => false ],
			'booking_url'     => [ 'type' => 'url', 'i18n' => false ],
			'commission_pct'  => [ 'type' => 'number', 'i18n' => false, 'internal' => true ],
		];
	}

	/** @return array<string, array<string, array<string, mixed>>> post type => fields, for every content type. */
	public static function all(): array {
		return [
			PostTypes::PLACE      => self::place(),
			PostTypes::ROUTE      => self::route(),
			PostTypes::BRAND      => self::brand(),
			PostTypes::TOUR       => self::tour(),
			PostTypes::LODGING    => self::lodging(),
			PostTypes::MOTORHOME  => self::motorhome_spot(),
			PostTypes::EXPERIENCE => self::experience(),
		];
	}

	/** Fields of a type, by post type. */
	public static function for( string $post_type ): array {
		return self::all()[ $post_type ] ?? [];
	}

	/** Field names that are safe to expose publicly (drops the ones flagged `internal`). @return list<string> */
	public static function public_names( string $post_type ): array {
		return array_keys( array_filter( self::for( $post_type ), static fn( array $def ) => empty( $def['internal'] ) ) );
	}
}
