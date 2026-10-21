<?php

namespace App\Services;

use App\Models\ShiftBlock;
use App\Models\Stop;
use App\Models\Trip;
use App\Repositories\FleetLocationRepository;
use App\Repositories\FleetRouteRepository;
use App\Repositories\ShiftBlockRepository;
use App\Repositories\StaffRepository;
use App\Repositories\TripRepository;
use App\Support\Notifications\NotificationLink;
use App\Support\ServiceType;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;

/**
 * Batch 18: Shift Block Hand-off System.
 *
 * Implements Batch 17 S2's hand-off state machine concretely: one shift
 * block per driver/conductor per day (hard exclusivity), a block wraps
 * several existing `trips` rows ("legs") rather than duplicating leg data,
 * automatic GPS-geofence hand-off eligibility ("Location Match Rule"), a
 * minimum-rest gate between a staff member's blocks ("Rest Rule"), and a
 * simplified audit_status flag instead of a separate reconciliation system.
 *
 * Real-time interactions stay poll-based + FCM (Batch 15) — no WebSocket
 * infrastructure is introduced anywhere in this service.
 */
class ShiftBlockService
{
    public function __construct(
        private ShiftBlockRepository $shiftBlockRepository,
        private TripRepository $tripRepository,
        private StaffRepository $staffRepository,
        private FleetRouteRepository $fleetRouteRepository,
        private FleetLocationRepository $fleetLocationRepository,
        private FcmService $fcmService,
        private ShiftBlockAssignmentService $assignments,
        private TripCancellationService $cancellations,
        private FleetScheduleGuard $fleetGuard,
    ) {}

