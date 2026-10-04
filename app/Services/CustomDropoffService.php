<?php

namespace App\Services;

use App\Models\AlightingPoint;
use App\Models\Route;
use App\Repositories\RouteRepository;
use App\Repositories\RouteStopRepository;
use App\Support\Geo\GeoLine;
use Illuminate\Validation\ValidationException;

/**
 * D5: server-authoritative custom drop-off. The client only sends a pin (or, from Batch 28 Stage 3, the id of a
 * designated alighting point); every rule is (re)checked here, both when the timeline is built and again when a ticket
 * is priced, so a tampered payload cannot bypass them.
 *
 * Rules: the route must allow it (routes.custom_dropoff_mode is not "off"); in "designated_points" mode only a listed
 * alighting point is accepted (never raw coordinates), in "free_pin" mode a pin is; the point must be inside the
 * configured region, snap to the route line for the leg's direction within the threshold, be ahead of the boarding
 * point, and NOT be inside an active restricted area (Batch 28 Stage 3, item 10). A free pin must also not be too near
 * the boarding point or an existing stop. Official stops are never affected by any of this.
 */
class CustomDropoffService
{
    public function __construct(
        private RouteSnapService $routeSnapService,
        private RouteStopRepository $routeStopRepository,
        private RouteRepository $routeRepository,
        private RegionService $regionService,
        private DropoffRestrictionService $restrictions,
    ) {}

    /**
     * @return array{snapped_latitude: float, snapped_longitude: float, distance_to_route_m: float, distance_along_route_m: float, km_from_origin: float, offset_min: float|null}
     *
     * @throws ValidationException
     */
    public function evaluate(int $routeId, string $direction, int $boardingStopId, float $lat, float $lng): array
    {
        return $this->evaluateFrom($routeId, $direction, $boardingStopId, null, $lat, $lng);
    }

    /**
     * A designated alighting point instead of a pin. The id is the only thing the client supplies: the coordinates
     * come from the stored point, which must be active, on this route, ahead of the boarding point and outside every
     * restricted area. Same result shape as evaluate(), plus `alighting_point`.
     *
     * @throws ValidationException
     */
    public function evaluatePoint(int $routeId, string $direction, ?int $boardingStopId, ?float $boardingAlongM, int $pointId): array
    {
        $point = AlightingPoint::find($pointId);
        if (! $point || ! $point->is_active || (int) $point->route_id !== $routeId) {
            $this->fail('That drop-off point is not available on this trip\'s route.');
        }

        return $this->evaluateFrom($routeId, $direction, $boardingStopId, $boardingAlongM, (float) $point->latitude, (float) $point->longitude, $point);
    }

    /**
     * Server-side normalisation of a ticket item that names a designated point: the item may carry the point id and
     * nothing that says where it is. Coordinates, label and any destination stop the client sent are replaced by the
     * stored point's own, so a tampered payload cannot move the drop-off.
     *
     * @throws ValidationException
     */
    public function normaliseItem(array $item, int $routeId): array
    {
        if (! isset($item['alighting_point_id'])) {
            return $item;
        }
        if (isset($item['destination_stop_id'])) {
            $this->fail('Choose either a stop or a drop-off point, not both.');
        }

        $point = AlightingPoint::find((int) $item['alighting_point_id']);
        if (! $point || ! $point->is_active || (int) $point->route_id !== $routeId) {
            $this->fail('That drop-off point is not available on this trip\'s route.');
        }

        $item['alighting_point_id'] = (int) $point->id;
        $item['destination_lat'] = (float) $point->latitude;
        $item['destination_lng'] = (float) $point->longitude;
        $item['destination_label'] = $point->name;

        return $item;
    }

