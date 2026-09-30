<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

/**
 * Backend proxy for the custom drop-off address search. The client never talks
 * to the geocoder or holds a key; results are limited to the configured region
 * (viewbox + a second containment check), cached, and the route is rate limited.
 */
class AddressSearchService
{
    public function __construct(private RegionService $regionService) {}

    /** @return array<int, array{label: string, latitude: float, longitude: float}>|null null when the provider is unavailable */
    public function search(string $query): ?array
    {
        $cfg = config('timeline.address_search');
        $normalized = mb_strtolower(trim(preg_replace('/\s+/', ' ', $query)));
        $box = config('region.bbox');

        return Cache::remember('address-search:'.md5($normalized.'|'.json_encode($box)), now()->addMinutes((int) $cfg['cache_minutes']), function () use ($normalized, $cfg, $box) {
            try {
                $response = Http::timeout(5)
                    ->acceptJson()
                    ->withHeaders(['User-Agent' => 'smart-transit-address-search/1.0', 'Accept-Language' => 'en'])
                    ->get('https://nominatim.openstreetmap.org/search', [
                        'q' => $normalized,
                        'format' => 'json',
                        'limit' => (int) $cfg['limit'],
                        'bounded' => 1,
                        'viewbox' => "{$box['min_lng']},{$box['max_lat']},{$box['max_lng']},{$box['min_lat']}",
                    ]);
            } catch (\Throwable) {
                return null;
            }

            if (! $response->successful()) {
                return null;
            }

            return collect($response->json() ?? [])
                ->map(fn ($row) => [
                    'label' => trim((string) ($row['display_name'] ?? '')),
                    'latitude' => (float) ($row['lat'] ?? 0),
                    'longitude' => (float) ($row['lon'] ?? 0),
                ])
                ->filter(fn ($row) => $row['label'] !== '' && $this->regionService->contains($row['latitude'], $row['longitude']))
                ->values()
                ->all();
        });
    }
}