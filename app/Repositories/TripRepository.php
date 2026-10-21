<?php

namespace App\Repositories;

use App\Models\Trip;
use Illuminate\Pagination\LengthAwarePaginator;
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

    // How far back a staff member's "all trips" list reaches; older trips are in the "completed" history.
    private const ASSIGNED_RECENT_DAYS = 30;

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

    public function listUpcomingByOperator(int $operatorCompanyUserId, string $statusFilter = 'all', int $page = 1, int $perPage = 100): LengthAwarePaginator
    {
        $today = $this->serviceToday();

        $query = Trip::with(['fleetRoute.route', 'fleetRoute.fleet', 'driver.user', 'conductor.user', 'assignmentRequests'])
            ->where('company_user_id', $operatorCompanyUserId)
            ->orderBy('trip_date', 'asc')
            ->orderBy('departure_time', 'asc');

        $normalized = $this->normalizeStatusFilter($statusFilter);

        if ($normalized === 'completed') {
            $query->where('status', 'completed');
        } elseif ($normalized === 'scheduled') {
            return $query
                ->whereIn('status', ['scheduled', 'delayed', 'boarding', 'departed', 'in-progress'])
                ->whereDate('trip_date', '>=', $today)
                ->paginate($perPage, ['*'], 'page', $page);
        } elseif ($normalized !== 'all') {
            $timezone = config('app.timezone', 'Asia/Manila');
            $today = $this->serviceToday()->toDateString();
            $currentTime = Carbon::now($timezone)->format('H:i:s');
            $scheduledTime = "COALESCE(trips.departure_time, (SELECT start_time FROM fleets_routes WHERE fleets_routes.fleet_route_id = trips.fleet_route_id))";

            $query->whereIn('status', ['scheduled', 'delayed', 'boarding', 'departed', 'in-progress'])
                ->where(function ($upcoming) use ($today, $currentTime, $scheduledTime) {
                    $upcoming->where('trip_date', '>', $today)
                        ->orWhere(function ($sameDay) use ($today, $currentTime, $scheduledTime) {
                            $sameDay->where('trip_date', $today)
                                ->where(function ($departure) use ($currentTime, $scheduledTime) {
                                    $departure->whereRaw("{$scheduledTime} IS NULL")
                                        ->orWhereRaw("{$scheduledTime} >= ?", [$currentTime]);
                                });
                            });
                });
        }

        return $query->paginate($perPage, ['*'], 'page', $page);
    }

    // A staff member's own trips, one page at a time. Upcoming work reads earliest first. "All" is the recent past plus
    // everything ahead, earliest first, so today is always on page 1; older history is the "completed" filter, newest first.
    public function paginateByDriver(int $driverCompanyUserId, string $statusFilter = 'all', int $page = 1, int $perPage = 100): LengthAwarePaginator
    {
        return $this->paginateAssigned('driver_id', $driverCompanyUserId, ['fleetRoute.route', 'fleetRoute.fleet', 'driver.user', 'conductor.user', 'assignmentRequests'], $statusFilter, $page, $perPage);
    }

    public function paginateByConductor(int $conductorCompanyUserId, string $statusFilter = 'all', int $page = 1, int $perPage = 100): LengthAwarePaginator
    {
        return $this->paginateAssigned('conductor_id', $conductorCompanyUserId, ['fleetRoute.route.routeStops.stop', 'fleetRoute.fleet', 'driver.user', 'conductor.user', 'assignmentRequests'], $statusFilter, $page, $perPage);
    }

    private function paginateAssigned(string $column, int $staffId, array $with, string $statusFilter, int $page, int $perPage): LengthAwarePaginator
    {
        $today = $this->serviceToday();
        $normalized = $this->normalizeStatusFilter($statusFilter);
        $newestFirst = $normalized === 'completed';
        $direction = $newestFirst ? 'desc' : 'asc';

        $query = Trip::with($with)
            ->where($column, $staffId)
            ->orderBy('trip_date', $direction)
            ->orderBy('departure_time', $direction);

        $this->applyStatusFilter($query, $statusFilter, $today);
        if (in_array($normalized, ['all', 'upcoming'], true)) {
            $query->whereDate('trip_date', '>=', $today->copy()->subDays(self::ASSIGNED_RECENT_DAYS)->toDateString());
        }

        $result = $query->paginate($perPage, ['*'], 'page', $page);
        if ($newestFirst) {
            $result->setCollection($result->getCollection()->reverse()->values());
        }

        return $result;
    }

    public function findCurrentByDriver(int $driverCompanyUserId): ?Trip
    {
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

    /**
     * C8: trips on the calendar for one staff scope between two business
     * dates (inclusive), cancelled ones excluded. Scope is the trips column
     * that ties the staff member to the trip.
     */
    public function listForCalendar(string $role, int $companyUserId, string $fromDate, string $toDate): Collection
    {
        return Trip::where($this->calendarScopeColumn($role), $companyUserId)
            ->whereDate('trip_date', '>=', $fromDate)
            ->whereDate('trip_date', '<=', $toDate)
            ->where('status', '!=', 'cancelled')
            ->get(['trip_id', 'trip_date', 'shift_block_id', 'status']);
    }

    /**
     * Batch 26: the trips and shift legs of one business day for the calendar's day list, earliest
     * first, scoped like the summary (the caller's own assignments, or the operator's fleets).
     */
    public function paginateForCalendarDay(string $role, int $companyUserId, string $date, int $page, int $perPage): LengthAwarePaginator
    {
        return Trip::with(['fleetRoute.route', 'fleetRoute.fleet'])
            ->where($this->calendarScopeColumn($role), $companyUserId)
            ->whereDate('trip_date', $date)
            ->where('status', '!=', 'cancelled')
            ->orderBy('departure_time')
            ->orderBy('trip_id')
            ->paginate($perPage, ['*'], 'page', $page);
    }

    /** C8: the next trip that is still to be run (not completed or cancelled). */
    public function findNextForCalendar(string $role, int $companyUserId, string $fromDate): ?Trip
    {
        return Trip::with('fleetRoute.route')
            ->where($this->calendarScopeColumn($role), $companyUserId)
            ->whereIn('status', self::ACTIVE_STATUSES)
            ->whereDate('trip_date', '>=', $fromDate)
            ->orderBy('trip_date', 'asc')
            ->orderBy('departure_time', 'asc')
            ->orderBy('trip_id', 'asc')
            ->first();
    }

    private function calendarScopeColumn(string $role): string
    {
        return match ($role) {
            'driver' => 'driver_id',
            'conductor' => 'conductor_id',
            'operator' => 'company_user_id',
        };
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

    /**
     * Batch 27 Part 2: for a shift block the "current trip" of a staff member is the block's current leg:
     * the first leg that is not completed or cancelled, in travel order. It no longer depends on which
     * leg an old shift log happened to be opened on.
     */
    private function currentLegOf(?Trip $trip): ?Trip
    {
        if (! $trip || ! $trip->shift_block_id) {
            return $trip;
        }

        return Trip::with(['fleetRoute.fleet', 'fleetRoute.route'])
            ->where('shift_block_id', $trip->shift_block_id)
            ->whereNotIn('status', ['completed', 'cancelled'])
            ->orderBy('departure_time')
            ->orderBy('trip_id')
            ->first() ?? $trip;
    }

    public function findTodayByDriver(int $driverCompanyUserId): ?Trip
    {
        return $this->currentLegOf($this->rawFindTodayByDriver($driverCompanyUserId));
    }

    public function findTodayByConductor(int $conductorCompanyUserId): ?Trip
    {
        return $this->currentLegOf($this->rawFindTodayByConductor($conductorCompanyUserId));
    }

    public function findCurrentOrUpcomingByDriver(int $driverCompanyUserId): ?Trip
    {
        return $this->currentLegOf($this->rawFindCurrentOrUpcomingByDriver($driverCompanyUserId));
    }

    public function findCurrentOrUpcomingByConductor(int $conductorCompanyUserId): ?Trip
    {
        return $this->currentLegOf($this->rawFindCurrentOrUpcomingByConductor($conductorCompanyUserId));
    }

    private function rawFindTodayByDriver(int $driverCompanyUserId): ?Trip
    {
        return Trip::with(['fleetRoute.fleet', 'fleetRoute.route'])
            ->where('driver_id', $driverCompanyUserId)
            ->whereDate('trip_date', $this->serviceToday())
            ->whereIn('status', ['scheduled', 'delayed', 'boarding', 'departed', 'in-progress'])
            ->orderByRaw(self::CURRENT_LEG_STATUS_ORDER)
            ->orderBy('departure_time', 'asc')
            ->orderBy('trip_id', 'asc')
            ->first();
    }

    private function rawFindTodayByConductor(int $conductorCompanyUserId): ?Trip
    {
        return Trip::with(['fleetRoute.fleet', 'fleetRoute.route'])
            ->where('conductor_id', $conductorCompanyUserId)
            ->whereDate('trip_date', $this->serviceToday())
            ->whereIn('status', ['scheduled', 'delayed', 'boarding', 'departed', 'in-progress'])
            ->orderByRaw(self::CURRENT_LEG_STATUS_ORDER)
            ->orderBy('departure_time', 'asc')
            ->orderBy('trip_id', 'asc')
            ->first();
    }

    private function rawFindCurrentOrUpcomingByDriver(int $driverCompanyUserId): ?Trip
    {
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

    private function rawFindCurrentOrUpcomingByConductor(int $conductorCompanyUserId): ?Trip
    {
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
