<?php

namespace App\Repositories;

use App\Models\FleetRoute;
use Illuminate\Support\Collection;

class FleetRouteRepository
{
    public function create(array $payload): FleetRoute
    {
        return FleetRoute::create($payload);
    }

    public function findWithRoute(int $fleetRouteId): ?FleetRoute
    {
        return FleetRoute::with(['route', 'fleet'])->find($fleetRouteId);
    }

    public function listByRoute(int $routeId): Collection
    {
        return FleetRoute::where('route_id', $routeId)->where('status', 'active')->get();
    }

    public function updateStatus(int $fleetRouteId, string $status): bool
    {
        return FleetRoute::where('fleet_route_id', $fleetRouteId)->update(['status' => $status]) > 0;
    }
}
