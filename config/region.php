<?php

/*
|--------------------------------------------------------------------------
| Region configuration (Batch 24)
|--------------------------------------------------------------------------
| The ONLY place a region-wide value may exist: the service region's name,
| map centre, bounding box, geocoding hint and its provinces/municipalities.
| Everything route-level (stops, geometry, bounds, corridor, origin and
| destination) is data on the route, trip or leg, never a constant here.
|
| Changing region = changing this file (or the REGION_* env values). Nothing
| in application code names a city, province or corridor.
*/

return [

    'name' => env('REGION_NAME', 'Davao Region'),

    // Appended to free-text stop geocoding queries.
    'geocode_hint' => env('GEOCODE_REGION_HINT', 'Davao Region Philippines'),

    // Region view used when no route or trip is in context.
    'center' => [
        'lat' => (float) env('REGION_CENTER_LAT', 6.9),
        'lng' => (float) env('REGION_CENTER_LNG', 125.7),
    ],

    // Service-area bounding box: stop-coordinate validation, address search
    // restriction and the default map view.
    'bbox' => [
        'min_lat' => (float) env('REGION_MIN_LAT', 5.3),
        'max_lat' => (float) env('REGION_MAX_LAT', 8.1),
        'min_lng' => (float) env('REGION_MIN_LNG', 124.9),
        'max_lng' => (float) env('REGION_MAX_LNG', 126.7),
    ],

    // Province => municipalities/cities. Stops may record one of these as their
    // municipality; the province is derived from this map, never stored twice.
    'provinces' => [
        'Davao City' => ['Davao City'],
        'Davao de Oro' => ['Compostela', 'Laak', 'Mabini', 'Maco', 'Maragusan', 'Mawab', 'Monkayo', 'Montevista', 'Nabunturan', 'New Bataan', 'Pantukan'],
        'Davao del Norte' => ['Asuncion', 'Braulio E. Dujali', 'Carmen', 'Kapalong', 'New Corella', 'Panabo City', 'Samal', 'San Isidro', 'Santo Tomas', 'Tagum City', 'Talaingod'],
        'Davao del Sur' => ['Bansalan', 'Digos City', 'Hagonoy', 'Kiblawan', 'Magsaysay', 'Malalag', 'Matanao', 'Padada', 'Santa Cruz', 'Sulop'],
        'Davao Occidental' => ['Don Marcelino', 'Jose Abad Santos', 'Malita', 'Santa Maria', 'Sarangani'],
        'Davao Oriental' => ['Baganga', 'Banaybanay', 'Boston', 'Caraga', 'Cateel', 'Governor Generoso', 'Lupon', 'Manay', 'Mati City', 'San Isidro', 'Tarragona'],
    ],
];
