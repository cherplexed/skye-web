<?php
declare(strict_types=1);

namespace Skye\Bokun;

/**
 * Request signing for the Bókun REST API (CONTRACTS.md §12).
 *
 * Signature = base64( HMAC-SHA1( secret, date + access_key + METHOD + path_and_query ) ), where `date`
 * is the exact value sent in X-Bokun-Date (UTC, "Y-m-d H:i:s") and `path_and_query` excludes scheme and host.
 * Pure: no WordPress, unit-tested against the worked example in Bókun's documentation.
 */
final class Signer {

	public const DATE_FORMAT = 'Y-m-d H:i:s';

	public static function sign( string $date, string $access_key, string $secret_key, string $method, string $path_and_query ): string {
		return base64_encode( hash_hmac( 'sha1', $date . $access_key . strtoupper( $method ) . $path_and_query, $secret_key, true ) );
	}

	/**
	 * @param int|null $now Unix timestamp; null = now. Injectable for tests.
	 * @return array<string, string> Headers to send with the request.
	 */
	public static function headers( string $access_key, string $secret_key, string $method, string $path_and_query, ?int $now = null ): array {
		$date = gmdate( self::DATE_FORMAT, $now ?? time() );
		return [
			'X-Bokun-Date'      => $date,
			'X-Bokun-AccessKey' => $access_key,
			'X-Bokun-Signature' => self::sign( $date, $access_key, $secret_key, $method, $path_and_query ),
			'Accept'            => 'application/json',
		];
	}
}
