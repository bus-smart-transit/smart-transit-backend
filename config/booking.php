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

    // Available trips: how many the home preview shows, and the page size of the all-trips page.
    'home_preview_limit' => (int) env('BOOKING_HOME_PREVIEW_LIMIT', 5),
    'trips_per_page' => (int) env('BOOKING_TRIPS_PER_PAGE', 10),
    'trips_max_per_page' => (int) env('BOOKING_TRIPS_MAX_PER_PAGE', 50),
];