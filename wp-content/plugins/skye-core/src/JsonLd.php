<?php
declare(strict_types=1);

namespace Skye;

/**
 * schema.org output (CONTRACTS.md §13). Builders are pure (API object in, array out);
 * register() prints the one for the current page in <head>.
 */
final class JsonLd {

	public static function register(): void {
		add_action( 'wp_head', [ self::class, 'print' ], 20 );
	}

	public static function print(): void {
		if ( Frontend::is_directory() ) {
			$view = Frontend::places_view();
			self::emit( self::item_list( (string) $view['title'], $view['places'] ) );
			return;
		}
		if ( is_post_type_archive( PostTypes::MOTORHOME ) ) {
			self::emit( self::spot_list( __( 'Skye by car or motorhome', 'skye-core' ), Frontend::motorhome_view()['spots'] ) );
			return;
		}
		if ( is_singular( PostTypes::TOUR ) ) {
			$post = get_queried_object();
			if ( $post instanceof \WP_Post ) {
				$view = Frontend::tour_view( $post );
				self::emit( self::tour( $view ) );
				self::emit( self::faq( Render\TourBlocks::faq_items( $view ) ) );
			}
			return;
		}
		if ( ! is_singular( PostTypes::PLACE ) ) {
			return;
		}
		$post = get_queried_object();
		if ( ! $post instanceof \WP_Post ) {
			return;
		}
		[ 'lang' => $lang, 'market' => $market ] = Frontend::context();
		$item = Api\Places::item( $post, $lang, $market );
		$data = self::place( $item );
		$faq  = self::faq( Render\PlaceBlocks::faq_items( $item, $market ) );
		foreach ( array_filter( [ $data, $faq ] ) as $block ) {
			self::emit( $block );
		}
	}

	private static function emit( ?array $block ): void {
		if ( $block ) {
			// JSON_HEX_TAG keeps a "</script>" inside any text from closing the tag.
			echo '<script type="application/ld+json">' . wp_json_encode( $block, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG ) . "</script>\n"; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		}
	}

	/**
	 * ItemList of the car and motorhome page: campsites as Campground, overnight stops as
	 * ParkingFacility, the rest as Place. A spot points at its own website when it has one.
	 *
	 * @param list<array<string, mixed>> $spots
	 * @return array<string, mixed>|null
	 */
	public static function spot_list( string $name, array $spots ): ?array {
		$types = [ 'campsite' => 'Campground', 'aire' => 'ParkingFacility', 'parking' => 'ParkingFacility' ];
		$items = [];
		foreach ( $spots as $s ) {
			if ( '' === trim( (string) ( $s['title'] ?? '' ) ) ) {
				continue;
			}
			$thing = array_filter(
				[
					'@type'       => $types[ (string) ( $s['spot_type'] ?? '' ) ] ?? 'Place',
					'name'        => (string) $s['title'],
					'description' => trim( (string) ( $s['notes'] ?? '' ) ),
					'url'         => (string) ( $s['booking_url'] ?? '' ),
				]
			);
			$items[] = [ '@type' => 'ListItem', 'position' => count( $items ) + 1, 'item' => $thing ];
		}
		return $items ? [ '@context' => 'https://schema.org', '@type' => 'ItemList', 'name' => $name, 'numberOfItems' => count( $items ), 'itemListElement' => $items ] : null;
	}

	/**
	 * ItemList for a listing page: the places shown, in order, each pointing at its own page.
	 *
	 * @param list<array<string, mixed>> $places
	 * @return array<string, mixed>|null
	 */
	public static function item_list( string $name, array $places ): ?array {
		$items = [];
		foreach ( $places as $p ) {
			if ( ! empty( $p['url'] ) ) {
				$items[] = [ '@type' => 'ListItem', 'position' => count( $items ) + 1, 'name' => (string) $p['title'], 'url' => (string) $p['url'] ];
			}
		}
		return $items ? [ '@context' => 'https://schema.org', '@type' => 'ItemList', 'name' => $name, 'numberOfItems' => count( $items ), 'itemListElement' => $items ] : null;
	}

	/**
	 * FAQPage for the questions shown on the page; null when there are none.
	 *
	 * @param list<array{question:string, answer:string}> $items
	 * @return array<string, mixed>|null
	 */
	public static function faq( array $items ): ?array {
		if ( ! $items ) {
			return null;
		}
		return [
			'@context'   => 'https://schema.org',
			'@type'      => 'FAQPage',
			'mainEntity' => array_map(
				static fn( array $i ): array => [ '@type' => 'Question', 'name' => $i['question'], 'acceptedAnswer' => [ '@type' => 'Answer', 'text' => $i['answer'] ] ],
				$items
			),
		];
	}

