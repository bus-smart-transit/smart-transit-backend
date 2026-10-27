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

    // 2026-10-27 (§4.2): a block is no longer a fixed number of legs. The planner (LegPlanningService) suggests how many
    // fit from the route's travel time; a block may have 1 to this many. legs_per_block above stays as the count the
    // older screens and tests fill in by default.
    'max_legs_per_block' => (int) env('SHIFT_BLOCK_MAX_LEGS_PER_BLOCK', 8),

    // Terminal Turnaround Buffer: wait time between legs WITHIN the same
    // block, at the terminal — distinct from the Rest Rule below.
    'terminal_turnaround_buffer_minutes' => (int) env('SHIFT_BLOCK_TURNAROUND_BUFFER_MINUTES', 30),

    // Target total duration of one shift block (informational/validation
    // only — not enforced as a hard cutoff).
    'target_block_duration_hours' => (int) env('SHIFT_BLOCK_TARGET_DURATION_HOURS', 8),

    // Rest Rule threshold: minimum time between a staff member finishing
    // one block and starting their next (across different blocks/days).
    'rest_threshold_hours' => (int) env('SHIFT_BLOCK_REST_THRESHOLD_HOURS', 12),

    // Phase B (smarttransit_prompt.md §4.13): the Operator schedules the same block over a From..To range of dates.
    // range_max_days: longest range accepted (confirmed: 31). max_consecutive_work_days: a range day is skipped when
    // the Driver or Chauffeur would work more days in a row than this without a rest day (Labor Code Art. 91 and
    // DO 118-12 reference: a rest day after 6 work days; a reference for planning, not a legal determination).
    'range_max_days' => (int) env('SHIFT_BLOCK_RANGE_MAX_DAYS', 31),
    'max_consecutive_work_days' => (int) env('SHIFT_BLOCK_MAX_CONSECUTIVE_WORK_DAYS', 6),

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

    // Batch 27 Part 2: from the second leg on, the bus must be at that leg's origin terminal before the
    // leg can start (the Location Match Rule applied per leg). DEFAULT: ON, and it must stay on in any real
    // deployment. Turning it OFF (SHIFT_BLOCK_LEG_LOCATION_RULE=false) is for a DEV machine with no GPS feed
    // only: with it off a leg can start anywhere, which defeats the rule.
    'leg_location_rule_enabled' => filter_var(env('SHIFT_BLOCK_LEG_LOCATION_RULE', true), FILTER_VALIDATE_BOOLEAN),
];
