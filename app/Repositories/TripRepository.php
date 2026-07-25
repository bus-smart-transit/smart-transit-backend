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

    /**
     * Same as findById(), but locks the row (SELECT ... FOR UPDATE) so
     * concurrent capacity checks against the same trip serialize instead of
     * racing. Must be called inside a DB::transaction().
     */
    public function findByIdForUpdate(int $tripId): ?Trip
    {
        return Trip::with('fleetRoute.fleet')->lockForUpdate()->find($tripId);
    }

    public function listForDate(string $date): Collection
    {
        return Trip::with('fleetRoute')->where('trip_date', $date)->get();
    }

    public function listUpcomingByOperator(int $operatorCompanyUserId): Collection
    {
        return Trip::with(['fleetRoute.route', 'fleetRoute.fleet'])
            ->where('company_user_id', $operatorCompanyUserId)
            ->where('trip_date', '>=', today())
            ->orderBy('trip_date', 'asc')
            ->get();
    }

    public function listByDriver(int $driverCompanyUserId): Collection
    {
        return Trip::with(['fleetRoute.route', 'fleetRoute.fleet'])
            ->where('driver_id', $driverCompanyUserId)
            ->where('trip_date', '>=', today())
            ->orderBy('trip_date', 'asc')
            ->get();
    }

    public function listByConductor(int $conductorCompanyUserId): Collection
    {
        return Trip::with(['fleetRoute.route.routeStops.stop', 'fleetRoute.fleet'])
            ->where('conductor_id', $conductorCompanyUserId)
            ->where('trip_date', '>=', today())
            ->orderBy('trip_date', 'asc')
            ->get();
    }

    public function findCurrentByDriver(int $driverCompanyUserId): ?Trip
    {
        return Trip::with(['fleetRoute.route', 'fleetRoute.fleet'])
            ->where('driver_id', $driverCompanyUserId)
            ->whereIn('status', ['scheduled', 'boarding', 'departed', 'in-progress'])
            ->whereDate('trip_date', today())
            ->orderByRaw("CASE status WHEN 'in-progress' THEN 0 WHEN 'departed' THEN 1 WHEN 'boarding' THEN 2 WHEN 'scheduled' THEN 3 ELSE 4 END")
            ->orderBy('trip_date', 'asc')
            ->first();
    }

    public function findCurrentByConductor(int $conductorCompanyUserId): ?Trip
    {
        return Trip::with(['fleetRoute.route.routeStops.stop', 'fleetRoute.fleet'])
            ->where('conductor_id', $conductorCompanyUserId)
            ->whereIn('status', ['scheduled', 'boarding', 'departed', 'in-progress'])
            ->whereDate('trip_date', today())
            ->orderByRaw("CASE status WHEN 'in-progress' THEN 0 WHEN 'departed' THEN 1 WHEN 'boarding' THEN 2 WHEN 'scheduled' THEN 3 ELSE 4 END")
            ->orderBy('trip_date', 'asc')
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

    public function findTodayByDriver(int $driverCompanyUserId): ?Trip
    {
        return Trip::with(['fleetRoute.fleet', 'fleetRoute.route'])
            ->where('driver_id', $driverCompanyUserId)
            ->where('trip_date', today())
            ->first();
    }

    public function findTodayByConductor(int $conductorCompanyUserId): ?Trip
    {
        return Trip::with(['fleetRoute.fleet', 'fleetRoute.route'])
            ->where('conductor_id', $conductorCompanyUserId)
            ->where('trip_date', today())
            ->first();
    }

    public function incrementOccupancy(int $tripId, int $seatedDelta, int $standingDelta): bool
    {
        $trip = Trip::find($tripId);
        if (!$trip)
            return false;
        $trip->current_seated_capacity += $seatedDelta;
        $trip->current_standing_capacity += $standingDelta;
        $trip->total_occupancy = $trip->current_seated_capacity + $trip->current_standing_capacity;
        return $trip->save();
    }

    /**
     * Inverse of incrementOccupancy() — releases previously held capacity.
     * Floored at 0 so a duplicate release call can't push counts negative.
     */
    public function decrementOccupancy(int $tripId, int $seatedDelta, int $standingDelta): bool
    {
        $trip = Trip::find($tripId);
        if (!$trip)
            return false;
        $trip->current_seated_capacity = max(0, $trip->current_seated_capacity - $seatedDelta);
        $trip->current_standing_capacity = max(0, $trip->current_standing_capacity - $standingDelta);
        $trip->total_occupancy = $trip->current_seated_capacity + $trip->current_standing_capacity;
        return $trip->save();
    }

    /**
     * Update the last acknowledged stop for a trip
     */
    public function updateLastAcknowledgedStop(int $tripId, int $stopId): bool
    {
        return Trip::where('trip_id', $tripId)->update([
            'last_acknowledged_stop_id' => $stopId,
            'last_acknowledged_at' => now()
        ]) > 0;
    }
}