<?php

namespace App\Repositories;

use App\Models\Trip;
use Illuminate\Support\Collection;

class TripRepository
{
    public function create(array $payload): Trip
    {
        return Trip::create($payload);
    }

    public function findById(int $tripId): ?Trip
    {
        return Trip::with('fleetRoute.fleet')->find($tripId);
    }

    public function findActiveByFleetRoute(int $fleetRouteId): ?Trip
    {
        return Trip::where('fleet_route_id', $fleetRouteId)
            ->whereIn('status', ['scheduled', 'boarding'])
            ->latest('trip_date')
            ->first();
    }

    public function listForDate(string $date): Collection
    {
        return Trip::with('fleetRoute')->where('trip_date', $date)->get();
    }

    public function listByDriver(int $driverCompanyUserId): Collection
    {
        return Trip::with(['fleetRoute.route', 'fleetRoute.fleet'])
            ->where('driver_id', $driverCompanyUserId)
            ->where('trip_date', today())
            ->get();
    }

    public function findCurrentByDriver(int $driverCompanyUserId): ?Trip
    {
        return Trip::with(['fleetRoute.route', 'fleetRoute.fleet'])
            ->where('driver_id', $driverCompanyUserId)
            ->whereIn('status', ['boarding', 'departed'])
            ->where('trip_date', today())
            ->first();
    }

    public function findCurrentByConductor(int $conductorCompanyUserId): ?Trip
    {
        return Trip::with(['fleetRoute.route', 'fleetRoute.fleet'])
            ->where('conductor_id', $conductorCompanyUserId)
            ->whereIn('status', ['boarding', 'departed'])
            ->where('trip_date', today())
            ->first();
    }

    public function updateStatus(int $tripId, string $status): bool
    {
        return Trip::where('trip_id', $tripId)->update(['status' => $status]) > 0;
    }

    public function assignDriver(int $tripId, int $driverCompanyUserId): bool
    {
        return Trip::where('trip_id', $tripId)
            ->update(['driver_id' => $driverCompanyUserId]) > 0;
    }

    public function assignConductor(int $tripId, int $conductorCompanyUserId): bool
    {
        return Trip::where('trip_id', $tripId)
            ->update(['conductor_id' => $conductorCompanyUserId]) > 0;
    }

    public function incrementOccupancy(int $tripId, int $seatedDelta, int $standingDelta): bool
    {
        $trip = Trip::find($tripId);
        if (!$trip) {
            return false;
        }

        $trip->current_seated_capacity += $seatedDelta;
        $trip->current_standing_capacity += $standingDelta;
        $trip->total_occupancy = $trip->current_seated_capacity + $trip->current_standing_capacity;

        return $trip->save();
    }
}
