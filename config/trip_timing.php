<?php

/*
|--------------------------------------------------------------------------
| Trip action timing (smarttransit_prompt.md §4.11, Phase A)
|--------------------------------------------------------------------------
| Read only through App\Support\TripTiming. Two separate settings that must not be merged:
|
| - pairing_opens_minutes_before: the Driver and Chauffeur can get their pairing code and pair from this many minutes
|   before the departure (for a shift block, the first leg's departure). From the operator interview: 60.
| - boarding_window_minutes: Start Boarding opens this many minutes before the leg's scheduled departure.
|
| Depart is allowed at or after the scheduled departure and stays allowed when the bus is late.
*/

return [
    'pairing_opens_minutes_before' => (int) env('TRIP_PAIRING_OPENS_MINUTES_BEFORE', 60),
    'boarding_window_minutes' => (int) env('TRIP_BOARDING_WINDOW_MINUTES', 30),
];
