<?php

use App\Http\Controllers\PassengerController;
use Illuminate\Support\Facades\Route;

Route::apiResource('passengers', PassengerController::class);
