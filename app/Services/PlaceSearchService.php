<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

/**
 * 2026-11-06 (Batch 47 part A): places for "Add stop". Each result has a short name and a detail line (street,
 * barangay, city) so places with the same name can be told apart ("Acacia, Indangan" / "Acacia, Poblacion").
 * mode "suggest" (while typing) asks Photon; mode "search" (the Search button), or a Photon failure, asks Nominatim
 * through AddressSearchService. Results are inside the region box and cached. They only help find the place: the
 * stop is saved where the pin is.
 */
class PlaceSearchService
{
    public function __construct(
        private RegionService $regionService,
        private AddressSearchService $addressSearch,
    ) {}

    /**
     * @return array{results: list<array{name: string, detail: string, latitude: float, longitude: float}>, source: string, available: bool}
     */
    public function search(string $query, string $mode = 'suggest'): array
    {
        $normalized = mb_strtolower(trim((string) preg_replace('/\s+/', ' ', $query)));
        if (mb_strlen($normalized) < (int) config('places.min_chars', 3)) {
            return ['results' => [], 'source' => 'none', 'available' => true];
        }

        if ($mode === 'suggest') {
            $photon = $this->photon($normalized);
            if ($photon !== null) {
                return ['results' => $photon, 'source' => 'photon', 'available' => true];
            }
        }

        $nominatim = $this->addressSearch->search($normalized);
        if ($nominatim === null) {
            return ['results' => [], 'source' => 'nominatim', 'available' => false];
        }

        return ['results' => array_map(fn (array $row) => $this->fromNominatim($row), $nominatim), 'source' => 'nominatim', 'available' => true];
    }

    /** @return list<array{name: string, detail: string, latitude: float, longitude: float}>|null null when Photon is unavailable */
    private function photon(string $query): ?array
    {
        $box = config('region.bbox');
        $key = 'places:photon:'.md5($query.'|'.json_encode($box));
        $cached = Cache::get($key);
        if (is_array($cached)) {
            return $cached;
        }

        try {
            $response = Http::timeout((int) config('places.timeout_seconds', 4))
                ->acceptJson()
                ->withHeaders(['User-Agent' => 'smart-transit-stop-search/1.0'])
                ->get((string) config('places.photon_url'), [
                    'q' => $query,
                    'limit' => (int) config('places.limit', 6),
                    'lang' => 'en',
                    'bbox' => "{$box['min_lng']},{$box['min_lat']},{$box['max_lng']},{$box['max_lat']}",
                ]);
        } catch (\Throwable) {
            return null;
        }
        if (! $response->successful()) {
            return null;
        }

        $results = collect($response->json('features') ?? [])
            ->map(function ($feature) {
                $p = $feature['properties'] ?? [];
                [$lng, $lat] = array_map('floatval', array_slice($feature['geometry']['coordinates'] ?? [0, 0], 0, 2) + [0, 0]);
                $name = trim((string) ($p['name'] ?? $p['street'] ?? ''));
                $street = trim((string) ($p['street'] ?? ''));
                $detail = collect([
                    $street !== '' && mb_strtolower($street) !== mb_strtolower($name) ? trim($street.' '.($p['housenumber'] ?? '')) : null,
                    $p['district'] ?? $p['locality'] ?? null,
                    $p['city'] ?? $p['county'] ?? null,
                ])->filter(fn ($part) => filled($part))->unique()->implode(', ');

                return ['name' => $name, 'detail' => $detail, 'latitude' => $lat, 'longitude' => $lng];
            })
            ->filter(fn ($row) => $row['name'] !== '' && $this->regionService->contains($row['latitude'], $row['longitude']))
            ->unique(fn ($row) => $row['name'].'|'.$row['detail'])
            ->values()
            ->all();

        Cache::put($key, $results, now()->addMinutes((int) config('places.cache_minutes', 1440)));

        return $results;
    }

    /** Nominatim gives one long line ("Acacia, Indangan, <city>, ..."): the first part is the name, the rest the detail. */
    private function fromNominatim(array $row): array
    {
        $parts = array_map('trim', explode(',', (string) $row['label']));
        $name = array_shift($parts) ?? '';
        $detail = implode(', ', array_slice(array_filter($parts, fn ($part) => $part !== '' && ! preg_match('/^\d{4}$/', $part)), 0, 3));

        return ['name' => $name, 'detail' => $detail, 'latitude' => (float) $row['latitude'], 'longitude' => (float) $row['longitude']];
    }
}
