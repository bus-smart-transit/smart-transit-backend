<?php

namespace App\Services;

/**
 * Reads the single region configuration (config/region.php). Region-wide
 * values are only ever obtained through here or that config; nothing else in
 * the application names a city, province or corridor.
 */
class RegionService
{
    /** Public payload for the map default view and municipality pickers. */
    public function toArray(): array
    {
        $bbox = config('region.bbox');

        return [
            'name' => config('region.name'),
            'center' => [
                'lat' => (float) config('region.center.lat'),
                'lng' => (float) config('region.center.lng'),
            ],
            'bbox' => [
                'min_lat' => (float) $bbox['min_lat'],
                'max_lat' => (float) $bbox['max_lat'],
                'min_lng' => (float) $bbox['min_lng'],
                'max_lng' => (float) $bbox['max_lng'],
            ],
            'provinces' => config('region.provinces'),
        ];
    }

    /** True when the point lies inside the configured region bounding box. */
    public function contains(float $lat, float $lng): bool
    {
        $box = config('region.bbox');

        return $lat >= $box['min_lat'] && $lat <= $box['max_lat'] && $lng >= $box['min_lng'] && $lng <= $box['max_lng'];
    }

    /** Canonical spelling of a municipality/city from the region list, or null when unknown. */
    public function canonicalMunicipality(?string $name): ?string
    {
        $needle = mb_strtolower(trim((string) $name));
        if ($needle === '') {
            return null;
        }

        foreach (config('region.provinces') as $municipalities) {
            foreach ($municipalities as $municipality) {
                if (mb_strtolower($municipality) === $needle) {
                    return $municipality;
                }
            }
        }

        return null;
    }
}
