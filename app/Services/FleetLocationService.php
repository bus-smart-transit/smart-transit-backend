<?php
namespace App\Services;
use App\Repositories\FleetLocationRepository;
use App\Repositories\TripRepository;
use Illuminate\Validation\ValidationException;

class FleetLocationService
{
    public function __construct(
        private FleetLocationRepository $fleetLocationRepository,
        private TripRepository $tripRepository,
    ) {}

    /**
     * $payload     = ['latitude','longitude','trip_id' nullable,'heading' nullable,'speed_kmh' nullable]
     * $companyUserId derives fleet_id server-side from driver's active trip
     *               (driver does NOT send fleet_id — we look it up to avoid trust issues)
     */
    public function updateLocation(int $companyUserId, array $payload): void
    {
        $trip = $this->tripRepository->findCurrentByDriver($companyUserId);

        if (!$trip) {
            throw ValidationException::withMessages([
                'trip' => ['No active trip found for this driver.'],
            ]);
        }

        $this->fleetLocationRepository->upsertLocation(array_merge($payload, [
            'fleet_id' => $trip->fleetRoute->fleet_id,
            'trip_id'  => $payload['trip_id'] ?? $trip->trip_id,
        ]));
    }

    public function getAllActiveLocations(): object
    {
        return $this->fleetLocationRepository->getAllActiveLocations();
    }

    // $payload = ['latitude','longitude']
    public function findNearestFleet(array $payload): ?object
    {
        return $this->fleetLocationRepository->findNearest($payload);
    }
}
