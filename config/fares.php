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
        'ordinary' => 'Ordinary',
        'aircon' => 'Aircon',
    ],

    // Names that are accepted on input and stored as another class ("Deluxe is part of aircon").
    'vehicle_class_aliases' => [
        'deluxe' => 'aircon',
    ],

    // fare_rules.rounding_mode values: nearest = half up, ceiling = always up, to the rounding increment.
    'rounding_modes' => ['nearest', 'ceiling'],
    'default_rounding_mode' => 'nearest',

    // The step a fare is rounded to. ADVISER DECISION (Batch 28 Stage 3): fares round to the WHOLE PESO, so the
    // default is 1.00 (nearest stays the default mode; ceiling stays an operator setting per fare rule). Set 0.01
    // for centavos or 0.25 for quarter-peso steps. One setting for the whole system; tickets already issued keep
    // the amount they were sold at.
    'rounding_increment' => env('FARE_ROUNDING_INCREMENT', '1.00'),
];