    /**
     * Position of any point on the route line: snapped point, distance along the leg and
     * the matching km from the route's first stop (the stored, outbound distances, whichever way
     * the leg runs; interpolated between the bracketing stops, the same frame the fare uses).
     * Null when the route has no generated path.
     *
     * @return array{snap: array, km_from_origin: float}|null
     */
    public function locate(int $routeId, string $direction, float $lat, float $lng): ?array
    {
        $snap = $this->routeSnapService->snap($routeId, $lat, $lng, $direction);
        if (! $snap) {
            return null;
        }

        $stops = $this->routeStopRepository->getOrderedStopsForDirection($routeId, $direction);
        $along = $this->alongByStop($routeId, $direction, $stops);
        if (collect($along)->filter(fn ($v) => $v !== null)->isEmpty()) {
            return null;
        }

        [$km] = $this->interpolate($stops, $along, $snap['distance_along_route_m']);

        // The ordered stops of a reverse leg carry mirrored distances; callers price with the route's own
        // (outbound) distances, so convert back.
        if ($direction === 'reverse') {
            $totalKm = (float) $this->routeStopRepository->getOrderedStops($routeId)->max('distance_from_origin_km');
            $km = round($totalKm - $km, 3);
        }

        return ['snap' => $snap, 'km_from_origin' => $km];
    }

