<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;

class ReverseGeocodingService
{
    public function resolvePlaceName(float $lat, float $lng): ?string
    {
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

            if (!$response->successful()) {
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

            if (!empty($parts)) {
                return implode(', ', $parts);
            }
        } catch (\Throwable) {
            return null;
        }

        return null;
    }
}
