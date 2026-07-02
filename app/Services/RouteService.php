<?php

namespace App\Services;

use App\Repositories\RouteRepository;
use App\Repositories\RouteStopRepository;

class RouteService
{
    private RouteRepository $routeRepository;
    private RouteStopRepository $routeStopRepository;
    public function __construct(
        RouteRepository $routeRepository,
        RouteStopRepository $routeStopRepository,
    ) {
        $this->routeRepository = $routeRepository;
        $this->routeStopRepository = $routeStopRepository;
    }

    public function createRoute(array $payload)
    {
        return $this->routeRepository->create($payload);
    }

    public function getRouteWithStops(int $routeId)
    {
        return $this->routeRepository->findWithStops($routeId);
    }

    public function listRoutes()
    {
        return $this->routeRepository->all();
    }

    // route_stop_table is folded in here — no separate controller/service for it.
    public function addStopToRoute(int $routeId, int $stopId, int $stopOrder, float $distanceFromOriginKm)
    {
        return $this->routeStopRepository->create([
            'route_id' => $routeId,
            'stop_id' => $stopId,
            'stop_order' => $stopOrder,
            'distance_from_origin_km' => $distanceFromOriginKm,
        ]);
    }

    public function removeStopFromRoute(int $routeStopId)
    {
        return $this->routeStopRepository->delete($routeStopId);
    }
}
