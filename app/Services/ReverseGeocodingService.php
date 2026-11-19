<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;

class ReverseGeocodingService
{
    public function __construct(private NominatimThrottle $throttle) {}

    public function resolvePlaceName(float $lat, float $lng): ?string
    {
        // Same shared 1 request/second gate as the address search (Nominatim policy); no turn, no label.
        if (! $this->throttle->acquire()) {
            return null;
        }

        try {
            $response = Http::timeout(5)
                ->acceptJson()
                ->withHeaders([
                    'User-Agent' => 'smart-transit-reverse-geocoder/1.0',
                    'Accept-Language' => 'en',
                ])
                ->get('https://nominatim.openstreetmap.org/reverse', [
                    'lat' => $lat,
                    'lon' => $lng,
                    'format' => 'jsonv2',
                    'zoom' => 18,
                    'addressdetails' => 1,
                ]);

            if (! $response->successful()) {
                return null;
            }

            $json = $response->json();
            $displayName = trim((string) ($json['display_name'] ?? ''));
            if ($displayName !== '') {
                return $displayName;
            }

            $address = is_array($json['address'] ?? null) ? $json['address'] : [];
            $parts = array_filter([
                $address['road'] ?? null,
                $address['suburb'] ?? null,
                $address['city'] ?? ($address['town'] ?? null),
            ]);

            if (! empty($parts)) {
                return implode(', ', $parts);
            }
        } catch (\Throwable) {
            return null;
        }

        return null;
    }

    /**
     * Batch 51 item 3 (user, 2026-11-09): the town or city names Nominatim gives for a point, most specific first
     * (city, town, municipality, county), for RegionPlaceService to match against the region list. Empty on failure.
     *
     * @return list<string>
     */
    public function townNames(float $lat, float $lng): array
    {
        if (! $this->throttle->acquire()) {
            return [];
        }

        try {
            $response = Http::timeout(5)
                ->acceptJson()
                ->withHeaders(['User-Agent' => 'smart-transit-reverse-geocoder/1.0', 'Accept-Language' => 'en'])
                ->get('https://nominatim.openstreetmap.org/reverse', ['lat' => $lat, 'lon' => $lng, 'format' => 'jsonv2', 'zoom' => 10, 'addressdetails' => 1]);
            if (! $response->successful()) {
                return [];
            }
            $address = (array) ($response->json('address') ?? []);

            return array_values(array_filter(array_map(
                fn (string $key) => trim((string) ($address[$key] ?? '')),
                ['city', 'town', 'municipality', 'county'],
            )));
        } catch (\Throwable) {
            return [];
        }
    }
}
