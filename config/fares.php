<?php

/*
|--------------------------------------------------------------------------
| Fleet classes and fare rounding (Batch 28, Stage 2, item 5)
|--------------------------------------------------------------------------
| Lists and settings only. No fare amount, rate or place lives here: fares are rows in fare_rules.
*/

return [

    // Allowed fleets.vehicle_class values (key => label shown on screens). Edit this list to add a class.
    'vehicle_classes' => [
        // Batch 75 (panel, 2026-11-15): shown as "Non-aircon"; the stored key stays 'ordinary'.
        'ordinary' => 'Non-aircon',
        'aircon' => 'Aircon',
    ],

    // Names that are accepted on input and stored as another class ("Deluxe is part of aircon").
    'vehicle_class_aliases' => [
        'deluxe' => 'aircon',
        'non-aircon' => 'ordinary',
        'non aircon' => 'ordinary',
        'nonaircon' => 'ordinary',
    ],

    // fare_rules.rounding_mode values: nearest = half up, ceiling = always up, to the rounding increment.
    'rounding_modes' => ['nearest', 'ceiling'],
    'default_rounding_mode' => 'nearest',

    // The step a fare is rounded to. ADVISER DECISION (Batch 28 Stage 3): fares round to the WHOLE PESO, so the
    // default is 1.00 (nearest stays the default mode; ceiling stays an operator setting per fare rule). Set 0.01
    // for centavos or 0.25 for quarter-peso steps. One setting for the whole system; tickets already issued keep
    // the amount they were sold at.
    'rounding_increment' => env('FARE_ROUNDING_INCREMENT', '1.00'),

    // Batch 75 (user, 2026-11-15): the online transaction fee follows PayMongo's published e-wallet rates
    // (paymongo.com/pricing, read 2026-11-15: GCash 2.23%, Maya 1.79%, no fixed part, 12% VAT charged on the fee),
    // a percentage of the fares, once per payment (App\Support\BookingFee owns the rules). Cash sales pay none.
    // A capstone mock-up of PayMongo's charge in test mode, not a live reading. 0 turns a channel's fee off (L-65).
    'transaction_fee' => [
        'rates_percent' => [
            'gcash' => (float) env('TRANSACTION_FEE_GCASH_PERCENT', 2.23),
            'maya' => (float) env('TRANSACTION_FEE_MAYA_PERCENT', 1.79),
        ],
        'vat_percent' => (float) env('TRANSACTION_FEE_VAT_PERCENT', 12),
    ],
];
