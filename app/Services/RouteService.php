<?php

namespace App\Services;

use App\Models\Route;
use App\Models\User;
use App\Repositories\RouteGeometryRepository;
use App\Repositories\RouteRepository;
use App\Repositories\RouteStopRepository;
use App\Repositories\StopRepository;
use App\Support\Geo\GeoLine;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Validation\ValidationException;

class RouteService
{
    public function __construct(
        private RouteRepository $routeRepository,
        private RouteStopRepository $routeStopRepository,
        private StopRepository $stopRepository,
        private RouteGeometryRepository $geometryRepository,
        private RouteGeometryGenerator $geometryGenerator,
    ) {}

    // $payload = ['origin','destination','route_name']
    public function createRoute(array $payload): object
    {
        return $this->routeRepository->create($payload);
    }

    public function listRoutes(?User $user = null): object
    {
        $routes = $this->routeRepository->all();

        return $routes->each(fn (Route $route) => $this->attachGeometryState($route, $user));
    }

    public function getRouteWithStops(int $routeId, ?User $user = null): ?object
    {
        $route = $this->routeRepository->findWithStops($routeId);

        return $route ? $this->attachGeometryState($route, $user) : null;
    }

    /**
     * Public endpoint payload: ordered stops with coordinates for a route.
     * Used by the passenger map (route polyline) and driver navigation map.
     * Moved here from RouteController (was direct RouteStop::with()->where()
     * query building in the controller — a Controller -> Service ->
     * Repository layering violation).
     */
    public function getPublicStopsForRoute(int $routeId, ?string $direction = 'outbound'): Collection
    {
        $geometry = $this->geometryRepository->get($routeId);

        return $this->routeStopRepository->getOrderedStopsForDirection($routeId, $direction)
            ->map(function ($rs) use ($geometry) {
                $latitude = $rs->stop?->latitude !== null ? (float) $rs->stop->latitude : null;
                $longitude = $rs->stop?->longitude !== null ? (float) $rs->stop->longitude : null;
                $projection = $geometry && $latitude !== null && $longitude !== null
                    ? GeoLine::project($geometry['coordinates'], $latitude, $longitude)
                    : null;

                return [
                    'stop_id' => $rs->stop_id,
                    'stop_name' => $rs->stop?->stop_name,
                    'municipality' => $rs->stop?->municipality,
                    'latitude' => $latitude,
                    'longitude' => $longitude,
                    'snapped_latitude' => $rs->snapped_latitude !== null ? (float) $rs->snapped_latitude : null,
                    'snapped_longitude' => $rs->snapped_longitude !== null ? (float) $rs->snapped_longitude : null,
                    'distance_to_line_m' => $projection ? round((float) $projection['distance_to_line_m'], 1) : null,
                    'stop_order' => $rs->stop_order,
                    'stop_type' => $rs->stop_type ?? 'stop',
                    'served_by_express' => (bool) $rs->served_by_express,
                    'express_offset_min' => $rs->express_offset_min !== null ? (int) $rs->express_offset_min : null,
                    'distance_from_origin_km' => (float) $rs->distance_from_origin_km,
                ];
            })
            ->values();
    }

    /**
     * Canonical route line as GeoJSON ([lng, lat]); the reverse direction is
     * the same line reversed (equivalent to ST_Reverse).
     */
    public function getRouteGeometry(int $routeId, ?string $direction = 'outbound'): array
    {
        $geometry = $this->geometryRepository->get($routeId);
        $status = $this->geometryRepository->status($routeId) ?? [];

        if ($geometry && $direction === 'reverse') {
            $geometry['coordinates'] = GeoLine::reverse($geometry['coordinates']);
        }

        return [
            'route_id' => $routeId,
            'direction' => $direction === 'reverse' ? 'reverse' : 'outbound',
            'geometry' => $geometry,
            'total_length_m' => $geometry ? round(GeoLine::lengthM($geometry['coordinates']), 1) : null,
            'geometry_stale' => (bool) ($status['geometry_stale'] ?? false),
            'geometry_generated_at' => $status['geometry_generated_at'] ?? null,
            'geometry_error' => $status['geometry_error'] ?? null,
            'geometry_regeneration_pending' => Cache::has(RouteGeometryGenerator::pendingCacheKey($routeId)),
        ];
    }

