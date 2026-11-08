<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Ticket-scoped bus tracking (Batch 26)
    |--------------------------------------------------------------------------
    | A tracking link works only for its own ticket's trip: from `opens_minutes_before` the
    | scheduled departure until the trip has ended plus `grace_minutes_after` (or, if the trip
    | never ends, `max_active_hours` after departure plus the grace). A position older than
    | `stale_seconds` is shown as stale, not as live. `backfill_days` limits how far back an
    | old ticket is given a link the first time its owner opens it.
    */
    'opens_minutes_before' => (int) env('TRACKING_OPENS_MINUTES_BEFORE', 60),
    'grace_minutes_after' => (int) env('TRACKING_GRACE_MINUTES_AFTER', 30),
    'max_active_hours' => (int) env('TRACKING_MAX_ACTIVE_HOURS', 12),
    'stale_seconds' => (int) env('TRACKING_STALE_SECONDS', 120),
    // Batch 50: the bus is AT a stop within this many metres of it along the route (and has arrived when that stop is
    // the last one); the live ETA uses the speed the bus reports, or this speed when it reports none.
    'at_stop_radius_m' => (float) env('TRACKING_AT_STOP_RADIUS_M', 150),
    'eta_fallback_kmh' => (float) env('TRACKING_ETA_FALLBACK_KMH', 30),
    'poll_seconds' => (int) env('TRACKING_POLL_SECONDS', 10),
    'backfill_days' => (int) env('TRACKING_BACKFILL_DAYS', 1),

    // Batch 45 Feature C (decided 2026-11-04): the "buses near you" radius on the public map, in km. The passenger's
    // location stays on their device: the page measures the distance itself from the public bus list.
    'nearby_radius_km' => (float) env('TRACKING_NEARBY_RADIUS_KM', 5),

    // 2026-11-08 (user): a bus that has not departed shows on the public map as waiting at its first stop, from this
    // many minutes before its departure (today's trips only), until the Driver presses Depart. A boarding trip shows
    // whatever its time. See App\Services\WaitingBusService.
    'waiting_bus_minutes_before' => (int) env('TRACKING_WAITING_BUS_MINUTES_BEFORE', 60),

    // Seconds the public map's bus list (GET fleet/locations) is shared between callers; 0 turns the cache off.
    'active_locations_cache_seconds' => (int) env('TRACKING_ACTIVE_LOCATIONS_CACHE_SECONDS', 5),

    // Misses (unknown or malformed links) allowed per client in `miss_window_minutes`.
    'miss_limit' => (int) env('TRACKING_MISS_LIMIT', 20),
    'miss_window_minutes' => (int) env('TRACKING_MISS_WINDOW_MIN', 10),
];
