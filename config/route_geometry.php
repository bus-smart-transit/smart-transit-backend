<?php

/*
|--------------------------------------------------------------------------
| Route geometry, snapping and GPS acceptance settings (Batch 24, Stage 1)
|--------------------------------------------------------------------------
| Nothing here is route- or city-specific; adding a route needs data, not code.
| Region-wide values (name, centre, bounding box, geocoding hint) live in
| config/region.php.
*/

return [

    'ors' => [
        'base_url' => rtrim((string) env('ORS_BASE_URL', 'https://api.openrouteservice.org'), '/'),
        'api_key' => env('ORS_API_KEY'),
        // ORS has no dedicated bus profile. driving-car is the default because
        // driving-hgv applies vehicle-dimension restrictions that need per-fleet
        // data; set ORS_PROFILE=driving-hgv once bus dimensions are recorded.
        'profile' => env('ORS_PROFILE', 'driving-car'),
        // Public ORS allows 50 waypoints per directions request.
        'max_waypoints' => (int) env('ORS_MAX_WAYPOINTS', 50),
        'snap_radius_m' => (int) env('ORS_SNAP_RADIUS_M', 350),
        // Public ORS rate limit is ~40 requests/minute; pause between calls.
        'request_delay_ms' => (int) env('ORS_REQUEST_DELAY_MS', 1600),
        'connect_timeout_s' => (int) env('ORS_CONNECT_TIMEOUT_S', 5),
        'timeout_s' => (int) env('ORS_TIMEOUT_S', 20),
    ],

    // A stop farther than this from the road network is rejected (D5 snap threshold).
    'max_stop_off_network_m' => (float) env('STOP_MAX_OFF_NETWORK_M', 150),

    // Distance from the route line beyond which a vehicle is "not on route".
    'on_route_threshold_m' => (float) env('ROUTE_ON_THRESHOLD_M', 75),

    // A stop farther than this from the stored route line is reported as off-route.
    'stop_off_route_report_m' => (float) env('STOP_OFF_ROUTE_REPORT_M', 100),

    'redraw_cooldown_minutes' => (int) env('ROUTE_GEOMETRY_REDRAW_COOLDOWN_MINUTES', 15),
    'regeneration_pending_ttl_s' => (int) env('ROUTE_GEOMETRY_PENDING_TTL_S', 3600),
    'debounce_s' => (int) env('ROUTE_GEOMETRY_DEBOUNCE_S', 5),
    'result_cache_ttl_s' => (int) env('ROUTE_GEOMETRY_RESULT_CACHE_TTL_S', 600),

    'gps' => [
        // Fixes reporting a worse (larger) accuracy radius are not accepted.
        'max_accuracy_m' => (float) env('GPS_MAX_ACCURACY_M', 100),
        // Position older than this is shown as stale ("last seen X ago").
        'stale_after_s' => (int) env('GPS_STALE_AFTER_S', 120),
        // Dev-only simulator (artisan gps:simulate). Must stay false in production.
        'simulator_enabled' => (bool) env('GPS_SIMULATOR_ENABLED', false),
    ],
];
