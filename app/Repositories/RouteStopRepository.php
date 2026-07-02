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

    public function updateOrder(int $routeStopId, int $stopOrder): bool
    {
        return RouteStop::where('id', $routeStopId)->update(['stop_order' => $stopOrder]) > 0;
    }

    public function delete(int $routeStopId): bool
    {
        return RouteStop::where('id', $routeStopId)->delete() > 0;
    }
}