    /**
     * Operator/Dispatcher creates a shift block and its legs up front —
     * mirrors how regular trips are already scheduled today. Legs are NOT
     * auto-timed by estimating travel duration (no per-trip duration data
     * exists anywhere in this schema — see Batch 17's S2 finding); the
     * Operator supplies each leg's departure time explicitly, same as
     * TripService::scheduleTrip() already requires for a single trip.
     */
    public function createBlock(array $payload): ShiftBlock
    {
        $fleetRoute = $this->fleetRouteRepository->findWithRoute($payload['fleet_route_id']);
        if (! $fleetRoute) {
            throw ValidationException::withMessages(['fleet_route_id' => ['Fleet route not found.']]);
        }

        $driver = $this->staffRepository->findById($payload['driver_id']);
        if (! $driver || $driver->user?->role !== 'driver') {
            throw ValidationException::withMessages(['driver_id' => ['The selected user is not a driver.']]);
        }

        // Driver-Conductor Pair Rule: both roles must be valid and distinct.
        $conductor = $this->staffRepository->findById($payload['conductor_id']);
        if (! $conductor || $conductor->user?->role !== 'conductor') {
            throw ValidationException::withMessages(['conductor_id' => ['The selected user is not a conductor.']]);
        }

        $blockTypes = config('shift_blocks.block_types');
        if (! in_array($payload['block_type'], $blockTypes, true)) {
            throw ValidationException::withMessages(['block_type' => ['Invalid block type. Allowed: '.implode(', ', $blockTypes)]]);
        }

        $scheduledDate = $payload['scheduled_date'];
        $legTimes = $payload['leg_departure_times'] ?? [];
        $legsPerBlock = (int) config('shift_blocks.legs_per_block');

        if (count($legTimes) !== $legsPerBlock) {
            throw ValidationException::withMessages(['leg_departure_times' => ["Exactly {$legsPerBlock} leg departure times are required."]]);
        }

        for ($i = 1; $i < count($legTimes); $i++) {
            if ($legTimes[$i] <= $legTimes[$i - 1]) {
                throw ValidationException::withMessages(['leg_departure_times' => ['Leg departure times must be strictly increasing.']]);
            }
        }

        // One-shift-block-per-day exclusivity (supersedes the earlier
        // per-trip exclusivity check — see TripService::assertNotAlreadyAssignedThatDay()
        // for the plain non-block trip-assignment equivalent).
        if ($this->shiftBlockRepository->hasBlockOnDate('driver', $payload['driver_id'], $scheduledDate)) {
            throw ValidationException::withMessages(['driver_id' => ['This driver already has a shift block scheduled for this date.']]);
        }
        if ($this->shiftBlockRepository->hasBlockOnDate('conductor', $payload['conductor_id'], $scheduledDate)) {
            throw ValidationException::withMessages(['conductor_id' => ['This conductor already has a shift block scheduled for this date.']]);
        }

        // Cross-system exclusivity: a staff member also may not already
        // hold a plain (non-block) trip assignment on this date. Legs
        // created below are `trips` rows with driver_id/conductor_id set,
        // so TripService::assertNotAlreadyAssignedThatDay() already blocks
        // the reverse case (plain trip vs. existing shift block); this
        // closes the other direction.
        if ($this->tripRepository->hasAnySameDayAssignment('driver', $payload['driver_id'], $scheduledDate)) {
            throw ValidationException::withMessages(['driver_id' => ['This driver already has a trip assigned on this date.']]);
        }
        if ($this->tripRepository->hasAnySameDayAssignment('conductor', $payload['conductor_id'], $scheduledDate)) {
            throw ValidationException::withMessages(['conductor_id' => ['This conductor already has a trip assigned on this date.']]);
        }

        // Rest Rule.
        $this->assertRestThresholdMet('driver', $payload['driver_id'], $scheduledDate, $legTimes[0]);
        $this->assertRestThresholdMet('conductor', $payload['conductor_id'], $scheduledDate, $legTimes[0]);

        // The bus must run this route at every leg's time and not already be on another trip then. Checked for all legs
        // before anything is created, so a refusal leaves no half-made block.
        foreach ($legTimes as $index => $time) {
            $this->fleetGuard->assertCanSchedule(
                (int) $fleetRoute->fleet_route_id,
                (string) $scheduledDate,
                (string) $time,
                $this->expectedLegDirectionFromIndex($index),
                ServiceType::normalize($payload['service_type'] ?? null),
            );
        }

        $block = $this->shiftBlockRepository->create([
            'fleet_id' => $fleetRoute->fleet_id,
            'driver_id' => $payload['driver_id'],
            'conductor_id' => $payload['conductor_id'],
            'company_user_id' => $payload['company_user_id'],
            'block_type' => $payload['block_type'],
            'status' => 'scheduled',
            'audit_status' => 'not_applicable',
            'scheduled_date' => $scheduledDate,
        ]);

        foreach ($legTimes as $index => $time) {
            $this->tripRepository->create([
                'fleet_route_id' => $fleetRoute->fleet_route_id,
                'company_user_id' => $payload['company_user_id'],
                'trip_date' => $scheduledDate,
                'departure_time' => $time,
                'trip_type' => 'one_way',
                'leg_direction' => $this->expectedLegDirectionFromIndex($index),
                'service_type' => ServiceType::normalize($payload['service_type'] ?? null),
                'status' => 'scheduled',
                'driver_id' => $payload['driver_id'],
                'conductor_id' => $payload['conductor_id'],
                'shift_block_id' => $block->shift_block_id,
                'current_seated_capacity' => 0,
                'current_standing_capacity' => 0,
                'total_occupancy' => 0,
            ]);
        }

        $routeName = $fleetRoute->route?->route_name ?? 'a shift block';
        $this->assignments->ensureForBlock($block);
        if ($driver->user) {
            $this->fcmService->sendToUser(
                $driver->user,
                'New Shift Block Assignment',
                "You've been assigned to a {$payload['block_type']} shift block on {$routeName} on {$scheduledDate}.",
                NotificationLink::blockAssignedData('driver', (int) $block->shift_block_id),
            );
        }
        if ($conductor->user) {
            $this->fcmService->sendToUser(
                $conductor->user,
                'New Shift Block Assignment',
                "You've been assigned to a {$payload['block_type']} shift block on {$routeName} on {$scheduledDate}.",
                NotificationLink::blockAssignedData('conductor', (int) $block->shift_block_id),
            );
        }

        return $this->shiftBlockRepository->findById($block->shift_block_id);
    }

