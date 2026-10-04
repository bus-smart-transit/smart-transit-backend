<?php

/*
|--------------------------------------------------------------------------
| Operator-cancelled trips: rebook or refund (Batch 28 Stage 3, item 6)
|--------------------------------------------------------------------------
| Settings only. No fare or amount lives here: refunds are worked out from the ticket's own fare rule.
*/

return [

    // How many days after a cancellation a PASSENGER may still choose rebook or refund. Deliberately NO default:
    // null means "no deadline is set", and until one is configured a ticket simply stays "trip cancelled, awaiting
    // choice" (nothing is ever refunded automatically). Staff can still act for a passenger after a deadline.
    'choice_deadline_days' => env('CANCELLATION_CHOICE_DEADLINE_DAYS') !== null && env('CANCELLATION_CHOICE_DEADLINE_DAYS') !== ''
        ? (int) env('CANCELLATION_CHOICE_DEADLINE_DAYS')
        : null,

    // After a cancellation DURING the trip, where the bus had got to is the last accepted GPS position (matched to the
    // route) or the last stop the driver acknowledged, whichever was recorded later. A GPS fix older than this, or
    // farther than the route's on-route threshold, is not used (nothing is guessed): with no usable point the ticket is
    // flagged for operator review instead of being priced.
    'last_point' => [
        'max_gps_age_seconds' => (int) env('REFUND_LAST_POINT_MAX_GPS_AGE_SECONDS', 300),
    ],

    // Rebook: how many days ahead (Manila calendar) a replacement departure is searched, and how many are offered.
    'rebook' => [
        'search_days' => (int) env('REFUND_REBOOK_SEARCH_DAYS', 14),
        'max_options' => (int) env('REFUND_REBOOK_MAX_OPTIONS', 5),
    ],

    // PayMongo: the reason code sent with a refund (its documented values are duplicate, fraudulent, others).
    'gateway_reason' => env('REFUND_GATEWAY_REASON', 'others'),
];
