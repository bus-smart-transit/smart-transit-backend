<?php

namespace App\Support;

/**
 * Batch 27 Part 2, Task 4: distance between two points of a route measured the way the bus travels.
 *
 * route_stop.distance_from_origin_km counts from the route's first stop. An outbound leg travels that way, so
 * distance = destination - boarding. A reverse leg travels back, so distance = boarding - destination.
 * A zero or negative result means the destination is not ahead of the boarding point: callers reject it.
 * This replaces the absolute (direction-blind) difference the pricing used before.
 */
final class LegDistance
{
    public static function direction(?string $legDirection): string
    {
        return $legDirection === 'reverse' ? 'reverse' : 'outbound';
    }

    /** Signed kilometres ahead along the leg's direction. */
    public static function alongLegKm(float $boardingKm, float $alightingKm, ?string $legDirection): float
    {
        return self::direction($legDirection) === 'reverse' ? $boardingKm - $alightingKm : $alightingKm - $boardingKm;
    }

    /** Metres along the route from its first stop, for the ticket snapshot. */
    public static function toMeters(float $km): int
    {
        return (int) round($km * 1000);
    }
}
