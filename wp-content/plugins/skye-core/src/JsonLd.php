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
			// JSON_HEX_TAG keeps a "</script>" inside any text from closing the tag.
			echo '<script type="application/ld+json">' . wp_json_encode( $block, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG ) . "</script>\n"; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		}
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
