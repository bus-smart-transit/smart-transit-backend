<?php

/*
 * 2026-11-06 (Batch 47 part A): a new or moved stop this close to another stop of the same company gives a warning
 * ("Is this the same stop?"), not a refusal.
 */
return [
    'duplicate_warning_m' => (int) env('STOPS_DUPLICATE_WARNING_M', 30),
];
