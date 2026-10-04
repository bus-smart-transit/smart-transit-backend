<?php

namespace App\Services;

use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Facades\Cache;

/**
 * Nominatim's usage policy allows at most one request per second for the whole application, not per user.
 * Every Nominatim call (address search and reverse geocoding) takes a turn here first. Callers are queued
 * through one cache lock, so the spacing holds across users and PHP workers. Results are cached by the
 * callers, so only cache misses reach this gate.
 */
class NominatimThrottle
{
    /**
     * Waits for this caller's turn. False when the queue is too long to wait for (the caller reports
     * "unavailable right now" instead of sending the request).
     */
    public function acquire(): bool
    {
        $cfg = config('timeline.address_search');
        $intervalSeconds = max(0, (int) $cfg['min_interval_ms']) / 1000;
        if ($intervalSeconds <= 0) {
            return true;
        }

        try {
            return (bool) Cache::lock('nominatim:gate', 10)->block(max(1, (int) ceil((int) $cfg['max_wait_ms'] / 1000)), function () use ($intervalSeconds) {
                $wait = $intervalSeconds - (microtime(true) - (float) Cache::get('nominatim:last', 0));
                if ($wait > 0) {
                    usleep((int) round($wait * 1_000_000));
                }
                Cache::put('nominatim:last', microtime(true), 60);

                return true;
            });
        } catch (LockTimeoutException) {
            return false;
        }
    }
}
