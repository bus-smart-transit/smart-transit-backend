<?php

/*
|--------------------------------------------------------------------------
| Passenger trip timeline and custom drop-off settings (Batch 24, Stage 3)
|--------------------------------------------------------------------------
| Thresholds only; nothing here names a place, route or corridor.
*/

return [

    // Stop types a passenger can board or get off at. `pass_through` never is.
    'stopping_types' => ['terminal', 'stop'],

    // "Via" line in the timeline header: at most this many major stops.
    'via_max' => (int) env('TIMELINE_VIA_MAX', 3),

    // Consecutive pass-through stops are collapsed into one "N stops" row
    // once there are at least this many of them.
    'collapse_min_stops' => (int) env('TIMELINE_COLLAPSE_MIN_STOPS', 3),

    // Suggested client polling interval (the client pauses it when the tab is hidden).
    'poll_interval_seconds' => (int) env('TIMELINE_POLL_SECONDS', 30),

    // D5: custom drop-off rules. Also gated per route by routes.allow_custom_dropoff.
    'custom_dropoff' => [
        // The pin must be within this distance of the route line.
        'snap_threshold_m' => (float) env('CUSTOM_DROPOFF_SNAP_M', 150),
        // Minimum distance ahead of the boarding point (along the route).
        'min_ahead_of_boarding_m' => (float) env('CUSTOM_DROPOFF_MIN_AHEAD_M', 500),
        // Minimum spacing from any existing stop of the route.
        'min_spacing_from_stop_m' => (float) env('CUSTOM_DROPOFF_MIN_SPACING_M', 150),
    ],

    // Address search proxy (Nominatim), restricted to the region bounding box.
    'address_search' => [
        'limit' => (int) env('ADDRESS_SEARCH_LIMIT', 5),
        'cache_minutes' => (int) env('ADDRESS_SEARCH_CACHE_MIN', 60),
        'min_query_length' => (int) env('ADDRESS_SEARCH_MIN_LENGTH', 3),
    ],
];
