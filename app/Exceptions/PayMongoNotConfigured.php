<?php

namespace App\Exceptions;

use RuntimeException;

class PayMongoNotConfigured extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('Online payment processing is not configured.');
    }
}
