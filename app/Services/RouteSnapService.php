<?php

namespace App\Services;

use App\Repositories\RouteGeometryRepository;
use App\Support\Geo\GeoLine;

/**
 * Single server-side snapping implementation: given a point and a route
 * direction, returns the snapped point, the distance to the route and the
 * distance along the route in that direction. Used for the live GPS position
 * today and for custom drop-off / stop validation.
 */
class RouteSnapService
{
    public function __construct(private RouteGeometryRepository $geometryRepository)
    {
    }

    public function hasGeometry(int $routeId): bool
    {
        return $this->geometryRepository->get($routeId) !== null;
    }

    /**
     * @return array{snapped_latitude: float, snapped_longitude: float, distance_to_route_m: float, distance_along_route_m: float, total_length_m: float, on_route: bool}|null
     *         null when the route has no canonical geometry yet
     */
    public function snap(int $routeId, float $lat, float $lng, ?string $direction = 'outbound'): ?array
    {
        $geometry = $this->geometryRepository->get($routeId);
        if (! $geometry) {
            return null;
        }

        return $this->snapToGeometry($geometry['coordinates'], $lat, $lng, $direction);
    }

    /** @param array<int, array{0: float, 1: float}> $coordinates */
    public function snapToGeometry(array $coordinates, float $lat, float $lng, ?string $direction = 'outbound'): ?array
    {
        $line = $direction === 'reverse' ? GeoLine::reverse($coordinates) : $coordinates;
        $projection = GeoLine::project($line, $lat, $lng);
        if (! $projection) {
            return null;
        }

        return [
            'snapped_latitude' => round($projection['latitude'], 7),
            'snapped_longitude' => round($projection['longitude'], 7),
            'distance_to_route_m' => round($projection['distance_to_line_m'], 1),
            'distance_along_route_m' => round($projection['distance_along_m'], 1),
            'total_length_m' => round($projection['total_length_m'], 1),
            'on_route' => $projection['distance_to_line_m'] <= (float) config('route_geometry.on_route_threshold_m'),
        ];
    }
}
