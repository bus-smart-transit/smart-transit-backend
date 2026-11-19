<?php

namespace Database\Seeders;

use App\Repositories\RouteStopRepository;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

/**
 * Dev data: per-stop scheduled offsets and geometry distances for routes that have none.
 *
 * Nothing here is guessed. The routing engine that draws the route line (OpenRouteService,
 * same profile, same waypoints as the geometry generator: the stored snapped stop point, else
 * the stop coordinate) reports the driving time and distance of every leg between consecutive
 * stops. Offsets are the cumulative leg durations; `distance_from_origin_m` is the cumulative
 * leg distance. Fare distances (`distance_from_origin_km`) are never touched.
 *
 * Only empty values are filled, so anything an operator set by hand is kept. A route the
 * provider cannot route is skipped and reported, never filled with a made-up number.
 * Driving-car durations are a floor for a bus (no dwell time at stops); operators can
 * overwrite the offsets through the route stop screens.
 *
 * Run: php artisan db:seed --class=RouteStopScheduleSeeder
 */
class RouteStopScheduleSeeder extends Seeder
{
    public function run(): void
    {
        $apiKey = config('route_geometry.ors.api_key');
        if (! $apiKey) {
            $this->report('ORS_API_KEY is not configured; no offsets were filled.');

            return;
        }

        $routeIds = DB::table('route_stop')->distinct()->orderBy('route_id')->pluck('route_id');
        foreach ($routeIds as $routeId) {
            $this->fillRoute((int) $routeId);
        }
    }

    private function fillRoute(int $routeId): void
    {
        $stops = app(RouteStopRepository::class)->getOrderedStops($routeId)->values();
        $needsFill = $stops->contains(fn ($rs) => $rs->scheduled_offset_min === null);
        if ($stops->count() < 2 || ! $needsFill) {
            return;
        }

        $waypoints = $stops->map(fn ($rs) => $rs->snapped_longitude !== null
            ? [(float) $rs->snapped_longitude, (float) $rs->snapped_latitude]
            : [(float) $rs->stop->longitude, (float) $rs->stop->latitude])->all();

        $legs = $this->legs($waypoints);
        if ($legs === null) {
            $this->report("Route {$routeId}: the routing provider could not route these stops; nothing was filled.");

            return;
        }

        $seconds = 0.0;
        $metres = 0.0;
        foreach ($stops as $i => $routeStop) {
            if ($i > 0) {
                $seconds += $legs[$i - 1]['duration'];
                $metres += $legs[$i - 1]['distance'];
            }

            $update = ['distance_from_origin_m' => (int) round($metres)];
            if ($routeStop->scheduled_offset_min === null) {
                $update['scheduled_offset_min'] = (int) round($seconds / 60);
            }
            // Query builder on purpose: the model keeps metres in step with the fare km,
            // which this seeder must not change.
            DB::table('route_stop')->where('id', $routeStop->id)->update($update);
        }

        $this->report("Route {$routeId}: filled offsets and distances for {$stops->count()} stops (total ".round($seconds / 60).' min, '.round($metres / 1000, 1).' km).');
    }

    /** @return array<int, array{distance: float, duration: float}>|null one entry per consecutive pair */
    private function legs(array $waypoints): ?array
    {
        $cfg = config('route_geometry.ors');
        $max = max(2, (int) $cfg['max_waypoints']);
        $legs = [];

        for ($start = 0; $start < count($waypoints) - 1; $start += ($max - 1)) {
            $chunk = array_slice($waypoints, $start, $max);
            if (count($chunk) < 2) {
                break;
            }
            if ($start > 0) {
                usleep(((int) $cfg['request_delay_ms']) * 1000);
            }

            $response = Http::timeout((int) $cfg['timeout_s'])->acceptJson()
                ->withHeaders(['Authorization' => (string) $cfg['api_key'], 'Accept' => 'application/geo+json, application/json'])
                ->post("{$cfg['base_url']}/v2/directions/{$cfg['profile']}/geojson", [
                    'coordinates' => $chunk,
                    'continue_straight' => true,
                    'preference' => 'recommended',
                ]);

            $segments = $response->successful() ? $response->json('features.0.properties.segments') : null;
            if (! is_array($segments) || count($segments) !== count($chunk) - 1) {
                return null;
            }
            foreach ($segments as $segment) {
                $legs[] = ['distance' => (float) $segment['distance'], 'duration' => (float) $segment['duration']];
            }
        }

        return $legs;
    }

    private function report(string $message): void
    {
        if ($this->command) {
            $this->command->info($message);
        }
    }
}
