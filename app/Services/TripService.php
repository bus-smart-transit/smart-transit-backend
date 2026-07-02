<?php

namespace App\Services;

use App\Repositories\StaffRepository;
use App\Repositories\TripRepository;
use Illuminate\Validation\ValidationException;

class TripService
{
    private TripRepository $tripRepository;

    private StaffRepository $staffRepository;

    public function __construct(
        TripRepository $tripRepository,
        StaffRepository $staffRepository,
    ) {
        $this->tripRepository = $tripRepository;
        $this->staffRepository = $staffRepository;
    }

    public function listTrip(int $perPage = 15): object
    {
        return $this->tripRepository->listPaginated($perPage);
    }

    public function scheduleTrip(array $payload): object
    {
        return $this->tripRepository->create([
            'fleet_route_id' => $payload['fleet_route_id'],
            'company_user_id' => $payload['company_user_id'],
            'trip_date' => $payload['trip_date'],
            'status' => 'scheduled',
            'current_seated_capacity' => 0,
            'current_standing_capacity' => 0,
            'total_occupancy' => 0,
        ]);
    }

    public function startBoarding(int $tripId): object
    {
        $this->tripRepository->updateStatus($tripId, 'boarding');

        return $this->tripRepository->findById($tripId);
    }

    public function departTrip(int $tripId): object
    {
        $this->tripRepository->updateStatus($tripId, 'departed');

        return $this->tripRepository->findById($tripId);
    }

    public function completeTrip(int $tripId): object
    {
        $this->tripRepository->updateStatus($tripId, 'completed');

        return $this->tripRepository->findById($tripId);
    }

    public function assignDriver(array $payload): void
    {
        // Confirm the company_user being assigned is actually a driver
        $driver = $this->staffRepository->findById($payload['driver_id']);

        if (! $driver || $driver->user->role !== 'driver') {
            throw ValidationException::withMessages([
                'driver_id' => ['The selected user is not a driver.'],
            ]);
        }

        $this->tripRepository->assignDriver($payload['trip_id'], $payload['driver_id']);
    }

    public function assignConductor(array $payload): void
    {
        // Confirm the company_user being assigned is actually a conductor
        $conductor = $this->staffRepository->findById($payload['conductor_id']);

        if (! $conductor || $conductor->user->role !== 'conductor') {
            throw ValidationException::withMessages([
                'conductor_id' => ['The selected user is not a conductor.'],
            ]);
        }

        $this->tripRepository->assignConductor($payload['trip_id'], $payload['conductor_id']);
    }

    public function getDriverTrips(int $driverId): object
    {
        return $this->tripRepository->listByDriver($driverId);
    }

    public function getCurrentTripForDriver(int $driverId): ?object
    {
        return $this->tripRepository->findCurrentByDriver($driverId);
    }

    public function getCurrentTripForConductor(int $conductorId): ?object
    {
        return $this->tripRepository->findCurrentByConductor($conductorId);
    }

    public function recordBoarding(array $payload): object
    {
        $trip = $this->tripRepository->findById($payload['trip_id']);

        if (! $trip) {
            throw ValidationException::withMessages([
                'trip' => ['Trip not found.'],
            ]);
        }

        $fleet = $trip->fleetRoute->fleet;
        $seatedDelta = $payload['seat_type'] === 'seated' ? 1 : 0;
        $standingDelta = $payload['seat_type'] === 'standing' ? 1 : 0;

        $wouldExceed = $payload['seat_type'] === 'seated'
            ? $trip->current_seated_capacity + 1 > $fleet->seated_capacity
            : $trip->current_standing_capacity + 1 > $fleet->standing_capacity;

        if ($wouldExceed) {
            throw ValidationException::withMessages([
                'capacity' => ['This trip is full.'],
            ]);
        }

        $this->tripRepository->incrementOccupancy($payload['trip_id'], $seatedDelta, $standingDelta);

        return $this->tripRepository->findById($payload['trip_id']);
    }
}
