<?php

namespace App\Services;

use App\Models\FleetRoute;
use App\Models\PendingBoarding;
use App\Models\Ticket;
use App\Models\Trip;
use App\Models\TripAssignmentRequest;
use App\Repositories\ConductorShiftLogRepository;
use App\Repositories\DriverShiftLogRepository;
use App\Repositories\StaffRepository;
use App\Repositories\TicketRepository;
use App\Repositories\TripRepository;
use App\Support\BusLabel;
use App\Support\Notifications\NotificationLink;
use App\Support\ScheduleStatus;
use App\Support\TripCapacity;
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
        private LegProgressionService $legs,
        private TripCancellationService $cancellations,
        private RouteDurationFactorService $durationFactors,
        private FleetScheduleGuard $fleetGuard,
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
        if (TripAssignmentRequest::query()
            ->where('role', $role)
            ->where('staff_company_user_id', $companyUserId)
            ->where('status', TripAssignmentRequest::APPROVED)
            ->whereHas('trip', fn ($query) => $query->whereDate('trip_date', $tripDate))
            ->exists()) {
            $field = $role === 'driver' ? 'driver_id' : 'conductor_id';
            throw ValidationException::withMessages([
                $field => [ucfirst($role).' has an approved release request for this date and cannot be reassigned.'],
            ]);
        }

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
        // The bus must be running this route at that time, and not already on another trip then.
        if (! empty($payload['fleet_route_id']) && ! empty($payload['departure_time']) && ! empty($payload['trip_date'])) {
            $this->fleetGuard->assertCanSchedule(
                (int) $payload['fleet_route_id'],
                (string) $payload['trip_date'],
                (string) $payload['departure_time'],
                $payload['leg_direction'] ?? null,
                $payload['service_type'] ?? null,
            );
        }
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
                NotificationLink::tripAssignedData('driver', (int) $trip->trip_id),
            );
        }
        if (! empty($trip->conductor_id) && isset($conductor)) {
            $this->fcmService->sendToUser(
                $conductor->user,
                'New Trip Assignment',
                "You've been assigned to {$routeName} on {$trip->trip_date}.",
                NotificationLink::tripAssignedData('conductor', (int) $trip->trip_id),
            );
        }

        return $trip;
    }

    public function startBoarding(int $tripId): object
    {
        $this->tripRepository->updateStatus($tripId, 'boarding');
        $this->durationFactors->freezeFor($tripId); // the leg keeps the factor it started with
        app(ShiftBlockLifecycleService::class)->started($tripId); // a shift block starts with its first leg

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
        $this->durationFactors->freezeFor($tripId);
        app(ShiftBlockLifecycleService::class)->started($tripId);

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
        // Passenger In: check-ins still waiting at End Trip were never paid (the leakage list).
        app(PendingBoardingService::class)->closeOpenForTrip($tripId, PendingBoarding::UNPAID);
        // A shift block finishes with its last leg (pending the Operator's review).
        app(ShiftBlockLifecycleService::class)->legEnded($tripId);

        return $this->tripRepository->findById($tripId);
    }

    /**
     * Batch 27 Part 2, Task 1: the driver's shift log no longer closes at each End Trip of a shift
     * block. It stays open across the legs and closes at End Shift (a forgotten one is auto-closed as
     * stale when the next shift starts, Batch 17 safety net). A standalone trip keeps the old behaviour:
     * End Trip is the driver's last action and closes the log. The Chauffeur's log follows the same rule
     * as before: it closes with a standalone trip, and for a block at End Shift.
     */
    private function closeShiftLogsForTrip(int $tripId): void
    {
        $trip = $this->tripRepository->findById($tripId);
        if (empty($trip?->shift_block_id)) {
            $this->driverShiftLogRepository->endOpenForTrip($tripId);
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
    public function overrideStatus(int $tripId, string $newStatus, string $requestingRole, ?object $actor = null, ?string $reason = null): array
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
            // Passenger In: unpaid at completion (leakage list); closed without blame when the trip is cancelled.
            app(PendingBoardingService::class)->closeOpenForTrip($tripId, $newStatus === 'completed' ? PendingBoarding::UNPAID : PendingBoarding::CANCELLED);
            app(ShiftBlockLifecycleService::class)->legEnded($tripId);
        }

        // Batch 28 Stage 3, item 6: the paid, unused tickets of a cancelled trip are marked "awaiting choice"
        // (rebook or refund) and their passengers are told.
        if ($newStatus === 'cancelled') {
            $this->cancellations->record($this->tripRepository->findById($tripId), $previousStatus, $actor, $reason);
        }

        return [$this->tripRepository->findById($tripId), $previousStatus];
    }

    /**
     * Batch 28 Stage 3, item 6: an Operator (or Admin) cancels one trip. Allowed from any state that has not ended,
     * including after departure; the stage (before or during the trip) decides what a refund is worth. Every paid,
     * unused ticket becomes "trip cancelled, awaiting choice" and its passenger is told (TripCancellationService).
     *
     * @return array{0: object, 1: string} [the cancelled trip, its previous status]
     *
     * @throws ValidationException
     */
    public function cancelTrip(int $tripId, ?object $actor, ?string $reason = null): array
    {
        $trip = $this->tripRepository->findById($tripId);
        if (! $trip) {
            throw ValidationException::withMessages(['trip' => ['Trip not found.']]);
        }
        if (in_array($trip->status, ['completed', 'cancelled'], true)) {
            throw ValidationException::withMessages(['trip' => ['A completed or already cancelled trip cannot be cancelled.']]);
        }

        $previousStatus = $trip->status;
        $this->tripRepository->updateStatus($tripId, 'cancelled');
        $this->closeShiftLogsForTrip($tripId);
        $this->cancellations->record($this->tripRepository->findById($tripId), $previousStatus, $actor, $reason);
        app(ShiftBlockLifecycleService::class)->legEnded($tripId);

        return [$this->tripRepository->findById($tripId), $previousStatus];
    }

    /**
     * Batch 28, item 4: move a trip that has not started onto another bus of the same route (an operator swap).
     * Valid tickets follow the trip, every passenger holding one is told which bus it is now (FCM + in-app), and
     * the new bus must have room for what is already sold. The label they see is the new fleet's own, read live.
     *
     * @throws ValidationException
     */
    public function changeFleet(int $tripId, int $fleetRouteId): object
    {
        $trip = $this->tripRepository->findById($tripId);
        if (! $trip) {
            throw ValidationException::withMessages(['trip' => ['Trip not found.']]);
        }
        if (! in_array($trip->status, ['scheduled', 'delayed'], true)) {
            throw ValidationException::withMessages(['trip' => ['The bus can only be changed before the trip starts boarding.']]);
        }

        $target = FleetRoute::with('fleet')->find($fleetRouteId);
        if (! $target || $target->status !== 'active' || (int) $target->route_id !== (int) $trip->fleetRoute->route_id) {
            throw ValidationException::withMessages(['fleet_route_id' => ['Choose an active bus assigned to this trip\'s route.']]);
        }
        if ((int) $target->fleet_route_id === (int) $trip->fleet_route_id) {
            return $trip;
        }
        if ((int) $trip->current_seated_capacity > (int) $target->fleet->seated_capacity
            || (int) $trip->current_standing_capacity > (int) $target->fleet->standing_capacity) {
            throw ValidationException::withMessages(['fleet_route_id' => ['That bus does not have room for the tickets already sold on this trip.']]);
        }

        // The other bus must be free at this trip's time (this trip itself does not count against it).
        $this->fleetGuard->assertCanSchedule(
            (int) $target->fleet_route_id,
            $trip->trip_date->toDateString(),
            (string) $trip->departure_time,
            $trip->leg_direction,
            $trip->service_type,
            (int) $trip->trip_id,
        );

        DB::transaction(function () use ($trip, $target) {
            Trip::where('trip_id', $trip->trip_id)->update(['fleet_route_id' => $target->fleet_route_id]);
            Ticket::where('trip_id', $trip->trip_id)->update(['fleet_route_id' => $target->fleet_route_id]);
        });

        $fresh = $this->tripRepository->findById($tripId);
        $this->notifyPassengersOfBusChange($fresh);

        return $fresh;
    }

    /** "Your bus is now ..." to every passenger holding a valid ticket on the trip. Fail-soft like every push here. */
    private function notifyPassengersOfBusChange(object $trip): void
    {
        $routeName = $trip->fleetRoute?->route?->route_name ?? 'Your trip';
        $label = BusLabel::for($trip->fleetRoute?->fleet);
        $body = $label
            ? "{$routeName} on {$trip->trip_date->toDateString()} will now run on {$label}."
            : "{$routeName} on {$trip->trip_date->toDateString()} has a new bus. Its label will be shown on your ticket as soon as it is available.";

        foreach ($this->ticketRepository->findActivePassengersForTrip($trip->trip_id) as $passenger) {
            if (! $passenger->user) {
                continue;
            }

            $this->fcmService->sendToUser($passenger->user, 'Your bus has changed', $body, ['type' => 'trip_bus_changed', 'trip_id' => $trip->trip_id]);
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
        $this->tripRepository->markOverdueScheduledAsDelayed();
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

        if ($trip->shift_block_id) {
            throw ValidationException::withMessages([
                'trip' => ['This trip is a leg of a shift block. Accept the whole shift block instead.'],
            ]);
        }

        if (! in_array($trip->status, ['scheduled', 'delayed', 'boarding'], true)) {
            throw ValidationException::withMessages([
                'status' => ['This trip has already departed and can no longer be accepted.'],
            ]);
        }

        // Batch 29, issue 3: a date that has passed (Manila) can no longer be answered.
        if (ScheduleStatus::isPastDate($trip->trip_date?->toDateString())) {
            throw ValidationException::withMessages(['status' => [ScheduleStatus::PAST_DATE_MESSAGE]]);
        }

        $assignedId = $role === 'driver' ? $trip->driver_id : $trip->conductor_id;
        if ((int) $assignedId !== $staffCompanyUserId) {
            throw ValidationException::withMessages([
                'trip' => ['You are not the assigned '.$role.' for this trip.'],
            ]);
        }

        // A pending request to be released is a different answer to the same question. The screen already hides Accept
        // in that case; the server refuses it too (a shift block's accept makes the same check).
        $pendingRelease = TripAssignmentRequest::where('trip_id', $tripId)
            ->where('role', $role)
            ->where('staff_company_user_id', $staffCompanyUserId)
            ->where('status', TripAssignmentRequest::FOR_APPROVAL)
            ->exists();
        if ($pendingRelease) {
            throw ValidationException::withMessages([
                'trip' => ['You asked to be released from this trip. Wait for the Operator to answer before accepting it.'],
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
                NotificationLink::tripAssignedData('driver', (int) $trip->trip_id),
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
                NotificationLink::tripAssignedData('conductor', (int) $trip->trip_id),
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

    public function getDriverTrips(int $driverCompanyUserId, string $statusFilter = 'all', int $page = 1, int $perPage = 100): object
    {
        return $this->tripRepository->paginateByDriver($driverCompanyUserId, $statusFilter, $page, $perPage);
    }

    public function getConductorTrips(int $conductorCompanyUserId, string $statusFilter = 'all', int $page = 1, int $perPage = 100): object
    {
        return $this->tripRepository->paginateByConductor($conductorCompanyUserId, $statusFilter, $page, $perPage);
    }

    public function getOperatorUpcomingTrips(int $operatorCompanyUserId, string $statusFilter = 'all', int $page = 1, int $perPage = 100): object
    {
        return $this->tripRepository->listUpcomingByOperator($operatorCompanyUserId, $statusFilter, $page, $perPage);
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
    /**
     * $seatsFirst: a new sale may take a standing place only once every seat is sold. A rebook after an Operator
     * cancellation keeps the ticket's own seat type, so it passes false.
     */
    public function recordBoarding(int $tripId, string $seatType, bool $seatsFirst = false): object
    {
        return $this->recordBoardingMultiple($tripId, $seatType, 1, $seatsFirst);
    }

    /**
     * Batched variant of recordBoarding() that reserves multiple seats/slots
     * in one locked transaction, reducing lock churn for multi-ticket checkout.
     */
    public function recordBoardingMultiple(int $tripId, string $seatType, int $count, bool $seatsFirst = false): object
    {
        $count = max(1, $count);

        $execute = function () use ($tripId, $seatType, $count, $seatsFirst) {
            $trip = $this->tripRepository->findByIdForUpdate($tripId);

            if (! $trip) {
                throw ValidationException::withMessages(['trip' => ['Trip not found.']]);
            }

            if ($seatsFirst && $seatType === 'standing' && ! TripCapacity::standingOpen($trip)) {
                throw ValidationException::withMessages(['seat_type' => ['Seats are still available. Sell a seated ticket first.']]);
            }

            $fleet = $trip->fleetRoute->fleet;
            $seatedDelta = $seatType === 'seated' ? $count : 0;
            $standingDelta = $seatType === 'standing' ? $count : 0;

            $wouldExceed = TripCapacity::wouldExceed($trip, $seatType, $count);

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
        if (in_array($status, ['completed', 'cancelled'], true) && ! $trip->shift_block_id) {
            return null;
        }

        // Batch 27 Part 2: for a block, the current trip is the block's current leg, whatever leg the
        // shift log was opened on; null once every leg is completed or cancelled.
        if ($trip->shift_block_id) {
            $trip = $this->legs->currentLeg((int) $trip->shift_block_id);
            if (! $trip) {
                return null;
            }
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
        if (in_array($status, ['completed', 'cancelled'], true) && ! $trip->shift_block_id) {
            return null;
        }

        if ($trip->shift_block_id) {
            $trip = $this->legs->currentLeg((int) $trip->shift_block_id);
            if (! $trip) {
                return null;
            }
        }

        // Batch 27, Task 2: the stops must come with the trip. The Chauffeur's
        // Manual Cash Ticket lists origin/destination from route_stops; since this
        // lookup replaced the repository's findCurrentByConductor() (which loaded
        // them) in commit 18f11ee, the lists were empty and no ticket could be sold.
        return $trip->loadMissing(['fleetRoute.route.routeStops.stop', 'fleetRoute.fleet', 'driver.user', 'conductor.user']);
    }
}
