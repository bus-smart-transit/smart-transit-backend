<?php

/*
|--------------------------------------------------------------------------
| Designated alighting points and drop-off restrictions (Batch 28 Stage 3, item 10)
|--------------------------------------------------------------------------
| Settings only. The restricted areas and the designated points themselves are database rows entered by the team;
| nothing here names a place.
*/

return [

    // A restriction stores the two end stops' distances when it is saved. If an end stop's stored distance later differs
    // by more than this, the stop was moved or the route was rebuilt: the restriction is flagged "needs review".
    // (Stored distances are whole metres from the route line, so a regenerated line moves them by a metre or two.)
    'restriction_distance_tolerance_m' => (float) env('DROPOFF_RESTRICTION_TOLERANCE_M', 50),

    // Stored stop distances are whole metres, so a restricted area that ends at the last stop (or starts where a
    // passenger boards) can miss the line's end by a metre or two. Gaps and shortfalls up to this are ignored when
    // asking "is everything left on the trip restricted?".
    'remainder_tolerance_m' => (float) env('DROPOFF_REMAINDER_TOLERANCE_M', 5),

    // What a restriction that needs review does. "closed" (adviser-recommended): custom drop-off is blocked on that
    // whole route until someone reviews it. "open" would allow it; it is not offered, so a broken row can never let a
    // passenger be dropped inside a restricted area.
    'needs_review_fails' => 'closed',

    // A designated point must lie within this distance of the route line (otherwise it cannot be offered).
    // Defaults to the free-pin snap threshold so one number governs both.
    'point_snap_threshold_m' => (float) env('DROPOFF_POINT_SNAP_M', env('CUSTOM_DROPOFF_SNAP_M', 150)),
];
