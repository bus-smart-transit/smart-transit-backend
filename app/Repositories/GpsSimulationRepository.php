<?php

namespace App\Repositories;

use App\Models\GpsSimulation;
use App\Models\Trip;
use Illuminate\Support\Collection;

/** Batch 49 item 4: every query about GPS simulations and the trips that can be simulated. */
class GpsSimulationRepository
{
    public function find(int $id): ?GpsSimulation
    {
        return GpsSimulation::find($id);
    }

    public function findForUpdate(int $id): ?GpsSimulation
    {
        return GpsSimulation::whereKey($id)->lockForUpdate()->first();
    }

    /** The simulation that has control of this trip's position (running or paused), if any. */
    public function activeForTrip(int $tripId): ?GpsSimulation
    {
        return GpsSimulation::where('trip_id', $tripId)->whereIn('status', [GpsSimulation::RUNNING, GpsSimulation::PAUSED])->latest('id')->first();
    }

    public function create(array $attributes): GpsSimulation
    {
        return GpsSimulation::create($attributes);
    }

    /** @return Collection<int, GpsSimulation> running or paused, newest first */
    public function active(): Collection
    {
        return GpsSimulation::with('trip.fleetRoute.route', 'trip.fleetRoute.fleet')
            ->whereIn('status', [GpsSimulation::RUNNING, GpsSimulation::PAUSED])
            ->latest('id')
            ->get();
    }

    /** @return Collection<int, Trip> trips on the road now (the only ones that may be simulated) */
    public function departedTrips(): Collection
    {
        return Trip::with('fleetRoute.route', 'fleetRoute.fleet')
            ->whereIn('status', ['departed', 'in-progress'])
            ->orderBy('trip_date')
            ->orderBy('departure_time')
            ->get();
    }
}