	/**
	 * TouristTrip for a tour: the stops that have their own page as its itinerary, and an Offer only
	 * when Bókun gave a price (a tour stores none).
	 *
	 * @return array<string, mixed>
	 */
	public static function tour( array $t ): array {
		$ld = [
			'@context' => 'https://schema.org',
			'@type'    => 'TouristTrip',
			'name'     => (string) ( $t['title'] ?? '' ),
			'url'      => (string) ( $t['url'] ?? '' ),
		];
		if ( ! empty( $t['summary'] ) ) {
			$ld['description'] = (string) $t['summary'];
		}
		if ( ! empty( $t['image']['url'] ) ) {
			$ld['image'] = (string) $t['image']['url'];
		}
		$stops = [];
		$seen  = [];
		foreach ( $t['days'] ?? [] as $day ) {
			foreach ( $day['stops'] ?? [] as $stop ) {
				$place = is_array( $stop['place'] ?? null ) ? $stop['place'] : [];
				$id    = (int) ( $place['id'] ?? 0 );
				if ( ! $id || isset( $seen[ $id ] ) || empty( $place['url'] ) ) {
					continue; // A place visited twice is one attraction of the trip.
				}
				$seen[ $id ] = true;
				$stops[]     = [ '@type' => 'ListItem', 'position' => count( $stops ) + 1, 'item' => [ '@type' => 'TouristAttraction', 'name' => (string) ( $place['title'] ?? '' ), 'url' => (string) $place['url'] ] ];
			}
		}
		if ( $stops ) {
			$ld['itinerary'] = [ '@type' => 'ItemList', 'numberOfItems' => count( $stops ), 'itemListElement' => $stops ];
		}
		$offer = is_array( $t['offer'] ?? null ) ? $t['offer'] : [];
		$brand = ! empty( $offer['brand']['title'] ) ? [ '@type' => 'Organization', 'name' => (string) $offer['brand']['title'] ] : null;
		if ( $brand ) {
			$ld['provider'] = $brand;
		}
		if ( isset( $offer['price_from'] ) && (float) $offer['price_from'] > 0 && ! empty( $offer['currency'] ) ) {
			$ld['offers'] = array_filter(
				[
					'@type'         => 'Offer',
					'price'         => round( (float) $offer['price_from'], 2 ),
					'priceCurrency' => (string) $offer['currency'],
					// Sold on this page when it carries the booking calendar; otherwise on the operator's site.
					'url'           => ! empty( $offer['booking_widget'] ) && ! empty( $t['url'] ) ? $t['url'] . '#' . Render\TourBlocks::BOOKING_ID : (string) ( $offer['booking_url'] ?? '' ),
					'seller'        => $brand,
				]
			);
		}
		$v = $t['verification'] ?? [];
		if ( ! empty( $v['verified_at'] ) ) {
			$ld['dateModified'] = (string) ( $v['updated_at'] ?? $v['verified_at'] );
		}
		return $ld;
	}

	/** @return array<string, mixed> TouristAttraction */
	public static function place( array $p ): array {
		$ld = [
			'@context' => 'https://schema.org',
			'@type'    => 'TouristAttraction',
			'name'     => (string) $p['title'],
			'url'      => (string) ( $p['url'] ?? '' ),
		];
		if ( ! empty( $p['gaelic_name'] ) ) {
			$ld['alternateName'] = (string) $p['gaelic_name'];
		}
		if ( ! empty( $p['summary'] ) ) {
			$ld['description'] = (string) $p['summary'];
		}
		if ( ! empty( $p['image']['url'] ) ) {
			$ld['image'] = (string) $p['image']['url'];
		}
		if ( ! empty( $p['location'] ) ) {
			$ld['geo'] = [ '@type' => 'GeoCoordinates', 'latitude' => (float) $p['location']['lat'], 'longitude' => (float) $p['location']['lng'] ];
		}
		$ld['isAccessibleForFree'] = 'ticket' !== ( $p['price_type'] ?? 'free' );
		$ld['publicAccess']        = true;
		if ( ! empty( $p['profiles'] ) ) {
			$ld['touristType'] = array_values( array_map( static fn( $t ) => (string) $t['name'], $p['profiles'] ) );
		}
		if ( ! empty( $p['zone']['name'] ) ) {
			$ld['containedInPlace'] = [ '@type' => 'Place', 'name' => $p['zone']['name'] . ', Isle of Skye' ];
		}
		$choices = Fields::place()['services_nearby']['choices'];
		foreach ( $p['services_nearby'] ?? [] as $s ) {
			$ld['amenityFeature'][] = [ '@type' => 'LocationFeatureSpecification', 'name' => $choices[ $s ] ?? (string) $s, 'value' => true ];
		}

		// Verification (§10): only claim a review that happened.
		$v = $p['verification'] ?? [];
		if ( ! empty( $v['verified_at'] ) ) {
			$ld['dateModified'] = (string) ( $v['updated_at'] ?? $v['verified_at'] );
			if ( ! empty( $v['verified_by']['name'] ) ) {
				$ld['reviewedBy'] = [ '@type' => 'Person', 'name' => (string) $v['verified_by']['name'] ];
			}
		}
		return $ld;
	}
}
