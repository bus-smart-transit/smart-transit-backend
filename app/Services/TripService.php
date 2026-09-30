<?php

namespace App\Services;

use App\Repositories\ConductorShiftLogRepository;
use App\Repositories\DriverShiftLogRepository;
use App\Repositories\StaffRepository;
use App\Repositories\TicketRepository;
use App\Repositories\TripRepository;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class TripService
{
    public function __construct(
        private TripRepository $tripRepository,
        private StaffRepository $StaffRepository,
        private FleetDailyPinService $pinService,
        private DriverShiftLogRepository $driverShiftLogRepository,
        private ConductorShiftLogRepository $conductorShiftLogRepository,
        private TicketRepository $ticketRepository,
        private FcmService $fcmService,
    ) {}

    /**
     * IDOR guard used by controllers before allowing an operator to act on
     * or view data scoped to a trip.
     */
    public function isOwnedByOperator(int $tripId, int $companyUserId): bool
    {
        return $this->tripRepository->isOwnedByOperator($tripId, $companyUserId);
    }

    /**
     * Batch 18: a staff member may hold only ONE shift-block/trip
     * assignment per calendar day. Hard exclusivity, no time-overlap math.
     * (Superseded the earlier per-trip-segment exclusivity check from the
     * prior, now-replaced trip_driver_segments design.)
     */
    private function assertNotAlreadyAssignedThatDay(string $role, int $companyUserId, string $tripDate, ?int $excludeTripId = null): void
    {
        $alreadyAssigned = $this->tripRepository->hasAnySameDayAssignment($role, $companyUserId, $tripDate, $excludeTripId);

        if ($alreadyAssigned) {
            $field = $role === 'driver' ? 'driver_id' : 'conductor_id';
            throw ValidationException::withMessages([
                $field => [ucfirst($role).' is already assigned to another trip on this date.'],
            ]);
        }
    }

    public function scheduleTrip(array $payload): object
    {
        // StoreTripRequest only checks driver_id/conductor_id exist as SOME
        // company_users record (not their role) — validate the role here,
        // the same way assignDriver()/assignConductor() already do, so a
        // trip can never be created (and a PIN never generated for it) with
        // a driver_id/conductor_id that isn't actually a driver/conductor.
        if (! empty($payload['driver_id'])) {
            $driver = $this->StaffRepository->findById($payload['driver_id']);
            if (! $driver || $driver->user?->role !== 'driver') {
                throw ValidationException::withMessages([
                    'driver_id' => ['The selected user is not a driver.'],
                ]);
            }

            $this->assertNotAlreadyAssignedThatDay('driver', $payload['driver_id'], $payload['trip_date']);
        }

        if (! empty($payload['conductor_id'])) {
            $conductor = $this->StaffRepository->findById($payload['conductor_id']);
            if (! $conductor || $conductor->user?->role !== 'conductor') {
                throw ValidationException::withMessages([
                    'conductor_id' => ['The selected user is not a conductor.'],
                ]);
            }

            $this->assertNotAlreadyAssignedThatDay('conductor', $payload['conductor_id'], $payload['trip_date']);
        }

        // current_* fields represent occupied slots; new trips must start at 0.
        $trip = $this->tripRepository->create(array_merge($payload, [
            'trip_type' => $payload['trip_type'] ?? 'one_way',
            'return_departure_time' => $payload['trip_type'] === 'round_trip'
                ? ($payload['return_departure_time'] ?? null)
                : null,
            'status' => 'scheduled',
            'current_seated_capacity' => 0,
            'current_standing_capacity' => 0,
            'total_occupancy' => 0,
        ]));

        if (! empty($trip->driver_id) && ! empty($trip->conductor_id) && $this->isTripToday($trip)) {
            $this->pinService->generateOrGet(
                $trip->trip_id,
                $trip->driver_id,
                $trip->conductor_id,
            );
        }

        // Follow-up fix (caught by the user asking "does trip creation also
        // notify the assigned driver/conductor?"): assignDriver()/
        // assignConductor() push a notification, but scheduleTrip() creates
        // the trip with driver_id/conductor_id already set directly in the
        // payload — the normal way an Operator schedules a trip — and never
        // routed through those methods. Without this, a newly-created trip
        // with both roles assigned up front sent no notification at all.
        $routeName = $trip->fleetRoute?->route?->route_name ?? 'a trip';
        if (! empty($trip->driver_id) && isset($driver)) {
            $this->fcmService->sendToUser(
                $driver->user,
                'New Trip Assignment',
                "You've been assigned to {$routeName} on {$trip->trip_date}.",
                ['type' => 'trip_assigned', 'trip_id' => $trip->trip_id],
            );
        }
        if (! empty($trip->conductor_id) && isset($conductor)) {
            $this->fcmService->sendToUser(
                $conductor->user,
                'New Trip Assignment',
                "You've been assigned to {$routeName} on {$trip->trip_date}.",
                ['type' => 'trip_assigned', 'trip_id' => $trip->trip_id],
            );
        }

        return $trip;
    }

    public function startBoarding(int $tripId): object
    {
        $this->tripRepository->updateStatus($tripId, 'boarding');

        return $this->tripRepository->findById($tripId);
    }

    public function getTrip(int $tripId): ?object
    {
        return $this->tripRepository->findById($tripId);
    }

    public function departTrip(int $tripId): object
    {
        $trip = $this->tripRepository->findById($tripId);

        if (! $trip) {
            throw ValidationException::withMessages(['trip' => ['Trip not found.']]);
        }

        if (! $this->pinService->isBothVerified($trip->trip_id)) {
            throw ValidationException::withMessages([
                'pin' => ['Both the driver and conductor must complete QR pairing before the trip can depart.'],
            ]);
        }

        $this->tripRepository->updateStatus($tripId, 'departed');

        return $this->tripRepository->findById($tripId);
    }

    public function completeTrip(int $tripId): object
    {
        $this->tripRepository->updateStatus($tripId, 'completed');

        // Batch 17, Issue #4 prevention: a shift left open past trip
        // completion silently blocks every later shift start for that
        // staff member. Auto-close it here instead of relying on the
        // driver/conductor remembering to press "End Shift".
        $this->closeShiftLogsForTrip($tripId);

        return $this->tripRepository->findById($tripId);
    }

    /**
     * B5: the driver's shift log always closes with the trip (End Trip is the
     * driver's last action). The Chauffeur's log closes with a standalone
     * trip, but for a shift-block leg it stays open until the Chauffeur
     * presses End Shift once every leg is finished; a forgotten one is still
     * auto-closed as stale when the next shift starts (Batch 17 safety net).
     */
    private function closeShiftLogsForTrip(int $tripId): void
    {
        $this->driverShiftLogRepository->endOpenForTrip($tripId);

        $trip = $this->tripRepository->findById($tripId);
        if (empty($trip?->shift_block_id)) {
            $this->conductorShiftLogRepository->endOpenForTrip($tripId);
        }
    }

    /**
     * Operator/Admin manual status override (e.g. driver forgot to mark a
     * trip completed, or a trip's scheduled time passed without ever
     * departing and needs to be resolved).
     *
     * - Operator: may only force a trip to 'completed', and only if it has
     *   already reached 'departed'/'in-progress' — covers "driver forgot to
     *   complete it on their end", not trips that never actually ran.
     * - Admin: full override — any status, from any current status —
     *   covers trips that passed their scheduled time without ever
     *   departing (no-shows) needing manual resolution.
     *
     * Returns [Trip $updatedTrip, string $previousStatus] so the caller can
     * audit-log the transition.
     */
    public function overrideStatus(int $tripId, string $newStatus, string $requestingRole): array
    {
        $trip = $this->tripRepository->findById($tripId);

        if (! $trip) {
            throw ValidationException::withMessages(['trip' => ['Trip not found.']]);
        }

        $previousStatus = $trip->status;

        if ($requestingRole === 'operator') {
            $alreadyRan = in_array($previousStatus, ['departed', 'in-progress'], true);

            if ($newStatus !== 'completed' || ! $alreadyRan) {
                throw ValidationException::withMessages([
                    'status' => ['Operators can only mark an already-departed trip as completed. Contact an admin for other status overrides.'],
                ]);
            }
        }

        $this->tripRepository->updateStatus($tripId, $newStatus);

        // Batch 17, Issue #4 prevention: see completeTrip() above — same
        // reasoning applies to an admin/operator override landing on
        // 'completed' or 'cancelled'.
        if (in_array($newStatus, ['completed', 'cancelled'], true)) {
            $this->closeShiftLogsForTrip($tripId);
        }

        // Notifications audit follow-up: passengers who already paid for a
        // now-cancelled trip previously got no notification at all.
        if ($newStatus === 'cancelled') {
            $this->notifyPassengersOfCancellation($trip);
        }

        return [$this->tripRepository->findById($tripId), $previousStatus];
    }

    private function notifyPassengersOfCancellation(object $trip): void
    {
        $routeName = $trip->fleetRoute?->route?->route_name ?? 'Your trip';
        $passengers = $this->ticketRepository->findActivePassengersForTrip($trip->trip_id);

        foreach ($passengers as $passenger) {
            if (! $passenger->user) {
                continue;
            }

            $this->fcmService->sendToUser(
                $passenger->user,
                'Trip Cancelled',
                "{$routeName} on {$trip->trip_date} has been cancelled. Please contact support for a refund or rebooking.",
                ['type' => 'trip_cancelled', 'trip_id' => $trip->trip_id],
            );
        }
    }

    /**
     * Notifications audit follow-up (auto-delay item): notifies passengers
     * of trips that have been auto-marked 'delayed'.
     *
     * Deliberately NOT called from markOverdueScheduledAsDelayed() or any
     * of its 10+ read-time call sites — that method's status flip has no
     * single authoritative caller, so notifying there would risk duplicate
     * sends across overlapping requests. Instead, this is driven by a
     * dedicated scheduled command (trips:notify-delayed) that polls for
     * trips already in 'delayed' status with a null delay_notified_at, and
     * atomically claims each one via TripRepository::markDelayNotified()
     * before sending — safe even if the command run overlaps itself.
     *
     * Returns the number of trips actually notified.
     */
    public function notifyDelayedTrips(): int
    {
        $trips = $this->tripRepository->findDelayedTripsPendingNotification();
        $notified = 0;

        foreach ($trips as $trip) {
            if (! $this->tripRepository->markDelayNotified($trip->trip_id)) {
                // Another run already claimed this trip — skip.
                continue;
            }

            $this->notifyPassengersOfDelay($trip);
            $notified++;
        }

        return $notified;
    }

    private function notifyPassengersOfDelay(object $trip): void
    {
        $routeName = $trip->fleetRoute?->route?->route_name ?? 'Your trip';
        $passengers = $this->ticketRepository->findActivePassengersForTrip($trip->trip_id);

        foreach ($passengers as $passenger) {
            if (! $passenger->user) {
                continue;
            }

            $this->fcmService->sendToUser(
                $passenger->user,
                'Trip Delayed',
                "{$routeName} on {$trip->trip_date} is running late. We'll update you as soon as it departs.",
                ['type' => 'trip_delayed', 'trip_id' => $trip->trip_id],
            );
        }
    }

    /**
     * Batch 15, Item 1: explicit Accept (see TripAssignmentRequestService for decline requests).
     * Records the assigned driver/conductor's acknowledgement of the trip
     * without altering the assignment itself. Same eligibility rules as
     * decline (must be the assigned staff member, trip not yet departed).
     */
    public function acceptTrip(int $tripId, string $role, int $staffCompanyUserId): object
    {
        if (! in_array($role, ['driver', 'conductor'], true)) {
            throw ValidationException::withMessages(['role' => ['Only a driver or conductor can accept a trip.']]);
        }

        $trip = $this->tripRepository->findById($tripId);
        if (! $trip) {
            throw ValidationException::withMessages(['trip' => ['Trip not found.']]);
        }

        if (! in_array($trip->status, ['scheduled', 'delayed', 'boarding'], true)) {
            throw ValidationException::withMessages([
                'status' => ['This trip has already departed and can no longer be accepted.'],
            ]);
        }

        $assignedId = $role === 'driver' ? $trip->driver_id : $trip->conductor_id;
        if ((int) $assignedId !== $staffCompanyUserId) {
            throw ValidationException::withMessages([
                'trip' => ['You are not the assigned '.$role.' for this trip.'],
            ]);
        }

        if ($role === 'driver') {
            $this->tripRepository->markDriverAccepted($tripId);
        } else {
            $this->tripRepository->markConductorAccepted($tripId);
        }

        // Follow-up (caught during the notifications audit): previously
        // acceptance notified no one at all — the Operator had zero
        // visibility into whether an assignment was acknowledged.
        $staff = $this->StaffRepository->findById($staffCompanyUserId);
        $staffName = $staff?->name ?: ucfirst($role);
        $routeName = $trip->fleetRoute?->route?->route_name ?? 'Unassigned route';
        $operatorUser = $trip->companyUser?->user;
        if ($operatorUser) {
            $this->fcmService->sendToUser(
                $operatorUser,
                'Trip Assignment Accepted',
                "{$staffName} accepted the {$role} assignment for {$routeName} on {$trip->trip_date}.",
                ['type' => 'trip_accepted', 'trip_id' => $tripId],
            );
        }

        return $this->tripRepository->findById($tripId);
    }

    public function assignDriver(int $tripId, array $payload): void
    {
        $driver = $this->StaffRepository->findById($payload['driver_id']);

        if (! $driver || $driver->user->role !== 'driver') {
            throw ValidationException::withMessages([
                'driver_id' => ['The selected user is not a driver.'],
            ]);
        }

        $targetTrip = $this->tripRepository->findById($tripId);
        if (! $targetTrip) {
            throw ValidationException::withMessages(['trip' => ['Trip not found.']]);
        }

        // S2 corrected model: hard one-assignment-per-day exclusivity —
        // see assertNotAlreadyAssignedThatDay().
        $this->assertNotAlreadyAssignedThatDay('driver', $payload['driver_id'], $targetTrip->trip_date->format('Y-m-d'), $tripId);

        $this->tripRepository->assignDriver($tripId, $payload['driver_id']);

        // Auto-generate daily PIN if conductor is already assigned
        $trip = $this->tripRepository->findById($tripId);
        if ($trip && $trip->conductor_id && $this->isTripToday($trip)) {
            $this->pinService->generateOrGet(
                $trip->trip_id,
                $payload['driver_id'],
                $trip->conductor_id,
            );
        }

        // Batch 15, Item 2: push notification on new trip assignment.
        // Fire-and-forget — a failed/unconfigured push must never break
        // trip assignment itself (see FcmService's fail-soft design).
        if ($trip && $driver->user) {
            $routeName = $trip->fleetRoute?->route?->route_name ?? 'a trip';
            $this->fcmService->sendToUser(
                $driver->user,
                'New Trip Assignment',
                "You've been assigned to {$routeName} on {$trip->trip_date}.",
                ['type' => 'trip_assigned', 'trip_id' => $trip->trip_id],
            );
        }
    }

    public function assignConductor(int $tripId, array $payload): void
    {
        $conductor = $this->StaffRepository->findById($payload['conductor_id']);

        if (! $conductor || $conductor->user->role !== 'conductor') {
            throw ValidationException::withMessages([
                'conductor_id' => ['The selected user is not a conductor.'],
            ]);
        }

        $targetTrip = $this->tripRepository->findById($tripId);
        if (! $targetTrip) {
            throw ValidationException::withMessages(['trip' => ['Trip not found.']]);
        }

        $this->assertNotAlreadyAssignedThatDay('conductor', $payload['conductor_id'], $targetTrip->trip_date->format('Y-m-d'), $tripId);

        $this->tripRepository->assignConductor($tripId, $payload['conductor_id']);

        // Auto-generate daily PIN if driver is already assigned
        $trip = $this->tripRepository->findById($tripId);
        if ($trip && $trip->driver_id && $this->isTripToday($trip)) {
            $this->pinService->generateOrGet(
                $trip->trip_id,
                $trip->driver_id,
                $payload['conductor_id'],
            );
        }

        // Batch 15, Item 2 (extended to Conductor for symmetry with
        // assignDriver() above — the original spec only mentioned "driver",
        // but leaving the Conductor unnotified while Driver, Accept/Reject,
        // and Available-toggle are all symmetric across both roles would be
        // an inconsistent, easily-missed gap).
        if ($trip && $conductor->user) {
            $routeName = $trip->fleetRoute?->route?->route_name ?? 'a trip';
            $this->fcmService->sendToUser(
                $conductor->user,
                'New Trip Assignment',
                "You've been assigned to {$routeName} on {$trip->trip_date}.",
                ['type' => 'trip_assigned', 'trip_id' => $trip->trip_id],
            );
        }
    }

    public function saveDispatchDecision(int $tripId, array $payload): object
    {
        $trip = $this->tripRepository->findById($tripId);

        if (! $trip) {
            throw ValidationException::withMessages(['trip' => ['Trip not found.']]);
        }

        $decision = $payload['decision'] ?? null;
        if (! in_array($decision, ['accept', 'keep'], true)) {
            throw ValidationException::withMessages([
                'decision' => ['Dispatch decision must be either "accept" or "keep".'],
            ]);
        }

        $this->tripRepository->updateDispatchDecision(
            $tripId,
            $decision,
            $payload['route'] ?? null,
            $payload['reason'] ?? null,
        );

        return $this->tripRepository->findById($tripId);
    }

    public function getDriverTrips(int $driverCompanyUserId, string $statusFilter = 'all'): object
    {
        return $this->tripRepository->listByDriver($driverCompanyUserId, $statusFilter);
    }

    public function getConductorTrips(int $conductorCompanyUserId, string $statusFilter = 'all'): object
    {
        return $this->tripRepository->listByConductor($conductorCompanyUserId, $statusFilter);
    }

    public function getOperatorUpcomingTrips(int $operatorCompanyUserId, string $statusFilter = 'all'): object
    {
        return $this->tripRepository->listUpcomingByOperator($operatorCompanyUserId, $statusFilter);
    }

    public function getCurrentTripForDriver(int $driverCompanyUserId): ?object
    {
        return $this->resolveOpenDriverShiftTrip($driverCompanyUserId);
    }

    public function getCurrentTripForConductor(int $conductorCompanyUserId): ?object
    {
        return $this->resolveOpenConductorShiftTrip($conductorCompanyUserId);
    }

    /**
     * Returns assigned trip context for pairing/PIN flows:
     * prefers today's active/scheduled trip, then falls back to nearest
     * upcoming assigned trip when today's assignment does not exist.
     */
    public function getCurrentOrScheduledTripForDriver(int $driverCompanyUserId): ?object
    {
        return $this->tripRepository->findTodayByDriver($driverCompanyUserId)
            ?? $this->tripRepository->findCurrentOrUpcomingByDriver($driverCompanyUserId);
    }

    /**
     * Returns assigned trip context for pairing/PIN flows:
     * prefers today's active/scheduled trip, then falls back to nearest
     * upcoming assigned trip when today's assignment does not exist.
     */
    public function getCurrentOrScheduledTripForConductor(int $conductorCompanyUserId): ?object
    {
        return $this->tripRepository->findTodayByConductor($conductorCompanyUserId)
            ?? $this->tripRepository->findCurrentOrUpcomingByConductor($conductorCompanyUserId);
    }

    /**
     * Returns only today's assigned trip context for PIN/QR shift actions.
     */
    public function getTodayAssignedTripForDriver(int $driverCompanyUserId): ?object
    {
        return $this->tripRepository->findTodayByDriver($driverCompanyUserId);
    }

    /**
     * Returns only today's assigned trip context for PIN/QR shift actions.
     */
    public function getTodayAssignedTripForConductor(int $conductorCompanyUserId): ?object
    {
        return $this->tripRepository->findTodayByConductor($conductorCompanyUserId);
    }

    public function getAvailableTripsForPassengers(bool $includeStops = true, ?string $tripDate = null): object
    {
        return $this->tripRepository->listAvailableForPassengers($includeStops, $tripDate);
    }

    /**
     * Resolve the current active trip for a staff user by their role.
     * Removes the role-dispatch match block from callers (O-01 fix).
     */
    public function getCurrentTripForUser(object $user): ?object
    {
        $companyUser = $user->companyProfile;
        if (! $companyUser) {
            return null;
        }

        return match ($user->role) {
            'conductor' => $this->getCurrentTripForConductor($companyUser->company_user_id),
            'driver' => $this->getCurrentTripForDriver($companyUser->company_user_id),
            default => null,
        };
    }

    /**
     * Called internally by TicketService — not from any controller.
     * $seatType = 'seated' | 'standing'
     *
     * Wrapped in its own transaction with a row lock (findByIdForUpdate) so
     * concurrent reservations against the same trip can't both read stale
     * capacity and both pass the "would exceed" check.
     */
    public function recordBoarding(int $tripId, string $seatType): object
    {
        return $this->recordBoardingMultiple($tripId, $seatType, 1);
    }

    /**
     * Batched variant of recordBoarding() that reserves multiple seats/slots
     * in one locked transaction, reducing lock churn for multi-ticket checkout.
     */
    public function recordBoardingMultiple(int $tripId, string $seatType, int $count): object
    {
        $count = max(1, $count);

        $execute = function () use ($tripId, $seatType, $count) {
            $trip = $this->tripRepository->findByIdForUpdate($tripId);

            if (! $trip) {
                throw ValidationException::withMessages(['trip' => ['Trip not found.']]);
            }

            $fleet = $trip->fleetRoute->fleet;
            $seatedDelta = $seatType === 'seated' ? $count : 0;
            $standingDelta = $seatType === 'standing' ? $count : 0;

            $wouldExceed = $seatType === 'seated'
                ? $trip->current_seated_capacity + $count > $fleet->seated_capacity
                : $trip->current_standing_capacity + $count > $fleet->standing_capacity;

            if ($wouldExceed) {
                throw ValidationException::withMessages(['capacity' => ['This trip is full.']]);
            }

            $trip->current_seated_capacity += $seatedDelta;
            $trip->current_standing_capacity += $standingDelta;
            $trip->total_occupancy = $trip->current_seated_capacity + $trip->current_standing_capacity;
            $trip->save();

            return $trip;
        };

        if (DB::transactionLevel() > 0) {
            return $execute();
        }

        return DB::transaction($execute);
    }

    /**
     * Inverse of recordBoarding() — releases a previously held seat/standing
     * slot. Called when a pending online payment fails or its hold expires
     * without ever becoming a ticket.
     */
    public function releaseBoarding(int $tripId, string $seatType): object
    {
        $execute = function () use ($tripId, $seatType) {
            $trip = $this->tripRepository->findByIdForUpdate($tripId);

            if (! $trip) {
                throw ValidationException::withMessages(['trip' => ['Trip not found.']]);
            }

            $seatedDelta = $seatType === 'seated' ? 1 : 0;
            $standingDelta = $seatType === 'standing' ? 1 : 0;

            $trip->current_seated_capacity = max(0, $trip->current_seated_capacity - $seatedDelta);
            $trip->current_standing_capacity = max(0, $trip->current_standing_capacity - $standingDelta);
            $trip->total_occupancy = $trip->current_seated_capacity + $trip->current_standing_capacity;
            $trip->save();

            return $trip;
        };

        if (DB::transactionLevel() > 0) {
            return $execute();
        }

        return DB::transaction($execute);
    }

    private function isTripToday(object $trip): bool
    {
        $date = $trip->trip_date instanceof Carbon
            ? $trip->trip_date
            : Carbon::parse((string) $trip->trip_date, config('app.timezone', 'Asia/Manila'));

        return $date->setTimezone(config('app.timezone', 'Asia/Manila'))->isToday();
    }

    private function resolveOpenDriverShiftTrip(int $driverCompanyUserId): ?object
    {
        $openShift = $this->driverShiftLogRepository->findOpenByDriver($driverCompanyUserId);
        $trip = $openShift?->trip;

        if (! $trip) {
            return null;
        }

        $status = strtolower((string) ($trip->status ?? ''));
        if (in_array($status, ['completed', 'cancelled'], true)) {
            return null;
        }

        return $trip->loadMissing(['fleetRoute.route', 'fleetRoute.fleet', 'driver.user', 'conductor.user']);
    }

    private function resolveOpenConductorShiftTrip(int $conductorCompanyUserId): ?object
    {
        $openShift = $this->conductorShiftLogRepository->findOpenByConductor($conductorCompanyUserId);
        $trip = $openShift?->trip;

        if (! $trip) {
            return null;
        }

        $status = strtolower((string) ($trip->status ?? ''));
        if (in_array($status, ['completed', 'cancelled'], true)) {
            return null;
        }

        return $trip->loadMissing(['fleetRoute.route', 'fleetRoute.fleet', 'driver.user', 'conductor.user']);
    }
}
