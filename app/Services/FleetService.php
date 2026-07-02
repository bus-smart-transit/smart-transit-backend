<?php

namespace App\Services;

use App\Repositories\FleetRepository;
use App\Repositories\FleetRouteRepository;

class FleetService
{
    private FleetRepository $fleetRepository;
    private FleetRouteRepository $fleetRouteRepository;
    public function __construct(
        FleetRepository $fleetRepository,
        FleetRouteRepository $fleetRouteRepository,
    ) {
        $this->fleetRepository = $fleetRepository;
        $this->fleetRouteRepository = $fleetRouteRepository;
    }

    public function registerFleet(array $payload)
    {
        return $this->fleetRepository->create([
            'company_user_id' => $payload['company_user_id'],
            'plate_number' => $payload['plate_number'],
            'capacity' => $payload['capacity'],
            'seated_capacity' => $payload['seated_capacity'],
            'standing_capacity' => $payload['standing_capacity'],
            'status' => 'active',
        ]);
    }

    public function listFleets()
    {
        return $this->fleetRepository->all();
    }

    public function assignRouteToFleet(int $fleetId, int $routeId, string $startTime, string $endTime)
    {
        return $this->fleetRouteRepository->create([
            'fleet_id' => $fleetId,
            'route_id' => $routeId,
            'start_time' => $startTime,
            'end_time' => $endTime,
            'status' => 'active',
        ]);
        // After this, call FareCalculationService::recalculateForFleetRoute()
        // with the new fleet_route_id so fares exist before trips run.
    }
}
