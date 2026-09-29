<?php

namespace App\Services;

use App\Jobs\GenerateRouteGeometry;
use App\Repositories\RouteGeometryRepository;
use App\Repositories\RouteStopRepository;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Builds the canonical route line once from the ordered stops via
 * OpenRouteService. Admin/background only: viewers never trigger routing.
 *
 * - Each stop is snapped to the road network first and the snapped point is
 *   used as the waypoint, so the router does not detour to an off-road pin
 *   and double back (the end-spur signature).
 * - continue_straight stops the router U-turning at waypoints.
 * - On any failure the previous geometry is kept and the route is flagged
 *   geometry_stale so an admin can see it.
 */
class RouteGeometryGenerator
{
    public function __construct(
        private RouteGeometryRepository $geometryRepository,
        private RouteStopRepository $routeStopRepository,
    ) {
    }

    /** Flags the route stale and queues regeneration (called when stops change). */
    public function queueRegeneration(int $routeId): void
    {
        $this->geometryRepository->markStale($routeId, 'Stops changed; geometry regeneration queued.');
        GenerateRouteGeometry::dispatch($routeId);
    }

    /** @return array{ok: bool, message: string} */
    public function generate(int $routeId): array
    {
        $apiKey = config('route_geometry.ors.api_key');
        if (! $apiKey) {
            return $this->fail($routeId, 'ORS_API_KEY is not configured.');
        }

        $routeStops = $this->routeStopRepository->getOrderedStops($routeId);
        $usable = $routeStops->filter(fn ($rs) => $rs->stop && $rs->stop->latitude !== null && $rs->stop->longitude !== null)->values();
        if ($usable->count() < 2) {
            return $this->fail($routeId, 'A route needs at least two stops with coordinates.');
        }

        $raw = $usable->map(fn ($rs) => [(float) $rs->stop->longitude, (float) $rs->stop->latitude])->all();

        try {
            $snapped = $this->snapWaypoints($raw);

            // Stops that cannot be snapped are data errors to correct, never
            // silently moved; keep the previous geometry and flag the route.
            if (count($snapped['unsnapped']) > 0) {
                $names = $usable->filter(fn ($rs, $i) => in_array($i, $snapped['unsnapped'], true))
                    ->map(fn ($rs) => $rs->stop->stop_name)->implode(', ');

                return $this->fail($routeId, "Stops not within snap radius of a road (fix coordinates): {$names}");
            }

            $line = $this->route($snapped['coordinates']);
        } catch (\Throwable $e) {
            Log::warning('Route geometry generation failed', ['route_id' => $routeId, 'error' => $e->getMessage()]);

            return $this->fail($routeId, $e->getMessage());
        }

        DB::transaction(function () use ($routeId, $usable, $snapped, $line) {
            foreach ($usable as $i => $rs) {
                DB::table('route_stop')->where('id', $rs->id)->update([
                    'snapped_longitude' => $snapped['coordinates'][$i][0],
                    'snapped_latitude' => $snapped['coordinates'][$i][1],
                ]);
            }
            $this->geometryRepository->save($routeId, $line);
        });

        return ['ok' => true, 'message' => 'Geometry regenerated (' . count($line['coordinates']) . ' points).'];
    }

    /**
     * @param  array<int, array{0: float, 1: float}>  $coords
     * @return array{coordinates: array<int, array{0: float, 1: float}>, unsnapped: array<int, int>}
     */
    private function snapWaypoints(array $coords): array
    {
        $cfg = config('route_geometry.ors');
        $response = $this->client()->post("{$cfg['base_url']}/v2/snap/{$cfg['profile']}/json", [
            'locations' => $coords,
            'radius' => $cfg['snap_radius_m'],
        ]);

        // Snap endpoint unavailable on this ORS deployment: route from raw
        // coordinates rather than blocking; B2 validation covers stop quality.
        if ($response->status() === 404) {
            return ['coordinates' => $coords, 'unsnapped' => []];
        }
        $response->throw();

        $locations = $response->json('locations') ?? [];
        $out = [];
        $unsnapped = [];
        foreach ($coords as $i => $original) {
            $snapped = $locations[$i]['location'] ?? null;
            if (is_array($snapped) && count($snapped) >= 2) {
                $out[] = [(float) $snapped[0], (float) $snapped[1]];
            } else {
                $out[] = $original;
                $unsnapped[] = $i;
            }
        }

        return ['coordinates' => $out, 'unsnapped' => $unsnapped];
    }

    /**
     * @param  array<int, array{0: float, 1: float}>  $waypoints
     * @return array{type: string, coordinates: array<int, array{0: float, 1: float}>}
     */
    private function route(array $waypoints): array
    {
        $cfg = config('route_geometry.ors');
        $max = max(2, (int) $cfg['max_waypoints']);
        $line = [];

        // Chunk long routes to respect the provider waypoint limit; chunks
        // overlap by one waypoint so the line stays continuous.
        for ($start = 0; $start < count($waypoints) - 1; $start += ($max - 1)) {
            $chunk = array_slice($waypoints, $start, $max);
            if (count($chunk) < 2) {
                break;
            }

            if ($start > 0) {
                usleep(((int) $cfg['request_delay_ms']) * 1000);
            }

            $response = $this->client()->withHeaders(['Accept' => 'application/geo+json, application/json'])->post("{$cfg['base_url']}/v2/directions/{$cfg['profile']}/geojson", [
                'coordinates' => $chunk,
                'continue_straight' => true,
                'preference' => 'recommended',
            ]);
            $response->throw();

            $part = $response->json('features.0.geometry.coordinates');
            if (! is_array($part) || count($part) < 2) {
                throw new \RuntimeException('Routing provider returned no geometry.');
            }

            foreach ($part as $point) {
                $line[] = [(float) $point[0], (float) $point[1]];
            }
        }

        if (count($line) < 2) {
            throw new \RuntimeException('Routing provider returned no geometry.');
        }

        return ['type' => 'LineString', 'coordinates' => $line];
    }

    private function client()
    {
        return Http::timeout((int) config('route_geometry.ors.timeout_s'))
            ->acceptJson()
            ->withHeaders(['Authorization' => (string) config('route_geometry.ors.api_key')]);
    }

    /** @return array{ok: bool, message: string} */
    private function fail(int $routeId, string $message): array
    {
        $this->geometryRepository->markStale($routeId, $message);

        return ['ok' => false, 'message' => $message];
    }
}
