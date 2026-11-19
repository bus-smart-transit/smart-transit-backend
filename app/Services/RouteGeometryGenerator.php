<?php

namespace App\Services;

use App\Jobs\GenerateRouteGeometry;
use App\Repositories\RouteGeometryRepository;
use App\Repositories\RouteStopRepository;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

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
    private const PENDING_CACHE_PREFIX = 'route_geometry:pending:';

    private const ROUTE_CACHE_PREFIX = 'route_geometry:result:';

    private const PROGRESS_CACHE_PREFIX = 'route_geometry:progress:';

    private const LAST_REDRAW_CACHE_PREFIX = 'route_geometry:last_redraw:';

    private const DURATIONS_CACHE_KEY = 'route_geometry:redraw_durations';

    // Where each stage sits on a 0-100 bar; the routing stage fills 20-90 as its chunks finish.
    private const STAGE_LABELS = [
        'queued' => 'Waiting to start',
        'snapping' => 'Matching the stops to the road',
        'routing' => 'Drawing the route line',
        'saving' => 'Saving the new line',
        'retrying' => 'The routing provider is busy, trying again shortly',
    ];

    public function __construct(
        private RouteGeometryRepository $geometryRepository,
        private RouteStopRepository $routeStopRepository,
    ) {}

    /** Flags the route stale and queues regeneration (called when stops change). */
    public function queueRegeneration(int $routeId): void
    {
        $this->geometryRepository->markStale($routeId, 'Stops changed; geometry regeneration queued.');
        Cache::put(self::pendingCacheKey($routeId), (string) Str::uuid(), max(60, (int) config('route_geometry.regeneration_pending_ttl_s', 3600)));
        Cache::put(self::progressCacheKey($routeId), ['requested_at' => now()->toIso8601String(), 'stage' => 'queued', 'step' => 0, 'steps' => 0], max(60, (int) config('route_geometry.regeneration_pending_ttl_s', 3600)));
        Cache::forget(self::lastRedrawCacheKey($routeId));

        try {
            GenerateRouteGeometry::dispatch($routeId)
                ->delay(now()->addSeconds(max(0, (int) config('route_geometry.debounce_s', 5))));
        } catch (\Throwable $exception) {
            Cache::forget(self::pendingCacheKey($routeId));
            Cache::forget(self::progressCacheKey($routeId));
            throw $exception;
        }
    }

    public static function progressCacheKey(int $routeId): string
    {
        return self::PROGRESS_CACHE_PREFIX.$routeId;
    }

    public static function lastRedrawCacheKey(int $routeId): string
    {
        return self::LAST_REDRAW_CACHE_PREFIX.$routeId;
    }

    /** The provider asked us to wait; the bar steps back and says so until the next attempt. */
    public function markRetrying(int $routeId): void
    {
        $this->setProgress($routeId, 'retrying');
    }

    /** Moves the progress of a redraw that someone asked for; a generate() run with no redraw pending records nothing. */
    private function setProgress(int $routeId, string $stage, int $step = 0, int $steps = 0): void
    {
        $current = Cache::get(self::progressCacheKey($routeId));
        if (! is_array($current) || ! Cache::has(self::pendingCacheKey($routeId))) {
            return;
        }

        Cache::put(self::progressCacheKey($routeId), array_merge($current, [
            'stage' => $stage,
            'step' => $step,
            'steps' => $steps,
            'started_at' => $current['started_at'] ?? now()->toIso8601String(),
        ]), max(60, (int) config('route_geometry.regeneration_pending_ttl_s', 3600)));
    }

    /** Called when a requested redraw is over: remembers how long it took and what happened, and clears the progress. */
    public function finishRedraw(int $routeId, bool $ok, string $message): void
    {
        $progress = Cache::get(self::progressCacheKey($routeId));
        $requestedAt = is_array($progress) && ! empty($progress['requested_at']) ? Carbon::parse($progress['requested_at']) : null;
        $duration = $requestedAt ? max(0, now()->timestamp - $requestedAt->timestamp) : null;

        Cache::put(self::lastRedrawCacheKey($routeId), [
            'ok' => $ok,
            'message' => $message,
            'finished_at' => now()->toIso8601String(),
            'duration_s' => $duration,
        ], 7 * 24 * 3600);
        Cache::forget(self::progressCacheKey($routeId));

        if ($ok && $duration !== null) {
            $durations = Cache::get(self::DURATIONS_CACHE_KEY, []);
            $durations = array_slice([...(is_array($durations) ? $durations : []), $duration], -20);
            Cache::put(self::DURATIONS_CACHE_KEY, $durations, 30 * 24 * 3600);
        }
    }

    /**
     * What the operator sees while and after a redraw: the stage, a 0-100 percentage, the time so far, how long redraws
     * usually take here (the median of the recent ones), and the outcome of the last one.
     *
     * @return array<string, mixed>
     */
    public static function redrawStatus(int $routeId): array
    {
        $pending = Cache::has(self::pendingCacheKey($routeId));
        $progress = $pending ? Cache::get(self::progressCacheKey($routeId)) : null;
        $progress = is_array($progress) ? $progress : null;

        $durations = Cache::get(self::DURATIONS_CACHE_KEY, []);
        $durations = is_array($durations) ? array_values($durations) : [];
        sort($durations);
        $typical = $durations === [] ? null : (int) $durations[intdiv(count($durations), 2)];

        $stage = $pending ? ($progress['stage'] ?? 'queued') : null;
        $step = (int) ($progress['step'] ?? 0);
        $steps = (int) ($progress['steps'] ?? 0);
        $percent = match ($stage) {
            null => null,
            'queued' => 5,
            'snapping' => 15,
            'routing' => $steps > 0 ? 20 + (int) round(70 * min($step, $steps) / $steps) : 20,
            'saving' => 95,
            'retrying' => 10,
            default => 5,
        };
        $requestedAt = $progress['requested_at'] ?? null;

        $last = Cache::get(self::lastRedrawCacheKey($routeId));

        return [
            'pending' => $pending,
            'stage' => $stage,
            'stage_label' => $stage ? (self::STAGE_LABELS[$stage] ?? 'Working') : null,
            'percent' => $percent,
            'step' => $step,
            'steps' => $steps,
            'requested_at' => $requestedAt,
            'started_at' => $progress['started_at'] ?? null,
            'elapsed_s' => $requestedAt ? max(0, now()->timestamp - Carbon::parse($requestedAt)->timestamp) : null,
            'typical_s' => $typical,
            'last' => is_array($last) ? $last : null,
        ];
    }

    public static function pendingCacheKey(int $routeId): string
    {
        return self::PENDING_CACHE_PREFIX.$routeId;
    }

    /** @return array{ok: bool, message: string, retryable: bool} */
    public function generate(int $routeId): array
    {
        $apiKey = config('route_geometry.ors.api_key');
        if (! $apiKey) {
            return $this->fail($routeId, 'ORS_API_KEY is not configured.');
        }

        $routeStops = $this->routeStopRepository->getOrderedStops($routeId);
        $usable = $routeStops->filter(fn ($routeStop) => $routeStop->stop && $routeStop->stop->latitude !== null && $routeStop->stop->longitude !== null)->values();
        if ($usable->count() < 2) {
            return $this->fail($routeId, 'A route needs at least two stops with coordinates.');
        }
        if ($usable->count() !== $routeStops->count()) {
            return $this->fail($routeId, 'Every route stop needs coordinates before distances can be recalculated.');
        }

        $raw = $usable->map(fn ($routeStop) => [(float) $routeStop->stop->longitude, (float) $routeStop->stop->latitude])->all();
        $cacheKey = $this->routeCacheKey($usable, $apiKey);
        $cached = Cache::get($cacheKey);

        try {
            if (is_array($cached) && isset($cached['snapped'], $cached['line'])) {
                $snapped = $cached['snapped'];
                $line = $cached['line'];
            } else {
                $this->setProgress($routeId, 'snapping');
                $snapped = $this->snapWaypoints($raw);
                if (count($snapped['unsnapped']) > 0) {
                    $names = $usable->filter(fn ($routeStop, $index) => in_array($index, $snapped['unsnapped'], true))
                        ->map(fn ($routeStop) => $routeStop->stop->stop_name)->implode(', ');

                    return $this->fail($routeId, "Stops not within snap radius of a road (fix coordinates): {$names}");
                }

                $chunks = max(1, (int) ceil((count($snapped['coordinates']) - 1) / (max(2, (int) config('route_geometry.ors.max_waypoints')) - 1)));
                $this->setProgress($routeId, 'routing', 0, $chunks);
                $line = $this->route($snapped['coordinates'], fn (int $done) => $this->setProgress($routeId, 'routing', $done, $chunks));
                Cache::put($cacheKey, ['snapped' => $snapped, 'line' => $line], max(1, (int) config('route_geometry.result_cache_ttl_s', 600)));
            }
        } catch (\Throwable $exception) {
            Log::warning('Route geometry generation failed', ['route_id' => $routeId, 'error' => $exception->getMessage()]);

            return $this->fail($routeId, $exception->getMessage(), $this->isRetryableProviderFailure($exception));
        }

        $this->setProgress($routeId, 'saving');
        $applied = DB::transaction(function () use ($routeId, $usable, $snapped, $line) {
            $currentStops = DB::table('route_stop')
                ->join('stops', 'stops.stop_id', '=', 'route_stop.stop_id')
                ->where('route_stop.route_id', $routeId)
                ->orderBy('route_stop.stop_order')
                ->lockForUpdate()
                ->get(['route_stop.id', 'route_stop.stop_id', 'route_stop.stop_order', 'stops.latitude', 'stops.longitude'])
                ->map(fn ($stop) => [(int) $stop->id, (int) $stop->stop_id, (int) $stop->stop_order, (float) $stop->latitude, (float) $stop->longitude])
                ->all();
            $routedStops = $usable->map(fn ($stop) => [
                (int) $stop->id, (int) $stop->stop_id, (int) $stop->stop_order,
                (float) $stop->stop->latitude, (float) $stop->stop->longitude,
            ])->all();
            if ($currentStops !== $routedStops) {
                return false;
            }

            foreach ($usable as $index => $routeStop) {
                DB::table('route_stop')->where('id', $routeStop->id)->update([
                    'snapped_longitude' => $snapped['coordinates'][$index][0],
                    'snapped_latitude' => $snapped['coordinates'][$index][1],
                    'distance_from_origin_km' => round($line['distances_m'][$index] / 1000, 4),
                    'distance_from_origin_m' => (int) round($line['distances_m'][$index]),
                    'scheduled_offset_min' => $line['offsets_min'][$index],
                ]);
            }
            $this->geometryRepository->save($routeId, $line['geometry']);

            return true;
        });

        if (! $applied) {
            return $this->fail($routeId, 'Stops changed during routing; regenerate again using the current stops.', true);
        }

        return ['ok' => true, 'message' => 'Geometry and fare distances regenerated ('.count($line['geometry']['coordinates']).' points).', 'retryable' => false];
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
     * @return array{geometry: array{type: string, coordinates: array<int, array{0: float, 1: float}>}, distances_m: array<int, float>, offsets_min: array<int, int>}
     */
    private function route(array $waypoints, ?callable $onChunk = null): array
    {
        $cfg = config('route_geometry.ors');
        $max = max(2, (int) $cfg['max_waypoints']);
        $line = [];
        $distances = [0.0];
        $cumulativeDistance = 0.0;
        $offsets = [0];
        $cumulativeDuration = 0.0;

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

            $segments = $response->json('features.0.properties.segments');
            if (! is_array($segments) || count($segments) !== count($chunk) - 1) {
                throw new \RuntimeException('Routing provider returned incomplete leg distances.');
            }
            foreach ($segments as $segment) {
                $distance = $segment['distance'] ?? null;
                $duration = $segment['duration'] ?? null;
                if (! is_numeric($distance) || ! is_finite((float) $distance) || (float) $distance <= 0
                    || ! is_numeric($duration) || ! is_finite((float) $duration) || (float) $duration <= 0) {
                    throw new \RuntimeException('Routing provider returned an invalid leg distance or duration.');
                }
                $cumulativeDistance += (float) $distance;
                $distances[] = $cumulativeDistance;
                $cumulativeDuration += (float) $duration;
                $offsets[] = (int) round($cumulativeDuration / 60);
            }

            foreach ($part as $point) {
                $line[] = [(float) $point[0], (float) $point[1]];
            }
            if ($onChunk) {
                $onChunk(intdiv($start, $max - 1) + 1);
            }
        }

        if (count($line) < 2) {
            throw new \RuntimeException('Routing provider returned no geometry.');
        }

        return [
            'geometry' => ['type' => 'LineString', 'coordinates' => $line],
            'distances_m' => $distances,
            'offsets_min' => $offsets,
        ];
    }

    private function client()
    {
        return Http::connectTimeout((int) config('route_geometry.ors.connect_timeout_s', 5))
            ->timeout((int) config('route_geometry.ors.timeout_s'))
            ->acceptJson()
            ->withHeaders(['Authorization' => (string) config('route_geometry.ors.api_key')]);
    }

    private function routeCacheKey($routeStops, string $apiKey): string
    {
        $inputs = [
            'result_schema_version' => 2,
            'base_url' => config('route_geometry.ors.base_url'),
            'profile' => config('route_geometry.ors.profile'),
            'api_key_hash' => hash('sha256', $apiKey),
            'snap_radius_m' => config('route_geometry.ors.snap_radius_m'),
            'max_waypoints' => config('route_geometry.ors.max_waypoints'),
            'stops' => $routeStops->map(fn ($routeStop) => [
                (int) $routeStop->stop_id,
                (int) $routeStop->stop_order,
                (float) $routeStop->stop->latitude,
                (float) $routeStop->stop->longitude,
            ])->all(),
        ];

        return self::ROUTE_CACHE_PREFIX.hash('sha256', json_encode($inputs, JSON_THROW_ON_ERROR));
    }

    private function isRetryableProviderFailure(\Throwable $exception): bool
    {
        if ($exception instanceof ConnectionException) {
            return true;
        }
        if ($exception instanceof RequestException) {
            return in_array($exception->response->status(), [408, 425, 429, 500, 502, 503, 504], true);
        }

        return false;
    }

    /** @return array{ok: bool, message: string, retryable: bool} */
    private function fail(int $routeId, string $message, bool $retryable = false): array
    {
        $this->geometryRepository->markStale($routeId, $message);

        return ['ok' => false, 'message' => $message, 'retryable' => $retryable];
    }
}
