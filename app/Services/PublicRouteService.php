<?php

namespace App\Services;

use App\Models\Route;
use App\Models\Trip;
use App\Repositories\RouteRepository;
use App\Repositories\TripRepository;
use App\Support\BusLabel;
use Illuminate\Support\Carbon;

/**
 * 2026-11-20 (user): the passengers' route pages. A province picture on the homepage opens the routes that serve that
 * province; a route opens its own page with its stops (RouteService::getPublicStopsForRoute) and today's departures.
 *
 * A route serves a province when one of its stops is in one of the province's towns (config region.provinces, the same
 * map the homepage cards are built from). Only routes a bus runs (an active bus on an active assignment) are listed.
 * Public data only: route names, places, departure times and bus labels, never plates or people.
 */
class PublicRouteService
{
    public function __construct(
        private RouteRepository $routes,
        private TripRepository $trips,
    ) {}

    /**
     * @return array{province: string, routes: list<array<string, mixed>>, groups: list<array<string, mixed>>}|null null for a province the region does not have
     */
    public function forProvince(string $province): ?array
    {
        $towns = $this->townsOf($province);
        if ($towns === null) {
            return null;
        }
        $lower = array_map(fn ($town) => mb_strtolower($town), $towns);

        $found = $this->routes->servingTowns($lower);
        $routes = $found->map(function (Route $route) use ($lower) {
            $stops = $route->routeStops->sortBy('stop_order');
            $inProvince = $stops->map(fn ($rs) => $rs->stop?->municipality)
                ->filter(fn ($town) => $town !== null && in_array(mb_strtolower($town), $lower, true))
                ->unique()->values()->all();

            return [
                'route_id' => (int) $route->route_id,
                'route_name' => $route->route_name,
                'color' => $route->color, // 2026-11-21 (user): the route's own colour
                'origin' => $route->origin,
                'destination' => $route->destination,
                'stop_count' => $stops->count(),
                'towns_in_province' => $inProvince,
                'company_name' => $route->company?->company_name, // 2026-11-21 (user): the company that runs the route
            ];
        })->values()->all();

        return ['province' => $this->provinceName($province), 'routes' => $routes, 'groups' => $this->groups($found)];
    }

    /**
     * 2026-11-21 (user): "View all routes" on the homepage: every route once (no repeats across provinces), by name,
     * with the company that runs it.
     *
     * @return array{routes: list<array{route_id: int, route_name: string, color: ?string, origin: ?string, destination: ?string, company_name: ?string}>}
     */
    public function all(): array
    {
        $routes = $this->routes->allPublic();

        return [
            'routes' => $routes->map(fn (Route $route) => [
                'route_id' => (int) $route->route_id,
                'route_name' => $route->route_name,
                'color' => $route->color,
                'origin' => $route->origin,
                'destination' => $route->destination,
                'company_name' => $route->company?->company_name,
            ])->values()->all(),
            'groups' => $this->groups($routes),
        ];
    }

