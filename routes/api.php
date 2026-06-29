<?php

use App\Http\Controllers\AuthController;
use App\Http\Controllers\PassengerController;
use Illuminate\Support\Facades\Route;

Route::prefix('passengers')->group(function () {
    Route::post('login', [AuthController::class, 'login']);
    Route::post('register', [PassengerController::class, 'store']);

    Route::middleware('auth:sanctum')->group(function () {
        Route::delete('logout', [AuthController::class, 'logout']);
        Route::get('profile', [AuthController::class, 'profile']);

        Route::apiResource('/', PassengerController::class)
            ->except(['store'])
            ->parameters(['' => 'passenger']);
    });
});