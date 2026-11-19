<?php

return [
    /*
    | Batch 81 (user, 2026-11-19): the Operator dashboard's On-time trips. A trip is on time when it left no later than
    | this many minutes after its scheduled departure (the same 5 minutes the stop arrivals card uses for "Delayed").
    */
    'on_time_grace_minutes' => (int) env('DASHBOARD_ON_TIME_GRACE_MINUTES', 5),
];
