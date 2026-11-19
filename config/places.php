<?php

/*
 * 2026-11-06 (Batch 47 part A, decided by the user): the place search behind "Add stop". Suggestions while typing
 * come from Photon (OpenStreetMap data, free public server, fair use, no key); the Search button, or a Photon
 * failure, uses Nominatim through AddressSearchService (1 request a second, never while typing). Results are limited
 * to the region box (config/region.php) and cached. A result only moves the map and the pin; the saved place is the pin.
 */
return [
    'photon_url' => env('PLACES_PHOTON_URL', 'https://photon.komoot.io/api/'),
    'timeout_seconds' => (int) env('PLACES_TIMEOUT_SECONDS', 4),
    'limit' => (int) env('PLACES_LIMIT', 6),
    'min_chars' => 3,
    'cache_minutes' => (int) env('PLACES_CACHE_MINUTES', 1440),
];
