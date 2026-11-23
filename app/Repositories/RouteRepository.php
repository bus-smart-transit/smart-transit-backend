<?php

namespace App\Repositories;

use App\Models\Route;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class RouteRepository
{
    public function create(array $payload): Route
    {
        return Route::create($payload);
    }

    /**
     * 2026-11-21 (user): the colours routes already use (RouteColor::nextFree skips them).
     *
     * @return array<int, string>
     */
    public function usedColors(): array
    {
        return Route::query()->whereNotNull('color')->pluck('color')->all();
    }

    /**
     * 2026-11-23: one company's routes with their two ends (the duplicate-route check). A null company means the routes
     * that belong to no company.
     *
     * @return Collection<int, Route>
     */
    public function endsForCompany(?int $companyId): Collection
    {
        return Route::query()
            ->when($companyId === null, fn ($q) => $q->whereNull('company_id'), fn ($q) => $q->where('company_id', $companyId))
            ->get(['route_id', 'route_name', 'origin', 'destination', 'company_id']);
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

    /** Phase 4 (2026-11-19): a route's custom drop-off settings only (moved from RouteController::updateCustomDropoff). */
    public function customDropoffSettings(int $routeId): ?Route
    {
        return Route::where('route_id', $routeId)->first(['route_id', 'allow_custom_dropoff', 'custom_dropoff_mode']);
    }

    /**
     * 2026-11-20: the routes a bus runs (an active bus on an active assignment) with a stop in one of these towns
     * (lower case), with their stops and running buses, by name.
     *
     * @param  array<int, string>  $lowerTowns
     * @return Collection<int, Route>
     */
    public function servingTowns(array $lowerTowns): Collection
    {
        if ($lowerTowns === []) {
            return new Collection;
        }

        return $this->publicQuery()
            ->whereHas('routeStops.stop', fn ($q) => $q->whereIn(DB::raw('lower(municipality)'), $lowerTowns))
            ->orderBy('route_name')
            ->get();
    }

    /** 2026-11-20: one route a bus runs, for its public page; null when no bus runs it. */
    public function findPublic(int $routeId): ?Route
    {
        return $this->publicQuery()->where('route_id', $routeId)->first();
    }

    /**
     * 2026-11-21 (user): every route with at least two stops is public, whether or not a bus runs it now (its page says
     * when no trip is scheduled), with the company that runs it. (Earlier: only routes with a trip in the next 7 days,
     * which emptied the pages whenever nothing was scheduled.)
     */
    private function publicQuery(): Builder
    {
        return Route::query()
            ->has('routeStops', '>=', 2)
            ->with(['routeStops.stop:stop_id,stop_name,municipality', 'company:company_id,company_name']);
    }

    /**
     * 2026-11-21 (user): every public route once, by name (the passengers' "View all routes").
     *
     * @return Collection<int, Route>
     */
    public function allPublic(): Collection
    {
        return $this->publicQuery()->orderBy('route_name')->orderBy('route_id')->get();
    }
}
