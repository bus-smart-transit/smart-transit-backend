<?php

use App\Http\Controllers\AuthController;
use App\Http\Controllers\PassengerController;
use App\Http\Controllers\StaffAuthController;
use App\Http\Controllers\StopController;
use App\Http\Controllers\RouteController;
use App\Http\Controllers\FleetsController;
use App\Http\Controllers\FareController;
use App\Http\Controllers\TripController;
use App\Http\Controllers\TicketController;
use App\Http\Controllers\PaymentController;
use Illuminate\Support\Facades\Route;

// ══════════════════════════════════════════════════════════════════════
// PUBLIC — no auth required
// ══════════════════════════════════════════════════════════════════════

Route::post('passengers/login', [AuthController::class, 'login']);
Route::post('passengers/register', [PassengerController::class, 'store']);
Route::post('staff/login', [StaffAuthController::class, 'login']);

// ══════════════════════════════════════════════════════════════════════
// PASSENGER
// Can: view own profile, get fare quotes, buy tickets, view own tickets
// ══════════════════════════════════════════════════════════════════════

Route::prefix('passengers')
    ->middleware(['auth:sanctum', 'role:passenger'])
    ->group(function () {
        Route::delete('logout', [AuthController::class, 'logout']);
        Route::get('profile', [AuthController::class, 'profile']);
        Route::put('profile', [PassengerController::class, 'update']);

        Route::get('tickets', [TicketController::class, 'myTickets']);
        Route::get('fare/quote', [FareController::class, 'quote']);
        Route::post('checkout', [PaymentController::class, 'checkoutOnline']);
    });

// ══════════════════════════════════════════════════════════════════════
// BUS OPERATOR
// Can: manage fleets, assign routes, set fares, schedule trips,
//      assign drivers and conductors, create driver/conductor accounts,
//      view drivers and conductors under them
// ══════════════════════════════════════════════════════════════════════

Route::prefix('operator')
    ->middleware(['auth:sanctum', 'role:operator'])
    ->group(function () {
        Route::delete('logout', [StaffAuthController::class, 'logout']);
        Route::get('profile', [StaffAuthController::class, 'profile']);

        // Account management — operator can only create driver/conductor accounts
        Route::post('accounts', [StaffAuthController::class, 'createAccount']);
        Route::get('drivers', [StaffAuthController::class, 'listDrivers']);
        Route::get('conductors', [StaffAuthController::class, 'listConductors']);

        // Fleet management
        Route::get('fleets', [FleetsController::class, 'index']);
        Route::post('fleets', [FleetsController::class, 'store']);
        Route::post('fleets/{fleetId}/routes', [FleetsController::class, 'assignRoute']);

        // Fare rules
        Route::post('fare-rules', [FareController::class, 'storeRule']);
        Route::post('fare/recalculate/{fleetRouteId}', [FareController::class, 'recalculate']);

        // Trip scheduling + staff assignment
        Route::post('trips', [TripController::class, 'store']);
        Route::patch('trips/{tripId}/driver', [TripController::class, 'assignDriver']);
        Route::patch('trips/{tripId}/conductor', [TripController::class, 'assignConductor']);
        Route::patch('trips/{tripId}/boarding', [TripController::class, 'startBoarding']);
        Route::patch('trips/{tripId}/depart', [TripController::class, 'depart']);
        Route::patch('trips/{tripId}/complete', [TripController::class, 'complete']);
    });

// ══════════════════════════════════════════════════════════════════════
// DRIVER
// Can: view their assigned trips, update trip status (depart / complete)
// Cannot: create accounts, manage fleets, sell tickets
// ══════════════════════════════════════════════════════════════════════

Route::prefix('driver')
    ->middleware(['auth:sanctum', 'role:driver'])
    ->group(function () {
        Route::delete('logout', [StaffAuthController::class, 'logout']);
        Route::get('profile', [StaffAuthController::class, 'profile']);

        Route::get('trips', [TripController::class, 'myTrips']);
        Route::get('trips/current', [TripController::class, 'currentTripDriver']);
        Route::patch('trips/{tripId}/depart', [TripController::class, 'depart']);
        Route::patch('trips/{tripId}/complete', [TripController::class, 'complete']);
    });

// ══════════════════════════════════════════════════════════════════════
// CONDUCTOR
// Can: view current trip, scan/validate QR tickets, record onsite cash sales
// Cannot: create accounts, manage fleets, change trip status
// ══════════════════════════════════════════════════════════════════════

Route::prefix('conductor')
    ->middleware(['auth:sanctum', 'role:conductor'])
    ->group(function () {
        Route::delete('logout', [StaffAuthController::class, 'logout']);
        Route::get('profile', [StaffAuthController::class, 'profile']);

        Route::get('trips/current', [TripController::class, 'currentTripConductor']);
        Route::post('tickets/scan', [TicketController::class, 'scan']);
        Route::post('checkout', [PaymentController::class, 'checkoutOnsite']);
    });

// ══════════════════════════════════════════════════════════════════════
// ADMIN (developers)
// Full access to everything — no restrictions
// ══════════════════════════════════════════════════════════════════════

Route::prefix('admin')
    ->middleware(['auth:sanctum', 'role:admin'])
    ->group(function () {
        Route::delete('logout', [StaffAuthController::class, 'logout']);
        Route::get('profile', [StaffAuthController::class, 'profile']);

        // Admin creates operator accounts
        Route::post('accounts', [StaffAuthController::class, 'createAccount']);
        Route::get('drivers', [StaffAuthController::class, 'listDrivers']);
        Route::get('conductors', [StaffAuthController::class, 'listConductors']);

        // Stops
        Route::apiResource('stops', StopController::class);

        // Routes + stops
        Route::apiResource('routes', RouteController::class);
        Route::post('routes/{routeId}/stops', [RouteController::class, 'addStop']);
        Route::delete('routes/{routeId}/stops/{routeStopId}', [RouteController::class, 'removeStop']);

        // Fleets
        Route::apiResource('fleets', FleetsController::class);
        Route::post('fleets/{fleetId}/routes', [FleetsController::class, 'assignRoute']);

        // Fare rules + recalculation
        Route::post('fare-rules', [FareController::class, 'storeRule']);
        Route::post('fare/recalculate/{fleetRouteId}', [FareController::class, 'recalculate']);

        // Trips — full control
        Route::post('trips', [TripController::class, 'store']);
        Route::patch('trips/{tripId}/driver', [TripController::class, 'assignDriver']);
        Route::patch('trips/{tripId}/conductor', [TripController::class, 'assignConductor']);
        Route::patch('trips/{tripId}/boarding', [TripController::class, 'startBoarding']);
        Route::patch('trips/{tripId}/depart', [TripController::class, 'depart']);
        Route::patch('trips/{tripId}/complete', [TripController::class, 'complete']);
    });
