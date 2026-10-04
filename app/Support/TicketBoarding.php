<?php

namespace App\Support;

use Illuminate\Support\Collection;

/**
 * Batch 28 Stage 2: a ticket's boarding point is usually a stop (`origin_stop_id`), but a Chauffeur's mid-trip
 * sale stores a point snapped to the route instead (`origin_lat/lng` + `boarding_distance_m`, no origin stop).
 * Every reader of `origin_stop_id` goes through here so it handles both shapes the same way.
 *
 * Where a stop is needed for a snapped boarding (per-stop counts, a roundtrip's return destination) the rule is
 * the NEAREST PRECEDING STOP in the leg's direction: the last stop the bus passed before the boarding point.
 */
final class TicketBoarding
{
    public const BETWEEN_STOPS = 'Boarded between stops';

    /** True for a ticket whose boarding point is a snapped position, not a stop. */
    public static function isSnapped(object $ticket): bool
    {
        return ($ticket->origin_stop_id ?? null) === null && ($ticket->origin_lat ?? null) !== null;
    }

    /**
     * The last stop passed before a boarding point, in the leg's direction.
     *
     * @param  Collection<int, \App\Models\RouteStop>  $routeStops  the route's stops with stored (outbound) distance_from_origin_km
     */
    public static function precedingStopId(Collection $routeStops, ?int $boardingDistanceM, ?string $legDirection): ?int
    {
        if ($boardingDistanceM === null) {
            return null;
        }

        $reverse = LegDistance::direction($legDirection) === 'reverse';
        // Only places the bus really stops at: a pass-through point is never "the stop passed".
        $candidates = $routeStops
            ->filter(fn ($s) => in_array($s->stop_type ?? 'stop', config('timeline.stopping_types'), true))
            ->filter(fn ($s) => $reverse
                ? LegDistance::toMeters((float) $s->distance_from_origin_km) >= $boardingDistanceM
                : LegDistance::toMeters((float) $s->distance_from_origin_km) <= $boardingDistanceM)
            ->sortBy(fn ($s) => LegDistance::toMeters((float) $s->distance_from_origin_km));

        $stop = $reverse ? $candidates->first() : $candidates->last();

        return $stop ? (int) $stop->stop_id : null;
    }
}
