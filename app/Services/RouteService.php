<?php

namespace App\Services;

use App\Repositories\RouteRepository;
use App\Http\Resources\RouteResource;

class RouteService
{
    private RouteRepository $routeRepository;

    public function __construct(RouteRepository $routeRepository) 
    {
        $this->routeRepository = $routeRepository;
    }

    public function listRoute(int $perPage = 15)
    {
        $collection = $this->routeRepository->paginate($perPage);
        return RouteResource::collection($collection);
    }

    public function createRoute(array $payload)
    {
        $model = $this->routeRepository->create($payload);
        
    }

    public function getRoute(string $uuid)
    {
        $model = $this->routeRepository->findByUuid($uuid);
        
    }

    public function getRouteByField(string $field, $value)
    {
        $model = $this->routeRepository->findByField($field, $value);
        
    }

    public function updateRoute(string $uuid, array $payload)
    {
        $model = $this->routeRepository->update($uuid, $payload);
        
    }

    public function deleteRoute(string $uuid)
    {
        $this->routeRepository->delete($uuid);
        return true;
    }

    public function restoreRoute(string $uuid)
    {
        $model = $this->routeRepository->restore($uuid);
        
    }
}