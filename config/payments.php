<?php

/*
|--------------------------------------------------------------------------
| Online checkout (smarttransit_prompt.md §12)
|--------------------------------------------------------------------------
| unknown_outcome_release_minutes: when PayMongo did not answer a checkout in time, no checkout link reached the
| passenger, so nobody can pay that session. A retry after this many minutes releases its seats and lets a fresh
| checkout start, instead of waiting out the 15-minute hold kept for a checkout whose link WAS delivered.
*/

return [
    'unknown_outcome_release_minutes' => (int) env('PAYMENTS_UNKNOWN_OUTCOME_RELEASE_MINUTES', 2),
];
