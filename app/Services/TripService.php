<?php
namespace App\Services;
use App\Repositories\TripRepository;
use App\Repositories\StaffRepository;
use App\Services\FleetDailyPinService;
use Illuminate\Validation\ValidationException;
use Illuminate\Support\Facades\DB;

class TripService
{
    public function __construct(
        private TripRepository $tripRepository,
        private StaffRepository $StaffRepository,
        private FleetDailyPinService $pinService,
    ) {}

    public function scheduleTrip(array $payload): object
    {
        $trip = $this->tripRepository->create(array_merge($payload, [
            'status' => 'scheduled',
            'current_seated_capacity' => 0,
            'current_standing_capacity' => 0,
            'total_occupancy' => 0,
        ]));

        if (!empty($trip->driver_id) && !empty($trip->conductor_id)) {
            $this->pinService->generateOrGet(
                $trip->trip_id,
                $trip->driver_id,
                $trip->conductor_id,
            );
        }

        return $trip;
    }

    public function startBoarding(int $tripId): object
    {
        $this->tripRepository->updateStatus($tripId, 'boarding');
        return $this->tripRepository->findById($tripId);
    }

    public function departTrip(int $tripId): object
    {
        $trip = $this->tripRepository->findById($tripId);

        if (!$trip) {
            throw ValidationException::withMessages(['trip' => ['Trip not found.']]);
        }

        if (!$this->pinService->isBothVerified($trip->trip_id)) {
            throw ValidationException::withMessages([
                'pin' => ['Both the driver and conductor must verify the daily fleet PIN before the trip can depart.'],
            ]);
        }

        $this->tripRepository->updateStatus($tripId, 'departed');
        return $this->tripRepository->findById($tripId);
    }

    public function completeTrip(int $tripId): object
    {
        $this->tripRepository->updateStatus($tripId, 'completed');
        return $this->tripRepository->findById($tripId);
    }

    public function assignDriver(int $tripId, array $payload): void
    {
        $driver = $this->StaffRepository->findById($payload['driver_id']);

        if (!$driver || $driver->user->role !== 'driver') {
            throw ValidationException::withMessages([
                'driver_id' => ['The selected user is not a driver.'],
            ]);
        }

        $this->tripRepository->assignDriver($tripId, $payload['driver_id']);

        // Auto-generate daily PIN if conductor is already assigned
        $trip = $this->tripRepository->findById($tripId);
        if ($trip && $trip->conductor_id) {
            $this->pinService->generateOrGet(
                $trip->trip_id,
                $payload['driver_id'],
                $trip->conductor_id,
            );
        }
    }

    public function assignConductor(int $tripId, array $payload): void
    {
        $conductor = $this->StaffRepository->findById($payload['conductor_id']);

        if (!$conductor || $conductor->user->role !== 'conductor') {
            throw ValidationException::withMessages([
                'conductor_id' => ['The selected user is not a conductor.'],
            ]);
        }

        $this->tripRepository->assignConductor($tripId, $payload['conductor_id']);

        // Auto-generate daily PIN if driver is already assigned
        $trip = $this->tripRepository->findById($tripId);
        if ($trip && $trip->driver_id) {
            $this->pinService->generateOrGet(
                $trip->trip_id,
                $trip->driver_id,
                $payload['conductor_id'],
            );
        }
    }

    public function getDriverTrips(int $driverCompanyUserId): object
    {
        return $this->tripRepository->listByDriver($driverCompanyUserId);
    }

    public function getConductorTrips(int $conductorCompanyUserId): object
    {
        return $this->tripRepository->listByConductor($conductorCompanyUserId);
    }

    public function getOperatorUpcomingTrips(int $operatorCompanyUserId): object
    {
        return $this->tripRepository->listUpcomingByOperator($operatorCompanyUserId);
    }

    public function getCurrentTripForDriver(int $driverCompanyUserId): ?object
    {
        return $this->tripRepository->findCurrentByDriver($driverCompanyUserId);
    }

    public function getCurrentTripForConductor(int $conductorCompanyUserId): ?object
    {
        return $this->tripRepository->findCurrentByConductor($conductorCompanyUserId);
    }

    /**
     * Returns any trip assigned to this driver today (including scheduled),
     * used to resolve fleet context for PIN endpoints.
     */
    public function getCurrentOrScheduledTripForDriver(int $driverCompanyUserId): ?object
    {
        return $this->tripRepository->findTodayByDriver($driverCompanyUserId);
    }

    /**
     * Returns any trip assigned to this conductor today (including scheduled),
     * used to resolve fleet context for PIN endpoints.
     */
    public function getCurrentOrScheduledTripForConductor(int $conductorCompanyUserId): ?object
    {
        return $this->tripRepository->findTodayByConductor($conductorCompanyUserId);
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
        return DB::transaction(function () use ($tripId, $seatType) {
            $trip = $this->tripRepository->findByIdForUpdate($tripId);

            if (!$trip) {
                throw ValidationException::withMessages(['trip' => ['Trip not found.']]);
            }

            $fleet = $trip->fleetRoute->fleet;
            $seatedDelta = $seatType === 'seated' ? 1 : 0;
            $standingDelta = $seatType === 'standing' ? 1 : 0;

            $wouldExceed = $seatType === 'seated'
                ? $trip->current_seated_capacity + 1 > $fleet->seated_capacity
                : $trip->current_standing_capacity + 1 > $fleet->standing_capacity;

            if ($wouldExceed) {
                throw ValidationException::withMessages(['capacity' => ['This trip is full.']]);
            }

            $this->tripRepository->incrementOccupancy($tripId, $seatedDelta, $standingDelta);
            return $this->tripRepository->findById($tripId);
        });
    }

    /**
     * Inverse of recordBoarding() — releases a previously held seat/standing
     * slot. Called when a pending online payment fails or its hold expires
     * without ever becoming a ticket.
     */
    public function releaseBoarding(int $tripId, string $seatType): object
    {
        return DB::transaction(function () use ($tripId, $seatType) {
            $trip = $this->tripRepository->findByIdForUpdate($tripId);

            if (!$trip) {
                throw ValidationException::withMessages(['trip' => ['Trip not found.']]);
            }

            $seatedDelta = $seatType === 'seated' ? 1 : 0;
            $standingDelta = $seatType === 'standing' ? 1 : 0;

            $this->tripRepository->decrementOccupancy($tripId, $seatedDelta, $standingDelta);
            return $this->tripRepository->findById($tripId);
        });
    }
}