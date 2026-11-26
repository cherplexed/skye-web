<?php
declare(strict_types=1);

namespace Skye\Bokun;

/**
 * Turns the list returned by GET /activity.json/{id}/availabilities into the "from" price and the next
 * bookable date (CONTRACTS.md §12). Pure: no WordPress, no HTTP.
 *
 * Which price is "the" price of a departure: the one of the availability's default rate (`defaultRateId`)
 * for the adult pricing category. When the caller does not know the adult category ID, the highest
 * per-person amount of that rate is used: adults are the most expensive category, so "from" never shows a
 * child price. Pass $category_id once the real product is known to remove the heuristic.
 */
final class Pricing {

	/**
	 * @param array<int, mixed> $availabilities Decoded Bókun response.
	 * @return array{price_from: float, currency: string, next_available: string, departures: int}|null
	 *         null when nothing is bookable in the list.
	 */
	public static function summarize( array $availabilities, ?int $category_id = null ): ?array {
		$best      = null;
		$currency  = '';
		$next      = null;
		$bookable  = 0;

		foreach ( $availabilities as $a ) {
			if ( ! is_array( $a ) || ! self::is_bookable( $a ) ) {
				continue;
			}
			$price = self::departure_price( $a, $category_id );
			if ( null === $price ) {
				continue;
			}
			++$bookable;
			if ( null === $best || $price['amount'] < $best ) {
				$best     = $price['amount'];
				$currency = $price['currency'];
			}
			$date = self::date( $a );
			if ( null !== $date && ( null === $next || $date < $next ) ) {
				$next = $date;
			}
		}

		if ( null === $best || null === $next ) {
			return null;
		}
		return [ 'price_from' => $best, 'currency' => $currency, 'next_available' => $next, 'departures' => $bookable ];
	}

	/** A departure can be sold when it is neither flagged off nor out of seats. */
	public static function is_bookable( array $a ): bool {
		if ( ! empty( $a['soldOut'] ) || ! empty( $a['unavailable'] ) ) {
			return false;
		}
		if ( ! empty( $a['unlimitedAvailability'] ) ) {
			return true;
		}
		$seats = (int) ( $a['availabilityCount'] ?? 0 );
		$min   = max( 1, (int) ( $a['minParticipantsToBookNow'] ?? 1 ) );
		return $seats >= $min;
	}

	/** @return array{amount: float, currency: string}|null */
	public static function departure_price( array $a, ?int $category_id = null ): ?array {
		$rates = $a['pricesByRate'] ?? [];
		if ( ! is_array( $rates ) || ! $rates ) {
			return null;
		}
		$rate = null;
		foreach ( $rates as $r ) {
			if ( is_array( $r ) && isset( $a['defaultRateId'] ) && (int) ( $r['activityRateId'] ?? 0 ) === (int) $a['defaultRateId'] ) {
				$rate = $r;
				break;
			}
		}
		$rate ??= is_array( $rates[0] ?? null ) ? $rates[0] : null;
		if ( null === $rate ) {
			return null;
		}

		$picked = null;
		foreach ( (array) ( $rate['pricePerCategoryUnit'] ?? [] ) as $unit ) {
			$amount = $unit['amount']['amount'] ?? null;
			if ( ! is_numeric( $amount ) || (float) $amount <= 0 ) {
				continue;
			}
			$row = [ 'amount' => (float) $amount, 'currency' => (string) ( $unit['amount']['currency'] ?? '' ) ];
			if ( null !== $category_id ) {
				if ( (int) ( $unit['id'] ?? 0 ) === $category_id ) {
					return $row;
				}
				continue;
			}
			if ( null === $picked || $row['amount'] > $picked['amount'] ) {
				$picked = $row;
			}
		}
		return $picked;
	}

	/** Bókun sends `date` as epoch milliseconds (midnight UTC of the departure day). */
	private static function date( array $a ): ?string {
		$ms = $a['date'] ?? null;
		return is_numeric( $ms ) ? gmdate( 'Y-m-d', (int) floor( (float) $ms / 1000 ) ) : null;
	}
}