    public function findById(int $blockId): ShiftBlock
    {
        $block = $this->shiftBlockRepository->findById($blockId);
        if (! $block) {
            throw ValidationException::withMessages(['shift_block' => ['Shift block not found.']]);
        }

        return $block;
    }

    // The status filter works on the derived status, which needs each block's legs, so it looks at the newest blocks only.
    private const DERIVED_FILTER_WINDOW = 300;

    public function listForOperator(int $operatorCompanyUserId, ?string $statusFilter = null, int $page = 1, int $perPage = 100): LengthAwarePaginator
    {
        // Batch 29: the filter works on the same derived status the cards show, not on the stored workflow column.
        $groups = [
            'scheduled' => ['scheduled', 'delayed'],
            'in_progress' => ['boarding', 'departed', 'in-progress'],
            'completed' => ['completed'],
            'cancelled' => ['cancelled'],
        ];
        if (! ($statusFilter && isset($groups[$statusFilter]))) {
            return $this->shiftBlockRepository->paginateForOperator($operatorCompanyUserId, $page, $perPage);
        }

        $blocks = $this->shiftBlockRepository->listForOperator($operatorCompanyUserId, null, self::DERIVED_FILTER_WINDOW)
            ->filter(fn (ShiftBlock $block) => in_array($block->lifecycle_status, $groups[$statusFilter], true))
            ->values();

        return new LengthAwarePaginator($blocks->forPage($page, $perPage)->values(), $blocks->count(), $perPage, $page);
    }

    public function listPendingReview(int $operatorCompanyUserId)
    {
        return $this->shiftBlockRepository->findPendingReview($operatorCompanyUserId);
    }

    public function listForDriver(int $driverCompanyUserId, int $page = 1, int $perPage = 50): LengthAwarePaginator
    {
        return $this->shiftBlockRepository->paginateForDriver($driverCompanyUserId, $page, $perPage);
    }

    public function listForConductor(int $conductorCompanyUserId, int $page = 1, int $perPage = 50): LengthAwarePaginator
    {
        return $this->shiftBlockRepository->paginateForConductor($conductorCompanyUserId, $page, $perPage);
    }

