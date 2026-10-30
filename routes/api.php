<?php

use App\Http\Controllers\AssignmentController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\BookingController;
use App\Http\Controllers\CalendarSummaryController;
use App\Http\Controllers\ConductorShiftController;
use App\Http\Controllers\CrewShiftLogController;
use App\Http\Controllers\DiscountTypeController;
use App\Http\Controllers\DriverShiftController;
use App\Http\Controllers\DropoffOptionsController;
use App\Http\Controllers\DropoffRulesController;
use App\Http\Controllers\FareRuleController;
use App\Http\Controllers\FinancialController;
use App\Http\Controllers\FleetController;
use App\Http\Controllers\FleetDailyPinController;
use App\Http\Controllers\FleetLocationController;
use App\Http\Controllers\FleetPairingController;
use App\Http\Controllers\PassengerController;
use App\Http\Controllers\PaymentController;
use App\Http\Controllers\PayMongoController;
use App\Http\Controllers\RegionController;
use App\Http\Controllers\RemittanceController;
use App\Http\Controllers\ReportingController;
use App\Http\Controllers\RewardController;
use App\Http\Controllers\RouteController;
use App\Http\Controllers\RouteDurationFactorController;
use App\Http\Controllers\ShiftBlockController;
use App\Http\Controllers\SignInController;
use App\Http\Controllers\StaffAuthController;
use App\Http\Controllers\StopController;
use App\Http\Controllers\TicketController;
use App\Http\Controllers\TicketResolutionController;
use App\Http\Controllers\TrackingController;
use App\Http\Controllers\TrafficController;
use App\Http\Controllers\TripAssignmentRequestController;
use App\Http\Controllers\TripController;
use App\Http\Controllers\TripNotificationController;
use App\Http\Controllers\TripTimelineController;
use App\Http\Controllers\UnfulfilledPaymentController;
use App\Http\Controllers\UserNotificationController;
use Illuminate\Support\Facades\Route;

// PUBLIC — no auth required
// Login endpoints use controller-level RateLimiter (failed-only counting).
// 2026-10-30: one sign-in address for passengers and staff (the body's `portal` picks the flow).
Route::post('auth/login', [SignInController::class, 'login']);
// 2026-10-28: a new email verification code (right after sign-up, the password is not at hand to sign in again).
Route::post('passengers/verify-email/resend', [AuthController::class, 'resendVerification'])->middleware('throttle:3,15,passengers-verify-email-resend');
Route::post('passengers/verify-otp', [AuthController::class, 'verifyOtp'])->middleware('throttle:10,15,passengers-verify-otp-post');
Route::post('passengers/register', [PassengerController::class, 'store'])->middleware('throttle:5,15,passenger-register');
Route::post('passengers/forgot-password', [AuthController::class, 'forgotPassword'])->middleware('throttle:3,15,pw-forgot');
Route::post('passengers/reset-password', [AuthController::class, 'resetPassword'])->middleware('throttle:6,15,pw-reset');
Route::post('staff/verify-otp', [StaffAuthController::class, 'verifyOtp'])->middleware('throttle:5,15,staff-verify-otp-post');
Route::post('staff/forgot-password', [StaffAuthController::class, 'forgotPassword'])->middleware('throttle:3,15,pw-forgot');
Route::post('staff/reset-password', [StaffAuthController::class, 'resetPassword'])->middleware('throttle:6,15,pw-reset');
Route::delete('staff/logout', [StaffAuthController::class, 'logout'])
    ->middleware(['auth:sanctum', 'role:driver,conductor,operator,admin']);
Route::patch('staff/2fa-preference', [StaffAuthController::class, 'updateTwoFactorPreference'])
    ->middleware(['auth:sanctum', 'role:driver,conductor,operator,admin']);

// Batch 15, Items 2/3: FCM device-token registration — shared across all
// staff roles (Driver receives trip-assignment pushes, Operator receives
// payment-confirmation pushes; Conductor/Admin registration is harmless
// and future-proofs other notification types).
Route::post('staff/fcm-token', [StaffAuthController::class, 'registerFcmToken'])
    ->middleware(['auth:sanctum', 'role:driver,conductor,operator,admin']);

// Batch 24 (C8): dashboard calendar quick summary. One endpoint, scoped to the
// caller's own role and staff id (driver, Chauffeur or operator).
Route::get('staff/calendar-summary', [CalendarSummaryController::class, 'show'])
    ->middleware(['auth:sanctum', 'role:driver,conductor,operator']);
// Batch 26: the calendar widget's day markers and the list of one day, same scoping.
Route::get('staff/calendar/days', [CalendarSummaryController::class, 'days'])
    ->middleware(['auth:sanctum', 'role:driver,conductor,operator', 'throttle:120,1,staff-calendar-days']);
Route::get('staff/calendar/day', [CalendarSummaryController::class, 'day'])
    ->middleware(['auth:sanctum', 'role:driver,conductor,operator', 'throttle:120,1,staff-calendar-day']);

