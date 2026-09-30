<?php

/*
|--------------------------------------------------------------------------
| Passenger booking settings (Batch 24, Stage 3)
|--------------------------------------------------------------------------
*/

return [

    // Book Later: how far ahead (in days, Manila calendar) a departure can be booked.
    'max_advance_days' => (int) env('BOOKING_MAX_ADVANCE_DAYS', 30),

    // How many days after the requested time to look for an eligible departure.
    'search_window_days' => (int) env('BOOKING_SEARCH_WINDOW_DAYS', 7),
];