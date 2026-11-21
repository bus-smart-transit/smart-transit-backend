<?php

namespace App\Repositories;

use App\Models\Route;
use App\Support\FinancialClock;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
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
     * 2026-11-21 (user, Dynamic Route Scheduling): a route is public while one of its buses has a trip on it that is not
     * cancelled or finished, from today to config booking.public_route_window_days ahead; its bus companies are those.
     */
    private function publicQuery(): Builder
    {
        $today = FinancialClock::today();
        $until = Carbon::parse($today)->addDays((int) config('booking.public_route_window_days', 7))->toDateString();
        $running = fn ($q) => $q->where('status', 'active')
            ->whereHas('fleet', fn ($f) => $f->where('status', 'active'))
            ->whereHas('trips', fn ($t) => $t->whereBetween('trip_date', [$today, $until])->whereIn('status', ['scheduled', 'delayed', 'boarding', 'departed', 'in-progress']));

        return Route::query()
            ->whereHas('fleetRoutes', $running)
            ->with(['routeStops.stop:stop_id,stop_name,municipality', 'fleetRoutes' => $running, 'fleetRoutes.fleet:fleet_id,bus_company_name,body_no,company_id']);
    }
}
