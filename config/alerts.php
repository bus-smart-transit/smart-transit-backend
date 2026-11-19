<?php

/*
|--------------------------------------------------------------------------
| Crew alerts ("Alert operator", Batch 58)
|--------------------------------------------------------------------------
| A Driver or Chauffeur tells every Operator of the company that something stopped their trip. This alerts the bus
| company only; it never calls the police or an ambulance (the screens say so and offer to call 911).
*/
return [
    // The types a crew member may choose (user, 2026-11-10: these three for now).
    'types' => [
        'breakdown' => 'Breakdown',
        'accident' => 'Accident',
        'road_blocked' => 'Road blocked',
    ],

    // What passengers on the trip read when an Operator presses "Tell passengers" ({route} is the route name).
    'passenger_notice' => [
        'breakdown' => '{route}: your bus has a breakdown and will be late. Your bus company is arranging help. We will update you.',
        'accident' => '{route}: your bus was in a road incident and will be late. Your bus company is handling it. We will update you.',
        'road_blocked' => '{route}: the road ahead of your bus is blocked, so it will be late. We will update you.',
    ],

    // A trip in one of these states can raise an alert (from boarding until it ends).
    'raise_statuses' => ['boarding', 'departed', 'in-progress', 'delayed'],

    // The crew member may take an alert back this long after sending it (a mistaken press).
    'cancel_window_seconds' => (int) env('ALERT_CANCEL_WINDOW_SECONDS', 120),

    // A second press of the same type on the same trip within this time returns the alert already open (no duplicates).
    'repeat_window_seconds' => (int) env('ALERT_REPEAT_WINDOW_SECONDS', 300),

    // A last GPS report older than this is not used as the alert's place.
    'gps_max_age_seconds' => (int) env('ALERT_GPS_MAX_AGE_SECONDS', 300),

    // How far back the Operator's alert list goes.
    'recent_hours' => (int) env('ALERT_RECENT_HOURS', 24),
];
