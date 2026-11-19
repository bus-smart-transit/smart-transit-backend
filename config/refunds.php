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

    // 2026-11-13 (user): when the travelled part of a boarded ticket cannot be priced (no usable point, boarding point or
    // fare rule), this percent of the fare is kept and the rest is refunded at once, with no Operator review. The booking
    // fee is still refunded in full. Empty (REFUND_UNPRICED_DEDUCTION_PERCENT=) sends such tickets to Operator review.
    'unpriced_deduction_percent' => env('REFUND_UNPRICED_DEDUCTION_PERCENT', 10),

    // Rebook: how many days ahead (Manila calendar) a replacement departure is searched, and how many are offered.
    'rebook' => [
        'search_days' => (int) env('REFUND_REBOOK_SEARCH_DAYS', 14),
        'max_options' => (int) env('REFUND_REBOOK_MAX_OPTIONS', 5),
    ],

    // 2026-10-26: when the Operator cancels a trip, ONLINE tickets are refunded at once (RefundCancelledTripTickets) and
    // the buyer is emailed, instead of choosing rebook or refund. Cash tickets keep the choice. A switch, so automatic
    // refunds can be paused (the passenger choice then applies to online tickets again).
    'auto_refund_online_on_cancel' => filter_var(env('REFUND_AUTO_ONLINE_ON_CANCEL', true), FILTER_VALIDATE_BOOLEAN),

    // PayMongo: the reason code sent with a refund (its documented values are duplicate, fraudulent, others).
    'gateway_reason' => env('REFUND_GATEWAY_REASON', 'others'),

    // 2026-11-04 (Batch 45, Todo R): the refund event is the normal way a gateway refund is settled; this check
    // (`refunds:reconcile`, every 15 minutes) asks PayMongo about refunds still pending after `after_minutes`, so a
    // missed event cannot leave a ticket waiting for ever. After `give_up_hours` pending, the ticket goes to the
    // Operator's review queue instead.
    'reconcile' => [
        'after_minutes' => (int) env('REFUND_RECONCILE_AFTER_MINUTES', 10),
        'give_up_hours' => (int) env('REFUND_RECONCILE_GIVE_UP_HOURS', 72),
    ],
];
