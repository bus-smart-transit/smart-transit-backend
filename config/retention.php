<?php

/*
|--------------------------------------------------------------------------
| Data retention (Batch 28 Stage 3, item 9)
|--------------------------------------------------------------------------
*/

return [

    // Bus position history (fleet_location_history) is kept this many days. Adviser decision: 30.
    'location_history_days' => (int) env('LOCATION_HISTORY_RETENTION_DAYS', 30),

    // The daily deletion is NOT scheduled until this is true (set LOCATION_HISTORY_PRUNE_SCHEDULE=true). Run
    // `php artisan fleet:prune-location-history --dry-run` first and check the count it reports.
    'location_history_schedule_enabled' => filter_var(env('LOCATION_HISTORY_PRUNE_SCHEDULE', false), FILTER_VALIDATE_BOOLEAN),
];
