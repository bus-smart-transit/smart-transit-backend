<?php

namespace App\Support;

use App\Models\Fleet;

/**
 * The only place the passenger-facing bus label is built: "{bus_company_name} - {body_no}".
 *
 * It returns null when either part is missing. Callers must show "unavailable" for null;
 * they must never fall back to the plate number or a made-up value (Batch 28, item 2).
 * It is computed from the fleet's current row on every read, so there is no snapshot.
 */
final class BusLabel
{
    public static function compose(?string $companyName, ?string $bodyNo): ?string
    {
        $company = trim((string) $companyName);
        $body = trim((string) $bodyNo);

        if ($company === '' || $body === '') {
            return null;
        }

        return $company.' - '.$body;
    }

    public static function for(?Fleet $fleet): ?string
    {
        return $fleet ? self::compose($fleet->bus_company_name, $fleet->body_no) : null;
    }
}
