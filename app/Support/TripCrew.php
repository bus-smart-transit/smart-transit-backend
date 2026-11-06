<?php

namespace App\Support;

use App\Models\Trip;

/**
 * Batch 46 item 6 (2026-11-05): a trip may be scheduled before its Driver and Chauffeur are chosen ("Crew needed").
 * The one rule for what such a trip may do: it cannot start boarding or depart until both are assigned. Passengers
 * may still book it (seats are often sold before the crew roster is set; the trip cannot run without a crew, and a
 * cancelled trip is refunded as usual). No Daily PIN, pairing or crew notification exists for it until then (those
 * already need both people).
 */
class TripCrew
{
    public const MISSING_MESSAGE = 'Assign a Driver and a Chauffeur to this trip first.';

    public static function isComplete(?Trip $trip): bool
    {
        return $trip !== null && ! empty($trip->driver_id) && ! empty($trip->conductor_id);
    }
}
