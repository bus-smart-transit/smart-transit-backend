<?php
namespace App\Services;
use App\Repositories\RouteRepository;
use App\Repositories\RouteStopRepository;

class RouteService
{
    public function __construct(
        private RouteRepository $routeRepository,
        private RouteStopRepository $routeStopRepository,
    ) {
    }

    // $payload = ['origin','destination','route_name']
    public function createRoute(array $payload): object
    {
        return $this->routeRepository->create($payload);
    }

    public function listRoutes(): object
    {
        return $this->routeRepository->all();
    }

    public function getRouteWithStops(int $routeId): ?object
    {
        return $this->routeRepository->findWithStops($routeId);
    }

    // $payload = ['stop_id','stop_order','distance_from_origin_km']
    // route_stop_table is folded into RouteService — no separate service/controller
    public function addStopToRoute(int $routeId, array $payload): object
    {
        return $this->routeStopRepository->create(array_merge(
            $payload,
            ['route_id' => $routeId]
        ));
    }

    public function removeStopFromRoute(int $routeStopId): bool
    {
        return $this->routeStopRepository->delete($routeStopId);
    }
}
