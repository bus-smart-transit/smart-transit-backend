<?php

/*
|--------------------------------------------------------------------------
| Route geometry, snapping and GPS acceptance settings (Batch 24, Stage 1)
|--------------------------------------------------------------------------
| Nothing here is route- or city-specific. The region bounding box is the
| configured service area (default: Davao Region) and is used only to
| validate stop coordinates; adding a route needs data, not code.
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
        'timeout_s' => (int) env('ORS_TIMEOUT_S', 20),
    ],

    // Service-area bounding box used for stop-coordinate validation.
    'region_bbox' => [
        'min_lat' => (float) env('REGION_MIN_LAT', 5.3),
        'max_lat' => (float) env('REGION_MAX_LAT', 8.1),
        'min_lng' => (float) env('REGION_MIN_LNG', 124.9),
        'max_lng' => (float) env('REGION_MAX_LNG', 126.7),
    ],

    // Text appended to free-text stop geocoding queries (never a literal in code).
    'geocode_region_hint' => env('GEOCODE_REGION_HINT', 'Davao Region Philippines'),

    // A stop farther than this from the road network is rejected (D5 snap threshold).
    'max_stop_off_network_m' => (float) env('STOP_MAX_OFF_NETWORK_M', 150),

    // Distance from the route line beyond which a vehicle is "not on route".
    'on_route_threshold_m' => (float) env('ROUTE_ON_THRESHOLD_M', 75),

    // A stop farther than this from the stored route line is reported as off-route.
    'stop_off_route_report_m' => (float) env('STOP_OFF_ROUTE_REPORT_M', 100),

    'gps' => [
        // Fixes reporting a worse (larger) accuracy radius are not accepted.
        'max_accuracy_m' => (float) env('GPS_MAX_ACCURACY_M', 100),
        // Position older than this is shown as stale ("last seen X ago").
        'stale_after_s' => (int) env('GPS_STALE_AFTER_S', 120),
        // Dev-only simulator (artisan gps:simulate). Must stay false in production.
        'simulator_enabled' => (bool) env('GPS_SIMULATOR_ENABLED', false),
    ],
];
