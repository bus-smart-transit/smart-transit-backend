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

            // Batch 17, Issue #4: a shift log left open for a trip that has
            // since completed/cancelled (e.g. the driver never pressed "End
            // Shift") was permanently blocking every later shift start,
            // including for a same-day trip they're already paired for.
            // Self-heal by auto-closing it instead of hard-blocking.
            $staleTripStatus = strtolower((string) ($existingOpen->trip?->status ?? ''));
            if (in_array($staleTripStatus, ['completed', 'cancelled'], true)) {
                $this->driverShiftLogRepository->markEnded($existingOpen, 'auto_closed_stale');
            } else {
                throw ValidationException::withMessages([
                    'shift' => ['You already have an active shift. End it before starting a new one.'],
                ]);
            }
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