    /**
     * Dispatcher exception-handling (Section 4.3): reassign the incoming
     * crew on a still-scheduled block — e.g. the originally-scheduled
     * driver called in sick before their block ever started.
     */
    public function updateAssignment(int $blockId, array $payload): ShiftBlock
    {
        $block = $this->findById($blockId);
        if ($block->status !== 'scheduled') {
            throw ValidationException::withMessages(['status' => ['Only a scheduled shift block can be reassigned.']]);
        }

        $update = [];

        if (! empty($payload['driver_id']) && (int) $payload['driver_id'] !== (int) $block->driver_id) {
            $driver = $this->staffRepository->findById($payload['driver_id']);
            if (! $driver || $driver->user?->role !== 'driver') {
                throw ValidationException::withMessages(['driver_id' => ['The selected user is not a driver.']]);
            }
            if ($this->shiftBlockRepository->hasBlockOnDate('driver', $payload['driver_id'], $block->scheduled_date->format('Y-m-d'), $blockId)) {
                throw ValidationException::withMessages(['driver_id' => ['This driver already has a shift block scheduled for this date.']]);
            }
            if ($this->tripRepository->hasAnySameDayAssignment('driver', $payload['driver_id'], $block->scheduled_date->format('Y-m-d'))) {
                throw ValidationException::withMessages(['driver_id' => ['This driver already has a trip assigned on this date.']]);
            }
            $update['driver_id'] = $payload['driver_id'];
        }

        if (! empty($payload['conductor_id']) && (int) $payload['conductor_id'] !== (int) $block->conductor_id) {
            $conductor = $this->staffRepository->findById($payload['conductor_id']);
            if (! $conductor || $conductor->user?->role !== 'conductor') {
                throw ValidationException::withMessages(['conductor_id' => ['The selected user is not a conductor.']]);
            }
            if ($this->shiftBlockRepository->hasBlockOnDate('conductor', $payload['conductor_id'], $block->scheduled_date->format('Y-m-d'), $blockId)) {
                throw ValidationException::withMessages(['conductor_id' => ['This conductor already has a shift block scheduled for this date.']]);
            }
            if ($this->tripRepository->hasAnySameDayAssignment('conductor', $payload['conductor_id'], $block->scheduled_date->format('Y-m-d'))) {
                throw ValidationException::withMessages(['conductor_id' => ['This conductor already has a trip assigned on this date.']]);
            }
            $update['conductor_id'] = $payload['conductor_id'];
        }

        if ($update) {
            $this->shiftBlockRepository->update($blockId, $update);
            // A replacement must accept for themselves: the previous person's acceptance never carries over.
            $legUpdate = array_intersect_key($update, array_flip(['driver_id', 'conductor_id']));
            if (isset($legUpdate['driver_id'])) {
                $legUpdate['driver_accepted_at'] = null;
            }
            if (isset($legUpdate['conductor_id'])) {
                $legUpdate['conductor_accepted_at'] = null;
            }
            Trip::where('shift_block_id', $blockId)->update($legUpdate);

            $this->assignments->ensureForBlock($this->findById($blockId));
            $routeName = $block->legs->first()?->fleetRoute?->route?->route_name ?? 'a shift block';
            foreach (['driver', 'conductor'] as $role) {
                if (! isset($update[$role.'_id'])) {
                    continue;
                }
                $assignee = $this->staffRepository->findById($update[$role.'_id']);
                if ($assignee?->user) {
                    $this->fcmService->sendToUser(
                        $assignee->user,
                        'New Shift Block Assignment',
                        "You've been assigned to a {$block->block_type} shift block on {$routeName} on {$block->scheduled_date->format('Y-m-d')}.",
                        NotificationLink::blockAssignedData($role, $blockId),
                    );
                }
            }
        }

        return $this->findById($blockId);
    }

    public function markReviewed(int $blockId): ShiftBlock
    {
        $this->shiftBlockRepository->updateAuditStatus($blockId, 'reviewed');

        return $this->findById($blockId);
    }

    /**
     * Dispatcher-only: cancel a shift block that hasn't started yet.
     */
    public function cancelBlock(int $blockId, ?object $actor = null): ShiftBlock
    {
        $block = $this->findById($blockId);
        // Batch 29: the stored column stays "scheduled" while legs run, so it cannot be the only guard. A block with a leg
        // that is under way or finished is cancelled leg by leg (the trip cancel), never wholesale: that used to overwrite
        // a completed leg as "cancelled".
        if ($block->status !== 'scheduled' || ! in_array($block->lifecycle_status, ['scheduled', 'delayed', 'boarding'], true)) {
            throw ValidationException::withMessages(['status' => ['Only a shift block whose legs have not started can be cancelled. Cancel the remaining legs one by one.']]);
        }

        $this->shiftBlockRepository->updateStatus($blockId, 'cancelled');
        $legs = Trip::where('shift_block_id', $blockId)->get();
        Trip::where('shift_block_id', $blockId)->whereIn('status', ['scheduled', 'delayed', 'boarding'])->update(['status' => 'cancelled']);

        // Batch 28 Stage 3, item 6: a cancelled block cancels every leg, and each leg's paid tickets await a choice.
        foreach ($legs as $leg) {
            if ($leg->status !== 'cancelled') {
                $this->cancellations->record(Trip::find($leg->trip_id), (string) $leg->status, $actor, 'Shift block cancelled');
            }
        }

        return $this->findById($blockId);
    }

