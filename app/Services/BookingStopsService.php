<?php

namespace App\Services;

use App\Models\FleetRoute;
use App\Repositories\RouteStopRepository;

/**
 * C3 / D2: the origins and destinations a passenger can pick are computed from
 * the database: stops of a stopping type on any route that has an active fleet,
 * inside the configured region, grouped by municipality. The code does not
 * know any place; a new route appears here as soon as it exists.
 */
class BookingStopsService
{
    public function __construct(
        private RouteStopRepository $routeStopRepository,
        private RegionService $regionService,
    ) {}

    /**
     * @return array{groups: array<int, array{municipality: string|null, provinces: array<int, string>, stops: array<int, array{stop_id: int, name: string, route_ids: array<int, int>}>}>}
     */
    public function list(?int $originStopId = null): array
    {
        $routeIds = FleetRoute::where('status', 'active')->distinct()->pluck('route_id')->map(fn ($id) => (int) $id);
        $stopping = config('timeline.stopping_types');

        $byStop = [];
        $routesWithOrigin = [];
        foreach ($routeIds as $routeId) {
            $routeStops = $this->routeStopRepository->getOrderedStops($routeId);
            if ($originStopId !== null && ! $routeStops->contains('stop_id', $originStopId)) {
                continue;
            }
            $routesWithOrigin[] = $routeId;

            foreach ($routeStops as $routeStop) {
                $stop = $routeStop->stop;
                if (! $stop || ! in_array($routeStop->stop_type ?? 'stop', $stopping, true)) {
                    continue;
                }
                if ($stop->latitude === null || ! $this->regionService->contains((float) $stop->latitude, (float) $stop->longitude)) {
                    continue;
                }
                if ($originStopId !== null && (int) $stop->stop_id === $originStopId) {
                    continue;
                }
                $byStop[$stop->stop_id] ??= ['stop' => $stop, 'route_ids' => []];
                $byStop[$stop->stop_id]['route_ids'][] = $routeId;
            }
        }

        $groups = [];
        foreach ($byStop as $entry) {
            $key = $entry['stop']->municipality ?? '';
            $groups[$key]['municipality'] = $entry['stop']->municipality;
            $groups[$key]['provinces'] = $entry['stop']->municipality ? $this->provincesOf($entry['stop']->municipality) : [];
            $groups[$key]['stops'][] = [
                'stop_id' => (int) $entry['stop']->stop_id,
                'name' => $entry['stop']->stop_name,
                'route_ids' => array_values(array_unique($entry['route_ids'])),
            ];
        }

        ksort($groups);
        foreach ($groups as &$group) {
            usort($group['stops'], fn ($a, $b) => strcmp($a['name'], $b['name']));
        }
        unset($group);

        return ['groups' => array_values($groups)];
    }

    /** @return array<int, string> a municipality can appear under more than one province */
    private function provincesOf(string $municipality): array
    {
        $provinces = [];
        foreach (config('region.provinces') as $province => $municipalities) {
            if (in_array($municipality, $municipalities, true)) {
                $provinces[] = $province;
            }
        }

        return $provinces;
    }
}