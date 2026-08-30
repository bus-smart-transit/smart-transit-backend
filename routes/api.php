<?php

use App\Http\Controllers\AuthController;
use App\Http\Controllers\PassengerController;
use App\Http\Controllers\PassengerDashboardController;
use App\Http\Controllers\StaffAuthController;
use App\Http\Controllers\StopController;
use App\Http\Controllers\RouteController;
use App\Http\Controllers\FleetController;
use App\Http\Controllers\FareRuleController;
use App\Http\Controllers\PayMongoController;
use App\Http\Controllers\TripController;
use App\Http\Controllers\TicketController;
use App\Http\Controllers\PaymentController;
use App\Http\Controllers\FleetLocationController;       
use App\Http\Controllers\FleetDailyPinController;
use App\Http\Controllers\FleetPairingController;
use App\Http\Controllers\RewardController;
use App\Http\Controllers\ReportingController;
use Illuminate\Support\Facades\Route;

// PUBLIC — no auth required
// Login endpoints use controller-level RateLimiter (failed-only counting).
Route::post('passengers/login', [AuthController::class, 'login']);
Route::post('passengers/verify-otp', [AuthController::class, 'verifyOtp'])->middleware('throttle:10,15');
Route::post('passengers/register', [PassengerController::class, 'store'])->middleware('throttle:5,15,passenger-register');
Route::post('passengers/forgot-password', [AuthController::class, 'forgotPassword'])->middleware('throttle:3,15');
Route::post('passengers/reset-password',  [AuthController::class, 'resetPassword'])->middleware('throttle:3,15');
Route::post('staff/login', [StaffAuthController::class, 'login']);
Route::post('staff/verify-otp', [StaffAuthController::class, 'verifyOtp'])->middleware('throttle:5,15');
Route::post('staff/forgot-password', [StaffAuthController::class, 'forgotPassword'])->middleware('throttle:3,15');
Route::post('staff/reset-password', [StaffAuthController::class, 'resetPassword'])->middleware('throttle:3,15');
Route::delete('staff/logout', [StaffAuthController::class, 'logout'])
    ->middleware(['auth:sanctum', 'role:driver,conductor,operator,admin']);
Route::post('fare/quote', [FareRuleController::class, 'quote']);
Route::post('fare/quote-fleets-by-location', [FareRuleController::class, 'quoteFleetsByLocation']);
Route::get('fleet/locations', [FleetLocationController::class, 'activeLocations']);
Route::get('fleet/nearest', [FleetLocationController::class, 'nearest']);
Route::get('/routes/{routeId}/fares', [FareRuleController::class, 'listFares']);
Route::get('/routes/{routeId}/stops', [RouteController::class, 'publicStops']);
Route::get('trips', [TripController::class, 'availableTrips']);

// Remove:

//PAYMONGO / PAYMENT 

Route::post('checkout', [PaymentController::class, 'checkoutOnline'])
    ->middleware('auth.optional');
Route::get('tickets/lookup', [TicketController::class, 'guestLookup']);
Route::post('webhooks/paymongo', [PayMongoController::class, 'handle']);

// PASSENGER
// Can: view own profile, get fare quotes, buy tickets, view own tickets

Route::prefix('passengers')
    ->middleware(['auth:sanctum', 'role:passenger'])
    ->group(function () {
        Route::delete('logout', [AuthController::class, 'logout']);
        Route::get('profile', [AuthController::class, 'profile']);
        Route::put('profile', [PassengerController::class, 'update']);
        Route::patch('two-factor', [AuthController::class, 'updateTwoFactor']);
        Route::get('tickets', [TicketController::class, 'myTickets']);
        Route::get('tickets/{ticketUuid}/qr', [TicketController::class, 'getTicketQR']);
        Route::get('dashboard-summary', [PassengerDashboardController::class, 'summary']);
        Route::get('payments', [PaymentController::class, 'passengerHistory']);
        Route::get('rewards/history', [RewardController::class, 'history']);
        Route::post('checkout', [PaymentController::class, 'checkoutOnline']);

        // Route::post('fare/quote', [FareRuleController::class, 'quote']);
        // Route::get('fleet/locations', [FleetLocationController::class, 'activeLocations']);
        // Route::get('fleet/nearest', [FleetLocationController::class, 'nearest']);
    });

