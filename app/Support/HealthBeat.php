<?php

namespace App\Support;

use Illuminate\Support\Facades\Cache;

/**
 * Batch 48 Phase 6: the last result of each moving part (scheduler, queue, email, push and the outside services), for
 * the Super Admin's System health page. One small cache entry per part: when, whether it worked, and a short detail
 * such as an HTTP status code. Never a URL, key, address or message body. Recording must never break the request.
 */
class HealthBeat
{
    public const PARTS = ['scheduler', 'queue', 'mail', 'push', 'routing', 'traffic', 'places', 'payments'];

    /** Outside services, found by a word in the host name. */
    private const HOSTS = [
        'openrouteservice' => 'routing',
        'tomtom' => 'traffic',
        'photon' => 'places',
        'nominatim' => 'places',
        'paymongo' => 'payments',
    ];

    public static function record(string $part, bool $ok, ?string $detail = null): void
    {
        try {
            Cache::forever('health:'.$part, ['at' => now()->toIso8601String(), 'ok' => $ok, 'detail' => $detail]);
        } catch (\Throwable) {
            // A health note must never fail the work it describes.
        }
    }

    /** The part an outgoing request belongs to, or null when it is not one we watch. */
    public static function partForHost(?string $host): ?string
    {
        foreach (self::HOSTS as $word => $part) {
            if ($host !== null && str_contains(strtolower($host), $word)) {
                return $part;
            }
        }

        return null;
    }

    /** @return array{at: string, ok: bool, detail: ?string}|null */
    public static function last(string $part): ?array
    {
        try {
            $value = Cache::get('health:'.$part);
        } catch (\Throwable) {
            return null;
        }

        return is_array($value) ? $value : null;
    }
}
