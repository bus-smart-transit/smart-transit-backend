<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;

/**
 * Batch 51 item 3 (user, 2026-11-09): the one owner of "which town of the region is this point in", for the homepage's
 * "Are you in {place}?". The point is rounded to 2 decimals (about 1 km) before it is used, the answer is cached per
 * rounded point, and only a town or city of the region list (config region.provinces) is ever returned: a point outside
 * the region, or a name the list does not have, gives null and the homepage simply asks nothing.
 */
class RegionPlaceService
{
    public function __construct(
        private ReverseGeocodingService $geocoder,
        private RegionService $region,
    ) {}

    /**
     * @return array{place: string, province: string}|null
     */
    public function placeAt(float $lat, float $lng): ?array
    {
        $lat = round($lat, 2);
        $lng = round($lng, 2);
        if (! $this->region->contains($lat, $lng)) {
            return null;
        }

        $key = "region-place:{$lat}:{$lng}";
        $place = Cache::get($key);
        if ($place === null) {
            // Batch 52: only a definite answer is kept (a town, or names the region list does not have). No names at all
            // (the lookup failed or was skipped by the 1 request/second limit) is not cached, so it is tried again.
            $names = $this->geocoder->townNames($lat, $lng);
            if ($names === []) {
                return null;
            }
            $place = '';
            foreach ($names as $name) {
                if ($match = $this->match($name)) {
                    $place = $match;
                    break;
                }
            }
            Cache::put($key, $place, now()->addDays(7));
        }

        return $place === '' ? null : ['place' => $place, 'province' => $this->provinceOf($place)];
    }

    /** The region list's spelling of a name, also when one side says "City" and the other does not. */
    private function match(string $name): ?string
    {
        $base = trim(preg_replace('/\s+city$/i', '', preg_replace('/^city of\s+/i', '', trim($name))));
        foreach ([$name, $base, $base.' City'] as $candidate) {
            if ($found = $this->region->canonicalMunicipality($candidate)) {
                return $found;
            }
        }

        return null;
    }

    private function provinceOf(string $place): string
    {
        foreach (config('region.provinces') as $province => $municipalities) {
            if (in_array($place, $municipalities, true)) {
                return (string) $province;
            }
        }

        return '';
    }
}
