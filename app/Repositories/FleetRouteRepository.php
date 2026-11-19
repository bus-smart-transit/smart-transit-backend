<?php

namespace App\Repositories;

use App\Models\FleetRoute;
use App\Support\CompanyScope;
use Illuminate\Support\Collection;

class FleetRouteRepository
{
    public function create(array $payload): FleetRoute
    {
        return FleetRoute::create($payload);
    }

    public function findById(int $fleetRouteId): ?FleetRoute
    {
        return FleetRoute::find($fleetRouteId);
    }

    public function findWithRoute(int $fleetRouteId): ?FleetRoute
    {
        return FleetRoute::with(['route', 'fleet'])->find($fleetRouteId);
    }

    /** @return array<int, int> the routes the buses of this Operator's company are assigned to (Batch 46: by company) */
    public function routeIdsForCompany(int $companyUserId): array
    {
        return FleetRoute::whereHas('fleet', fn ($q) => CompanyScope::whereFleetOfCompany($q, $companyUserId))->pluck('route_id')->unique()->values()->all();
    }

    public function listByRoute(int $routeId): Collection
    {
        return FleetRoute::where('route_id', $routeId)
            ->where('status', 'active')
            ->get();
    }

    public function updateStatus(int $fleetRouteId, string $status): bool
    {
        return FleetRoute::where('fleet_route_id', $fleetRouteId)
            ->update(['status' => $status]) > 0;
    }

    public function getActiveFleetsForRoute(int $routeId): Collection
    {
        return FleetRoute::with('fleet')
            ->where('route_id', $routeId)
            ->where('status', 'active')
            ->get()
            ->pluck('fleet')
            ->filter(fn ($fleet) => $fleet && $fleet->status === 'active')
            ->values();
    }

    public function listActiveByOperator(int $companyUserId): Collection
    {
        return FleetRoute::with(['fleet', 'route'])
            ->where('status', 'active')
            ->whereHas('fleet', fn ($q) => CompanyScope::whereFleetOfCompany($q, $companyUserId)->where('fleets.status', 'active'))
            ->orderBy('fleet_route_id', 'asc')
            ->get();
    }

    /**
     * Return all active fleet routes regardless of company. Batch 46: only for the Admin; an Operator gets
     * listActiveByOperator (their company's buses).
     */
    public function listAll(): Collection
    {
        return FleetRoute::with(['fleet', 'route'])
            ->where('status', 'active')
            ->whereHas('fleet', fn ($q) => $q->where('status', 'active'))
            ->orderBy('fleet_route_id', 'asc')
            ->get();
    }
}
