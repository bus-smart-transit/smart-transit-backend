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
    ) {}

    public function getStatusForConductor(int $conductorCompanyUserId): array
    {
        $openShift = $this->conductorShiftLogRepository->findOpenByConductor($conductorCompanyUserId);
        $latestShift = $openShift ?? $this->conductorShiftLogRepository->latestByConductor($conductorCompanyUserId);

        return [
            'open_shift' => $openShift,
            'latest_shift' => $latestShift,
            'has_open_shift' => $openShift !== null,
        ];
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
            if ((int) $existingOpen->trip_id === (int) $trip->trip_id) {
                return $existingOpen;
            }

            // Batch 17, Issue #4: see DriverShiftService::startShiftForDriver()
            // for the full explanation — self-heal a shift left open for a
            // trip that has since completed/cancelled instead of permanently
            // blocking every later shift start.
            $staleTripStatus = strtolower((string) ($existingOpen->trip?->status ?? ''));
            if (in_array($staleTripStatus, ['completed', 'cancelled'], true)) {
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

        $tripStatus = strtolower((string) ($openShift->trip?->status ?? ''));
        if (in_array($tripStatus, ['boarding', 'departed', 'in-progress'], true)) {
            throw ValidationException::withMessages([
                'shift' => ['Cannot end shift while your assigned trip is still boarding or in transit. Complete the trip first.'],
            ]);
        }

        // B5: End Shift closes the whole shift block, so every leg must be
        // completed or cancelled first.
        $blockId = $openShift->trip?->shift_block_id;
        if ($blockId) {
            $unfinished = $this->tripRepository->countUnfinishedLegs((int) $blockId);
            if ($unfinished > 0) {
                throw ValidationException::withMessages([
                    'shift' => ["Cannot end shift: {$unfinished} leg(s) of your shift block are not completed or cancelled yet."],
                ]);
            }
        }

        return $this->conductorShiftLogRepository->markEnded($openShift, $endSource);
    }
}