    /**
     * 2026-11-21 (user): routes that start in the same town and end in the same town are shown as one card, listing the
     * companies that run them, each opening its own route (companies keep their own stops, so stop names can differ
     * while the towns match). The card is titled with the first and last stop of its earliest route.
     *
     * @param  iterable<Route>  $routes  with routeStops.stop and company loaded
     * @return list<array{key: string, title: string, color: ?string, routes: list<array{route_id: int, route_name: string, company_name: ?string}>}>
     */
    private function groups(iterable $routes): array
    {
        $groups = [];
        foreach ($routes as $route) {
            $stopping = $route->routeStops->sortBy('stop_order')->filter(fn ($rs) => $rs->stop_type !== 'pass_through')->values();
            $first = $stopping->first()?->stop;
            $last = $stopping->last()?->stop;
            $place = fn ($stop, ?string $fallback) => mb_strtolower(trim((string) ($stop?->municipality ?: $stop?->stop_name ?: $fallback)));
            $key = $place($first, $route->origin).'|'.$place($last, $route->destination);

            $groups[$key] ??= ['key' => $key, 'routes' => []];
            $groups[$key]['routes'][] = [
                'route_id' => (int) $route->route_id,
                'route_name' => $route->route_name,
                'company_name' => $route->company?->company_name,
                'color' => $route->color,
                'title' => trim(($first?->stop_name ?: $route->origin).' → '.($last?->stop_name ?: $route->destination)),
            ];
        }

        return collect($groups)->map(function (array $group) {
            $routes = collect($group['routes'])->sortBy('route_id')->values();
            $earliest = $routes->first();

            return [
                'key' => $group['key'],
                'title' => $earliest['title'],
                'color' => $earliest['color'],
                'routes' => $routes->map(fn ($r) => ['route_id' => $r['route_id'], 'route_name' => $r['route_name'], 'company_name' => $r['company_name']])
                    ->sortBy(fn ($r) => mb_strtolower((string) $r['company_name']))->values()->all(),
            ];
        })->sortBy(fn ($g) => mb_strtolower($g['title']))->values()->all();
    }

    /** @return array<string, mixed>|null */
    public function summary(int $routeId): ?array
    {
        $route = $this->routes->findPublic($routeId);
        if (! $route) {
            return null;
        }
        $towns = $route->routeStops->map(fn ($rs) => $rs->stop?->municipality)->filter()->unique()->values()->all();

        return [
            'route_id' => (int) $route->route_id,
            'route_name' => $route->route_name,
            'color' => $route->color,
            'origin' => $route->origin,
            'destination' => $route->destination,
            'provinces' => $this->provincesOf($towns),
            'company_name' => $route->company?->company_name,
        ];
    }

    /**
     * Today's departures on the route still to run or on the road, earliest first, each with the way it runs.
     *
     * @return list<array<string, mixed>>
     */
    public function departures(int $routeId): array
    {
        $route = $this->routes->findPublic($routeId);
        if (! $route) {
            return [];
        }
        $today = Carbon::now(config('app.timezone', 'Asia/Manila'))->toDateString();

        return $this->trips->openOnRouteOnDate($routeId, $today)->map(function (Trip $trip) use ($route) {
            $reverse = $trip->leg_direction === 'reverse';

            return [
                'trip_id' => (int) $trip->trip_id,
                'trip_date' => $trip->trip_date?->toDateString(),
                'departure_time' => substr((string) $trip->departure_time, 0, 5),
                'from' => $reverse ? $route->destination : $route->origin,
                'to' => $reverse ? $route->origin : $route->destination,
                'direction' => $reverse ? 'reverse' : 'outbound',
                'service_type' => (string) ($trip->service_type ?? 'regular'),
                'bus_label' => BusLabel::for($trip->fleetRoute?->fleet),
                'status' => (string) $trip->status,
            ];
        })->values()->all();
    }

    /** @return array<int, string>|null the towns of a province (case-insensitive name), null when it is not one */
    private function townsOf(string $province): ?array
    {
        foreach ((array) config('region.provinces', []) as $name => $towns) {
            if (mb_strtolower((string) $name) === mb_strtolower(trim($province))) {
                return array_values((array) $towns);
            }
        }

        return null;
    }

    private function provinceName(string $province): string
    {
        foreach (array_keys((array) config('region.provinces', [])) as $name) {
            if (mb_strtolower((string) $name) === mb_strtolower(trim($province))) {
                return (string) $name;
            }
        }

        return $province;
    }

    /**
     * @param  array<int, string>  $towns
     * @return list<string>
     */
    private function provincesOf(array $towns): array
    {
        $lower = array_map(fn ($town) => mb_strtolower((string) $town), $towns);
        $found = [];
        foreach ((array) config('region.provinces', []) as $name => $provinceTowns) {
            foreach ((array) $provinceTowns as $town) {
                if (in_array(mb_strtolower((string) $town), $lower, true)) {
                    $found[] = (string) $name;
                    break;
                }
            }
        }

        return $found;
    }
}
