<?php

namespace App\Services;

use App\Repositories\FleetRouteRepository;
use App\Http\Resources\FleetRouteResource;

class FleetRouteService
{
    private FleetRouteRepository $fleetRouteRepository;

    public function __construct(FleetRouteRepository $fleetRouteRepository) 
    {
        $this->fleetRouteRepository = $fleetRouteRepository;
    }

    public function listFleetRoute(int $perPage = 15)
    {
        $collection = $this->fleetRouteRepository->paginate($perPage);
        return FleetRouteResource::collection($collection);
    }

    public function createFleetRoute(array $payload)
    {
        $model = $this->fleetRouteRepository->create($payload);
        
    }

    public function getFleetRoute(string $uuid)
    {
        $model = $this->fleetRouteRepository->findByUuid($uuid);
        
    }

    public function getFleetRouteByField(string $field, $value)
    {
        $model = $this->fleetRouteRepository->findByField($field, $value);
        
    }

    public function updateFleetRoute(string $uuid, array $payload)
    {
        $model = $this->fleetRouteRepository->update($uuid, $payload);
        
    }

    public function deleteFleetRoute(string $uuid)
    {
        $this->fleetRouteRepository->delete($uuid);
        return true;
    }

    public function restoreFleetRoute(string $uuid)
    {
        $model = $this->fleetRouteRepository->restore($uuid);
        
    }
}