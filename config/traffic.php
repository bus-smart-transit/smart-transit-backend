<?php

/*
|--------------------------------------------------------------------------
| Live traffic through the backend (TomTom)
|--------------------------------------------------------------------------
| The browser never sees the key: the apps ask GET /api/traffic/segment and this server asks TomTom, caches the
| answer for a few minutes per ~100 m cell (so drivers on the same stretch share one request) and counts the
| requests it really makes against a monthly budget kept under the free allowance.
*/

return [

    'tomtom' => [
        'key' => env('TOMTOM_KEY'),
        'base_url' => rtrim((string) env('TOMTOM_BASE_URL', 'https://api.tomtom.com'), '/'),
        // The vehicle is a bus. TomTom also accepts car, truck, van and others.
        'travel_mode' => env('TOMTOM_TRAVEL_MODE', 'bus'),
        'timeout_s' => (int) env('TOMTOM_TIMEOUT_S', 8),
    ],

    // How long a good answer is reused for the same pair of ~100 m cells.
    'cache_ttl_s' => (int) env('TRAFFIC_CACHE_TTL_S', 150),

    // How long a failure (limit reached, provider error) is remembered, so a struggling provider is not hammered.
    'failure_cache_ttl_s' => (int) env('TRAFFIC_FAILURE_CACHE_TTL_S', 45),

    // Provider requests this server may make per calendar month. TomTom's free Routing allowance is 20,000.
    'monthly_budget' => (int) env('TRAFFIC_MONTHLY_BUDGET', 18000),

    // A segment longer than this (straight line) is refused: the app asks about the next stop, never a whole region.
    'max_distance_km' => (float) env('TRAFFIC_MAX_DISTANCE_KM', 50),

    // Road-congestion map tiles for the driver map (TomTom Traffic Flow, raster). Served through this server for the
    // same reason as the segment answer: the key stays secret and the picture is shared and counted.
    'flow_tiles' => [
        // TomTom's own colouring of speed against free flow (relative0 is the style TomTom recommends).
        'style' => env('TRAFFIC_FLOW_STYLE', 'relative0'),
        // Every driver looking at the same tile within this time shares one provider request.
        'cache_ttl_s' => (int) env('TRAFFIC_FLOW_CACHE_TTL_S', 120),
        // Provider tile requests this server may make per calendar month, kept under the free allowance.
        'monthly_budget' => (int) env('TRAFFIC_FLOW_MONTHLY_BUDGET', 150000),
        // Outside this zoom range a tile is refused (too wide to be useful, or finer than a driver needs).
        'min_zoom' => (int) env('TRAFFIC_FLOW_MIN_ZOOM', 8),
        'max_zoom' => (int) env('TRAFFIC_FLOW_MAX_ZOOM', 17),
    ],
];
