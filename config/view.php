<?php

return [

    /*
    |--------------------------------------------------------------------------
    | View Storage Paths
    |--------------------------------------------------------------------------
    */

    'paths' => [
        resource_path('views'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Compiled View Path
    |--------------------------------------------------------------------------
    | Use storage_path() directly — NOT realpath() — so config:cache never
    | bakes in `false` when the directory doesn't exist yet at cache time.
    */

    'compiled' => env('VIEW_COMPILED_PATH', storage_path('framework/views')),

];
