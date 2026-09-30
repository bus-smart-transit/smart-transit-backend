<?php

use App\Models\FareRule;
use App\Models\Fleet;
use App\Models\FleetRoute;
use App\Models\Route;
use App\Models\RouteStop;
use App\Models\StaffUser;
use App\Models\Stop;
use App\Models\Trip;
use App\Models\User;
use App\Services\FareRuleService;
use App\Services\TicketService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

// Batch 15, Item 10: verification task (not a reported bug) — confirm the
// fare shown to the passenger at booking/browse time matches what actually
// gets charged/recorded at checkout time, for a non-trivial fare rule
// (non-zero base fare AND non-zero per-km rate, non-round distance).
//
// Traced end-to-end via code reading first:
//   - Both paths share ONE formula: FareCalculationService::computeFare()
//     (round(baseFare + distanceKm * farePerKm, 2)) — there is exactly one
//     copy of this formula in the codebase, confirmed by its own doc comment.
//   - Both paths use the same fare-rule lookup priority: FareRuleRepository
//     ::getActiveRule() (fleet+seat_type exact match) with a fallback to
//     ::getActiveRuleForRoute().
//   - Stop-based mode: browse-time FareRuleService::getQuote() and
//     checkout-time TicketService::priceByStops() both compute distance as
//     abs(destinationRouteStop->distance_from_origin_km - originRouteStop->
//     distance_from_origin_km) — identical.
//   - Coordinate-based mode: browse-time matchRouteForTrip() and
//     checkout-time interpolateForKnownRoute() both delegate to the SAME
//     private RouteGeometryService::interpolateForRoute() method — the only
//     difference is matchRouteForTrip searches all routes to find a match,
//     interpolateForKnownRoute is restricted to one already-known route.
// This test proves it with real fixtures rather than relying on code
// reading alone. RESULT: no discrepancy found — confirmed match.
function makeFareVerificationFixtures(): array
{
    $operatorUser = User::create([
        'username' => 'operator_' . uniqid(),
        'email' => 'operator+' . uniqid() . '@smarttransit.test',
        'password' => bcrypt('password123'),
        'role' => 'operator',
    ]);
    $operatorStaff = StaffUser::create([
        'company_user_uuid' => (string) Str::uuid(),
        'user_id' => $operatorUser->user_id,
        'name' => 'Fare Verification Operator',
        'phone_num' => '09170000021',
        'address' => 'Test Address',
    ]);

    $route = Route::create([
        'route_name' => 'Fare Verification Route',
        'origin' => 'Origin Stop',
        'destination' => 'Destination Stop',
    ]);

    // Non-round distance (13.7 km) and non-trivial fare rule (₱15 base +
    // ₱2.35/km) so a coincidental match due to trivial numbers is ruled out.
    $originStop = Stop::create(['stop_name' => 'Origin Stop', 'latitude' => 7.0525, 'longitude' => 125.6069]);
    $destinationStop = Stop::create(['stop_name' => 'Destination Stop', 'latitude' => 7.0059, 'longitude' => 125.4956]);

    RouteStop::create(['route_id' => $route->route_id, 'stop_id' => $originStop->stop_id, 'stop_order' => 1, 'distance_from_origin_km' => 0]);
    RouteStop::create(['route_id' => $route->route_id, 'stop_id' => $destinationStop->stop_id, 'stop_order' => 2, 'distance_from_origin_km' => 13.7]);

    $fleet = Fleet::create([
        'company_user_id' => $operatorStaff->company_user_id,
        'plate_number' => 'FARE-' . fake()->unique()->numerify('###'),
        'capacity' => 40,
        'seated_capacity' => 30,
        'standing_capacity' => 10,
        'status' => 'active',
    ]);

    FareRule::create([
        'fleet_id' => $fleet->fleet_id,
        'base_fare' => 15,
        'fare_per_km' => 2.35,
        'status' => 'active',
        'seat_type' => 'seated',
    ]);

    $fleetRoute = FleetRoute::create([
        'fleet_id' => $fleet->fleet_id,
        'route_id' => $route->route_id,
        'start_time' => '06:00',
        'end_time' => '22:00',
        'status' => 'active',
    ]);

    $trip = Trip::create([
        'fleet_route_id' => $fleetRoute->fleet_route_id,
        'company_user_id' => $operatorStaff->company_user_id,
        'trip_date' => now()->toDateString(),
        'departure_time' => '08:30',
        'trip_type' => 'one_way',
        'status' => 'scheduled',
        'current_seated_capacity' => 0,
        'current_standing_capacity' => 0,
        'total_occupancy' => 0,
    ]);

    return [$route, $fleet, $originStop, $destinationStop, $trip];
}

test('stop-based browse-time quote matches checkout-time computed price', function () {
    [$route, $fleet, $originStop, $destinationStop, $trip] = makeFareVerificationFixtures();

    $browseTimeQuote = app(FareRuleService::class)->getQuote([
        'route_id' => $route->route_id,
        'origin_stop_id' => $originStop->stop_id,
        'destination_stop_id' => $destinationStop->stop_id,
        'seat_type' => 'seated',
        'fleet_id' => $fleet->fleet_id,
    ]);

    $reserved = app(TicketService::class)->reserveAndPrice([
        'trip_id' => $trip->trip_id,
        'seat_type' => 'seated',
        'origin_stop_id' => $originStop->stop_id,
        'destination_stop_id' => $destinationStop->stop_id,
    ]);

    // Expected: round(15 + 13.7 * 2.35, 2) = round(47.195, 2) = 47.2
    expect($browseTimeQuote)->toBe(47.2);
    expect((float) $reserved['amount'])->toBe($browseTimeQuote);
});

test('coordinate-based browse-time quote matches checkout-time computed price', function () {
    [, $fleet, $originStop, $destinationStop, $trip] = makeFareVerificationFixtures();

    $browseTimeResult = app(FareRuleService::class)->getFleetQuotesFromCoordinates([
        'origin_lat' => (float) $originStop->latitude,
        'origin_lng' => (float) $originStop->longitude,
        'destination_lat' => (float) $destinationStop->latitude,
        'destination_lng' => (float) $destinationStop->longitude,
        'seat_type' => 'seated',
    ]);
    $browseTimeAmount = collect($browseTimeResult['fleets'])
        ->firstWhere('fleet_id', $fleet->fleet_id)['amount'];

    $reserved = app(TicketService::class)->reserveAndPrice([
        'trip_id' => $trip->trip_id,
        'seat_type' => 'seated',
        'origin_stop_id' => $originStop->stop_id,
        'destination_stop_id' => $destinationStop->stop_id,
    ]);

    expect((float) $reserved['amount'])->toBe((float) $browseTimeAmount);
});
