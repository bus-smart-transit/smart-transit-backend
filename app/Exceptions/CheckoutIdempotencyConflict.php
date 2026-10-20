<?php

namespace App\Exceptions;

use RuntimeException;

class CheckoutIdempotencyConflict extends RuntimeException
{
}