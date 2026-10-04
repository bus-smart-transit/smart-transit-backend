<?php

namespace App\Exceptions;

/**
 * No fare rule prices this bus on this trip: no fleet override and no general rule for its class and service type
 * (Batch 28 item 5). The message is what passengers and the Chauffeur see; nothing is guessed or borrowed.
 */
class FareUnavailableException extends \RuntimeException
{
    public const MESSAGE = 'Fare unavailable';

    public function __construct(string $message = self::MESSAGE)
    {
        parent::__construct($message);
    }
}
