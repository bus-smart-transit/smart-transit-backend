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
    'poll_seconds' => (int) env('TRACKING_POLL_SECONDS', 10),
    'backfill_days' => (int) env('TRACKING_BACKFILL_DAYS', 1),

    // Misses (unknown or malformed links) allowed per client in `miss_window_minutes`.
    'miss_limit' => (int) env('TRACKING_MISS_LIMIT', 20),
    'miss_window_minutes' => (int) env('TRACKING_MISS_WINDOW_MIN', 10),
];
