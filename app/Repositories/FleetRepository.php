<?php

namespace App\Repositories;

use App\Models\Fleet;
use App\Support\CompanyScope;
use Illuminate\Support\Collection;

class FleetRepository
{
    public function create(array $payload): Fleet
    {
        return Fleet::create($payload);
    }

    public function findById(int $fleetId): ?Fleet
    {
        return Fleet::find($fleetId);
    }

    public function all(): Collection
    {
        return Fleet::with('fleetRoutes.route')->where('status', 'active')->get();
    }

    public function update(int $fleetId, array $payload): bool
    {
        return Fleet::where('fleet_id', $fleetId)->update($payload) > 0;
    }

    /**
     * IDOR guard: does this fleet belong to the given Operator's company? (Batch 46 step 2: by company, so every
     * Operator of the company may act on it; CompanyScope.)
     */
    public function isOwnedByOperator(int $fleetId, int $companyUserId): bool
    {
        return CompanyScope::whereFleetOfCompany(Fleet::where('fleet_id', $fleetId), $companyUserId)->exists();
    }

    /** Batch 46 step 2: the active buses of an Operator's company, with their routes. */
    public function forCompanyOf(int $companyUserId): Collection
    {
        return CompanyScope::whereFleetOfCompany(Fleet::with('fleetRoutes.route'), $companyUserId)
            ->where('status', 'active')
            ->get();
    }

    /** Batch 46 step 2: does this fleet route run on a bus of the Operator's company? */
    public function fleetRouteIsOwnedByOperator(int $fleetRouteId, int $companyUserId): bool
    {
        return CompanyScope::whereFleetOfCompany(
            Fleet::whereHas('fleetRoutes', fn ($q) => $q->where('fleet_route_id', $fleetRouteId)),
            $companyUserId,
        )->exists();
    }
}
