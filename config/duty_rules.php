<?php

/*
|--------------------------------------------------------------------------
| Duty-time and driving-time references for the leg planner (smarttransit_prompt.md section 5)
|--------------------------------------------------------------------------
| Starting values researched on 2026-10-24. They are a reference for planning, NOT a legal determination, and the
| planner only FLAGS a schedule for the Operator; it never blocks or approves one on these numbers.
|
|  - Labor Code Art. 83 / DOLE DO 118-12 Rule II Sec. 3: normal hours do not exceed 8 a day.
|  - DO 118-12 Rule II Sec. 3: with overtime, at most 12 hours of work in any 24 hours; a rest of at least 1 hour
|    (meals excluded) within a 12-hour shift. Sec. 2: an extra 10% for work between 10:00 pm and 6:00 am.
|  - LTFRB MC 2017-012: at most 6 hours of continuous driving; beyond that an alternate driver who is not the
|    conductor. A May 2025 DOTr order asked for 4 hours; no circular implementing it was found (limitation L-64), so
|    the default stays 6. Confirm with LTFRB Region XI before the defense.
|  - Only time in motion counts as driving. The planner adds up all driving in a block and does not assume a layover
|    resets the count.
*/

return [

    'normal_work_hours' => (float) env('DUTY_NORMAL_WORK_HOURS', 8),

    'max_work_hours_in_24h' => (float) env('DUTY_MAX_WORK_HOURS_24H', 12),

    'max_continuous_driving_hours' => (float) env('DUTY_MAX_CONTINUOUS_DRIVING_HOURS', 6),

    'min_rest_minutes_in_12h_shift' => (int) env('DUTY_MIN_REST_MINUTES', 60),

    // Night work (extra pay) starts and ends at these Manila times.
    'night_work_start' => env('DUTY_NIGHT_WORK_START', '22:00'),
    'night_work_end' => env('DUTY_NIGHT_WORK_END', '06:00'),
];