    // Routes are shared network data, so every operator and admin may redraw any route (cooldown, throttle and audit log still apply).
    public function canRegenerateGeometry(int $routeId, ?User $user): bool
    {
        return in_array($user?->role, ['admin', 'operator'], true);
    }

    public function queueManualGeometryRegeneration(int $routeId): void
    {
        if (! Route::where('route_id', $routeId)->exists()) {
            abort(404);
        }

        $pendingKey = RouteGeometryGenerator::pendingCacheKey($routeId);
        if (Cache::has($pendingKey)) {
            abort(409, 'A route line redraw is already running.');
        }

        $cooldownMinutes = max(1, (int) config('route_geometry.redraw_cooldown_minutes', 15));
        $cooldownKey = "route_geometry:redraw_cooldown:{$routeId}";
        if (! Cache::add($cooldownKey, now()->toIso8601String(), $cooldownMinutes * 60)) {
            abort(429, "This route was redrawn recently. Try again in {$cooldownMinutes} minutes.");
        }

        try {
            $this->geometryGenerator->queueRegeneration($routeId);
            AuditLogger::log('route.geometry_redraw_queued', 'Route', $routeId, [
                'requested_at' => now()->toIso8601String(),
                'cooldown_minutes' => $cooldownMinutes,
            ]);
        } catch (\Throwable $exception) {
            Cache::forget($cooldownKey);
            Cache::forget($pendingKey);
            throw $exception;
        }
    }

    private function attachGeometryState(Route $route, ?User $user): Route
    {
        $route->setAttribute('geometry_regeneration_pending', Cache::has(RouteGeometryGenerator::pendingCacheKey((int) $route->route_id)));
        $route->setAttribute('redraw', RouteGeometryGenerator::redrawStatus((int) $route->route_id));
        $route->setAttribute('can_regenerate_geometry', $this->canRegenerateGeometry((int) $route->route_id, $user));

        return $route;
    }

    public function setAllowCustomDropoff(int $routeId, bool $allow): bool
    {
        // The older on/off switch: on keeps designated-points mode if that is what the route had, otherwise free pin.
        $current = Route::where('route_id', $routeId)->value('custom_dropoff_mode');
        $mode = ! $allow
            ? Route::MODE_OFF
            : ($current === Route::MODE_DESIGNATED ? $current : Route::MODE_FREE_PIN);

        return $this->routeRepository->update($routeId, ['allow_custom_dropoff' => $allow, 'custom_dropoff_mode' => $mode]);
    }

    /** Batch 28 Stage 3, item 10: off | designated_points | free_pin (the old on/off flag follows it). */
    public function setCustomDropoffMode(int $routeId, string $mode): bool
    {
        if (! in_array($mode, Route::MODES, true)) {
            return false;
        }

        return $this->routeRepository->update($routeId, [
            'allow_custom_dropoff' => $mode !== Route::MODE_OFF,
            'custom_dropoff_mode' => $mode,
        ]);
    }

    public function updateStopType(int $routeId, int $routeStopId, string $stopType): bool
    {
        return $this->routeStopRepository->updateStopType($routeId, $routeStopId, $stopType);
    }

    /** Whether express trips stop here, and (optionally) when an express trip reaches it. */
    public function updateStopExpress(int $routeId, int $routeStopId, array $payload): bool
    {
        return $this->routeStopRepository->updateExpress($routeId, $routeStopId, $payload);
    }