// Step-up re-auth (requires an active Sanctum session — any staff role)
Route::post('step-up/initiate', [StaffAuthController::class, 'stepUpInitiate'])
    ->middleware(['auth:sanctum', 'role:driver,conductor,operator,admin', 'throttle:3,5,step-up-initiate-post']);
Route::post('step-up/verify', [StaffAuthController::class, 'stepUpVerify'])
    ->middleware(['auth:sanctum', 'role:driver,conductor,operator,admin', 'throttle:5,15,step-up-verify-post']);
Route::post('step-up/verify-password', [StaffAuthController::class, 'stepUpVerifyPassword'])
    ->middleware(['auth:sanctum', 'role:driver,conductor,operator,admin', 'throttle:10,1,step-up-verify-password-post']);

Route::get('fare/options', [FareRuleController::class, 'options']);
Route::post('fare/quote', [FareRuleController::class, 'quote']);
Route::post('fare/quote-fleets-by-location', [FareRuleController::class, 'quoteFleetsByLocation']);
// Batch 26: public, so throttled. The passenger map polls every 12 s (5 a minute per open tab).
Route::get('fleet/locations', [FleetLocationController::class, 'activeLocations'])->middleware(['auth.optional', 'throttle:90,1,fleet-locations']);
Route::get('fleet/nearest', [FleetLocationController::class, 'nearest'])->middleware(['auth.optional', 'throttle:90,1,fleet-nearest']);
// Live traffic for one segment (the next stop), asked through the server so the provider key stays secret and
// answers are shared and counted. Own throttle bucket (the third value) so it never shares a counter with other routes.
Route::get('traffic/segment', [TrafficController::class, 'segment'])->middleware(['auth.optional', 'throttle:30,1,traffic']);
// Road-congestion map tiles for the driver map only. Signed-in drivers only, own throttle bucket (one map view loads
// a few dozen tiles, and panning loads more).
Route::get('traffic/flow/{z}/{x}/{y}.png', [TrafficController::class, 'flowTile'])
    ->whereNumber(['z', 'x', 'y'])
    ->middleware(['auth:sanctum', 'role:driver', 'throttle:600,1,traffic-tiles']);
// Batch 26: follow the bus of one ticket. The 43-character token is the only credential.
Route::get('track/{token}', [TrackingController::class, 'show'])
    ->where('token', '[A-Za-z0-9_-]{43}')
    ->middleware('throttle:60,1,track');
Route::get('/routes/{routeId}/fares', [FareRuleController::class, 'listFares']);
Route::get('/region', [RegionController::class, 'show'])->middleware('throttle:60,1,region');
Route::get('/routes/{routeId}/stops', [RouteController::class, 'publicStops']);
Route::get('/routes/{routeId}/geometry', [RouteController::class, 'geometry'])->middleware('throttle:60,1,routes-geometry');
Route::get('trips', [TripController::class, 'availableTrips']);
Route::get('trips/{tripId}/timeline', [TripTimelineController::class, 'show'])->middleware('throttle:60,1,trips-timeline');
// Batch 28 Stage 3, item 10: restricted areas and designated drop-off points for a trip (feedback only; the server re-checks).
Route::get('trips/{tripId}/dropoff-options', [DropoffOptionsController::class, 'show'])->middleware('throttle:60,1,trips-dropoff-options');
Route::get('booking/stops', [BookingController::class, 'stops'])->middleware('throttle:60,1,booking-stops');
Route::get('booking/window', [BookingController::class, 'window'])->middleware('throttle:60,1,booking-window');
Route::get('booking/link', [BookingController::class, 'link'])->middleware('throttle:60,1,booking-link');
Route::get('booking/departures', [BookingController::class, 'departures'])->middleware('throttle:60,1,booking-departures');
Route::get('booking/trips', [BookingController::class, 'trips'])->middleware('throttle:60,1,booking-trips');
Route::get('booking/resolve', [BookingController::class, 'resolve'])->middleware('throttle:60,1,booking-resolve');
Route::get('booking/address-search', [BookingController::class, 'addressSearch'])->middleware('throttle:20,1,booking-address-search');

// Remove:

// PAYMONGO / PAYMENT

Route::post('checkout', [PaymentController::class, 'checkoutOnline'])
    ->middleware(['auth.optional', 'throttle:30,1,checkout']);
Route::get('tickets/lookup', [TicketController::class, 'guestLookup'])->middleware('throttle:10,15,tickets-lookup');
Route::post('webhooks/paymongo', [PayMongoController::class, 'handle']);
Route::get('payments/status', [PaymentController::class, 'onlineStatus'])->middleware('throttle:payment-status'); // 30 a minute per payment reference and IP (AppServiceProvider)

// PASSENGER
// Can: view own profile, get fare quotes, buy tickets, view own tickets