    /**
     * Same rules as evaluate(), but the boarding point may be a position along the leg
     * (metres from the leg start) instead of a stop: used for mid-trip boarding.
     *
     * @throws ValidationException
     */
    public function evaluateFrom(int $routeId, string $direction, ?int $boardingStopId, ?float $boardingAlongM, float $lat, float $lng, ?AlightingPoint $point = null): array
    {
        $cfg = config('timeline.custom_dropoff');

        $route = $this->routeRepository->findById($routeId);
        $mode = $route ? $route->dropoffMode() : Route::MODE_OFF;
        if (! $route || $mode === Route::MODE_OFF) {
            $this->fail('Custom drop-off is not available on this route.');
        }
        if ($point === null && $mode === Route::MODE_DESIGNATED) {
            $this->fail('This route lets you get off only at the listed drop-off points or at an official stop. Choose one of them.');
        }

        if (! $this->regionService->contains($lat, $lng)) {
            $this->fail('That location is outside the service region ('.config('region.name').').');
        }

        $snap = $this->routeSnapService->snap($routeId, $lat, $lng, $direction);
        if (! $snap) {
            $this->fail('This route has no generated path yet, so a custom drop-off cannot be placed.');
        }

        $threshold = $point !== null ? (float) config('dropoff.point_snap_threshold_m') : (float) $cfg['snap_threshold_m'];
        if ($snap['distance_to_route_m'] > $threshold) {
            $this->fail($point !== null
                ? 'The drop-off point "'.$point->name.'" is not on this route line right now. Please choose another point or an official stop.'
                : 'That point is '.round($snap['distance_to_route_m']).' m from the route. Drop the pin within '.(int) $cfg['snap_threshold_m'].' m of the route line.');
        }

        // Batch 28 Stage 3, item 10: a restricted area takes no custom drop-off (official stops are never checked here).
        $block = $this->restrictions->blockingFor($routeId, $direction, (float) $snap['distance_along_route_m'], (float) $snap['total_length_m']);
        if ($block !== null) {
            $this->fail($block['message']);
        }

        $stops = $this->routeStopRepository->getOrderedStopsForDirection($routeId, $direction);
        $along = $this->alongByStop($routeId, $direction, $stops);

        $boardingAlong = $boardingAlongM ?? ($boardingStopId !== null ? ($along[$boardingStopId] ?? null) : null);
        if ($boardingAlong === null) {
            $this->fail('The boarding stop is not on this trip\'s route.');
        }

        $ahead = $snap['distance_along_route_m'] - $boardingAlong;
        if ($ahead < 0 || ($point !== null && $ahead == 0.0)) {
            $this->fail($point !== null
                ? 'The drop-off point "'.$point->name.'" is behind or at your boarding point for this trip\'s direction.'
                : 'That point is behind your boarding stop for this trip\'s direction.');
        }

        if ($point === null) {
            if ($ahead < (float) $cfg['min_ahead_of_boarding_m']) {
                $this->fail('That point is too close to your boarding stop. Choose a point at least '.(int) $cfg['min_ahead_of_boarding_m'].' m further along the route.');
            }

            foreach ($stops as $routeStop) {
                $distance = GeoLine::haversineM($snap['snapped_latitude'], $snap['snapped_longitude'], (float) $routeStop->stop->latitude, (float) $routeStop->stop->longitude);
                if ($distance < (float) $cfg['min_spacing_from_stop_m']) {
                    $this->fail('That point is within '.(int) $cfg['min_spacing_from_stop_m'].' m of the stop "'.$routeStop->stop->stop_name.'". Choose that stop instead.');
                }
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
            'alighting_point' => $point ? ['id' => (int) $point->id, 'name' => $point->name, 'kind' => $point->kind] : null,
        ];
    }

    /**
     * Position (metres along the leg) of every stop of the leg, keyed by stop id.
     *
     * @return array<int, float|null>
     */
    private function alongByStop(int $routeId, string $direction, $stops): array
    {
        $along = [];
        foreach ($stops as $routeStop) {
            $point = $routeStop->snapped_latitude !== null
                ? [(float) $routeStop->snapped_latitude, (float) $routeStop->snapped_longitude]
                : [(float) $routeStop->stop->latitude, (float) $routeStop->stop->longitude];
            $stopSnap = $this->routeSnapService->snap($routeId, $point[0], $point[1], $direction);
            $along[$routeStop->stop_id] = $stopSnap['distance_along_route_m'] ?? null;
        }

        return $along;
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

    /**
     * Whether the passenger can use the pin control right now, and if not, why.
     * available=false with a null reason means the route does not offer it at all
     * (mode off): the UI hides the control. A reason means the route offers it but
     * the current selection cannot use it: the UI shows the control with that reason.
     *
     * @return array{available: bool, reason: string|null}
     */
    public function availability(int $routeId, string $direction, ?int $boardingStopId): array
    {
        $route = $this->routeRepository->findById($routeId);
        if (! $route || $route->dropoffMode() === Route::MODE_OFF) {
            return ['available' => false, 'reason' => null];
        }

        if (! $this->routeSnapService->hasGeometry($routeId)) {
            return ['available' => false, 'reason' => 'This route has no generated path yet, so a drop-off point cannot be placed.'];
        }

        if ($boardingStopId === null) {
            return ['available' => false, 'reason' => 'Choose where you board first, then pin where you want to get off.'];
        }

        $last = $this->routeStopRepository->getOrderedStopsForDirection($routeId, $direction)->last();
        if ($last && (int) $last->stop_id === $boardingStopId) {
            return ['available' => false, 'reason' => 'You board at the last stop of this trip, so there is nothing ahead to drop off at.'];
        }

        return $this->restrictionAvailability($routeId, $direction, $this->boardingAlongForStop($routeId, $direction, $boardingStopId))
            ?? ['available' => true, 'reason' => null];
    }

    /**
     * Everything a drop-off screen needs, from the server: the mode, whether the option can be used, the restricted
     * areas to shade (with their line), and, in designated-points mode, the points ahead of the boarding position that
     * are not inside a restricted area, in route order. Official stops are not part of this (they are never blocked).
     *
     * @return array{mode: string, available: bool, reason: string|null, restricted_areas: array, needs_review: array, designated_points: array}
     */
    public function options(int $routeId, string $direction, ?int $boardingStopId, ?float $boardingAlongM = null): array
    {
        $route = $this->routeRepository->findById($routeId);
        $mode = $route ? $route->dropoffMode() : Route::MODE_OFF;
        $empty = ['mode' => $mode, 'available' => false, 'reason' => null, 'restricted_areas' => [], 'needs_review' => [], 'designated_points' => []];
        if (! $route || $mode === Route::MODE_OFF) {
            return $empty;
        }

        $length = $this->restrictions->routeLengthM($routeId);
        if ($length === null) {
            return ['reason' => 'This route has no generated path yet, so a drop-off point cannot be placed.'] + $empty;
        }

        $boardingAlong = $boardingAlongM ?? ($boardingStopId !== null ? $this->boardingAlongForStop($routeId, $direction, $boardingStopId) : null);

        $areas = [];
        $review = [];
        foreach ($this->restrictions->activeForRoute($routeId) as $row) {
            if (! $row['resolved']['valid']) {
                $review[] = ['id' => (int) $row['restriction']->id, 'name' => $row['restriction']->name, 'reason' => $row['resolved']['reason']];

                continue;
            }
            $r = $row['resolved'];
            $legFrom = $direction === 'reverse' ? $length - $r['to_m'] : $r['from_m'];
            $legTo = $direction === 'reverse' ? $length - $r['from_m'] : $r['to_m'];
            $areas[] = [
                'id' => (int) $row['restriction']->id,
                'name' => $row['restriction']->name,
                'note' => $row['restriction']->note,
                'from_m' => round($legFrom, 1),
                'to_m' => round($legTo, 1),
                'path' => $this->restrictions->shadePath($routeId, (int) $r['from_m'], (int) $r['to_m']),
            ];
        }
        usort($areas, fn ($a, $b) => $a['from_m'] <=> $b['from_m']);

        $availability = $boardingAlong === null
            ? ['available' => false, 'reason' => 'Choose where you board first, then pick where you want to get off.']
            : ($this->restrictionAvailability($routeId, $direction, $boardingAlong) ?? ['available' => true, 'reason' => null]);

        $points = [];
        if ($mode === Route::MODE_DESIGNATED && $boardingAlong !== null && $availability['available']) {
            $candidates = AlightingPoint::where('route_id', $routeId)->where('is_active', true)->get();
            foreach ($candidates as $candidate) {
                $along = $candidate->distanceForDirection($direction);
                if ($along === null || $along <= $boardingAlong) {
                    continue;
                }
                $inside = false;
                foreach ($areas as $area) {
                    if ($along >= $area['from_m'] && $along <= $area['to_m']) {
                        $inside = true;
                        break;
                    }
                }
                if ($inside) {
                    continue;
                }
                $points[] = [
                    'id' => (int) $candidate->id,
                    'name' => $candidate->name,
                    'kind' => $candidate->kind,
                    'municipality' => $candidate->municipality,
                    'latitude' => (float) $candidate->latitude,
                    'longitude' => (float) $candidate->longitude,
                    'distance_along_route_m' => (int) $along,
                ];
            }
            usort($points, fn ($a, $b) => $a['distance_along_route_m'] <=> $b['distance_along_route_m']);
        }

        return [
            'mode' => $mode,
            'available' => $availability['available'] && ($mode !== Route::MODE_DESIGNATED || $points !== []),
            'reason' => $availability['available'] && $mode === Route::MODE_DESIGNATED && $points === []
                ? 'There is no listed drop-off point ahead of your boarding stop on this trip. Please choose an official stop.'
                : $availability['reason'],
            'restricted_areas' => $areas,
            'needs_review' => $review,
            'designated_points' => $points,
        ];
    }

    /** Null when a custom drop-off is possible from here; otherwise the availability answer with the reason. */
    private function restrictionAvailability(int $routeId, string $direction, ?float $boardingAlongM): ?array
    {
        $active = $this->restrictions->activeForRoute($routeId);
        if ($active->isEmpty()) {
            return null;
        }

        $review = $active->first(fn ($row) => ! $row['resolved']['valid']);
        if ($review && config('dropoff.needs_review_fails') !== 'open') {
            return ['available' => false, 'reason' => 'Custom drop-off is not available on this route right now: the restricted area "'.$review['restriction']->name.'" has to be reviewed. Please choose an official stop.'];
        }

        $length = $this->restrictions->routeLengthM($routeId);
        if ($length === null || $boardingAlongM === null) {
            return null;
        }

        $names = $this->restrictions->coversRemainder($routeId, $direction, $boardingAlongM, $length);
        if ($names !== null) {
            return ['available' => false, 'reason' => 'Custom drop-off is not available for the rest of this trip (inside '.implode(', ', $names).'). Please choose an official stop.'];
        }

        return null;
    }

    /** Metres from the leg's start to a stop, from the stop's stored distance (no snapping), or null when unknown. */
    private function boardingAlongForStop(int $routeId, string $direction, int $stopId): ?float
    {
        $routeStop = $this->routeStopRepository->findByRouteAndStop($routeId, $stopId);
        if (! $routeStop || $routeStop->distance_from_origin_m === null) {
            return null;
        }

        $outbound = (float) $routeStop->distance_from_origin_m;
        if ($direction !== 'reverse') {
            return $outbound;
        }

        $length = $this->restrictions->routeLengthM($routeId);

        return $length === null ? null : $length - $outbound;
    }
}
