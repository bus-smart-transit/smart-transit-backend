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

    // $payload = ['company_user_id','plate_number','capacity','seated_capacity','standing_capacity']
    public function registerFleet(array $payload, int $staffId): object
    {
        return $this->fleetRepository->create(array_merge(
            $payload,
            ['company_user_id' => $staffId],
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
