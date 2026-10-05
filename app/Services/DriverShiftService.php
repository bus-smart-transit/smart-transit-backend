<?php

namespace App\Services;

use App\Repositories\DriverShiftLogRepository;
use App\Support\AssignmentAcceptance;
use Illuminate\Validation\ValidationException;

class DriverShiftService
{
    public function __construct(
        private DriverShiftLogRepository $driverShiftLogRepository,
        private TripService $tripService,
        private LegProgressionService $legs,
    ) {}

    public function getStatusForDriver(int $driverCompanyUserId): array
    {
        $openShift = $this->driverShiftLogRepository->findOpenByDriver($driverCompanyUserId);
        $latestShift = $openShift ?? $this->driverShiftLogRepository->latestByDriver($driverCompanyUserId);

        return [
            'open_shift' => $openShift,
            'latest_shift' => $latestShift,
            'has_open_shift' => $openShift !== null,
            // Batch 27 Part 2: a driver on a shift block ends the shift once every leg is finished.
            'is_block_shift' => (bool) $openShift?->trip?->shift_block_id,
            'end_blocked_reason' => $openShift?->trip?->shift_block_id ? $this->legs->endBlockedReason($openShift->trip) : null,
        ];
    }

    /**
     * Batch 27 Part 2, Task 1: for a shift block the driver's shift log closes here, at End Shift, not at
     * each End Trip. Same guard as the Chauffeur's: every leg completed or cancelled first. A driver on a
     * standalone trip never ends a shift (End Trip closes the log), see DriverShiftController::end().
     */
    public function endShiftForDriver(int $driverCompanyUserId, string $endSource = 'dashboard'): object
    {
        $openShift = $this->driverShiftLogRepository->findOpenByDriver($driverCompanyUserId);
        if (! $openShift) {
            throw ValidationException::withMessages(['shift' => ['No active shift found to end.']]);
        }

        $blocked = $this->legs->endBlockedReason($openShift->trip);
        if ($blocked !== null) {
            throw ValidationException::withMessages(['shift' => [$blocked]]);
        }

        return $this->driverShiftLogRepository->markEnded($openShift, $endSource);
    }

    public function startShiftForDriver(int $driverCompanyUserId, string $startSource = 'dashboard'): object
    {
        $trip = $this->tripService->getTodayAssignedTripForDriver($driverCompanyUserId);
        if (!$trip) {
            throw ValidationException::withMessages([
                'shift' => ['No trip assigned for today. Cannot start shift.'],
            ]);
        }

        $existingOpen = $this->driverShiftLogRepository->findOpenByDriver($driverCompanyUserId);
        if ($existingOpen) {
            // Batch 27 Part 2: the same shift covers every leg of one block.
            if ($this->legs->sameShift($existingOpen->trip, $trip)) {
                return $existingOpen;
            }

            // Batch 17, Issue #4: a shift log left open for a trip that has
            // since completed/cancelled (e.g. the driver never pressed "End
            // Shift") was permanently blocking every later shift start,
            // including for a same-day trip they're already paired for.
            // Self-heal by auto-closing it instead of hard-blocking.
            if ($this->legs->isShiftStale($existingOpen->trip)) {
                $this->driverShiftLogRepository->markEnded($existingOpen, 'auto_closed_stale');
            } else {
                throw ValidationException::withMessages([
                    'shift' => ['You already have an active shift. End it before starting a new one.'],
                ]);
            }
        }

        // Batch 29: the assignment must be accepted before a shift can start.
        if ($reason = AssignmentAcceptance::blockedReason($trip, 'driver')) {
            throw ValidationException::withMessages(['shift' => [$reason]]);
        }

        return $this->driverShiftLogRepository->create([
            'driver_company_user_id' => $driverCompanyUserId,
            'trip_id' => $trip->trip_id,
            'fleet_id' => $trip->fleetRoute?->fleet_id,
            'shift_date' => $trip->trip_date,
            'started_at' => now(),
            'start_source' => $startSource,
        ])->fresh(['trip.fleetRoute.route', 'fleet']);
    }
}
