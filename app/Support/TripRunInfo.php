<?php

namespace App\Support;

use App\Models\RouteStop;
use App\Models\Trip;
use Illuminate\Support\Collection;

/**
 * Batch 28 Stage 3, item 8: two facts about a bus run, derived from stored data wherever a trip is shown.
 *
 *  RUN PATTERN  "One-way run" or "Round-trip run" (deliberately NOT worded like a passenger's roundtrip booking).
 *    The operator's schedule field is trips.trip_type (one_way | round_trip, required when a standalone trip is
 *    scheduled). The legs of a shift block are all stamped one_way by the block creator, so for a leg of a block the
 *    pattern is derived from the block instead: one leg = one-way run; more than one leg = round-trip run.
 *    No trip_type and no block = nothing is shown (never invented).
 *
 *  SERVICE  Regular = stops at every stopping-type stop. Express = serves only the stops marked served_by_express.
 *    "Non-stop" only when the stops an express trip serves are just terminals (derived from stop_type, no stored
 *    flag). The served stops are listed so a passenger sees where an express actually stops.
 */
final class TripRunInfo
{
    public const ONE_WAY = 'one_way';
    public const ROUND_TRIP = 'round_trip';

    /** @return array{key: string, label: string, source: string}|null */
    public static function runPattern(?string $tripType, ?int $shiftBlockId, ?int $blockLegCount): ?array
    {
        if ($shiftBlockId !== null && $blockLegCount !== null && $blockLegCount > 0) {
            return self::pattern($blockLegCount > 1 ? self::ROUND_TRIP : self::ONE_WAY, 'shift_block');
        }
        if (in_array($tripType, [self::ONE_WAY, self::ROUND_TRIP], true)) {
            return self::pattern($tripType, 'trip_type');
        }

        return null;
    }

    /**
     * @param  iterable<int, RouteStop>  $routeStops  the route's stops in the trip's travel direction
     * @return array{type: string, label: string, non_stop: bool, served_stops: array<int, array{stop_id: int, name: string|null, stop_type: string|null}>}
     */
    public static function service(?string $serviceType, iterable $routeStops): array
    {
        $type = ServiceType::normalize($serviceType);
        if ($type === ServiceType::REGULAR) {
            return ['type' => $type, 'label' => 'Regular', 'non_stop' => false, 'served_stops' => []];
        }

        $served = collect($routeStops)
            ->filter(fn (RouteStop $rs) => ServiceType::isServed($type, $rs))
            ->map(fn (RouteStop $rs) => ['stop_id' => (int) $rs->stop_id, 'name' => $rs->stop?->stop_name, 'stop_type' => $rs->stop_type])
            ->values();

        $nonStop = $served->isNotEmpty() && $served->every(fn ($s) => $s['stop_type'] === 'terminal');

        return [
            'type' => $type,
            'label' => $nonStop ? 'Express - Non-stop' : 'Express',
            'non_stop' => $nonStop,
            'served_stops' => $served->all(),
        ];
    }

    /**
     * One query per kind for a whole list: attaches `run_info` ({run_pattern, service}) to every trip.
     *
     * @param  iterable<int, Trip>  $trips
     */
    public static function attach(iterable $trips): void
    {
        $trips = collect($trips)->filter(fn ($t) => $t instanceof Trip)->values();
        if ($trips->isEmpty()) {
            return;
        }

        $blockIds = $trips->pluck('shift_block_id')->filter()->unique()->values();
        $legCounts = $blockIds->isEmpty()
            ? collect()
            : Trip::whereIn('shift_block_id', $blockIds)->selectRaw('shift_block_id, count(*) as legs')->groupBy('shift_block_id')->pluck('legs', 'shift_block_id');

        $trips->each(fn (Trip $t) => $t->loadMissing('fleetRoute'));
        $routeIds = $trips->map(fn (Trip $t) => $t->fleetRoute?->route_id)->filter()->unique()->values();
        $stopsByRoute = $routeIds->isEmpty()
            ? collect()
            : RouteStop::with('stop')->whereIn('route_id', $routeIds)->orderBy('stop_order')->get()->groupBy('route_id');

        foreach ($trips as $trip) {
            $stops = self::inDirection($stopsByRoute->get($trip->fleetRoute?->route_id, collect()), $trip->leg_direction);
            $trip->setAttribute('run_info', [
                'run_pattern' => self::runPattern($trip->trip_type, $trip->shift_block_id ? (int) $trip->shift_block_id : null, $trip->shift_block_id ? (int) ($legCounts[$trip->shift_block_id] ?? 0) : null),
                'service' => self::service($trip->service_type, $stops),
            ]);
        }
    }

    /** @return array{run_pattern: array|null, service: array} */
    public static function forTrip(Trip $trip): array
    {
        self::attach([$trip]);

        return $trip->getAttribute('run_info');
    }

    /** The stops of a route in the travel direction of a leg. @return Collection<int, RouteStop> */
    public static function inDirection(Collection $stops, ?string $legDirection): Collection
    {
        $ordered = $stops->sortBy('stop_order')->values();

        return LegDistance::direction($legDirection) === 'reverse' ? $ordered->reverse()->values() : $ordered;
    }

    /** @return array{key: string, label: string, source: string} */
    private static function pattern(string $key, string $source): array
    {
        return ['key' => $key, 'label' => $key === self::ROUND_TRIP ? 'Round-trip run' : 'One-way run', 'source' => $source];
    }
}
