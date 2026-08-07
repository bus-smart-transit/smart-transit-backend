<?php
namespace App\Repositories;
use App\Models\Trip;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

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
        // Single atomic UPDATE — avoids SELECT+UPDATE race condition under concurrent scans
        return Trip::where('trip_id', $tripId)->update([
            'current_seated_capacity'   => DB::raw("current_seated_capacity + {$seatedDelta}"),
            'current_standing_capacity' => DB::raw("current_standing_capacity + {$standingDelta}"),
            'total_occupancy'           => DB::raw("total_occupancy + {$seatedDelta} + {$standingDelta}"),
        ]) > 0;
    }

    /**
     * Inverse of incrementOccupancy() — releases previously held capacity.
     * Floored at 0 so a duplicate release call can't push counts negative.
     */
    public function decrementOccupancy(int $tripId, int $seatedDelta, int $standingDelta): bool
    {
        return Trip::where('trip_id', $tripId)->update([
            'current_seated_capacity'   => DB::raw("GREATEST(0, current_seated_capacity - {$seatedDelta})"),
            'current_standing_capacity' => DB::raw("GREATEST(0, current_standing_capacity - {$standingDelta})"),
            'total_occupancy'           => DB::raw("GREATEST(0, current_seated_capacity - {$seatedDelta}) + GREATEST(0, current_standing_capacity - {$standingDelta})"),
        ]) > 0;
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

    /**
     * All trips currently bookable by passengers.
     * Used by the public GET /trips endpoint.
     */
    public function listAvailableForPassengers(bool $includeStops = true): \Illuminate\Support\Collection
    {
        $with = [
            'fleetRoute.fleet',
            'fleetRoute.route',
        ];

        if ($includeStops) {
            $with[] = 'fleetRoute.route.routeStops.stop';
        }

        return Trip::query()
            ->whereIn('status', ['scheduled', 'boarding'])
            ->where('trip_date', '>=', now()->toDateString())
            ->with($with)
            ->orderBy('trip_date', 'asc')
            ->get();
    }
}