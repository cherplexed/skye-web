<?php
declare(strict_types=1);

namespace Skye\Bokun;

/** Any failure talking to Bókun. `retry_after` (seconds) is set when Bókun rate-limited us. */
final class BokunException extends \RuntimeException {

	public function __construct( string $message, int $status = 0, public readonly int $retry_after = 0, ?\Throwable $previous = null ) {
		parent::__construct( $message, $status, $previous );
	}
}
