<?php
declare(strict_types=1);

namespace Skye\Revolut;

/** A failed call to the Revolut Merchant API. The message never carries a key or a response body. */
final class RevolutException extends \RuntimeException {
}