// BUS OPERATOR
// Can: manage fleets, assign routes, set fares, schedule trips,
//      assign drivers and conductors, create driver/conductor accounts,
//      view drivers and conductors under them

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
        Route::get('fleets', [FleetController::class, 'index']);
        Route::get('routes', [RouteController::class, 'index']);
        Route::post('routes', [RouteController::class, 'store']);
        Route::get('routes/{routeId}', [RouteController::class, 'show']);
        Route::get('stops', [StopController::class, 'index']);
        Route::post('stops', [StopController::class, 'store']);
        Route::put('stops/{stopId}', [StopController::class, 'update']);
        Route::delete('stops/{stopId}', [StopController::class, 'destroy']);
        Route::post('routes/{routeId}/stops', [RouteController::class, 'addStop']);
        Route::delete('routes/{routeId}/stops/{routeStopId}', [RouteController::class, 'removeStop']);
        Route::get('fleet-routes', [FleetController::class, 'fleetRoutes']);
        Route::post('fleets', [FleetController::class, 'store']);
        Route::post('fleets/{fleetId}/routes', [FleetController::class, 'assignRoute']);

        // Fare rules
        Route::post('fare-rules', [FareRuleController::class, 'storeRule']);
        // Route::post('fare/recalculate/{fleetRouteId}', [FareRuleController::class, 'recalculate']);
    
        // Trip scheduling + staff assignment
        Route::get('trips', [TripController::class, 'operatorTrips']);
        Route::post('trips', [TripController::class, 'store']);
        Route::patch('trips/{tripId}/driver', [TripController::class, 'assignDriver']);
        Route::patch('trips/{tripId}/conductor', [TripController::class, 'assignConductor']);
        Route::patch('trips/{tripId}/boarding', [TripController::class, 'startBoarding']);
        Route::patch('trips/{tripId}/depart', [TripController::class, 'depart']);
        Route::patch('trips/{tripId}/complete', [TripController::class, 'complete']);

        // Fleet reports — financial audit, route adherence, occupancy trends
        Route::get('fleets/{fleetId}/reports/financial', [ReportingController::class, 'financialAudit']);
        Route::get('fleets/{fleetId}/reports/revenue-by-route', [ReportingController::class, 'revenueByRoute']);
        Route::get('fleets/{fleetId}/reports/route-adherence', [ReportingController::class, 'routeAdherence']);
        Route::get('fleets/{fleetId}/reports/occupancy-trends', [ReportingController::class, 'occupancyTrends']);
        Route::get('fleets/{fleetId}/reports/daily-summary', [ReportingController::class, 'dailySummary']);
        Route::get('fleets/{fleetId}/reports/payment-channels', [ReportingController::class, 'paymentChannels']);
    });

// DRIVER
// Can: view their assigned trips, update trip status (depart / complete)
// Cannot: create accounts, manage fleets, sell tickets

Route::prefix('driver')
    ->middleware(['auth:sanctum', 'role:driver'])
    ->group(function () {
        Route::delete('logout', [StaffAuthController::class, 'logout']);
        Route::get('profile', [StaffAuthController::class, 'profile']);

        Route::get('trips', [TripController::class, 'myTrips']);
        Route::get('trips/current', [TripController::class, 'currentTripDriver']);
        Route::patch('trips/{tripId}/complete', [TripController::class, 'complete']);

        Route::middleware('paired:driver')->group(function () {
            Route::get('trips/current/stops', [TripController::class, 'currentTripStops']);
            Route::get('trips/current/stops/{stopId}', [TripController::class, 'currentTripStopDetail']);
            Route::post('trips/current/stops/{stopId}/acknowledge', [TripController::class, 'acknowledgeStop']);
            Route::patch('trips/{tripId}/depart', [TripController::class, 'depart']);
            Route::post('location', [FleetLocationController::class, 'updateLocation']);
            Route::get('trips/current/earnings', [TicketController::class, 'currentTripEarnings']);
        });

        // Daily fleet PIN — view and verify
        Route::get('pin', [FleetDailyPinController::class, 'showForDriver']);
        Route::post('pin/verify', [FleetDailyPinController::class, 'verifyAsDriver']);

        // QR-based crew pairing (must complete before dashboard access)
        Route::get('pairing-token', [FleetPairingController::class, 'getDriverToken']);
        Route::post('pair', [FleetPairingController::class, 'pairAsDriver']);
        Route::get('pairing-status', [FleetPairingController::class, 'driverPairingStatus']);
    });

