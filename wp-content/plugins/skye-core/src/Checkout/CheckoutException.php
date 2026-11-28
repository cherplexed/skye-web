<?php
declare(strict_types=1);

namespace Skye\Checkout;

/** A booking step that cannot go on. `reason` is a short code the page turns into a message; `field` names the answer at fault, if one is. */
final class CheckoutException extends \RuntimeException {

	public function __construct( public readonly string $reason, string $message = '', public readonly string $field = '', int $status = 400, ?\Throwable $previous = null ) {
		parent::__construct( '' !== $message ? $message : $reason, $status, $previous );
	}
}
