<?php
namespace App\Repositories;
use App\Models\Trip;
use Illuminate\Support\Carbon;
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
        $this->markOverdueScheduledAsDelayed($operatorCompanyUserId);

        return Trip::with(['fleetRoute.route', 'fleetRoute.fleet', 'driver.user', 'conductor.user'])
            ->where('company_user_id', $operatorCompanyUserId)
            ->where('trip_date', '>=', today())
            ->orderBy('trip_date', 'asc')
            ->get();
    }

    public function listByDriver(int $driverCompanyUserId): Collection
    {
        $this->markOverdueScheduledAsDelayed();

        return Trip::with(['fleetRoute.route', 'fleetRoute.fleet', 'driver.user', 'conductor.user'])
            ->where('driver_id', $driverCompanyUserId)
            ->where('trip_date', '>=', today())
            ->orderBy('trip_date', 'asc')
            ->get();
    }

    public function listByConductor(int $conductorCompanyUserId): Collection
    {
        $this->markOverdueScheduledAsDelayed();

        return Trip::with(['fleetRoute.route.routeStops.stop', 'fleetRoute.fleet', 'driver.user', 'conductor.user'])
            ->where('conductor_id', $conductorCompanyUserId)
            ->where('trip_date', '>=', today())
            ->orderBy('trip_date', 'asc')
            ->get();
    }

    public function findCurrentByDriver(int $driverCompanyUserId): ?Trip
    {
        $this->markOverdueScheduledAsDelayed();

        return Trip::with(['fleetRoute.route', 'fleetRoute.fleet', 'driver.user', 'conductor.user'])
            ->where('driver_id', $driverCompanyUserId)
            ->whereIn('status', ['scheduled', 'delayed', 'boarding', 'departed', 'in-progress'])
            ->whereDate('trip_date', today())
            ->orderByRaw("CASE status WHEN 'in-progress' THEN 0 WHEN 'departed' THEN 1 WHEN 'boarding' THEN 2 WHEN 'delayed' THEN 3 WHEN 'scheduled' THEN 4 ELSE 5 END")
            ->orderBy('trip_date', 'asc')
            ->first();
    }

    public function findCurrentByConductor(int $conductorCompanyUserId): ?Trip
    {
        $this->markOverdueScheduledAsDelayed();

        return Trip::with(['fleetRoute.route.routeStops.stop', 'fleetRoute.fleet', 'driver.user', 'conductor.user'])
            ->where('conductor_id', $conductorCompanyUserId)
            ->whereIn('status', ['scheduled', 'delayed', 'boarding', 'departed', 'in-progress'])
            ->whereDate('trip_date', today())
            ->orderByRaw("CASE status WHEN 'in-progress' THEN 0 WHEN 'departed' THEN 1 WHEN 'boarding' THEN 2 WHEN 'delayed' THEN 3 WHEN 'scheduled' THEN 4 ELSE 5 END")
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

    public function updateDispatchDecision(int $tripId, string $decision, ?string $route = null, ?string $reason = null): bool
    {
        return Trip::where('trip_id', $tripId)->update([
            'dispatch_decision' => $decision,
            'dispatch_route' => $route,
            'dispatch_reason' => $reason,
            'dispatch_decided_at' => now(),
        ]) > 0;
    }

    public function findTodayByDriver(int $driverCompanyUserId): ?Trip
    {
        $this->markOverdueScheduledAsDelayed();

        return Trip::with(['fleetRoute.fleet', 'fleetRoute.route'])
            ->where('driver_id', $driverCompanyUserId)
            ->whereDate('trip_date', today())
            ->whereIn('status', ['scheduled', 'delayed', 'boarding', 'departed', 'in-progress'])
            ->orderByRaw("CASE status WHEN 'in-progress' THEN 0 WHEN 'departed' THEN 1 WHEN 'boarding' THEN 2 WHEN 'delayed' THEN 3 WHEN 'scheduled' THEN 4 ELSE 5 END")
            ->first();
    }

    public function findTodayByConductor(int $conductorCompanyUserId): ?Trip
    {
        $this->markOverdueScheduledAsDelayed();

        return Trip::with(['fleetRoute.fleet', 'fleetRoute.route'])
            ->where('conductor_id', $conductorCompanyUserId)
            ->whereDate('trip_date', today())
            ->whereIn('status', ['scheduled', 'delayed', 'boarding', 'departed', 'in-progress'])
            ->orderByRaw("CASE status WHEN 'in-progress' THEN 0 WHEN 'departed' THEN 1 WHEN 'boarding' THEN 2 WHEN 'delayed' THEN 3 WHEN 'scheduled' THEN 4 ELSE 5 END")
            ->first();
    }

    public function findCurrentOrUpcomingByDriver(int $driverCompanyUserId): ?Trip
    {
        $this->markOverdueScheduledAsDelayed();

        return Trip::with(['fleetRoute.fleet', 'fleetRoute.route'])
            ->where('driver_id', $driverCompanyUserId)
            ->whereIn('status', ['scheduled', 'delayed', 'boarding', 'departed', 'in-progress'])
            ->whereDate('trip_date', '>=', today())
            ->orderBy('trip_date', 'asc')
            ->orderByRaw("CASE status WHEN 'in-progress' THEN 0 WHEN 'departed' THEN 1 WHEN 'boarding' THEN 2 WHEN 'delayed' THEN 3 WHEN 'scheduled' THEN 4 ELSE 5 END")
            ->first();
    }

    public function findCurrentOrUpcomingByConductor(int $conductorCompanyUserId): ?Trip
    {
        $this->markOverdueScheduledAsDelayed();

        return Trip::with(['fleetRoute.fleet', 'fleetRoute.route'])
            ->where('conductor_id', $conductorCompanyUserId)
            ->whereIn('status', ['scheduled', 'delayed', 'boarding', 'departed', 'in-progress'])
            ->whereDate('trip_date', '>=', today())
            ->orderBy('trip_date', 'asc')
            ->orderByRaw("CASE status WHEN 'in-progress' THEN 0 WHEN 'departed' THEN 1 WHEN 'boarding' THEN 2 WHEN 'delayed' THEN 3 WHEN 'scheduled' THEN 4 ELSE 5 END")
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
        $this->markOverdueScheduledAsDelayed();

        $with = [
            'fleetRoute.fleet',
            'fleetRoute.route',
        ];

        if ($includeStops) {
            $with[] = 'fleetRoute.route.routeStops.stop';
        }

        return Trip::query()
            ->whereIn('status', ['scheduled', 'delayed', 'boarding'])
            ->where('trip_date', '>=', now()->toDateString())
            ->with($with)
            ->orderBy('trip_date', 'asc')
            ->get();
    }

    public function markOverdueScheduledAsDelayed(?int $operatorCompanyUserId = null): int
    {
        $query = Trip::with('fleetRoute')
            ->whereDate('trip_date', today())
            ->where('status', 'scheduled');

        if ($operatorCompanyUserId !== null) {
            $query->where('company_user_id', $operatorCompanyUserId);
        }

        $timezone = config('app.timezone', 'UTC');
        $now = Carbon::now($timezone);
        $updated = 0;

        $query->chunkById(100, function ($trips) use ($now, $timezone, &$updated) {
            foreach ($trips as $trip) {
                $departureTime = $trip->departure_time ?: $trip->fleetRoute?->start_time;
                if (!$departureTime) {
                    continue;
                }

                $scheduledAt = Carbon::parse($trip->trip_date->toDateString() . ' ' . $departureTime, $timezone);
                if ($scheduledAt->lte($now)) {
                    $updated += Trip::where('trip_id', $trip->trip_id)
                        ->where('status', 'scheduled')
                        ->update(['status' => 'delayed']);
                }
            }
        }, 'trip_id', 'trip_id');

        return $updated;
    }
}