Route::prefix('passengers')
    ->middleware(['auth:sanctum', 'role:passenger'])
    ->group(function () {
        Route::delete('logout', [AuthController::class, 'logout']);
        Route::get('profile', [AuthController::class, 'profile']);
        // 2026-10-29 (Batch 36 Phase 2): the passenger edits their own name, phone and gender (PATCH; PUT kept for older clients).
        Route::match(['put', 'patch'], 'profile', [PassengerController::class, 'update'])->middleware('throttle:10,1,passengers-profile-update');
        Route::patch('two-factor', [AuthController::class, 'updateTwoFactor']);
        Route::post('fcm-token', [AuthController::class, 'registerFcmToken']);
        Route::get('tickets', [TicketController::class, 'myTickets']);
        Route::get('tickets/{ticketUuid}/qr', [TicketController::class, 'getTicketQR']);
        Route::post('tickets/{ticketUuid}/tracking-link', [TicketController::class, 'renewTrackingLink'])->middleware('throttle:10,1,tracking-renew');
        // Batch 28 item 4: no passenger-initiated cancel or refund after payment. These routes exist so the answer is
        // an explicit, audited 422 for every ticket (one-way or either leg of a roundtrip), never a silent 404.
        Route::post('tickets/{ticketUuid}/cancel', [TicketController::class, 'refuseCancellation'])->middleware('throttle:20,1,passengers-tickets-cancel-post');
        Route::post('tickets/{ticketUuid}/refund', [TicketController::class, 'refuseCancellation'])->middleware('throttle:20,1,passengers-tickets-refund-post');
        // Batch 28 Stage 3, item 6: the ONE exception. A ticket of a trip the OPERATOR cancelled (status trip_cancelled) can be
        // rebooked onto a later departure or refunded. The server works out every amount; the passenger only chooses.
        Route::get('tickets/{ticketUuid}/resolution', [TicketResolutionController::class, 'show'])->middleware('throttle:60,1,passengers-tickets-resolution');
        Route::post('tickets/{ticketUuid}/resolution', [TicketResolutionController::class, 'choose'])->middleware('throttle:20,1,passengers-tickets-resolution-post');
        Route::get('dashboard-summary', [PassengerController::class, 'summary']);
        Route::get('payments', [PaymentController::class, 'passengerHistory']);
        Route::get('rewards/history', [RewardController::class, 'history']);
        Route::post('checkout', [PaymentController::class, 'checkoutOnline'])->middleware('throttle:30,1,checkout');

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
        Route::patch('2fa-preference', [StaffAuthController::class, 'updateTwoFactorPreference']);

        // Account management — operator can only create driver/conductor accounts
        Route::post('accounts', [StaffAuthController::class, 'createAccount']);
        Route::get('drivers', [StaffAuthController::class, 'listDrivers']);
        Route::get('conductors', [StaffAuthController::class, 'listConductors']);
        // §4.8: the crew's time in / time out (Start Shift / End Shift) on this Operator's buses, read only.
        Route::get('crew-shifts', [CrewShiftLogController::class, 'index'])->middleware('throttle:60,1,mgmt-crew-shifts');
        // 2026-10-27: onsite discount types (Student, Senior citizen, PWD) and this Operator's on/off choice.
        Route::get('discount-types', [DiscountTypeController::class, 'operatorIndex']);
        Route::patch('discount-types/{key}/active', [DiscountTypeController::class, 'setActive'])->where('key', '[a-z_]+')->middleware('throttle:30,1,mgmt-discount-types-active-patch');

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
        Route::patch('routes/{routeId}/stops/{routeStopId}/type', [RouteController::class, 'updateStopType']);
        Route::patch('routes/{routeId}/stops/{routeStopId}/express', [RouteController::class, 'updateStopExpress']);
        Route::post('routes/{routeId}/geometry/regenerate', [RouteController::class, 'regenerateGeometry'])->middleware('throttle:3,1,mgmt-routes-geometry-regenerate-post');
        Route::patch('routes/{routeId}/custom-dropoff', [RouteController::class, 'updateCustomDropoff']);
        Route::get('fleet-routes', [FleetController::class, 'fleetRoutes']);
        Route::post('fleets', [FleetController::class, 'store']);
        Route::post('fleets/{fleetId}/routes', [FleetController::class, 'assignRoute']);

        // Fare rules
        Route::get('fare-rules', [FareRuleController::class, 'listRules']);
        Route::post('fare-rules', [FareRuleController::class, 'storeRule'])->middleware('step-up');        // Route::post('fare/recalculate/{fleetRouteId}', [FareRuleController::class, 'recalculate']);

        // Trip scheduling + staff assignment
        Route::get('trips', [TripController::class, 'operatorTrips']);
        Route::post('trips', [TripController::class, 'store']);
        Route::patch('trips/{tripId}/fleet', [TripController::class, 'changeFleet']);
        Route::patch('trips/{tripId}/driver', [TripController::class, 'assignDriver']);
        Route::patch('trips/{tripId}/conductor', [TripController::class, 'assignConductor']);
        Route::patch('trips/{tripId}/dispatch-decision', [TripController::class, 'saveDispatchDecision']);
        Route::patch('trips/{tripId}/boarding', [TripController::class, 'startBoarding']);
        Route::patch('trips/{tripId}/depart', [TripController::class, 'depart']);
        Route::patch('trips/{tripId}/complete', [TripController::class, 'complete']);
        Route::patch('trips/{tripId}/status', [TripController::class, 'overrideStatus']);
        Route::patch('trips/{tripId}/cancel', [TripController::class, 'cancel'])->middleware('throttle:20,1,mgmt-trips-cancel-patch');
        // Batch 28 Stage 3, item 6: staff-assisted rebook/refund (acts for the passenger, recorded with the actor).
        Route::get('ticket-resolutions', [TicketResolutionController::class, 'queue']);
        Route::get('tickets/{ticketUuid}/resolution', [TicketResolutionController::class, 'staffShow'])->middleware('throttle:60,1,mgmt-tickets-resolution');
        Route::post('tickets/{ticketUuid}/resolution', [TicketResolutionController::class, 'staffChoose'])->middleware('throttle:30,1,mgmt-tickets-resolution-post');
        Route::post('ticket-resolutions/{resolutionId}/cash', [TicketResolutionController::class, 'recordCash'])->middleware('throttle:30,1,mgmt-ticket-resolutions-cash-post');
        Route::post('ticket-resolutions/{resolutionId}/review', [TicketResolutionController::class, 'review'])->middleware('throttle:30,1,mgmt-ticket-resolutions-review-post');
        // Batch 28 Stage 3, item 7: peak-hour duration windows ("configured", never measured) and the read-only report.
        Route::get('duration-factors', [RouteDurationFactorController::class, 'index']);
        Route::post('duration-factors', [RouteDurationFactorController::class, 'store'])->middleware('throttle:30,1,mgmt-duration-factors-post');
        Route::put('duration-factors/{id}', [RouteDurationFactorController::class, 'update'])->middleware('throttle:30,1,mgmt-duration-factors-put');
        Route::delete('duration-factors/{id}', [RouteDurationFactorController::class, 'destroy'])->middleware('throttle:30,1,mgmt-duration-factors-delete');
        Route::get('duration-report', [RouteDurationFactorController::class, 'report'])->middleware('throttle:20,1,mgmt-duration-report');
        // Batch 28 Stage 3, item 10: designated alighting points and restricted areas for custom drop-off.
        Route::get('dropoff/points', [DropoffRulesController::class, 'points']);
        Route::post('dropoff/points', [DropoffRulesController::class, 'storePoint'])->middleware('throttle:30,1,mgmt-dropoff-points-post');
        Route::put('dropoff/points/{id}', [DropoffRulesController::class, 'updatePoint'])->middleware('throttle:30,1,mgmt-dropoff-points-put');
        Route::get('dropoff/restrictions', [DropoffRulesController::class, 'restrictions']);
        Route::post('dropoff/restrictions', [DropoffRulesController::class, 'storeRestriction'])->middleware('throttle:30,1,mgmt-dropoff-restrictions-post');
        Route::put('dropoff/restrictions/{id}', [DropoffRulesController::class, 'updateRestriction'])->middleware('throttle:30,1,mgmt-dropoff-restrictions-put');
        Route::post('dropoff/restrictions/{id}/review', [DropoffRulesController::class, 'reviewRestriction'])->middleware('throttle:30,1,mgmt-dropoff-restrictions-review-post');
        // Batch 24 (C6): driver/Chauffeur requests to be released ("For Approval")
        Route::get('trip-requests', [TripAssignmentRequestController::class, 'index']);
        Route::get('assignments', [AssignmentController::class, 'operatorBlocks']);
        Route::patch('trip-requests/{requestId}/approve', [TripAssignmentRequestController::class, 'approve'])->middleware('throttle:30,1,mgmt-trip-requests-approve-patch');
        Route::patch('trip-requests/{requestId}/reject', [TripAssignmentRequestController::class, 'reject'])->middleware('throttle:30,1,mgmt-trip-requests-reject-patch');

        // Batch 18: Shift Block Hand-off System
        Route::get('shift-blocks', [ShiftBlockController::class, 'index']);
        Route::post('shift-blocks', [ShiftBlockController::class, 'store']);
        // Phase B (§4.13): the same block on every day of a From..To range; preview first, then create.
        Route::post('shift-blocks/range/preview', [ShiftBlockController::class, 'previewRange'])->middleware('throttle:30,1,mgmt-shift-blocks-range-preview-post');
        Route::post('shift-blocks/range', [ShiftBlockController::class, 'storeRange'])->middleware('throttle:10,1,mgmt-shift-blocks-range-post');
        // Batch 28 Stage 3, item 7: suggested leg times (previous leg's expected end + the turnaround buffer); the operator may override.
        Route::get('shift-blocks/plan-legs', [RouteDurationFactorController::class, 'planLegs'])->middleware('throttle:30,1,mgmt-shift-blocks-plan-legs');
        Route::get('shift-blocks/pending-review', [ShiftBlockController::class, 'pendingReview']);
        Route::get('shift-blocks/{shiftBlockId}', [ShiftBlockController::class, 'show']);
        Route::patch('shift-blocks/{shiftBlockId}', [ShiftBlockController::class, 'update']);
        Route::patch('shift-blocks/{shiftBlockId}/cancel', [ShiftBlockController::class, 'cancel']);
        // 2026-10-27 (§4.2): move one leg that has not started to a new time (checked like a new block; crew told).
        Route::patch('shift-blocks/{shiftBlockId}/legs/{tripId}', [ShiftBlockController::class, 'rescheduleLeg'])->whereNumber(['shiftBlockId', 'tripId'])->middleware('throttle:30,1,mgmt-shift-blocks-legs-patch');
        Route::patch('shift-blocks/{shiftBlockId}/mark-reviewed', [ShiftBlockController::class, 'markReviewed']);
        Route::post('shift-blocks/{shiftBlockId}/override-handoff', [ShiftBlockController::class, 'overrideHandoff']);
        Route::post('shift-blocks/{shiftBlockId}/override-takeover', [ShiftBlockController::class, 'overrideTakeover']);

        // Fleet reports — financial audit, route adherence, occupancy trends
        Route::get('fleets/{fleetId}/reports/financial', [ReportingController::class, 'financialAudit']);
        Route::get('fleets/{fleetId}/reports/revenue-by-route', [ReportingController::class, 'revenueByRoute']);
        Route::get('fleets/{fleetId}/reports/route-adherence', [ReportingController::class, 'routeAdherence']);
        Route::get('fleets/{fleetId}/reports/occupancy-trends', [ReportingController::class, 'occupancyTrends']);
        Route::get('fleets/{fleetId}/reports/daily-summary', [ReportingController::class, 'dailySummary']);
        Route::get('fleets/{fleetId}/reports/payment-channels', [ReportingController::class, 'paymentChannels']);

        // Batch 29, Stage 2: the financial page (paid tickets only, refunds separate, Manila days). The Operator is scoped to
        // their own fleets inside the query; an Admin sees all. Rate limited; the export is the strictest.
        Route::get('financials', [FinancialController::class, 'summary'])->middleware('throttle:30,1,fin-summary');
        Route::get('financials/transactions', [FinancialController::class, 'transactions'])->middleware('throttle:60,1,fin-log');
        Route::get('financials/export', [FinancialController::class, 'export'])->middleware('throttle:10,1,fin-export');

        // Batch 29, cash step 3: the remittance batch of each ended Conductor shift; the Operator confirms it. Operator only.
        Route::get('remittances', [RemittanceController::class, 'index'])->middleware('throttle:60,1,remit-list');
        Route::post('remittances/{id}/confirm', [RemittanceController::class, 'confirm'])->where('id', '[0-9]+')->middleware('throttle:30,1,remit-confirm');
        Route::post('remittances/{id}/resolve', [RemittanceController::class, 'resolve'])->where('id', '[0-9]+')->middleware('throttle:30,1,remit-resolve');

        // Online payments that took money but could not become tickets (a payment that arrived after its seats were released
        // and could not be honoured), and the Operator's record that they refunded them. Own throttle buckets.
        Route::get('payments/needs-refund', [UnfulfilledPaymentController::class, 'index'])->middleware('throttle:60,1,refund-list');
        Route::post('payments/{id}/refunded', [UnfulfilledPaymentController::class, 'recordRefund'])->where('id', '[0-9]+')->middleware('throttle:30,1,refund-record');
        Route::post('payments/{id}/checkout-reconciled-not-paid', [UnfulfilledPaymentController::class, 'reconcileCheckoutNotPaid'])->where('id', '[0-9]+')->middleware('throttle:30,1,checkout-reconcile');

        // GPS history for a trip (route adherence + replay)
        Route::get('trips/{tripId}/gps-history', [FleetLocationController::class, 'tripHistory']);

        // S2 (Batch 12): Operator-facing notification feed (currently: trip declines)
        Route::get('notifications', [TripNotificationController::class, 'index']);
        Route::patch('notifications/{id}/read', [TripNotificationController::class, 'markRead']);
        Route::patch('notifications/read-all', [TripNotificationController::class, 'markAllRead']);
        // 2026-10-27: one notification group in the bell, marked read at once.
        Route::patch('notifications/read-many', [TripNotificationController::class, 'markManyRead'])->middleware('throttle:60,1,notifications-read-many');
    });

