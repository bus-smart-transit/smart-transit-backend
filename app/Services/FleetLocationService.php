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
        private RouteSnapService $routeSnapService,
    ) {}

    /**
     * $payload     = ['latitude','longitude','trip_id' nullable,'heading' nullable,'speed_kmh' nullable]
     * $companyUserId derives fleet_id server-side from driver's active trip
     *               (driver does NOT send fleet_id — we look it up to avoid trust issues)
     */
    public function updateLocation(int $companyUserId, array $payload): bool
    {
        $trip = $this->tripRepository->findCurrentByDriver($companyUserId);

        if (!$trip) {
            throw ValidationException::withMessages([
                'trip' => ['No active trip found for this driver.'],
            ]);
        }

        // Poor-accuracy fixes never overwrite the last accepted position.
        $accuracy = isset($payload['accuracy']) ? (float) $payload['accuracy'] : null;
        if ($accuracy !== null && $accuracy > (float) config('route_geometry.gps.max_accuracy_m')) {
            return false;
        }

        $this->fleetLocationRepository->upsertLocation(array_merge($payload, [
            'fleet_id' => $trip->fleetRoute->fleet_id,
            'trip_id'  => $payload['trip_id'] ?? $trip->trip_id,
        ]));

        return true;
    }

    /**
     * Active positions with a server-computed projection onto the trip's
     * canonical route (the client never computes authoritative snapping).
     */
    public function getAllActiveLocations(): object
    {
        return $this->fleetLocationRepository->getAllActiveLocations()->map(function ($row) {
            $row->route_position = null;
            if ($row->route_id && $row->latitude !== null && $row->longitude !== null) {
                $row->route_position = $this->routeSnapService->snap(
                    (int) $row->route_id,
                    (float) $row->latitude,
                    (float) $row->longitude,
                    $row->leg_direction ?? 'outbound'
                );
            }

            return $row;
        });
    }

    // $payload = ['latitude','longitude']
    public function findNearestFleet(array $payload): ?object
    {
        return $this->fleetLocationRepository->findNearest($payload);
    }

    public function getTripHistory(int $tripId, int $limit = 500): object
    {
        return $this->fleetLocationRepository->getTripHistory($tripId, $limit);
    }
}
