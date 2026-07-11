<?php
namespace App\Repositories;
use App\Models\RouteStop;
use Illuminate\Support\Collection;

class RouteStopRepository
{
    public function create(array $payload): RouteStop
    {
        return RouteStop::create($payload);
    }

    public function getOrderedStops(int $routeId): Collection
    {
        return RouteStop::with('stop')
            ->where('route_id', $routeId)
            ->orderBy('stop_order')
            ->get();
    }

    // Exact lookup for a known stop on a known route — used to price
    // stop-based tickets on the fly, no interpolation needed since both
    // points are exact.
    public function findByRouteAndStop(int $routeId, int $stopId): ?RouteStop
    {
        return RouteStop::where('route_id', $routeId)
            ->where('stop_id', $stopId)
            ->first();
    }

    public function delete(int $routeStopId): bool
    {
        return RouteStop::where('route_stop_id', $routeStopId)->delete() > 0;
    }
}