<?php

namespace App\Repositories;

use App\Models\Route;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class RouteRepository
{
    public function create(array $payload): Route
    {
        return Route::create($payload);
    }

    public function findById(int $routeId): ?Route
    {
        return Route::with([
            'routeStops' => function ($q) {
                $q->orderBy('stop_order')->with('stop');
            },
        ])->find($routeId);
    }

    public function exists(int $routeId): bool
    {
        return Route::whereKey($routeId)->exists();
    }

    public function findWithStops(int $routeId): ?Route
    {
        return Route::with('routeStops.stop')->find($routeId);
    }

    /** Every route, or (2026-11-06) only one company's when $companyId is given. */
    public function all(?int $companyId = null): Collection
    {
        return Route::with([
            'routeStops' => function ($q) {
                $q->orderBy('stop_order')->with('stop');
            },
        ])->when($companyId !== null, fn ($q) => $q->where('company_id', $companyId))->get();
    }

    public function update(int $routeId, array $payload): bool
    {
        return Route::where('route_id', $routeId)->update($payload) > 0;
    }

    public function delete(int $routeId): bool
    {
        return Route::where('route_id', $routeId)->delete() > 0;
    }

    /**
     * Batch 59: what still uses a route (a route is deleted only when all are zero). Trips hang off the bus assignments
     * (fleets_routes), so a route that ever had a bus is never deleted.
     *
     * @return array{stops: int, buses: int, fare_rules: int}
     */
    public function usage(int $routeId): array
    {
        return [
            'stops' => DB::table('route_stop')->where('route_id', $routeId)->count(),
            'buses' => DB::table('fleets_routes')->where('route_id', $routeId)->count(),
            'fare_rules' => DB::table('fare_rules')->where('route_id', $routeId)->count(),
        ];
    }

    public function allWithOrderedStops(): Collection
    {
        return Route::with([
            'routeStops' => function ($q) {
                $q->orderBy('stop_order')->with('stop');
            },
        ])->get();
    }
}
