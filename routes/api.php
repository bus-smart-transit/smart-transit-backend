<?php

use App\Http\Controllers\AuthController;
use App\Http\Controllers\PassengerController;
use Illuminate\Support\Facades\Route;

Route::post('login', [AuthController::class, 'login']);
Route::post('register', [PassengerController::class, 'store']);

Route::middleware('auth:sanctum')->group(function () {

    Route::delete('logout', [AuthController::class, 'logout']);

    Route::apiResources(['passengers' => PassengerController::class]);
});

