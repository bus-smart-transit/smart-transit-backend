<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Week start day (Batch 24, C8)
    |--------------------------------------------------------------------------
    | 0 = Sunday ... 6 = Saturday. Defines "this week" in the dashboard
    | calendar summary. The default matches the Sunday-first month grid the
    | staff calendars already render.
    */
    'week_start_day' => (int) env('CALENDAR_WEEK_START_DAY', 0),

    /*
    |--------------------------------------------------------------------------
    | Calendar widget and day list (Batch 26)
    |--------------------------------------------------------------------------
    | `day_per_page` is how many trips/shifts the day list shows per page,
    | `day_max_per_page` the most a client may ask for, `max_range_days` the
    | widest span of days the month markers can be requested for.
    */
    'day_per_page' => (int) env('CALENDAR_DAY_PER_PAGE', 6),
    'day_max_per_page' => (int) env('CALENDAR_DAY_MAX_PER_PAGE', 50),
    'max_range_days' => (int) env('CALENDAR_MAX_RANGE_DAYS', 62),
];
