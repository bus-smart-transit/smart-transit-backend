<?php

namespace App\Repositories;

use App\Models\AlightingPoint;
use Illuminate\Support\Collection;

/** 2026-10-30: designated alighting points (moved out of DropoffRulesController). */
class AlightingPointRepository
{
    /**
     * @param  array{route_id?: int|null, municipality?: string|null, is_active?: bool|null}  $filters  a key that is
     *                                                                                                  absent is not filtered
     * @param  array<int, int>|null  $routeIds  limit to these routes (an Operator's), or null for all
     * @return Collection<int, AlightingPoint>
     */
    public function list(array $filters, ?array $routeIds): Collection
    {
        return AlightingPoint::with('route:route_id,route_name')
            ->when(isset($filters['route_id']), fn ($q) => $q->where('route_id', (int) $filters['route_id']))
            ->when(! empty($filters['municipality']), fn ($q) => $q->where('municipality', $filters['municipality']))
            ->when(array_key_exists('is_active', $filters), fn ($q) => $q->where('is_active', (bool) $filters['is_active']))
            ->when($routeIds !== null, fn ($q) => $q->whereIn('route_id', $routeIds))
            ->orderBy('route_id')->orderBy('distance_along_outbound_m')
            ->get();
    }

    public function findById(int $id): ?AlightingPoint
    {
        return AlightingPoint::find($id);
    }

    /** @param  array<string, mixed>  $attributes */
    public function create(array $attributes): AlightingPoint
    {
        return AlightingPoint::create($attributes);
    }

    /** @param  array<string, mixed>  $attributes */
    public function update(AlightingPoint $point, array $attributes): AlightingPoint
    {
        $point->update($attributes);

        return $point->fresh();
    }
}
