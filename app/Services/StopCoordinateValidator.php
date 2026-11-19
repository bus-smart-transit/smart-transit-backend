<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

/**
 * B2: stop coordinate validation shared by the create/edit paths and the
 * invalid-stop report. Rejects null, zero, out-of-range, outside the
 * configured service-area bounding box, and (when the routing provider is
 * reachable) points too far from the road network.
 */
class StopCoordinateValidator
{
    /** @return array<int, string> human-readable problems, empty when valid */
    public function problems(?float $lat, ?float $lng): array
    {
        if ($lat === null || $lng === null) {
            return ['Coordinates are missing.'];
        }

        if ($lat == 0.0 || $lng == 0.0) {
            return ['Latitude/longitude of 0 is not a valid stop location.'];
        }

        if ($lat < -90 || $lat > 90 || $lng < -180 || $lng > 180) {
            return ['Coordinates are out of range (latitude -90..90, longitude -180..180).'];
        }

        $box = config('region.bbox');
        if ($lat < $box['min_lat'] || $lat > $box['max_lat'] || $lng < $box['min_lng'] || $lng > $box['max_lng']) {
            return ['Coordinates are outside the configured service area (latitude/longitude may be swapped).'];
        }

        return [];
    }

    /** True when the provider confirms no road within the threshold; false when unknown. */
    public function isOffRoadNetwork(float $lat, float $lng): bool
    {
        $cfg = config('route_geometry.ors');
        if (! $cfg['api_key']) {
            return false;
        }

        try {
            $response = Http::timeout((int) $cfg['timeout_s'])
                ->acceptJson()
                ->withHeaders(['Authorization' => (string) $cfg['api_key']])
                ->post("{$cfg['base_url']}/v2/snap/{$cfg['profile']}/json", [
                    'locations' => [[$lng, $lat]],
                    'radius' => (int) config('route_geometry.max_stop_off_network_m'),
                ]);

            if (! $response->successful()) {
                return false;
            }

            return ($response->json('locations.0') ?? null) === null;
        } catch (\Throwable $e) {
            Log::info('Stop off-network check skipped', ['error' => $e->getMessage()]);

            return false;
        }
    }

    /** @throws ValidationException */
    public function assertValid(float $lat, float $lng, string $field = 'latitude'): void
    {
        $problems = $this->problems($lat, $lng);

        if ($problems === [] && $this->isOffRoadNetwork($lat, $lng)) {
            $problems = ['Location is farther than ' . (int) config('route_geometry.max_stop_off_network_m') . ' m from any road.'];
        }

        if ($problems !== []) {
            throw ValidationException::withMessages([$field => $problems]);
        }
    }
}