// CONDUCTOR
// Can: view current trip, scan/validate QR tickets, record onsite cash sales
// Cannot: create accounts, manage fleets, change trip status

Route::prefix('conductor')
    ->middleware(['auth:sanctum', 'role:conductor'])
    ->group(function () {
        Route::delete('logout', [StaffAuthController::class, 'logout']);
        Route::get('profile', [StaffAuthController::class, 'profile']);

        Route::get('trips', [TripController::class, 'myConductorTrips']);

        Route::middleware('paired:conductor')->group(function () {
            Route::get('trips/current', [TripController::class, 'currentTripConductor']);
            Route::post('tickets/scan', [TicketController::class, 'scan']);
            Route::post('tickets/scan-group', [TicketController::class, 'scanGroup']);
            Route::post('checkout', [PaymentController::class, 'checkoutOnsite']);

            // Occupancy monitoring dashboard
            Route::get('trips/current/occupancy', [TicketController::class, 'currentTripOccupancy']);
            Route::get('trips/current/occupancy/by-stop', [TicketController::class, 'occupancyByStop']);
            Route::get('trips/current/passengers', [TicketController::class, 'currentPassengers']);
            Route::get('trips/current/earnings', [TicketController::class, 'currentTripEarnings']);
            Route::post('tickets/{ticketId}/alight', [TicketController::class, 'recordAlighting']);
        });

        // Daily fleet PIN — view and verify
        Route::get('pin', [FleetDailyPinController::class, 'showForConductor']);
        Route::post('pin/verify', [FleetDailyPinController::class, 'verifyAsConductor']);

        // QR-based crew pairing (must complete before dashboard access)
        Route::get('pairing-token', [FleetPairingController::class, 'getConductorToken']);
        Route::post('pair', [FleetPairingController::class, 'pairAsConductor']);
        Route::get('pairing-status', [FleetPairingController::class, 'conductorPairingStatus']);
    });

// ADMIN (developers)
// Full access to everything — no restrictions

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
        Route::apiResource('fleets', FleetController::class);
        Route::post('fleets/{fleetId}/routes', [FleetController::class, 'assignRoute']);

        // Fare rules + recalculation
        Route::post('fare-rules', [FareRuleController::class, 'storeRule']);
        // Route::post('fare/recalculate/{fleetRouteId}', [FareRuleController::class, 'recalculate']);
    
        // Trips — full control
        Route::post('trips', [TripController::class, 'store']);
        Route::patch('trips/{tripId}/driver', [TripController::class, 'assignDriver']);
        Route::patch('trips/{tripId}/conductor', [TripController::class, 'assignConductor']);
        Route::patch('trips/{tripId}/boarding', [TripController::class, 'startBoarding']);
        Route::patch('trips/{tripId}/depart', [TripController::class, 'depart']);
        Route::patch('trips/{tripId}/complete', [TripController::class, 'complete']);

        // Fleet reports — financial audit, route adherence, occupancy trends
        Route::get('fleets/{fleetId}/reports/financial', [ReportingController::class, 'financialAudit']);
        Route::get('fleets/{fleetId}/reports/revenue-by-route', [ReportingController::class, 'revenueByRoute']);
        Route::get('fleets/{fleetId}/reports/route-adherence', [ReportingController::class, 'routeAdherence']);
        Route::get('fleets/{fleetId}/reports/occupancy-trends', [ReportingController::class, 'occupancyTrends']);
        Route::get('fleets/{fleetId}/reports/daily-summary', [ReportingController::class, 'dailySummary']);
        Route::get('fleets/{fleetId}/reports/payment-channels', [ReportingController::class, 'paymentChannels']);
    });
