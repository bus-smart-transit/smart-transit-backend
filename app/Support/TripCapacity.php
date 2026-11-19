<?php

namespace App\Support;

use App\Models\Trip;

/**
 * Batch 27 Part 2, Task 5: ONE definition of "occupied" for a trip, used by the sale block (recordBoardingMultiple)
 * and by the occupancy payload the Chauffeur dashboard reads.
 *
 * Occupied = tickets SOLD for the trip (the trip's counters, held at checkout, for online and cash alike), per seat
 * type. A bus is full for a seat type when sold reaches that type's capacity. "Boarded" (tickets scanned onto the bus)
 * is reported next to it for the manifest, but it never decides whether a sale is allowed.
 *
 * Why not "tickets valid at the boarding point" (seats freed when people alight)? Online tickets reserve their seat at
 * checkout, before anyone boards, and the counters have no per-segment history, so a segment-aware count needs
 * per-ticket distance ranges on every ticket (the snapshot columns only exist on tickets issued from now on) and a
 * rewrite of the online hold. That is a larger change than this task; the definition below is kept and documented.
 */
final class TripCapacity
{
    /**
     * @param  array{seated?: int, standing?: int}  $boarded  scanned-on counts by seat type
     * @return array<string, mixed>
     */
    public static function summary(Trip $trip, array $boarded = []): array
    {
        $fleet = $trip->fleetRoute?->fleet;
        $capacity = [
            'seated' => (int) ($fleet->seated_capacity ?? 0),
            'standing' => (int) ($fleet->standing_capacity ?? 0),
        ];
        $capacity['total'] = (int) ($fleet->capacity ?? ($capacity['seated'] + $capacity['standing']));

        $sold = [
            'seated' => (int) $trip->current_seated_capacity,
            'standing' => (int) $trip->current_standing_capacity,
        ];
        $sold['total'] = $sold['seated'] + $sold['standing'];

        $remaining = [
            'seated' => max(0, $capacity['seated'] - $sold['seated']),
            'standing' => max(0, $capacity['standing'] - $sold['standing']),
        ];
        $remaining['total'] = $remaining['seated'] + $remaining['standing'];

        $onboard = [
            'seated' => (int) ($boarded['seated'] ?? 0),
            'standing' => (int) ($boarded['standing'] ?? 0),
        ];
        $onboard['total'] = $onboard['seated'] + $onboard['standing'];

        return [
            'definition' => 'tickets_sold',
            'capacity' => $capacity,
            'sold' => $sold,
            'occupied' => $sold['total'],
            'onboard' => $onboard,
            'remaining' => $remaining,
            'is_full' => $remaining['total'] <= 0,
            'utilization_percentage' => $capacity['total'] > 0 ? round($sold['total'] / $capacity['total'] * 100, 2) : 0.0,
        ];
    }

    /**
     * Standing places are sold only on the bus, and only once every seat is sold. The Operator sets how many standing
     * places a bus has (fleets.standing_capacity); this only says whether standing may be offered yet.
     */
    public static function standingOpen(Trip $trip): bool
    {
        $fleet = $trip->fleetRoute->fleet;

        return (int) $trip->current_seated_capacity >= (int) $fleet->seated_capacity;
    }

    /** True when selling $count more tickets of this seat type would pass the bus's capacity for it. */
    public static function wouldExceed(Trip $trip, string $seatType, int $count): bool
    {
        $fleet = $trip->fleetRoute->fleet;

        return $seatType === 'seated'
            ? $trip->current_seated_capacity + $count > $fleet->seated_capacity
            : $trip->current_standing_capacity + $count > $fleet->standing_capacity;
    }
}
