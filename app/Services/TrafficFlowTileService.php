<?php

namespace App\Services;

use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Road-congestion map tiles (TomTom Traffic Flow, raster) for the driver map.
 *
 * The browser never sees the TomTom key: the map asks this server for a tile, the server asks TomTom, keeps the
 * picture for a couple of minutes (every driver looking at the same tile shares one request) and counts the requests
 * it really makes against a monthly budget kept under the free allowance. A tile outside the service region or outside
 * the zoom range is refused, so this cannot be used as an open window onto the provider.
 *
 * The picture is TomTom's own colouring of speed against free flow. Nothing is drawn or estimated here.
 */
class TrafficFlowTileService
{
    /**
     * @return array{ok: true, png: string}|array{ok: false, reason: string, status: int}
     */
    public function tile(int $zoom, int $x, int $y): array
    {
        $minZoom = (int) config('traffic.flow_tiles.min_zoom');
        $maxZoom = (int) config('traffic.flow_tiles.max_zoom');
        if ($zoom < $minZoom || $zoom > $maxZoom) {
            return $this->refused('zoom_out_of_range', 422);
        }
        $count = 2 ** $zoom;
        if ($x < 0 || $y < 0 || $x >= $count || $y >= $count || ! $this->insideRegion($zoom, $x, $y)) {
            return $this->refused('outside_region', 422);
        }
        if ((string) config('traffic.tomtom.key') === '') {
            return $this->refused('not_configured', 503);
        }

        $cacheKey = sprintf('traffic:flow:%s:%d:%d:%d', config('traffic.flow_tiles.style'), $zoom, $x, $y);
        if ($hit = $this->fromCache($cacheKey)) {
            return $hit;
        }

        try {
            return Cache::lock($cacheKey.':lock', 10)->block(3, function () use ($cacheKey, $zoom, $x, $y) {
                return $this->fromCache($cacheKey) ?? $this->fetch($cacheKey, $zoom, $x, $y);
            });
        } catch (LockTimeoutException) {
            return $this->fromCache($cacheKey) ?? $this->refused('busy', 503);
        }
    }

    /** @return array{ok: true, png: string}|array{ok: false, reason: string, status: int} */
    private function fetch(string $cacheKey, int $zoom, int $x, int $y): array
    {
        if (! $this->takeFromBudget()) {
            return $this->remember($cacheKey, $this->refused('budget_reached', 503));
        }

        $url = sprintf(
            '%s/traffic/map/4/tile/flow/%s/%d/%d/%d.png',
            config('traffic.tomtom.base_url'), config('traffic.flow_tiles.style'), $zoom, $x, $y,
        );

        try {
            $response = Http::timeout((int) config('traffic.tomtom.timeout_s'))
                ->get($url, ['key' => config('traffic.tomtom.key'), 'tileSize' => 256]);
        } catch (ConnectionException) {
            Log::warning('Traffic tile provider unreachable.', ['provider' => 'tomtom']);

            return $this->remember($cacheKey, $this->refused('provider_error', 503));
        }

        if (! $response->successful() || $response->body() === '') {
            // The key is never logged or returned. 403 and 429 are how TomTom says "limit reached".
            Log::warning('Traffic tile provider refused the request.', ['provider' => 'tomtom', 'status' => $response->status()]);
            $reason = in_array($response->status(), [403, 429], true) ? 'provider_limit' : 'provider_error';

            return $this->remember($cacheKey, $this->refused($reason, 503));
        }

        return $this->remember($cacheKey, ['ok' => true, 'png' => $response->body()]);
    }

    /** @param array<string, mixed> $answer */
    private function remember(string $cacheKey, array $answer): array
    {
        $good = $answer['ok'];
        $ttl = $good ? (int) config('traffic.flow_tiles.cache_ttl_s') : (int) config('traffic.failure_cache_ttl_s');
        if ($ttl > 0) {
            // Binary pictures are stored as text so any cache store (database included) can hold them.
            Cache::put($cacheKey, $good ? ['ok' => true, 'png64' => base64_encode($answer['png'])] : $answer, $ttl);
        }

        return $answer;
    }

    /** @return array<string, mixed>|null */
    private function fromCache(string $cacheKey): ?array
    {
        $hit = Cache::get($cacheKey);
        if (! is_array($hit)) {
            return null;
        }

        return $hit['ok'] ? ['ok' => true, 'png' => base64_decode($hit['png64'])] : $hit;
    }

    /** Counts one real provider request; false once this month's budget is used. */
    private function takeFromBudget(): bool
    {
        $budget = (int) config('traffic.flow_tiles.monthly_budget');
        $key = 'traffic:flow-budget:'.now()->format('Ym');

        Cache::add($key, 0, now()->endOfMonth()->addDay());
        $used = Cache::increment($key);

        return $budget <= 0 || $used <= $budget;
    }

    /** True when the tile overlaps the configured service-area bounding box. */
    private function insideRegion(int $zoom, int $x, int $y): bool
    {
        $bbox = config('region.bbox');
        $n = 2 ** $zoom;

        $west = $x / $n * 360 - 180;
        $east = ($x + 1) / $n * 360 - 180;
        $north = rad2deg(atan(sinh(M_PI * (1 - 2 * $y / $n))));
        $south = rad2deg(atan(sinh(M_PI * (1 - 2 * ($y + 1) / $n))));

        return $east >= $bbox['min_lng'] && $west <= $bbox['max_lng']
            && $north >= $bbox['min_lat'] && $south <= $bbox['max_lat'];
    }

    /** @return array{ok: false, reason: string, status: int} */
    private function refused(string $reason, int $status): array
    {
        return ['ok' => false, 'reason' => $reason, 'status' => $status];
    }
}
