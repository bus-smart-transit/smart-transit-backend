<?php

namespace App\Exceptions;

use RuntimeException;

class CheckoutIdempotencyConflict extends RuntimeException
{
    /**
     * $retryWithNewKey (§12, 2026-10-26): the earlier attempt with this key is over (it failed, or its gateway outcome
     * stayed unknown past the release window) and its seats are released, so the client may start a fresh checkout
     * with a new key at once instead of waiting out the hold.
     */
    public function __construct(string $message, public readonly bool $retryWithNewKey = false)
    {
        parent::__construct($message);
    }
}
