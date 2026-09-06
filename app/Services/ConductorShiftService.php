<?php

namespace App\Services;

use App\Repositories\ConductorShiftLogRepository;
use Illuminate\Validation\ValidationException;

class ConductorShiftService
{
    public function __construct(
        private ConductorShiftLogRepository $conductorShiftLogRepository,
        private TripService $tripService,
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

            throw ValidationException::withMessages([
                'shift' => ['You already have an active shift. End it before starting a new one.'],
            ]);
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

        return $this->conductorShiftLogRepository->markEnded($openShift, $endSource);
    }
}
