<?php

namespace App\Services;

use App\Models\AlightingPoint;
use App\Models\Route;
use App\Support\Geo\GeoLine;
use Illuminate\Validation\ValidationException;

/**
 * Batch 28 Stage 3, item 10: validation and measuring for a designated alighting point. A point is typed in by the team
 * (name, kind, coordinate, route); the SERVER works out where it is along the route line, in both directions, and
 * refuses one that is outside the region, not on the route line, or on a route with no generated path.
 */
class AlightingPointService
{
    public function __construct(
        private RouteSnapService $routeSnapService,
        private RegionService $regionService,
    ) {}

    /**
     * @param  array{route_id: int, name: string, kind: string, latitude: float, longitude: float, municipality?: string|null, source?: string|null, note?: string|null, is_active?: bool}  $data
     * @return array<string, mixed> the attributes to store (distances and snap distance computed)
     *
     * @throws ValidationException
     */
    public function prepare(array $data): array
    {
        $routeId = (int) $data['route_id'];
        $lat = (float) $data['latitude'];
        $lng = (float) $data['longitude'];

        if (! Route::whereKey($routeId)->exists()) {
            $this->fail('route_id', 'Route not found.');
        }
        if (! in_array($data['kind'] ?? null, AlightingPoint::KINDS, true)) {
            $this->fail('kind', 'The kind must be one of: '.implode(', ', AlightingPoint::KINDS).'.');
        }
        if (! $this->regionService->contains($lat, $lng)) {
            $this->fail('latitude', 'That location is outside the service region ('.config('region.name').').');
        }

        $municipality = null;
        if (isset($data['municipality']) && trim((string) $data['municipality']) !== '') {
            $municipality = $this->regionService->canonicalMunicipality((string) $data['municipality']);
            if ($municipality === null) {
                $this->fail('municipality', 'That municipality is not in the region list.');
            }
        }

        $snap = $this->routeSnapService->snap($routeId, $lat, $lng, 'outbound');
        if (! $snap) {
            $this->fail('route_id', 'This route has no generated path yet, so a drop-off point cannot be placed on it.');
        }
        $threshold = (float) config('dropoff.point_snap_threshold_m');
        if ($snap['distance_to_route_m'] > $threshold) {
            $this->fail('latitude', 'That point is '.round($snap['distance_to_route_m']).' m from the route line; it must be within '.(int) $threshold.' m.');
        }

        $outbound = (int) round($snap['distance_along_route_m']);
        $length = (float) $snap['total_length_m'];

        return [
            'route_id' => $routeId,
            'name' => mb_substr(trim((string) $data['name']), 0, 120),
            'kind' => $data['kind'],
            'municipality' => $municipality,
            'latitude' => round($lat, 7),
            'longitude' => round($lng, 7),
            'distance_along_outbound_m' => $outbound,
            'distance_along_reverse_m' => (int) round($length - $snap['distance_along_route_m']),
            'snap_distance_m' => $snap['distance_to_route_m'],
            'is_active' => (bool) ($data['is_active'] ?? true),
            'source' => isset($data['source']) ? mb_substr(trim((string) $data['source']), 0, 120) ?: null : null,
            'note' => isset($data['note']) ? trim((string) $data['note']) ?: null : null,
        ];
    }

    /** A point on the route line at a fraction of its length (used only by the SAMPLE command; no coordinate is typed). */
    public function pointAtFraction(int $routeId, float $fraction): ?array
    {
        $geometry = app(\App\Repositories\RouteGeometryRepository::class)->get($routeId);
        if (! $geometry) {
            return null;
        }
        $length = GeoLine::lengthM($geometry['coordinates']);
        $slice = GeoLine::slice($geometry['coordinates'], max(0.0, $length * $fraction - 1), $length * $fraction);

        return $slice === [] ? null : ['latitude' => end($slice)[1], 'longitude' => end($slice)[0]];
    }

    private function fail(string $field, string $message): never
    {
        throw ValidationException::withMessages([$field => [$message]]);
    }
}
