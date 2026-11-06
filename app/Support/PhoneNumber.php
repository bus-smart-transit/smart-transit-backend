<?php

namespace App\Support;

/**
 * Batch 46 / Batch 47 part B (decided 2026-11-05): the one rule for a typed phone number. Stored as one E.164 string
 * (+639171234567). Philippine numbers: a mobile 9XX XXX XXXX, also typed with a leading 0 or with +63. Other calling
 * codes from the short list in the frontend: a length check only. No phone library (a new package needs approval).
 */
class PhoneNumber
{
    /** The E.164 form, or null when the number is not valid. */
    public static function normalize(?string $typed): ?string
    {
        $raw = trim((string) $typed);
        if ($raw === '' || ! preg_match('/^\+?[\d\s\-()]+$/', $raw)) {
            return null;
        }
        $digits = preg_replace('/\D/', '', $raw);
        $international = str_starts_with($raw, '+');

        if (! $international) {
            // A local Philippine number: 09XXXXXXXXX or 9XXXXXXXXX.
            $local = str_starts_with($digits, '0') ? substr($digits, 1) : $digits;

            return preg_match('/^9\d{9}$/', $local) ? '+63'.$local : null;
        }

        if (str_starts_with($digits, '63')) {
            $local = substr($digits, 2);
            $local = str_starts_with($local, '0') ? substr($local, 1) : $local;

            return preg_match('/^9\d{9}$/', $local) ? '+63'.$local : null;
        }

        // Another calling code: E.164 allows up to 15 digits in all.
        return strlen($digits) >= 8 && strlen($digits) <= 15 ? '+'.$digits : null;
    }

    /** For logs and audit entries: the middle digits hidden (+63917*****67). */
    public static function mask(?string $phone): string
    {
        $phone = (string) $phone;
        if (strlen($phone) <= 6) {
            return str_repeat('*', strlen($phone));
        }

        return substr($phone, 0, 6).str_repeat('*', strlen($phone) - 8).substr($phone, -2);
    }

    public const INVALID_MESSAGE = 'Enter a valid mobile number, for example 917 123 4567.';
}
