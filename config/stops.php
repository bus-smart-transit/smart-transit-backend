<?php

/*
 * 2026-11-06 (Batch 47 part A): warnings when a stop is pinned. Warnings, not refusals; the company admin decides.
 * - duplicate_warning_m: a new or moved stop this close to another stop of the same company ("Is this the same stop?").
 * - detour_warning_km: going from the stop before to the stop after through this stop is this much longer than going
 *   straight (App\Support\StopSpacing), so the pin may be off the route.
 */
return [
    'duplicate_warning_m' => (int) env('STOPS_DUPLICATE_WARNING_M', 30),
    'detour_warning_km' => (float) env('STOPS_DETOUR_WARNING_KM', 3),
];
