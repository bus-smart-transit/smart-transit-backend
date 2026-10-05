<?php

namespace App\Support;

use App\Models\Trip;

/**
 * Batch 29 (adviser decision): a Driver or Chauffeur must ACCEPT their assignment before the shift can start and
 * before a pairing key or PIN is issued. Acceptance is written to every leg of a block together
 * (`driver_accepted_at` / `conductor_accepted_at`), so the trip the person is working on carries it.
 * A reassigned person starts unaccepted again (the previous person's acceptance never carries over).
 */
final class AssignmentAcceptance
{
    public const MESSAGE = 'Accept your assignment first (Assigned Routes). If you asked the Operator to release you, wait for their answer.';

    public static function isAccepted(Trip $trip, string $role): bool
    {
        return $role === 'driver' ? $trip->driver_accepted_at !== null : $trip->conductor_accepted_at !== null;
    }

    /** The plain reason this person cannot start the shift or get a pairing key yet, or null when they can. */
    public static function blockedReason(Trip $trip, string $role): ?string
    {
        return self::isAccepted($trip, $role) ? null : self::MESSAGE;
    }
}
