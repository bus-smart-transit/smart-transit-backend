<?php

namespace App\Services;

use App\Models\FleetRoute;
use App\Repositories\RouteStopRepository;
use App\Repositories\TripRepository;
use App\Support\BookingWindow;
use App\Support\PlaceGroups;

/**
 * C3 / D2: the origins and destinations a passenger can pick are computed from
 * the database: stops of a stopping type on any route that has an active fleet,
 * inside the configured region, grouped by municipality. The code does not
 * know any place; a new route appears here as soon as it exists.
 *
 * 2026-11-13 (user): with an origin, the destinations are only the stops the bus reaches AFTER the origin: further
 * along the route for outbound trips, back toward the start for return (reverse) trips. A direction counts when the
 * route has a trip in it inside the booking window; a route with no such trip offers its own (outbound) direction.
 */
class BookingStopsService
{
    public function __construct(
        private RouteStopRepository $routeStopRepository,
        private TripRepository $tripRepository,
        private RegionService $regionService,
        private PlaceGroups $places,
    ) {}

    /**
     * @return array{groups: array<int, array{municipality: string|null, provinces: array<int, string>, stops: array<int, array{stop_id: int, name: string, route_ids: array<int, int>}>}>}
     */
    public function list(?int $originStopId = null): array
    {
        $routeIds = FleetRoute::where('status', 'active')->distinct()->pluck('route_id')->map(fn ($id) => (int) $id);
        $stopping = config('timeline.stopping_types');

        $byStop = [];
        $originPlace = $originStopId !== null ? $this->places->membersOf($originStopId) : [];
        $runs = $originStopId !== null
            ? $this->tripRepository->directionsByRoute($routeIds->all(), BookingWindow::today()->toDateString(), BookingWindow::lastDateString())
            : [];
        foreach ($routeIds as $routeId) {
            $routeStops = $this->routeStopRepository->getOrderedStops($routeId)->values();
            // Batch 49 item 6: the origin is a place; a route serves it when it has any stop of that place.
            $originPositions = $originStopId !== null
                ? $routeStops->keys()->filter(fn ($i) => in_array((int) $routeStops[$i]->stop_id, $originPlace, true))->values()
                : collect();
            if ($originStopId !== null && $originPositions->isEmpty()) {
                continue;
            }
            $directions = $runs[$routeId] ?? ['outbound'];

            foreach ($routeStops as $position => $routeStop) {
                if ($originStopId !== null && ! $this->isAhead($position, $originPositions->min(), $originPositions->max(), $directions)) {
                    continue;
                }
                $stop = $routeStop->stop;
                if (! $stop || ! in_array($routeStop->stop_type ?? 'stop', $stopping, true)) {
                    continue;
                }
                if ($stop->latitude === null || ! $this->regionService->contains((float) $stop->latitude, (float) $stop->longitude)) {
                    continue;
                }
                if ($originStopId !== null && in_array((int) $stop->stop_id, $originPlace, true)) {
                    continue;
                }
                // One entry per place, named by its oldest stop, with the routes of all its stops.
                $place = $this->places->placeOf((int) $stop->stop_id);
                $byStop[$place] ??= ['stop' => $stop, 'route_ids' => []];
                if ((int) $stop->stop_id === $place) {
                    $byStop[$place]['stop'] = $stop;
                }
                $byStop[$place]['municipality'] ??= $stop->municipality;
                $byStop[$place]['route_ids'][] = $routeId;
            }
        }

        $groups = [];
        foreach ($byStop as $place => $entry) {
            $municipality = $entry['municipality'] ?? $entry['stop']->municipality;
            $key = $municipality ?? '';
            $groups[$key]['municipality'] = $municipality;
            $groups[$key]['provinces'] = $municipality ? $this->provincesOf($municipality) : [];
            $groups[$key]['stops'][] = [
                'stop_id' => (int) $place,
                'stop_code' => $this->places->placeCode($place) ?? $entry['stop']->stop_code,
                'name' => $this->places->displayName($place) ?? $entry['stop']->stop_name,
                'route_ids' => array_values(array_unique($entry['route_ids'])),
                // Batch 51 item 2: the stop's place, so the homepage finds the nearest stop on the passenger's device
                // (the passenger's location is never sent for this). Stop positions are public on the route maps too.
                'latitude' => $entry['stop']->latitude !== null ? (float) $entry['stop']->latitude : null,
                'longitude' => $entry['stop']->longitude !== null ? (float) $entry['stop']->longitude : null,
            ];
        }

        ksort($groups);
        foreach ($groups as &$group) {
            usort($group['stops'], fn ($a, $b) => strcmp($a['name'], $b['name']));
        }
        unset($group);

        return ['groups' => array_values($groups)];
    }

    /**
     * Is the stop at this position reached after the origin in one of the directions the route runs? Outbound goes up
     * the stop order, reverse goes down it.
     *
     * @param  array<int, string>  $directions
     */
    private function isAhead(int $position, int $originFirst, int $originLast, array $directions): bool
    {
        return (in_array('outbound', $directions, true) && $position > $originFirst)
            || (in_array('reverse', $directions, true) && $position < $originLast);
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