// DRIVER
// Can: view their assigned trips, update trip status (depart / complete)
// Cannot: create accounts, manage fleets, sell tickets

Route::prefix('driver')
    ->middleware(['auth:sanctum', 'role:driver'])
    ->group(function () {
        Route::delete('logout', [StaffAuthController::class, 'logout']);
        Route::get('profile', [StaffAuthController::class, 'profile']);
        Route::patch('2fa-preference', [StaffAuthController::class, 'updateTwoFactorPreference']);

        Route::get('trips', [TripController::class, 'myTrips']);
        Route::get('shift/status', [DriverShiftController::class, 'status']);
        Route::post('shift/start', [DriverShiftController::class, 'start']);
        Route::post('shift/end', [DriverShiftController::class, 'end']);

        Route::patch('trips/{tripId}/complete', [TripController::class, 'complete']);
        Route::patch('trips/{tripId}/boarding', [TripController::class, 'startBoarding']);
        Route::patch('trips/{tripId}/depart', [TripController::class, 'depart']);
        Route::patch('trips/{tripId}/decline', [TripController::class, 'declineAsDriver']);
        Route::patch('trips/{tripId}/accept', [TripController::class, 'acceptAsDriver']);
        // Batch 28: a shift block (or a standalone trip) is one card; accept / decline once, on the block.
        Route::get('assignments', [AssignmentController::class, 'indexAsDriver']);
        Route::patch('assignments/{key}/accept', [AssignmentController::class, 'acceptAsDriver'])->where('key', '(block|trip)-[0-9]+');
        Route::patch('assignments/{key}/decline', [AssignmentController::class, 'declineAsDriver'])->where('key', '(block|trip)-[0-9]+');
        Route::patch('availability', [StaffAuthController::class, 'updateAvailability']);

        // Batch 18: Shift Block Hand-off System
        Route::get('shift-blocks', [ShiftBlockController::class, 'myBlocksAsDriver']);
        Route::post('shift-blocks/{shiftBlockId}/confirm-takeover', [ShiftBlockController::class, 'confirmTakeover']);

        // S1 (Batch 17): in-app notification bell
        Route::get('notifications', [UserNotificationController::class, 'index']);
        Route::patch('notifications/{id}/read', [UserNotificationController::class, 'markRead']);
        Route::patch('notifications/read-all', [UserNotificationController::class, 'markAllRead']);
        // 2026-10-27: one notification group in the bell, marked read at once.
        Route::patch('notifications/read-many', [UserNotificationController::class, 'markManyRead'])->middleware('throttle:60,1,notifications-read-many');

        Route::middleware('paired:driver')->group(function () {
            Route::get('trips/current', [TripController::class, 'currentTripDriver']);
            Route::get('trips/current/stops', [TripController::class, 'currentTripStops']);
            Route::get('trips/current/stops/{stopId}', [TripController::class, 'currentTripStopDetail']);
            Route::post('trips/current/stops/{stopId}/acknowledge', [TripController::class, 'acknowledgeStop']);
            Route::post('location', [FleetLocationController::class, 'updateLocation']);
            Route::get('trips/current/earnings', [TicketController::class, 'currentTripEarnings']);
        });

        // Daily fleet PIN — view and verify
        Route::get('pin', [FleetDailyPinController::class, 'showForDriver']);
        // A 6-digit PIN has a million values: wrong guesses are limited per signed-in account (own counter), so the
        // partner's code cannot be found by trying them all.
        Route::post('pin/verify', [FleetDailyPinController::class, 'verifyAsDriver'])->middleware('throttle:12,5,crew-pairing');

        // QR-based crew pairing (must complete before dashboard access)
        Route::get('pairing-token', [FleetPairingController::class, 'getDriverToken']);
        Route::post('pair', [FleetPairingController::class, 'pairAsDriver'])->middleware('throttle:12,5,crew-pairing');
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
        Route::patch('2fa-preference', [StaffAuthController::class, 'updateTwoFactorPreference']);

        Route::get('trips', [TripController::class, 'myConductorTrips']);
        Route::get('shift/status', [ConductorShiftController::class, 'status']);
        Route::post('shift/start', [ConductorShiftController::class, 'start']);
        Route::post('shift/end', [ConductorShiftController::class, 'end']);
        Route::patch('trips/{tripId}/decline', [TripController::class, 'declineAsConductor']);
        Route::patch('trips/{tripId}/accept', [TripController::class, 'acceptAsConductor']);
        Route::get('assignments', [AssignmentController::class, 'indexAsConductor']);
        Route::patch('assignments/{key}/accept', [AssignmentController::class, 'acceptAsConductor'])->where('key', '(block|trip)-[0-9]+');
        Route::patch('assignments/{key}/decline', [AssignmentController::class, 'declineAsConductor'])->where('key', '(block|trip)-[0-9]+');
        Route::patch('availability', [StaffAuthController::class, 'updateAvailability']);
        // 2026-10-27: the onsite discounts on for this Chauffeur's bus.
        Route::get('discount-types', [DiscountTypeController::class, 'conductorIndex']);

        // Batch 18: Shift Block Hand-off System
        Route::get('shift-blocks', [ShiftBlockController::class, 'myBlocksAsConductor']);
        Route::get('shift-blocks/{shiftBlockId}/handoff-eligibility', [ShiftBlockController::class, 'handoffEligibility']);
        Route::post('shift-blocks/{shiftBlockId}/initiate-handoff', [ShiftBlockController::class, 'initiateHandoff']);

        // S1 (Batch 17): in-app notification bell
        Route::get('notifications', [UserNotificationController::class, 'index']);
        Route::patch('notifications/{id}/read', [UserNotificationController::class, 'markRead']);
        Route::patch('notifications/read-all', [UserNotificationController::class, 'markAllRead']);
        // 2026-10-27: one notification group in the bell, marked read at once.
        Route::patch('notifications/read-many', [UserNotificationController::class, 'markManyRead'])->middleware('throttle:60,1,notifications-read-many');

        Route::middleware('paired:conductor')->group(function () {
            Route::get('trips/current', [TripController::class, 'currentTripConductor']);
            Route::post('tickets/scan', [TicketController::class, 'scan'])->middleware('throttle:30,1,conductor-tickets-scan-post');
            Route::post('tickets/scan-group', [TicketController::class, 'scanGroup'])->middleware('throttle:20,1,conductor-tickets-scan-group-post');
            Route::post('checkout', [PaymentController::class, 'checkoutOnsite'])->middleware('throttle:20,1,conductor-checkout-post');
            Route::post('checkout/quote', [PaymentController::class, 'quoteOnsite'])->middleware('throttle:120,1,conductor-checkout-quote-post');

            // Occupancy monitoring dashboard
            Route::get('trips/current/occupancy', [TicketController::class, 'currentTripOccupancy']);
            Route::get('trips/current/occupancy/by-stop', [TicketController::class, 'occupancyByStop']);
            Route::get('trips/current/passengers', [TicketController::class, 'currentPassengers']);
            Route::get('trips/current/earnings', [TicketController::class, 'currentTripEarnings']);
            Route::get('trips/current/boarding-point', [TicketController::class, 'boardingPoint'])->middleware('throttle:30,1,conductor-trips-current-boarding-point');
            // 2026-10-26: Passenger In (check-in snapped back to the last stop passed) and the check-ins waiting for their fare.
            Route::post('trips/current/check-ins', [TicketController::class, 'checkIn'])->middleware('throttle:60,1,conductor-trips-current-check-ins-post');
            Route::get('trips/current/check-ins', [TicketController::class, 'checkIns'])->middleware('throttle:120,1,conductor-trips-current-check-ins');
            Route::post('tickets/{ticketId}/alight', [TicketController::class, 'recordAlighting']);
            // 2026-10-27 (§7): a destination group gets off in one action (the single-ticket drop-off above stays as the exception path).
            Route::post('trips/current/drop-off-groups/confirm', [TicketController::class, 'confirmDropOffGroup'])->middleware('throttle:60,1,conductor-trips-current-drop-off-groups-confirm-post');
        });

        // Daily fleet PIN — view and verify
        Route::get('pin', [FleetDailyPinController::class, 'showForConductor']);
        Route::post('pin/verify', [FleetDailyPinController::class, 'verifyAsConductor'])->middleware('throttle:12,5,crew-pairing');

        // QR-based crew pairing (must complete before dashboard access)
        Route::get('pairing-token', [FleetPairingController::class, 'getConductorToken']);
        Route::post('pair', [FleetPairingController::class, 'pairAsConductor'])->middleware('throttle:12,5,crew-pairing');
        Route::get('pairing-status', [FleetPairingController::class, 'conductorPairingStatus']);
    });

