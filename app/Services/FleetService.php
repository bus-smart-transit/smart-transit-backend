<?php
namespace App\Services;
use App\Repositories\FleetRepository;
use App\Repositories\FleetRouteRepository;

class FleetService
{
    public function __construct(
        private FleetRepository $fleetRepository,
        private FleetRouteRepository $fleetRouteRepository,
    ) {
    }

    // $payload = ['plate_number','seated_capacity','standing_capacity','fleet_type']
    public function registerFleet(array $payload, int $staffId): object
    {
        // Auto-calculate capacity from seated + standing
        $capacity = (intval($payload['seated_capacity'] ?? 0) + intval($payload['standing_capacity'] ?? 0));
        
        return $this->fleetRepository->create(array_merge(
            $payload,
            ['company_user_id' => $staffId],
            ['capacity' => $capacity],
            ['status' => 'active']
        ));
    }

    public function listFleets(): object
    {
        return $this->fleetRepository->all();
    }

    // $payload = ['route_id','start_time','end_time']
    public function assignRouteToFleet(int $fleetId, array $payload): object
    {
        return $this->fleetRouteRepository->create(array_merge(
            $payload,
            ['fleet_id' => $fleetId, 'status' => 'active']
        ));
    }
}
