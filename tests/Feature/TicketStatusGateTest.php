<?php

use App\Models\FareRule;
use App\Models\Fleet;
use App\Models\FleetRoute;
use App\Models\PassengerUser;
use App\Models\Route;
use App\Models\RouteStop;
use App\Models\Stop;
use App\Models\StaffUser;
use App\Models\Trip;
use App\Models\User;
use App\Services\TicketService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

// Batch 14, Q1: this is the third distinct bug tied to trip `status ===
// 'delayed'` handling being special-cased too narrowly somewhere in the
// codebase. Root cause found in TicketService::reserveAndPrice(), which
// only allowed ['scheduled', 'boarding'] — excluding 'delayed' — even
// though the trip listing endpoints (TripRepository::ACTIVE_STATUSES) and
// TripService::declineTrip() both correctly treat 'delayed' as a normal,
// still-bookable/still-active trip state. A delay is not a cancellation.
function makeCheckoutFixtures(): array
{
    $operatorUser = User::create([
        'username' => 'operator_' . uniqid(),
        'email' => 'operator+' . uniqid() . '@smarttransit.test',
        'password' => bcrypt('password123'),
        'role' => 'operator',
    ]);

    StaffUser::create([
        'company_user_uuid' => (string) Str::uuid(),
        'user_id' => $operatorUser->user_id,
        'name' => 'Operator Ticketing',
        'phone_num' => '09170000013',
        'address' => 'Test Address',
    ]);
    $operatorCompanyUserId = $operatorUser->fresh()->companyProfile->company_user_id;

    $route = Route::create([
        'route_name' => 'Ticketing Status Gate Route',
        'origin' => 'Origin Stop',
        'destination' => 'Destination Stop',
    ]);

    $originStop = Stop::create(['stop_name' => 'Origin Stop', 'latitude' => 7.1000, 'longitude' => 125.6000]);
    $destinationStop = Stop::create(['stop_name' => 'Destination Stop', 'latitude' => 7.2000, 'longitude' => 125.7000]);

    RouteStop::create(['route_id' => $route->route_id, 'stop_id' => $originStop->stop_id, 'stop_order' => 1, 'distance_from_origin_km' => 0]);
    RouteStop::create(['route_id' => $route->route_id, 'stop_id' => $destinationStop->stop_id, 'stop_order' => 2, 'distance_from_origin_km' => 10]);

    $fleet = Fleet::create([
        'company_user_id' => $operatorCompanyUserId,
        'plate_number' => 'GATE-' . fake()->unique()->numerify('###'),
        'capacity' => 50,
        'seated_capacity' => 40,
        'standing_capacity' => 10,
        'status' => 'active',
    ]);

    FareRule::create([
        'fleet_id' => $fleet->fleet_id,
        'base_fare' => 15,
        'fare_per_km' => 2,
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

    return [$fleetRoute, $originStop, $destinationStop, $operatorCompanyUserId];
}

function makeTripWithStatus(object $fleetRoute, int $operatorCompanyUserId, string $status): Trip
{
    return Trip::create([
        'fleet_route_id' => $fleetRoute->fleet_route_id,
        'company_user_id' => $operatorCompanyUserId,
        'trip_date' => now()->toDateString(),
        'departure_time' => '08:30',
        'trip_type' => 'one_way',
        'status' => $status,
        'current_seated_capacity' => 0,
        'current_standing_capacity' => 0,
        'total_occupancy' => 0,
    ]);
}

test('reserveAndPrice accepts a delayed trip', function () {
    [$fleetRoute, $originStop, $destinationStop, $operatorCompanyUserId] = makeCheckoutFixtures();
    $trip = makeTripWithStatus($fleetRoute, $operatorCompanyUserId, 'delayed');

    $reserved = app(TicketService::class)->reserveAndPrice([
        'trip_id' => $trip->trip_id,
        'seat_type' => 'seated',
        'origin_stop_id' => $originStop->stop_id,
        'destination_stop_id' => $destinationStop->stop_id,
    ]);

    expect($reserved['trip_id'])->toBe($trip->trip_id);
    expect($reserved['amount'])->toBeGreaterThan(0);
});

test('reserveAndPrice still rejects a cancelled trip', function () {
    [$fleetRoute, $originStop, $destinationStop, $operatorCompanyUserId] = makeCheckoutFixtures();
    $trip = makeTripWithStatus($fleetRoute, $operatorCompanyUserId, 'cancelled');

    app(TicketService::class)->reserveAndPrice([
        'trip_id' => $trip->trip_id,
        'seat_type' => 'seated',
        'origin_stop_id' => $originStop->stop_id,
        'destination_stop_id' => $destinationStop->stop_id,
    ]);
})->throws(ValidationException::class);
