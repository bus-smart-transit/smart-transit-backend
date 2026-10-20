<?php

namespace App\Exceptions;

use RuntimeException;
use Throwable;

class PayMongoCheckoutOutcomeUnknown extends RuntimeException
{
    public function __construct(public readonly string $transactionReference, ?Throwable $previous = null)
    {
        parent::__construct('The payment provider outcome is unknown.', 0, $previous);
    }
}
