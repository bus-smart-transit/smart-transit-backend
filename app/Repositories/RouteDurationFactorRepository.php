<?php

namespace App\Repositories;

use App\Models\RouteDurationFactor;
use Illuminate\Support\Collection;

/** 2026-10-30: peak-hour duration windows (moved out of RouteDurationFactorController). */
class RouteDurationFactorRepository
{
    /**
     * @param  array<int, int>|null  $routeIds  limit to these routes (an Operator's), or null for all
     * @return Collection<int, RouteDurationFactor>
     */
    public function list(?int $routeId, ?array $routeIds): Collection
    {
        return RouteDurationFactor::with('route:route_id,route_name')
            ->when($routeId !== null, fn ($q) => $q->where('route_id', $routeId))
            ->when($routeIds !== null, fn ($q) => $q->whereIn('route_id', $routeIds))
            ->orderBy('route_id')->orderBy('start_time')
            ->get();
    }

    public function findById(int $id): ?RouteDurationFactor
    {
        return RouteDurationFactor::find($id);
    }

    /** @param  array<string, mixed>  $attributes */
    public function create(array $attributes): RouteDurationFactor
    {
        return RouteDurationFactor::create($attributes);
    }

    /** @param  array<string, mixed>  $attributes */
    public function update(RouteDurationFactor $window, array $attributes): RouteDurationFactor
    {
        $window->update($attributes);

        return $window->fresh();
    }

    public function delete(RouteDurationFactor $window): void
    {
        $window->delete();
    }
}