// ADMIN (developers)
// Full access to everything — no restrictions

Route::prefix('admin')
    ->middleware(['auth:sanctum', 'role:admin'])
    ->group(function () {
        Route::delete('logout', [StaffAuthController::class, 'logout']);
        Route::get('profile', [StaffAuthController::class, 'profile']);
        Route::patch('2fa-preference', [StaffAuthController::class, 'updateTwoFactorPreference']);

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
        Route::patch('routes/{routeId}/stops/{routeStopId}/type', [RouteController::class, 'updateStopType']);
        Route::patch('routes/{routeId}/stops/{routeStopId}/express', [RouteController::class, 'updateStopExpress']);
        Route::post('routes/{routeId}/geometry/regenerate', [RouteController::class, 'regenerateGeometry'])->middleware('throttle:3,1,mgmt-routes-geometry-regenerate-post');
        Route::patch('routes/{routeId}/custom-dropoff', [RouteController::class, 'updateCustomDropoff']);

        // Fleets
        Route::apiResource('fleets', FleetController::class);
        Route::post('fleets/{fleetId}/routes', [FleetController::class, 'assignRoute']);

        // Fare rules + recalculation (step-up required — irreversible pricing change)
        Route::get('fare-rules', [FareRuleController::class, 'listRules']);
        Route::post('fare-rules', [FareRuleController::class, 'storeRule'])->middleware('step-up');
        // Route::post('fare/recalculate/{fleetRouteId}', [FareRuleController::class, 'recalculate']);

        // Trips — full control
        Route::post('trips', [TripController::class, 'store']);
        Route::patch('trips/{tripId}/fleet', [TripController::class, 'changeFleet']);
        Route::patch('trips/{tripId}/driver', [TripController::class, 'assignDriver']);
        Route::patch('trips/{tripId}/conductor', [TripController::class, 'assignConductor']);
        Route::patch('trips/{tripId}/dispatch-decision', [TripController::class, 'saveDispatchDecision']);
        Route::patch('trips/{tripId}/boarding', [TripController::class, 'startBoarding']);
        Route::patch('trips/{tripId}/depart', [TripController::class, 'depart']);
        Route::patch('trips/{tripId}/complete', [TripController::class, 'complete']);
        Route::patch('trips/{tripId}/status', [TripController::class, 'overrideStatus']);
        Route::patch('trips/{tripId}/cancel', [TripController::class, 'cancel'])->middleware('throttle:20,1,mgmt-trips-cancel-patch');
        // Batch 28 Stage 3, item 6: staff-assisted rebook/refund (acts for the passenger, recorded with the actor).
        Route::get('ticket-resolutions', [TicketResolutionController::class, 'queue']);
        Route::get('tickets/{ticketUuid}/resolution', [TicketResolutionController::class, 'staffShow'])->middleware('throttle:60,1,mgmt-tickets-resolution');
        Route::post('tickets/{ticketUuid}/resolution', [TicketResolutionController::class, 'staffChoose'])->middleware('throttle:30,1,mgmt-tickets-resolution-post');
        Route::post('ticket-resolutions/{resolutionId}/cash', [TicketResolutionController::class, 'recordCash'])->middleware('throttle:30,1,mgmt-ticket-resolutions-cash-post');
        Route::post('ticket-resolutions/{resolutionId}/review', [TicketResolutionController::class, 'review'])->middleware('throttle:30,1,mgmt-ticket-resolutions-review-post');
        // Batch 28 Stage 3, item 7: peak-hour duration windows ("configured", never measured) and the read-only report.
        Route::get('duration-factors', [RouteDurationFactorController::class, 'index']);
        Route::post('duration-factors', [RouteDurationFactorController::class, 'store'])->middleware('throttle:30,1,mgmt-duration-factors-post');
        Route::put('duration-factors/{id}', [RouteDurationFactorController::class, 'update'])->middleware('throttle:30,1,mgmt-duration-factors-put');
        Route::delete('duration-factors/{id}', [RouteDurationFactorController::class, 'destroy'])->middleware('throttle:30,1,mgmt-duration-factors-delete');
        Route::get('duration-report', [RouteDurationFactorController::class, 'report'])->middleware('throttle:20,1,mgmt-duration-report');
        // Batch 28 Stage 3, item 10: designated alighting points and restricted areas for custom drop-off.
        Route::get('dropoff/points', [DropoffRulesController::class, 'points']);
        Route::post('dropoff/points', [DropoffRulesController::class, 'storePoint'])->middleware('throttle:30,1,mgmt-dropoff-points-post');
        Route::put('dropoff/points/{id}', [DropoffRulesController::class, 'updatePoint'])->middleware('throttle:30,1,mgmt-dropoff-points-put');
        Route::get('dropoff/restrictions', [DropoffRulesController::class, 'restrictions']);
        Route::post('dropoff/restrictions', [DropoffRulesController::class, 'storeRestriction'])->middleware('throttle:30,1,mgmt-dropoff-restrictions-post');
        Route::put('dropoff/restrictions/{id}', [DropoffRulesController::class, 'updateRestriction'])->middleware('throttle:30,1,mgmt-dropoff-restrictions-put');
        Route::post('dropoff/restrictions/{id}/review', [DropoffRulesController::class, 'reviewRestriction'])->middleware('throttle:30,1,mgmt-dropoff-restrictions-review-post');
        // Fleet reports — financial audit, route adherence, occupancy trends
        Route::get('fleets/{fleetId}/reports/financial', [ReportingController::class, 'financialAudit']);
        Route::get('fleets/{fleetId}/reports/revenue-by-route', [ReportingController::class, 'revenueByRoute']);
        Route::get('fleets/{fleetId}/reports/route-adherence', [ReportingController::class, 'routeAdherence']);
        Route::get('fleets/{fleetId}/reports/occupancy-trends', [ReportingController::class, 'occupancyTrends']);
        Route::get('fleets/{fleetId}/reports/daily-summary', [ReportingController::class, 'dailySummary']);
        Route::get('fleets/{fleetId}/reports/payment-channels', [ReportingController::class, 'paymentChannels']);

        // Batch 29, Stage 2: the financial page (paid tickets only, refunds separate, Manila days). The Operator is scoped to
        // their own fleets inside the query; an Admin sees all. Rate limited; the export is the strictest.
        Route::get('financials', [FinancialController::class, 'summary'])->middleware('throttle:30,1,fin-summary');
        Route::get('financials/transactions', [FinancialController::class, 'transactions'])->middleware('throttle:60,1,fin-log');
        Route::get('financials/export', [FinancialController::class, 'export'])->middleware('throttle:10,1,fin-export');

        // Audit log viewer — admin only
        Route::get('audit-logs', [ReportingController::class, 'auditLogs']);

        // Stored scheduled reports (nightly generated)
        Route::get('reports/stored', [ReportingController::class, 'storedReports']);
    });
