<?php

namespace App\Support;

/**
 * Batch 51 item 7 (user, 2026-11-09): the contact of a person a passenger books for, an email or a Philippine mobile
 * number. The one owner of what is accepted and how it is stored: an email in lower case, a mobile number as
 * +639XXXXXXXXX (09XXXXXXXXX and 639XXXXXXXXX are accepted, spaces and dashes ignored). The website mirrors it in
 * utils/travellerContact.js.
 */
class TravellerContact
{
    /** The stored form, or null when it is neither a valid email nor a Philippine mobile number. */
    public static function normalize(?string $value): ?string
    {
        $value = trim((string) $value);
        if ($value === '') {
            return null;
        }
        if (str_contains($value, '@')) {
            return filter_var($value, FILTER_VALIDATE_EMAIL) ? mb_strtolower($value) : null;
        }
        $digits = preg_replace('/[\s\-()]/', '', $value);
        if (preg_match('/^(?:\+?63|0)(9\d{9})$/', $digits, $match)) {
            return '+63'.$match[1];
        }

        return null;
    }

    public static function isPhone(?string $stored): bool
    {
        return is_string($stored) && str_starts_with($stored, '+63');
    }
}
