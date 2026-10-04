<?php

/*
|--------------------------------------------------------------------------
| Leg planning and peak-hour duration factors (Batch 28 Stage 3, item 7)
|--------------------------------------------------------------------------
| Settings only. The factors themselves are rows in route_duration_factors; the turnaround buffer between legs is
| shift_blocks.terminal_turnaround_buffer_minutes (already configurable).
*/

return [

    // A factor must be above 0 and at most this (a typo like 25 is refused instead of making every leg a day long).
    'max_factor' => (float) env('DURATION_FACTOR_MAX', 5),

    // The read-only duration report proposes a measured factor for a route, direction and hour only when it has at
    // least this many completed legs behind it; below that it says "not enough data" and proposes nothing.
    'report_min_samples' => (int) env('DURATION_REPORT_MIN_SAMPLES', 10),
];
