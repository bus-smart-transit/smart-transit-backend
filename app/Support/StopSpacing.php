<?php

namespace App\Support;

use App\Support\Geo\GeoLine;
use Illuminate\Support\Collection;

/**
 * 2026-11-06 (Batch 47 part A): is a stop's pin out of the way on a route? Stops on intercity routes can be 20 km or
 * more apart, so a fixed distance would warn too often. Instead: going from the stop before to the stop after through
 * this stop should not be much longer than going straight (config stops.detour_warning_km). A warning only; the
 * Operator decides. The first and last stop of a route have one neighbour and are not checked.
 */
class StopSpacing
{
    /**
     * Warnings for a stop at ($lat, $lng) placed at $stopOrder on a route.
     *
     * @param  Collection<int, object>  $orderedRouteStops  route_stops (with ->stop) of the route, in stop order
     * @param  int|null  $exceptStopId  the stop itself when it is already on the route
     * @return list<string>
     */
    public static function detourWarnings(Collection $orderedRouteStops, float $lat, float $lng, int $stopOrder, string $routeName, ?int $exceptStopId = null): array
    {
        $others = $orderedRouteStops
            ->filter(fn ($rs) => $exceptStopId === null || (int) $rs->stop_id !== $exceptStopId)
            ->filter(fn ($rs) => $rs->stop && $rs->stop->latitude !== null && $rs->stop->longitude !== null)
            ->values();
        $before = $others->filter(fn ($rs) => (int) $rs->stop_order < $stopOrder)->sortBy('stop_order')->last();
        $after = $others->filter(fn ($rs) => (int) $rs->stop_order >= $stopOrder)->sortBy('stop_order')->first();
        if (! $before || ! $after) {
            return [];
        }

        $a = $before->stop;
        $b = $after->stop;
        $straightKm = GeoLine::haversineM((float) $a->latitude, (float) $a->longitude, (float) $b->latitude, (float) $b->longitude) / 1000;
        $throughKm = (GeoLine::haversineM((float) $a->latitude, (float) $a->longitude, $lat, $lng)
            + GeoLine::haversineM($lat, $lng, (float) $b->latitude, (float) $b->longitude)) / 1000;
        $extraKm = $throughKm - $straightKm;

        if ($extraKm <= (float) config('stops.detour_warning_km', 3)) {
            return [];
        }

        return ["On {$routeName}, this stop adds about ".self::km($extraKm)." km between \"{$a->stop_name}\" and \"{$b->stop_name}\". Check that the pin is in the right place."];
    }

    private static function km(float $km): string
    {
        return $km >= 10 ? (string) (int) round($km) : number_format($km, 1);
    }
}
