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
];
