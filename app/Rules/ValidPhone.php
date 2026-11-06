<?php

namespace App\Rules;

use App\Support\PhoneNumber;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * Batch 46 / Batch 47 part B: a phone number that App\Support\PhoneNumber accepts. Used by passenger sign-up, the
 * passenger profile edit and the Operator's Add / Edit Staff, so every form has the same rule. The form request turns
 * the value into E.164 first (PhoneNumber::normalize) and keeps the typed text when it is not valid, so this can
 * refuse it.
 */
class ValidPhone implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (PhoneNumber::normalize((string) $value) === null) {
            $fail(PhoneNumber::INVALID_MESSAGE);
        }
    }

    /** The value to validate and store: E.164 when valid, else the typed text (trimmed) so the rule can refuse it. */
    public static function prepare(mixed $typed): string
    {
        return PhoneNumber::normalize((string) $typed) ?? trim((string) $typed);
    }
}
