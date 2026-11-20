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
     * @return array{province: string, routes: list<array<string, mixed>>}|null null for a province the region does not have
     */
    public function forProvince(string $province): ?array
    {
        $towns = $this->townsOf($province);
        if ($towns === null) {
            return null;
        }
        $lower = array_map(fn ($town) => mb_strtolower($town), $towns);

        $routes = $this->routes->servingTowns($lower)->map(function (Route $route) use ($lower) {
            $stops = $route->routeStops->sortBy('stop_order');
            $inProvince = $stops->map(fn ($rs) => $rs->stop?->municipality)
                ->filter(fn ($town) => $town !== null && in_array(mb_strtolower($town), $lower, true))
                ->unique()->values()->all();

            return [
                'route_id' => (int) $route->route_id,
                'route_name' => $route->route_name,
                'origin' => $route->origin,
                'destination' => $route->destination,
                'stop_count' => $stops->count(),
                'towns_in_province' => $inProvince,
                'bus_companies' => $route->fleetRoutes->map(fn ($fr) => $fr->fleet?->bus_company_name)->filter()->unique()->values()->all(),
            ];
        })->values()->all();

        return ['province' => $this->provinceName($province), 'routes' => $routes];
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
            'origin' => $route->origin,
            'destination' => $route->destination,
            'provinces' => $this->provincesOf($towns),
            'bus_companies' => $route->fleetRoutes->map(fn ($fr) => $fr->fleet?->bus_company_name)->filter()->unique()->values()->all(),
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