    /**
     * State 2 (Terminal Transition): is hand-off currently available for
     * this block? Computed on demand (not persisted as a trip status —
     * the trips.status enum already has established meanings elsewhere in
     * this app; adding a new 'arrived' value there was judged riskier than
     * deriving this check live).
     */
    public function evaluateHandoffEligibility(int $blockId): array
    {
        $block = $this->findById($blockId);

        $finalLeg = $block->legs->last();
        if (! $finalLeg || $finalLeg->status !== 'completed') {
            return ['eligible' => false, 'reason' => 'The final leg of this shift block has not been completed yet.'];
        }

        $homeTerminalStop = $this->resolveHomeTerminalStop($finalLeg);
        if (! $homeTerminalStop) {
            return ['eligible' => false, 'reason' => 'Could not determine the home terminal stop for this route.'];
        }

        $currentLatLng = $this->fleetLocationRepository->getCurrentLatLng($block->fleet_id);
        if (! $currentLatLng) {
            return ['eligible' => false, 'reason' => 'No GPS position has been recorded for this fleet yet.'];
        }

        $radiusM = $homeTerminalStop->geofence_radius_m ?? config('shift_blocks.default_geofence_radius_m');
        $distanceM = $this->haversineMeters(
            $currentLatLng['latitude'],
            $currentLatLng['longitude'],
            (float) $homeTerminalStop->latitude,
            (float) $homeTerminalStop->longitude,
        );
        $withinGeofence = $distanceM <= $radiusM;

        return [
            'eligible' => $withinGeofence,
            'reason' => $withinGeofence ? null : "Fleet is ".round($distanceM)."m from the home terminal (must be within {$radiusM}m).",
            'distance_m' => round($distanceM, 1),
            'radius_m' => $radiusM,
        ];
    }

    /**
     * State 2 + Section 4.4: Conductor-initiated end-of-shift / hand-off.
     * $override bypasses the GPS eligibility check (Dispatcher-only manual
     * override path, Section 4.3) but not the caller-identity check unless
     * explicitly requested by the Dispatcher flow.
     */
    public function initiateHandoff(int $blockId, ?int $conductorCompanyUserId, bool $override = false): ShiftBlock
    {
        $block = $this->findById($blockId);

        if (! $override && (int) $block->conductor_id !== $conductorCompanyUserId) {
            throw ValidationException::withMessages(['conductor_id' => ['You are not the conductor for this shift block.']]);
        }

        if ($block->status !== 'in_progress') {
            throw ValidationException::withMessages(['status' => ['Only an in-progress shift block can be handed off.']]);
        }

        if (! $override) {
            $eligibility = $this->evaluateHandoffEligibility($blockId);
            if (! $eligibility['eligible']) {
                throw ValidationException::withMessages(['handoff' => [$eligibility['reason'] ?? 'Hand-off is not yet available.']]);
            }
        }

        // Finance/audit hand-off (Section 3): flag for Operator review —
        // reuses the existing finance/reporting data, no duplicate store.
        $this->shiftBlockRepository->update($blockId, [
            'status' => 'completed',
            'audit_status' => 'pending_review',
        ]);

        // Section 4.4 step 3: revoke outgoing crew's Sanctum tokens
        // immediately — same session-invalidation pattern already used
        // elsewhere in this app (e.g. StaffAuthController, UserService).
        // Their next request/poll naturally returns unauthorized; no
        // WebSocket-pushed logout signal needed.
        $block->driver?->user?->tokens()->delete();
        $block->conductor?->user?->tokens()->delete();

        return $this->findById($blockId);
    }

