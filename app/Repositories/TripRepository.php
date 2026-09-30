<?php

namespace App\Repositories;

use App\Models\Trip;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class TripRepository
{
    // Which of a staff member's same-day trips is "current": live states first,
    // then overdue, then upcoming; ties broken by departure_time then trip_id
    // so the earliest unfinished shift-block leg is always the one picked.
    private const CURRENT_LEG_STATUS_ORDER = "CASE status WHEN 'in-progress' THEN 0 WHEN 'departed' THEN 1 WHEN 'boarding' THEN 2 WHEN 'delayed' THEN 3 WHEN 'scheduled' THEN 4 ELSE 5 END";

    private const ACTIVE_STATUSES = ['scheduled', 'delayed', 'boarding', 'departed', 'in-progress'];

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

    public function listUpcomingByOperator(int $operatorCompanyUserId, string $statusFilter = 'all'): Collection
    {
        $this->markOverdueScheduledAsDelayed($operatorCompanyUserId);

        $today = $this->serviceToday();

        $query = Trip::with(['fleetRoute.route', 'fleetRoute.fleet', 'driver.user', 'conductor.user', 'assignmentRequests'])
            ->where('company_user_id', $operatorCompanyUserId)
            ->orderBy('trip_date', 'asc')
            ->orderBy('departure_time', 'asc');

        $normalized = $this->normalizeStatusFilter($statusFilter);

        if ($normalized === 'completed') {
            return $query->where('status', 'completed')->get()->values();
        }

        if ($normalized === 'scheduled') {
            return $query
                ->whereIn('status', ['scheduled', 'delayed', 'boarding', 'departed', 'in-progress'])
                ->whereDate('trip_date', '>=', $today)
                ->get()
                ->values();
        }

        if ($normalized === 'all') {
            return $query->get()->values();
        }

        $trips = $query
            ->whereIn('status', ['scheduled', 'delayed', 'boarding', 'departed', 'in-progress'])
            ->get();

        return $trips->filter(function (Trip $trip) {
            return $this->isUpcomingScheduledAt($trip);
        })->values();
    }

    public function listByDriver(int $driverCompanyUserId, string $statusFilter = 'all'): Collection
    {
        $this->markOverdueScheduledAsDelayed();

        $today = $this->serviceToday();

        $query = Trip::with(['fleetRoute.route', 'fleetRoute.fleet', 'driver.user', 'conductor.user', 'assignmentRequests'])
            ->where('driver_id', $driverCompanyUserId)
            ->orderBy('trip_date', 'asc')
            ->orderBy('departure_time', 'asc');

        $this->applyStatusFilter($query, $statusFilter, $today);

        return $query->get();
    }

    public function listByConductor(int $conductorCompanyUserId, string $statusFilter = 'all'): Collection
    {
        $this->markOverdueScheduledAsDelayed();

        $today = $this->serviceToday();

        $query = Trip::with(['fleetRoute.route.routeStops.stop', 'fleetRoute.fleet', 'driver.user', 'conductor.user', 'assignmentRequests'])
            ->where('conductor_id', $conductorCompanyUserId)
            ->orderBy('trip_date', 'asc')
            ->orderBy('departure_time', 'asc');

        $this->applyStatusFilter($query, $statusFilter, $today);

        return $query->get();
    }

    public function findCurrentByDriver(int $driverCompanyUserId): ?Trip
    {
        $this->markOverdueScheduledAsDelayed();

        return Trip::with(['fleetRoute.route', 'fleetRoute.fleet', 'driver.user', 'conductor.user', 'assignmentRequests'])
            ->where('driver_id', $driverCompanyUserId)
            ->whereIn('status', ['scheduled', 'delayed', 'boarding', 'departed', 'in-progress'])
            ->whereDate('trip_date', $this->serviceToday())
            ->orderByRaw(self::CURRENT_LEG_STATUS_ORDER)
            ->orderBy('trip_date', 'asc')
            ->orderBy('departure_time', 'asc')
            ->orderBy('trip_id', 'asc')
            ->first();
    }

    public function findCurrentByConductor(int $conductorCompanyUserId): ?Trip
    {
        $this->markOverdueScheduledAsDelayed();

        return Trip::with(['fleetRoute.route.routeStops.stop', 'fleetRoute.fleet', 'driver.user', 'conductor.user', 'assignmentRequests'])
            ->where('conductor_id', $conductorCompanyUserId)
            ->whereIn('status', ['scheduled', 'delayed', 'boarding', 'departed', 'in-progress'])
            ->whereDate('trip_date', $this->serviceToday())
            ->orderByRaw(self::CURRENT_LEG_STATUS_ORDER)
            ->orderBy('trip_date', 'asc')
            ->orderBy('departure_time', 'asc')
            ->orderBy('trip_id', 'asc')
            ->first();
    }

    /**
     * Legs of a shift block that are neither completed nor cancelled.
     */
    public function countUnfinishedLegs(int $shiftBlockId): int
    {
        return Trip::where('shift_block_id', $shiftBlockId)
            ->whereNotIn('status', ['completed', 'cancelled'])
            ->count();
    }

    public function updateStatus(int $tripId, string $status): bool
    {
        $update = ['status' => $status];

        // B5: server-side timestamps, set only on the first transition into a state.
        $stamp = ['boarding' => 'boarding_at', 'departed' => 'departed_at', 'completed' => 'completed_at'][$status] ?? null;
        if ($stamp !== null) {
            Trip::where('trip_id', $tripId)->whereNull($stamp)->update([$stamp => now()]);
        }

        return Trip::where('trip_id', $tripId)->update($update) > 0;
    }

    /**
     * IDOR guard: does this trip belong to the given operator?
     * Moved here from inline Trip::where()->exists() checks duplicated
     * across several controllers (TripController, FleetLocationController).
     */
    public function isOwnedByOperator(int $tripId, int $companyUserId): bool
    {
        return Trip::where('trip_id', $tripId)
            ->where('company_user_id', $companyUserId)
            ->exists();
    }

    public function assignDriver(int $tripId, int $driverCompanyUserId): bool
    {
        // Batch 15, Item 1: reassigning the driver (including re-assigning
        // the SAME driver after a decline/clear) always resets acceptance —
        // a driver must explicitly accept every assignment, not just the
        // first one they were ever given for this trip.
        return Trip::where('trip_id', $tripId)
            ->update(['driver_id' => $driverCompanyUserId, 'driver_accepted_at' => null]) > 0;
    }

    public function assignConductor(int $tripId, int $conductorCompanyUserId): bool
    {
        return Trip::where('trip_id', $tripId)
            ->update(['conductor_id' => $conductorCompanyUserId, 'conductor_accepted_at' => null]) > 0;
    }

    /**
     * S2 corrected model (Batch 17 continuation), superseded by Batch 18's
     * shift_blocks system: a staff member (driver OR conductor) may only
     * hold ONE assignment per day — hard exclusivity, not a time-overlap
     * check. This also transparently covers shift-block legs, since each
     * leg is itself a `trips` row with driver_id/conductor_id populated
     * (see ShiftBlockService::createBlock()) — no separate join needed.
     * The reverse direction (a shift block being created for a staff
     * member who already holds a plain trip that day) is checked in
     * ShiftBlockService via this same method.
     */
    public function hasAnySameDayAssignment(string $role, int $companyUserId, string $tripDate, ?int $excludeTripId = null): bool
    {
        $column = $role === 'driver' ? 'driver_id' : 'conductor_id';

        $query = Trip::where($column, $companyUserId)
            ->whereDate('trip_date', $tripDate)
            ->whereNotIn('status', ['cancelled']);

        if ($excludeTripId) {
            $query->where('trip_id', '!=', $excludeTripId);
        }

        return $query->exists();
    }

    /**
     * S2 (Batch 12): a driver/conductor declining their assignment returns
     * the trip to the unassigned pool for the Operator to manually
     * reassign — the trip itself keeps its current status, only the
     * declined role's assignment is cleared.
     */
    public function clearDriver(int $tripId): bool
    {
        return Trip::where('trip_id', $tripId)
            ->update(['driver_id' => null, 'driver_accepted_at' => null]) > 0;
    }

    public function clearConductor(int $tripId): bool
    {
        return Trip::where('trip_id', $tripId)
            ->update(['conductor_id' => null, 'conductor_accepted_at' => null]) > 0;
    }

    /**
     * Batch 15, Item 1: explicit Accept counterpart to decline/clear above.
     * Does not touch driver_id/conductor_id — the assignment already exists;
     * this only records the staff member's acknowledgement of it.
     */
    public function markDriverAccepted(int $tripId): bool
    {
        return Trip::where('trip_id', $tripId)->update(['driver_accepted_at' => now()]) > 0;
    }

    public function markConductorAccepted(int $tripId): bool
    {
        return Trip::where('trip_id', $tripId)->update(['conductor_accepted_at' => now()]) > 0;
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
            ->whereDate('trip_date', $this->serviceToday())
            ->whereIn('status', ['scheduled', 'delayed', 'boarding', 'departed', 'in-progress'])
            ->orderByRaw(self::CURRENT_LEG_STATUS_ORDER)
            ->orderBy('departure_time', 'asc')
            ->orderBy('trip_id', 'asc')
            ->first();
    }

    public function findTodayByConductor(int $conductorCompanyUserId): ?Trip
    {
        $this->markOverdueScheduledAsDelayed();

        return Trip::with(['fleetRoute.fleet', 'fleetRoute.route'])
            ->where('conductor_id', $conductorCompanyUserId)
            ->whereDate('trip_date', $this->serviceToday())
            ->whereIn('status', ['scheduled', 'delayed', 'boarding', 'departed', 'in-progress'])
            ->orderByRaw(self::CURRENT_LEG_STATUS_ORDER)
            ->orderBy('departure_time', 'asc')
            ->orderBy('trip_id', 'asc')
            ->first();
    }

    public function findCurrentOrUpcomingByDriver(int $driverCompanyUserId): ?Trip
    {
        $this->markOverdueScheduledAsDelayed();

        return Trip::with(['fleetRoute.fleet', 'fleetRoute.route'])
            ->where('driver_id', $driverCompanyUserId)
            ->whereIn('status', ['scheduled', 'delayed', 'boarding', 'departed', 'in-progress'])
            ->whereDate('trip_date', '>=', $this->serviceToday())
            ->orderBy('trip_date', 'asc')
            ->orderByRaw(self::CURRENT_LEG_STATUS_ORDER)
            ->orderBy('departure_time', 'asc')
            ->orderBy('trip_id', 'asc')
            ->first();
    }

    public function findCurrentOrUpcomingByConductor(int $conductorCompanyUserId): ?Trip
    {
        $this->markOverdueScheduledAsDelayed();

        return Trip::with(['fleetRoute.fleet', 'fleetRoute.route'])
            ->where('conductor_id', $conductorCompanyUserId)
            ->whereIn('status', ['scheduled', 'delayed', 'boarding', 'departed', 'in-progress'])
            ->whereDate('trip_date', '>=', $this->serviceToday())
            ->orderBy('trip_date', 'asc')
            ->orderByRaw(self::CURRENT_LEG_STATUS_ORDER)
            ->orderBy('departure_time', 'asc')
            ->orderBy('trip_id', 'asc')
            ->first();
    }

    public function incrementOccupancy(int $tripId, int $seatedDelta, int $standingDelta): bool
    {
        // Single atomic UPDATE — avoids SELECT+UPDATE race condition under concurrent scans
        return Trip::where('trip_id', $tripId)->update([
            'current_seated_capacity' => DB::raw("current_seated_capacity + {$seatedDelta}"),
            'current_standing_capacity' => DB::raw("current_standing_capacity + {$standingDelta}"),
            'total_occupancy' => DB::raw("total_occupancy + {$seatedDelta} + {$standingDelta}"),
        ]) > 0;
    }

    /**
     * Inverse of incrementOccupancy() — releases previously held capacity.
     * Floored at 0 so a duplicate release call can't push counts negative.
     */
    public function decrementOccupancy(int $tripId, int $seatedDelta, int $standingDelta): bool
    {
        return Trip::where('trip_id', $tripId)->update([
            'current_seated_capacity' => DB::raw("GREATEST(0, current_seated_capacity - {$seatedDelta})"),
            'current_standing_capacity' => DB::raw("GREATEST(0, current_standing_capacity - {$standingDelta})"),
            'total_occupancy' => DB::raw("GREATEST(0, current_seated_capacity - {$seatedDelta}) + GREATEST(0, current_standing_capacity - {$standingDelta})"),
        ]) > 0;
    }

    /**
     * Update the last acknowledged stop for a trip
     */
    public function updateLastAcknowledgedStop(int $tripId, int $stopId): bool
    {
        return Trip::where('trip_id', $tripId)->update([
            'last_acknowledged_stop_id' => $stopId,
            'last_acknowledged_at' => now(),
        ]) > 0;
    }

    /**
     * All trips currently bookable by passengers.
     * Used by the public GET /trips endpoint.
     */
    public function listAvailableForPassengers(bool $includeStops = true, ?string $tripDate = null): Collection
    {
        $this->markOverdueScheduledAsDelayed();

        $today = $this->serviceToday();

        $with = [
            'fleetRoute.fleet',
            'fleetRoute.fleet.fareRules',
            'fleetRoute.route',
        ];

        if ($includeStops) {
            $with[] = 'fleetRoute.route.routeStops.stop';
        }

        $query = Trip::query()
            ->whereIn('status', ['scheduled', 'delayed', 'boarding'])
            ->with($with)
            ->orderBy('trip_date', 'asc');

        if ($tripDate) {
            $query->whereDate('trip_date', $tripDate);
        } else {
            $query->whereDate('trip_date', '>=', $today);
        }

        return $query->get();
    }

    private function applyStatusFilter($query, string $statusFilter, Carbon $today): void
    {
        $normalized = $this->normalizeStatusFilter($statusFilter);

        if ($normalized === 'completed') {
            $query->where('status', 'completed');

            return;
        }

        if ($normalized === 'scheduled') {
            $query->whereIn('status', self::ACTIVE_STATUSES)
                ->whereDate('trip_date', '>=', $today);
        }
    }

    private function isUpcomingScheduledAt(Trip $trip): bool
    {
        $timezone = config('app.timezone', 'Asia/Manila');
        $scheduledAt = $this->resolveScheduledAt($trip, $timezone);

        if ($scheduledAt === null) {
            return true;
        }

        return $scheduledAt->greaterThanOrEqualTo(Carbon::now($timezone));
    }

    private function resolveScheduledAt(Trip $trip, ?string $timezone = null): ?Carbon
    {
        $timezone ??= config('app.timezone', 'Asia/Manila');

        $tripDate = $trip->trip_date;
        $departureTime = $trip->departure_time ?: $trip->fleetRoute?->start_time;

        if (! $tripDate || ! $departureTime) {
            return null;
        }

        $dateString = $tripDate instanceof Carbon
            ? $tripDate->format('Y-m-d')
            : Carbon::parse((string) $tripDate)->format('Y-m-d');

        foreach (['!Y-m-d H:i:s', '!Y-m-d H:i'] as $format) {
            try {
                $parsed = Carbon::createFromFormat($format, $dateString.' '.$departureTime, $timezone);
                if ($parsed !== false) {
                    return $parsed;
                }
            } catch (\Throwable) {
                continue;
            }
        }

        return null;
    }

    private function normalizeStatusFilter(string $statusFilter): string
    {
        return match (strtolower(trim($statusFilter))) {
            'upcoming' => 'upcoming',
            'scheduled', 'active', 'scheduled-active' => 'scheduled',
            'completed' => 'completed',
            'all' => 'all',
            default => 'all',
        };
    }

    private function serviceToday(): Carbon
    {
        return Carbon::today(config('app.timezone', 'Asia/Manila'));
    }

    /**
     * Trips already flipped to 'delayed' (by any of the read-time call
     * sites above) that have not yet had a passenger notification sent.
     * Scoped to today only — a trip's delayed notification is only ever
     * relevant same-day.
     */
    public function findDelayedTripsPendingNotification(): Collection
    {
        return Trip::with('fleetRoute.route')
            ->where('status', 'delayed')
            ->whereNull('delay_notified_at')
            ->whereDate('trip_date', $this->serviceToday())
            ->get();
    }

    /**
     * Atomically claims a trip's delayed-notification slot. The `whereNull`
     * guard means only one caller can ever succeed for a given trip, making
     * this safe to call from an overlapping scheduled command run.
     */
    public function markDelayNotified(int $tripId): bool
    {
        return Trip::where('trip_id', $tripId)
            ->whereNull('delay_notified_at')
            ->update(['delay_notified_at' => now()]) > 0;
    }

    public function markOverdueScheduledAsDelayed(?int $operatorCompanyUserId = null): int
    {
        $query = Trip::with('fleetRoute')
            ->whereDate('trip_date', $this->serviceToday())
            ->where('status', 'scheduled');

        if ($operatorCompanyUserId !== null) {
            $query->where('company_user_id', $operatorCompanyUserId);
        }

        $timezone = config('app.timezone', 'Asia/Manila');
        $now = Carbon::now($timezone);
        $updated = 0;

        $query->chunkById(100, function ($trips) use ($now, $timezone, &$updated) {
            foreach ($trips as $trip) {
                $scheduledAt = $this->resolveScheduledAt($trip, $timezone);
                if ($scheduledAt === null) {
                    continue;
                }

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
