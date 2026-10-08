<?php

namespace App\Services;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Live traffic for one segment (a position and the next stop) from TomTom's Routing API.
 *
 * One request returns the travel time with live traffic AND the time with no traffic, so a delay is the difference
 * between two numbers the provider itself reports, never a guess. When the provider cannot answer (no key, limit
 * reached, error, no travel time) the answer is "unavailable" with a reason: no ETA or delay is ever invented.
 */
class TrafficService
{
    /**
     * @return array<string, mixed>
     */
    public function segment(float $fromLat, float $fromLng, float $toLat, float $toLng): array
    {
        if ((string) config('traffic.tomtom.key') === '') {
            return $this->unavailable('not_configured');
        }

        $cacheKey = $this->cacheKey($fromLat, $fromLng, $toLat, $toLng);

        if ($hit = Cache::get($cacheKey)) {
            return $hit + ['cached' => true];
        }

        // One caller at a time per cell: drivers asking about the same stretch at the same moment share one request.
        try {
            return Cache::lock($cacheKey.':lock', 10)->block(3, function () use ($cacheKey, $fromLat, $fromLng, $toLat, $toLng) {
                if ($hit = Cache::get($cacheKey)) {
                    return $hit + ['cached' => true];
                }

                $answer = $this->fetch($fromLat, $fromLng, $toLat, $toLng);
                $ttl = $answer['available'] ? (int) config('traffic.cache_ttl_s') : (int) config('traffic.failure_cache_ttl_s');
                if ($ttl > 0) {
                    Cache::put($cacheKey, $answer, $ttl);
                }

                return $answer + ['cached' => false];
            });
        } catch (\Illuminate\Contracts\Cache\LockTimeoutException) {
            return Cache::get($cacheKey) ? Cache::get($cacheKey) + ['cached' => true] : $this->unavailable('busy');
        }
    }

    /** @return array<string, mixed> */
    private function fetch(float $fromLat, float $fromLng, float $toLat, float $toLng): array
    {
        if (! $this->takeFromBudget()) {
            return $this->unavailable('budget_reached');
        }

        $base = config('traffic.tomtom.base_url');
        $url = sprintf('%s/routing/1/calculateRoute/%s,%s:%s,%s/json', $base, $fromLat, $fromLng, $toLat, $toLng);

        try {
            $response = Http::timeout((int) config('traffic.tomtom.timeout_s'))
                ->acceptJson()
                ->get($url, [
                    'key' => config('traffic.tomtom.key'),
                    'traffic' => 'true',
                    'travelMode' => config('traffic.tomtom.travel_mode'),
                    'computeTravelTimeFor' => 'all',
                ]);
        } catch (ConnectionException $e) {
            Log::warning('Traffic provider unreachable.', ['provider' => 'tomtom']);

            return $this->unavailable('provider_error');
        }

        if (! $response->successful()) {
            // The key is never logged or returned. 403 and 429 are how TomTom says "limit reached".
            Log::warning('Traffic provider refused the request.', ['provider' => 'tomtom', 'status' => $response->status()]);

            return $this->unavailable(in_array($response->status(), [403, 429], true) ? 'provider_limit' : 'provider_error');
        }

        $summary = $response->json('routes.0.summary');
        $live = (int) ($summary['travelTimeInSeconds'] ?? 0);
        if (! is_array($summary) || $live <= 0) {
            return $this->unavailable('no_travel_time');
        }

        $noTraffic = (int) ($summary['noTrafficTravelTimeInSeconds'] ?? 0);

        return [
            'available' => true,
            'provider' => 'tomtom',
            'travel_time_s' => $live,
            'no_traffic_s' => $noTraffic > 0 ? $noTraffic : null,
            'historic_s' => isset($summary['historicTrafficTravelTimeInSeconds']) ? (int) $summary['historicTrafficTravelTimeInSeconds'] : null,
            'delay_s' => isset($summary['trafficDelayInSeconds']) ? (int) $summary['trafficDelayInSeconds'] : null,
            'length_m' => (int) ($summary['lengthInMeters'] ?? 0),
            'fetched_at' => now()->toIso8601String(),
        ];
    }

    /** Counts one real provider request; false once this month's budget is used. */
    private function takeFromBudget(): bool
    {
        $budget = (int) config('traffic.monthly_budget');
        $key = 'traffic:budget:'.now()->format('Ym');

        Cache::add($key, 0, now()->endOfMonth()->addDay());
        $used = Cache::increment($key);

        return $budget <= 0 || $used <= $budget;
    }

    /** About 100 m cells, so nearby drivers share an answer. */
    private function cacheKey(float $fromLat, float $fromLng, float $toLat, float $toLng): string
    {
        return sprintf(
            'traffic:segment:%s:%s,%s:%s,%s',
            config('traffic.tomtom.travel_mode'),
            round($fromLat, 3), round($fromLng, 3), round($toLat, 3), round($toLng, 3),
        );
    }

    /** @return array<string, mixed> */
    private function unavailable(string $reason): array
    {
        return ['available' => false, 'provider' => 'tomtom', 'reason' => $reason];
    }
}
