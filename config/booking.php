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
    // Batch 49 item 1: the most tickets one online purchase may hold (checked on the server; the form shows it too).
    // Seats are taken only when the payment is confirmed, so this limits one purchase, not a hold.
    // Batch 53 item 1 (user, 2026-11-10): a bus that has not departed stays on the Track Bus map and bookable for this
    // many minutes after its departure time (running late), then neither. TripTiming::lateGraceMinutes() reads it.
    'late_departure_grace_minutes' => (int) env('BOOKING_LATE_DEPARTURE_GRACE_MINUTES', 30),

    'max_seats_per_purchase' => (int) env('BOOKING_MAX_SEATS_PER_PURCHASE', 20),
    // Batch 49 item 6: stops with the same name (ignoring case, spaces and accents) within this many metres are one
    // place for passengers (PlaceGroups). Production pairs are 0 to 9 m apart; two real places 300 m apart stay apart.
    'place_group_radius_m' => (float) env('BOOKING_PLACE_GROUP_RADIUS_M', 100),
    // Batch 49 item 2: a departed trip stays bookable from a stop the bus has not reached (TripProgressService). The bus
    // has left a stop once its live position is this many metres past it (or the Driver tapped it). GPS noise margin.
    'later_stop_passed_margin_m' => (float) env('BOOKING_LATER_STOP_PASSED_MARGIN_M', 30),

    // Batch 27 Part 2, Task 6: when the Chauffeur sells a ticket to someone boarding between stops, the server
    // uses the bus's own latest accepted position. A fix older than this, or less accurate than this, is not
    // good enough: the Chauffeur picks the boarding stop instead and nothing is guessed. (The position upload
    // already refuses fixes worse than 100 m.)
    'boarding_gps' => [
        'max_age_seconds' => (int) env('BOARDING_GPS_MAX_AGE_SECONDS', 90),
        'max_accuracy_m' => (float) env('BOARDING_GPS_MAX_ACCURACY_M', 50),
    ],
];