    /**
     * State 3 (Shift Takeover): incoming driver confirms they're at the
     * terminal and ready to start their already-scheduled block.
     */
    public function confirmTakeover(int $blockId, ?int $driverCompanyUserId, bool $override = false): ShiftBlock
    {
        $block = $this->findById($blockId);

        if (! $override && (int) $block->driver_id !== $driverCompanyUserId) {
            throw ValidationException::withMessages(['driver_id' => ['You are not the driver for this shift block.']]);
        }

        if ($block->status !== 'scheduled') {
            throw ValidationException::withMessages(['status' => ['Only a scheduled shift block can be taken over.']]);
        }

        $activeForFleet = $this->shiftBlockRepository->findActiveForFleet($block->fleet_id, $blockId);
        if ($activeForFleet) {
            throw ValidationException::withMessages(['fleet_id' => ['This fleet still has another shift block in progress — it must be handed off first.']]);
        }

        if (! $override) {
            $firstLeg = $block->legs->first();
            $homeTerminalStop = $firstLeg ? $this->resolveHomeTerminalStop($firstLeg, originStop: true) : null;

            if ($homeTerminalStop) {
                $currentLatLng = $this->fleetLocationRepository->getCurrentLatLng($block->fleet_id);
                if (! $currentLatLng) {
                    throw ValidationException::withMessages(['location' => ['No GPS position has been recorded for this fleet yet — cannot confirm takeover location.']]);
                }

                $radiusM = $homeTerminalStop->geofence_radius_m ?? config('shift_blocks.default_geofence_radius_m');
                $distanceM = $this->haversineMeters(
                    $currentLatLng['latitude'],
                    $currentLatLng['longitude'],
                    (float) $homeTerminalStop->latitude,
                    (float) $homeTerminalStop->longitude,
                );

                if ($distanceM > $radiusM) {
                    throw ValidationException::withMessages(['location' => ['Fleet is '.round($distanceM)."m from the terminal (must be within {$radiusM}m) — move within range before confirming takeover."]]);
                }
            }
        }

        $this->shiftBlockRepository->updateStatus($blockId, 'in_progress');

        return $this->findById($blockId);
    }

    /**
     * Rest Rule: minimum time between a staff member finishing one block
     * and starting their next.
     */
    private function assertRestThresholdMet(string $role, int $companyUserId, string $scheduledDate, string $firstLegTime): void
    {
        $lastBlock = $this->shiftBlockRepository->findMostRecentCompletedBlock($role, $companyUserId);
        if (! $lastBlock) {
            return;
        }

        $newStart = Carbon::parse($scheduledDate.' '.$firstLegTime);
        $lastEnd = Carbon::parse($this->shiftBlockRepository->blockEndedAt($lastBlock));
        $thresholdHours = (int) config('shift_blocks.rest_threshold_hours');

        if ($lastEnd->diffInHours($newStart, false) < $thresholdHours) {
            $field = $role === 'driver' ? 'driver_id' : 'conductor_id';
            throw ValidationException::withMessages([
                $field => ["This {$role} has not had the minimum {$thresholdHours}-hour rest period since their last shift block."],
            ]);
        }
    }

    /**
     * B1: direction alternates by leg order for any configured leg count:
     * outbound, reverse, outbound, reverse, ...
     */
    private function expectedLegDirectionFromIndex(int $index): string
    {
        return $index % 2 === 0 ? 'outbound' : 'reverse';
    }

    /**
     * Location Match Rule support: the route's origin stop, treated as the
     * "home terminal" for whichever route the leg is on (data-driven, no
     * route is special-cased; a generic per-block terminal designation is
     * future work, see SDD.md Section 10.1).
     */
    private function resolveHomeTerminalStop(Trip $leg, bool $originStop = false): ?Stop
    {
        $leg->loadMissing('fleetRoute.route.routeStops.stop');

        return $leg->fleetRoute?->route?->routeStops?->first()?->stop;
    }

    /**
     * Haversine great-circle distance in metres — mirrors
     * ReportingRepository::haversineMeters() (route-adherence GPS-proximity
     * check), reused here rather than introducing a second implementation.
     */
    private function haversineMeters(float $lat1, float $lng1, float $lat2, float $lng2): float
    {
        $earthRadiusM = 6371000;
        $dLat = deg2rad($lat2 - $lat1);
        $dLng = deg2rad($lng2 - $lng1);
        $a = sin($dLat / 2) ** 2
            + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLng / 2) ** 2;

        return $earthRadiusM * 2 * asin(sqrt($a));
    }
}