    // $payload = ['stop_id','stop_order','distance_from_origin_km']
    // route_stop_table is folded into RouteService — no separate service/controller
    public function addStopToRoute(int $routeId, array $payload): object
    {
        $orderedStops = $this->routeStopRepository->getOrderedStops($routeId);

        $alreadyAssigned = $orderedStops->contains(function ($routeStop) use ($payload) {
            return (int) $routeStop->stop_id === (int) $payload['stop_id'];
        });

        if ($alreadyAssigned) {
            throw ValidationException::withMessages([
                'stop_id' => ['This stop is already assigned to the selected route.'],
            ]);
        }

        $orderTaken = $orderedStops->contains(function ($routeStop) use ($payload) {
            return (int) $routeStop->stop_order === (int) $payload['stop_order'];
        });

        if ($orderTaken) {
            throw ValidationException::withMessages([
                'stop_order' => ['This stop order is already used on the selected route.'],
            ]);
        }

        $distanceFromOrigin = isset($payload['distance_from_origin_km'])
            ? (float) $payload['distance_from_origin_km']
            : $this->estimateDistanceFromOrigin($orderedStops, (int) $payload['stop_id'], (int) $payload['stop_order']);

        $routeStop = $this->routeStopRepository->create(array_merge(
            $payload,
            ['distance_from_origin_km' => $distanceFromOrigin],
            ['route_id' => $routeId]
        ));

        $this->geometryGenerator->queueRegeneration($routeId);

        return $routeStop;
    }

    public function removeStopFromRoute(int $routeId, int $routeStopId): bool
    {
        $deleted = $this->routeStopRepository->delete($routeStopId);

        if ($deleted) {
            $this->routeStopRepository->compactStopOrder($routeId);
            $this->geometryGenerator->queueRegeneration($routeId);
        }

        return $deleted;
    }

    private function estimateDistanceFromOrigin(Collection $orderedStops, int $newStopId, int $newStopOrder): float
    {
        $newStop = $this->stopRepository->findById($newStopId);

        if (! $newStop || $newStop->latitude === null || $newStop->longitude === null || $orderedStops->isEmpty()) {
            return 0.0;
        }

        $orderedArray = $orderedStops->all();
        $prev = null;
        $next = null;

        foreach ($orderedArray as $routeStop) {
            if ((int) $routeStop->stop_order < $newStopOrder) {
                $prev = $routeStop;

                continue;
            }
            if ((int) $routeStop->stop_order > $newStopOrder) {
                $next = $routeStop;
                break;
            }
        }

        if ($prev && $next) {
            $prevStop = $prev->stop;
            $nextStop = $next->stop;
            if ($prevStop && $nextStop && $prevStop->latitude !== null && $prevStop->longitude !== null && $nextStop->latitude !== null && $nextStop->longitude !== null) {
                $between = max(0.001, $this->haversineKm((float) $prevStop->latitude, (float) $prevStop->longitude, (float) $nextStop->latitude, (float) $nextStop->longitude));
                $fromPrev = $this->haversineKm((float) $prevStop->latitude, (float) $prevStop->longitude, (float) $newStop->latitude, (float) $newStop->longitude);
                $fraction = min(1, max(0, $fromPrev / $between));

                return round((float) $prev->distance_from_origin_km + ($fraction * ((float) $next->distance_from_origin_km - (float) $prev->distance_from_origin_km)), 3);
            }

            return round(((float) $prev->distance_from_origin_km + (float) $next->distance_from_origin_km) / 2, 3);
        }

        if ($prev) {
            $prevStop = $prev->stop;
            if ($prevStop && $prevStop->latitude !== null && $prevStop->longitude !== null) {
                return round((float) $prev->distance_from_origin_km + $this->haversineKm((float) $prevStop->latitude, (float) $prevStop->longitude, (float) $newStop->latitude, (float) $newStop->longitude), 3);
            }

            return round((float) $prev->distance_from_origin_km, 3);
        }

        if ($next) {
            $nextStop = $next->stop;
            if ($nextStop && $nextStop->latitude !== null && $nextStop->longitude !== null) {
                return round(max(0, (float) $next->distance_from_origin_km - $this->haversineKm((float) $newStop->latitude, (float) $newStop->longitude, (float) $nextStop->latitude, (float) $nextStop->longitude)), 3);
            }

            return 0.0;
        }

        return 0.0;
    }

    private function haversineKm(float $lat1, float $lng1, float $lat2, float $lng2): float
    {
        $earthRadiusKm = 6371;
        $dLat = deg2rad($lat2 - $lat1);
        $dLng = deg2rad($lng2 - $lng1);

        $a = sin($dLat / 2) ** 2
            + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLng / 2) ** 2;

        $c = 2 * atan2(sqrt($a), sqrt(1 - $a));

        return $earthRadiusKm * $c;
    }
}
