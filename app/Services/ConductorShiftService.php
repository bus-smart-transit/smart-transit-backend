<?php

namespace App\Services;

use App\Repositories\ConductorShiftLogRepository;
use App\Repositories\TripRepository;
use Illuminate\Validation\ValidationException;

class ConductorShiftService
{
    public function __construct(
        private ConductorShiftLogRepository $conductorShiftLogRepository,
        private TripService $tripService,
        private TripRepository $tripRepository,
        private LegProgressionService $legs,
    ) {}

    public function getStatusForConductor(int $conductorCompanyUserId): array
    {
        $openShift = $this->conductorShiftLogRepository->findOpenByConductor($conductorCompanyUserId);
        $latestShift = $openShift ?? $this->conductorShiftLogRepository->latestByConductor($conductorCompanyUserId);

        return [
            'open_shift' => $openShift,
            'latest_shift' => $latestShift,
            'has_open_shift' => $openShift !== null,
            'end_blocked_reason' => $openShift ? $this->endBlockedReason($openShift) : null,
        ];
    }

    /**
     * C7: why End Shift is not allowed right now, or null when it is. This is
     * the single source for both the status payload the dashboard reads and
     * the guard in endShiftForConductor(), so the button state and the server
     * can never disagree.
     */
    private function endBlockedReason(object $openShift): ?string
    {
        // Batch 27 Part 2: one block-aware rule (a block needs every leg completed or cancelled).
        return $this->legs->endBlockedReason($openShift->trip);
    }

    public function startShiftForConductor(int $conductorCompanyUserId, string $startSource = 'dashboard'): object
    {
        $trip = $this->tripService->getTodayAssignedTripForConductor($conductorCompanyUserId);
        if (!$trip) {
            throw ValidationException::withMessages([
                'shift' => ['No trip assigned for today. Cannot start shift.'],
            ]);
        }

        $existingOpen = $this->conductorShiftLogRepository->findOpenByConductor($conductorCompanyUserId);
        if ($existingOpen) {
            // Batch 27 Part 2: the same shift covers every leg of one block.
            if ($this->legs->sameShift($existingOpen->trip, $trip)) {
                return $existingOpen;
            }

            // Batch 17, Issue #4: see DriverShiftService::startShiftForDriver()
            // for the full explanation — self-heal a shift left open for a
            // trip that has since completed/cancelled instead of permanently
            // blocking every later shift start.
            if ($this->legs->isShiftStale($existingOpen->trip)) {
                $this->conductorShiftLogRepository->markEnded($existingOpen, 'auto_closed_stale');
            } else {
                throw ValidationException::withMessages([
                    'shift' => ['You already have an active shift. End it before starting a new one.'],
                ]);
            }
        }

        return $this->conductorShiftLogRepository->create([
            'conductor_company_user_id' => $conductorCompanyUserId,
            'trip_id' => $trip->trip_id,
            'fleet_id' => $trip->fleetRoute?->fleet_id,
            'shift_date' => $trip->trip_date,
            'started_at' => now(),
            'start_source' => $startSource,
        ])->fresh(['trip.fleetRoute.route', 'fleet']);
    }

    public function endShiftForConductor(int $conductorCompanyUserId, string $endSource = 'dashboard'): object
    {
        $openShift = $this->conductorShiftLogRepository->findOpenByConductor($conductorCompanyUserId);
        if (!$openShift) {
            throw ValidationException::withMessages([
                'shift' => ['No active shift found to end.'],
            ]);
        }

        $blocked = $this->endBlockedReason($openShift);
        if ($blocked !== null) {
            throw ValidationException::withMessages(['shift' => [$blocked]]);
        }

        return $this->conductorShiftLogRepository->markEnded($openShift, $endSource);
    }
}
