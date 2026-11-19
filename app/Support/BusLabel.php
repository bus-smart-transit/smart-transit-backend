<?php

namespace App\Support;

use App\Models\Fleet;

/**
 * The only place the passenger-facing bus label is built: "{bus company} / {body no}", for example
 * "Metro Shuttle Co / MSC - 000001" (Batch 62, user, 2026-11-13; was "{company} - {body no}").
 *
 * The bus company is the bus's own bus_company_name, or, when that was never filled in, the name of the company that
 * owns the bus (fleets.company_id). The body number is what identifies the bus, so without it there is no label: it
 * returns null and callers show "Not provided". The plate is never used (Batch 28, item 2).
 * It is computed from the fleet's current row on every read, so there is no snapshot.
 */
final class BusLabel
{
    public const SEPARATOR = ' / ';

    public static function compose(?string $companyName, ?string $bodyNo, ?string $ownerCompanyName = null): ?string
    {
        $company = trim((string) $companyName);
        if ($company === '') {
            $company = trim((string) $ownerCompanyName);
        }
        $body = trim((string) $bodyNo);

        if ($body === '') {
            return null;
        }

        return $company === '' ? $body : $company.self::SEPARATOR.$body;
    }

    public static function for(?Fleet $fleet): ?string
    {
        if (! $fleet) {
            return null;
        }
        $owner = trim((string) $fleet->bus_company_name) === '' && $fleet->company_id
            ? $fleet->loadMissing('company')->company?->company_name
            : null;

        return self::compose($fleet->bus_company_name, $fleet->body_no, $owner);
    }
}
