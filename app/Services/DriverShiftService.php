<?php

namespace App\Services;

use App\Repositories\DriverShiftLogRepository;
use Illuminate\Validation\ValidationException;

class DriverShiftService
{
    public function __construct(
        private DriverShiftLogRepository $driverShiftLogRepository,
        private TripService $tripService,
    ) {}

    public function getStatusForDriver(int $driverCompanyUserId): array
    {
        $openShift = $this->driverShiftLogRepository->findOpenByDriver($driverCompanyUserId);
        $latestShift = $openShift ?? $this->driverShiftLogRepository->latestByDriver($driverCompanyUserId);

        return [
            'open_shift' => $openShift,
            'latest_shift' => $latestShift,
            'has_open_shift' => $openShift !== null,
        ];
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
            if ((int) $existingOpen->trip_id === (int) $trip->trip_id) {
                return $existingOpen;
            }

            throw ValidationException::withMessages([
                'shift' => ['You already have an active shift. End it before starting a new one.'],
            ]);
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

    public function endShiftForDriver(int $driverCompanyUserId, string $endSource = 'dashboard'): object
    {
        $openShift = $this->driverShiftLogRepository->findOpenByDriver($driverCompanyUserId);
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

        return $this->driverShiftLogRepository->markEnded($openShift, $endSource);
    }
}
