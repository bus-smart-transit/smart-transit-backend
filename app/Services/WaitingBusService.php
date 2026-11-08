<?php

namespace App\Services;

use App\Models\Trip;
use App\Repositories\RouteStopRepository;
use App\Repositories\TripRepository;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * 2026-11-08 (user): the one owner of "which buses wait at their first stop" on the public map.
 *
 * A trip of today that has not departed shows as a still bus at the first stop of its leg from
 * tracking.waiting_bus_minutes_before (60) before its departure until the Driver presses Depart; a boarding trip shows
 * whatever its time. Future dates never show. One row per bus (its earliest such trip). The row has the same fields as
 * a live row of FleetLocationRepository::getAllActiveLocations(), with `waiting` true, `departs_at` (H:i) and
 * `waiting_at` (the stop's name). The place is the stop's stored position, never a guessed one; a route without
 * stops gives no row.
 */
class WaitingBusService
{
    public function __construct(
        private TripRepository $trips,
        private RouteStopRepository $routeStops,
    ) {}

    /**
     * @return Collection<int, object>
     */
    public function rows(?Carbon $now = null): Collection
    {
        $now = ($now ?? now())->copy()->setTimezone((string) config('app.timezone'));
        $until = $now->copy()->addMinutes(max(0, (int) config('tracking.waiting_bus_minutes_before', 60)));
        $untilTime = $until->toDateString() === $now->toDateString() ? $until->format('H:i:s') : '23:59:59';

        return $this->trips->listWaitingToDepart($now->toDateString(), $untilTime)
            ->filter(fn (Trip $trip) => $trip->fleetRoute?->fleet !== null && $trip->fleetRoute?->route_id !== null)
            ->unique(fn (Trip $trip) => (int) $trip->fleetRoute->fleet_id)
            ->map(fn (Trip $trip) => $this->rowFor($trip))
            ->filter()
            ->values();
    }

    private function rowFor(Trip $trip): ?object
    {
        $direction = $trip->leg_direction === 'reverse' ? 'reverse' : 'outbound';
        $first = $this->routeStops->getOrderedStopsForDirection((int) $trip->fleetRoute->route_id, $direction)->first();
        $stop = $first?->stop;
        if ($stop === null || $stop->latitude === null || $stop->longitude === null) {
            return null;
        }
        $fleet = $trip->fleetRoute->fleet;
        $route = $trip->fleetRoute->route;

        return (object) [
            'fleet_location_id' => null,
            'fleet_id' => (int) $fleet->fleet_id,
            'trip_id' => (int) $trip->trip_id,
            'latitude' => (float) $stop->latitude,
            'longitude' => (float) $stop->longitude,
            'heading' => null,
            'speed_kmh' => null,
            'accuracy_m' => null,
            'is_simulated' => false,
            'recorded_at' => null,
            'age_seconds' => null,
            'plate_number' => $fleet->plate_number,
            'bus_company_name' => $fleet->bus_company_name,
            'body_no' => $fleet->body_no,
            'fleet_type' => $fleet->fleet_type,
            'trip_status' => $trip->status,
            'leg_direction' => $direction,
            'last_acknowledged_stop_id' => null,
            'last_acknowledged_at' => null,
            'route_id' => (int) $trip->fleetRoute->route_id,
            'route_name' => $route?->route_name,
            'origin' => $route?->origin,
            'destination' => $route?->destination,
            'waiting' => true,
            'departs_at' => $trip->departure_time ? substr((string) $trip->departure_time, 0, 5) : null,
            'waiting_at' => $stop->stop_name,
        ];
    }
}
