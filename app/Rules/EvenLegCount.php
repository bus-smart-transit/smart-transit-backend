<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * 2026-11-21 (user, Dynamic Route Scheduling): every schedule is a multi-leg block that goes out and comes back
 * (outbound, inbound, ...), so the bus ends where it started: at least 2 legs, always an even number.
 */
class EvenLegCount implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        $count = is_array($value) ? count($value) : 0;
        if ($count < 2 || $count % 2 !== 0) {
            $fail('A schedule goes out and comes back, so it needs an even number of legs (2, 4, 6 and so on).');
        }
    }
}
