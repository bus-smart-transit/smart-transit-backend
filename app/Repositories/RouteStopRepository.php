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
        return RouteStop::where('route_id', $routeId)
            ->orderBy('stop_order')
            ->get();
    }

    public function delete(int $routeStopId): bool
    {
        return RouteStop::where('id', $routeStopId)->delete() > 0;
    }
}
