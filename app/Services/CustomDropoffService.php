<?php

namespace App\Services;

use App\Repositories\RouteRepository;
use App\Repositories\RouteStopRepository;
use App\Support\Geo\GeoLine;
use Illuminate\Validation\ValidationException;

/**
 * D5: server-authoritative custom drop-off. The client only sends a pin; every
 * rule is (re)checked here, both when the timeline is built and again when a
 * ticket is priced, so a tampered payload cannot bypass them.
 *
 * Rules: the route must allow it (routes.allow_custom_dropoff); the pin must be
 * inside the configured region; it must snap to the route line for the leg's
 * direction within the threshold; it must be ahead of, and not too near, the
 * boarding point; and not too close to an existing stop.
 */
class CustomDropoffService
{
    public function __construct(
        private RouteSnapService $routeSnapService,
        private RouteStopRepository $routeStopRepository,
        private RouteRepository $routeRepository,
        private RegionService $regionService,
    ) {}

    /**
     * @return array{snapped_latitude: float, snapped_longitude: float, distance_to_route_m: float, distance_along_route_m: float, km_from_origin: float, offset_min: float|null}
     *
     * @throws ValidationException
     */
    public function evaluate(int $routeId, string $direction, int $boardingStopId, float $lat, float $lng): array
    {
        $cfg = config('timeline.custom_dropoff');

        $route = $this->routeRepository->findById($routeId);
        if (! $route || ! $route->allow_custom_dropoff) {
            $this->fail('Custom drop-off is not available on this route.');
        }

        if (! $this->regionService->contains($lat, $lng)) {
            $this->fail('That location is outside the service region ('.config('region.name').').');
        }

        $snap = $this->routeSnapService->snap($routeId, $lat, $lng, $direction);
        if (! $snap) {
            $this->fail('This route has no generated path yet, so a custom drop-off cannot be placed.');
        }

        if ($snap['distance_to_route_m'] > (float) $cfg['snap_threshold_m']) {
            $this->fail('That point is '.round($snap['distance_to_route_m']).' m from the route. Drop the pin within '.(int) $cfg['snap_threshold_m'].' m of the route line.');
        }

        $stops = $this->routeStopRepository->getOrderedStopsForDirection($routeId, $direction);
        $along = [];
        foreach ($stops as $routeStop) {
            $point = $routeStop->snapped_latitude !== null
                ? [(float) $routeStop->snapped_latitude, (float) $routeStop->snapped_longitude]
                : [(float) $routeStop->stop->latitude, (float) $routeStop->stop->longitude];
            $stopSnap = $this->routeSnapService->snap($routeId, $point[0], $point[1], $direction);
            $along[$routeStop->stop_id] = $stopSnap['distance_along_route_m'] ?? null;
        }

        $boardingAlong = $along[$boardingStopId] ?? null;
        if ($boardingAlong === null) {
            $this->fail('The boarding stop is not on this trip\'s route.');
        }

        $ahead = $snap['distance_along_route_m'] - $boardingAlong;
        if ($ahead < 0) {
            $this->fail('That point is behind your boarding stop for this trip\'s direction.');
        }
        if ($ahead < (float) $cfg['min_ahead_of_boarding_m']) {
            $this->fail('That point is too close to your boarding stop. Choose a point at least '.(int) $cfg['min_ahead_of_boarding_m'].' m further along the route.');
        }

        foreach ($stops as $routeStop) {
            $distance = GeoLine::haversineM($snap['snapped_latitude'], $snap['snapped_longitude'], (float) $routeStop->stop->latitude, (float) $routeStop->stop->longitude);
            if ($distance < (float) $cfg['min_spacing_from_stop_m']) {
                $this->fail('That point is within '.(int) $cfg['min_spacing_from_stop_m'].' m of the stop "'.$routeStop->stop->stop_name.'". Choose that stop instead.');
            }
        }

        [$km, $offset] = $this->interpolate($stops, $along, $snap['distance_along_route_m']);

        return [
            'snapped_latitude' => $snap['snapped_latitude'],
            'snapped_longitude' => $snap['snapped_longitude'],
            'distance_to_route_m' => $snap['distance_to_route_m'],
            'distance_along_route_m' => $snap['distance_along_route_m'],
            'km_from_origin' => $km,
            'offset_min' => $offset,
        ];
    }

    /**
     * Position and scheduled offset of the pin between the two stops that
     * bracket it along the route line.
     *
     * @return array{0: float, 1: float|null}
     */
    private function interpolate($stops, array $along, float $pointAlong): array
    {
        $rows = $stops->filter(fn ($s) => ($along[$s->stop_id] ?? null) !== null)->values();
        $last = max(0, $rows->count() - 1);

        for ($i = 0; $i < $last; $i++) {
            $a = $rows[$i];
            $b = $rows[$i + 1];
            $aAlong = $along[$a->stop_id];
            $bAlong = $along[$b->stop_id];
            if ($pointAlong >= $aAlong && $pointAlong <= $bAlong) {
                $fraction = $bAlong > $aAlong ? ($pointAlong - $aAlong) / ($bAlong - $aAlong) : 0.0;
                $km = (float) $a->distance_from_origin_km + $fraction * ((float) $b->distance_from_origin_km - (float) $a->distance_from_origin_km);
                $offset = ($a->scheduled_offset_min !== null && $b->scheduled_offset_min !== null)
                    ? (float) $a->scheduled_offset_min + $fraction * ((float) $b->scheduled_offset_min - (float) $a->scheduled_offset_min)
                    : null;

                return [round($km, 3), $offset === null ? null : round($offset, 2)];
            }
        }

        $end = $pointAlong <= ($along[$rows->first()->stop_id] ?? 0) ? $rows->first() : $rows->last();

        return [round((float) $end->distance_from_origin_km, 3), $end->scheduled_offset_min === null ? null : (float) $end->scheduled_offset_min];
    }

    private function fail(string $message): never
    {
        throw ValidationException::withMessages(['dropoff' => [$message]]);
    }
}
