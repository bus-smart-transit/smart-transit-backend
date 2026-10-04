<?php

/**
 * Batch 18: Shift Block Hand-off System configuration.
 *
 * Per the batch spec: represented as named config values rather than a
 * `shift_block_templates` table, since the system currently runs on a
 * small set of reference routes — a template table would only make sense
 * once per-route block templates are needed (see SDD.md
 * Section 10.1, "Recommendations for Future Work"). Defaulting to a config
 * file (not a runtime-editable settings table) since there's no current
 * requirement for non-technical staff to change these without a deploy —
 * revisit if that changes.
 */
return [
    // Number of one-way trip legs per shift block (e.g. 4 = two full
    // round trips before hand-off).
    'legs_per_block' => (int) env('SHIFT_BLOCK_LEGS_PER_BLOCK', 4),

    // Terminal Turnaround Buffer: wait time between legs WITHIN the same
    // block, at the terminal — distinct from the Rest Rule below.
    'terminal_turnaround_buffer_minutes' => (int) env('SHIFT_BLOCK_TURNAROUND_BUFFER_MINUTES', 30),

    // Target total duration of one shift block (informational/validation
    // only — not enforced as a hard cutoff).
    'target_block_duration_hours' => (int) env('SHIFT_BLOCK_TARGET_DURATION_HOURS', 8),

    // Rest Rule threshold: minimum time between a staff member finishing
    // one block and starting their next (across different blocks/days).
    'rest_threshold_hours' => (int) env('SHIFT_BLOCK_REST_THRESHOLD_HOURS', 12),

    // Location Match Rule: default geofence radius (metres) used when a
    // Stop doesn't have its own `geofence_radius_m` set.
    'default_geofence_radius_m' => (int) env('SHIFT_BLOCK_DEFAULT_GEOFENCE_RADIUS_M', 150),

    // Configurable list of valid block types — NOT hardcoded to exactly
    // morning/afternoon in application logic, so a third type can be added
    // here without a code change.
    'block_types' => ['morning', 'afternoon'],

    // Batch 28: how far back (days) the Driver / Chauffeur assignment cards reach, so an unfinished
    // block from earlier this week is not hidden, and how many block cards the Operator tab loads.
    'card_history_days' => (int) env('SHIFT_BLOCK_CARD_HISTORY_DAYS', 7),
    'operator_card_limit' => (int) env('SHIFT_BLOCK_OPERATOR_CARD_LIMIT', 60),
];
