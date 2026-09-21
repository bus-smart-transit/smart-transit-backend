<?php

use App\Models\Fleet;
use App\Models\FleetRoute;
use App\Models\Route;
use App\Models\StaffUser;
use App\Models\Trip;
use App\Models\User;
use App\Services\TripService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

uses(RefreshDatabase::class);

// Batch 15, Item 1: explicit Accept counterpart to the existing Decline flow
// (S2, Batch 12). These tests were written AFTER the migration/repository/
// service/controller/route changes were implemented (test-after, not TDD
// red-green-refactor) — logged honestly per Test-Driven-Development.md's
// verification protocol.
function makeStaffMemberForTripAccept(string $role): array
{
    $user = User::create([
        'username' => $role . '_' . uniqid(),
        'email' => $role . '+' . uniqid() . '@smarttransit.test',
        'password' => bcrypt('password123'),
        'role' => $role,
    ]);

    $staff = StaffUser::create([
        'company_user_uuid' => (string) Str::uuid(),
        'user_id' => $user->user_id,
        'name' => ucfirst($role) . ' Accept Test',
        'phone_num' => '09170000020',
        'address' => 'Test Address',
    ]);

    return [$user, $staff];
}

function makeTripForAcceptTest(int $companyUserId, ?int $driverId, ?int $conductorId, string $status = 'scheduled'): Trip
{
    $route = Route::create([
        'route_name' => 'Accept Test Route',
        'origin' => 'A',
        'destination' => 'B',
    ]);

    $fleet = Fleet::create([
        'company_user_id' => $companyUserId,
        'plate_number' => 'ACC-' . fake()->unique()->numerify('###'),
        'capacity' => 40,
        'seated_capacity' => 30,
        'standing_capacity' => 10,
        'status' => 'active',
    ]);

    $fleetRoute = FleetRoute::create([
        'fleet_id' => $fleet->fleet_id,
        'route_id' => $route->route_id,
        'start_time' => '06:00',
        'end_time' => '22:00',
        'status' => 'active',
    ]);

    return Trip::create([
        'fleet_route_id' => $fleetRoute->fleet_route_id,
        'company_user_id' => $companyUserId,
        'trip_date' => now()->toDateString(),
        'departure_time' => '08:30',
        'trip_type' => 'one_way',
        'status' => $status,
        'current_seated_capacity' => 0,
        'current_standing_capacity' => 0,
        'total_occupancy' => 0,
        'driver_id' => $driverId,
        'conductor_id' => $conductorId,
    ]);
}

test('driver can accept their assigned trip', function () {
    [, $driver] = makeStaffMemberForTripAccept('driver');
    $trip = makeTripForAcceptTest($driver->company_user_id, $driver->company_user_id, null);

    expect($trip->driver_accepted_at)->toBeNull();

    $accepted = app(TripService::class)->acceptTrip($trip->trip_id, 'driver', $driver->company_user_id);

    expect($accepted->driver_accepted_at)->not->toBeNull();
    expect((int) $accepted->driver_id)->toBe($driver->company_user_id);
});

test('conductor can accept their assigned trip', function () {
    [, $conductor] = makeStaffMemberForTripAccept('conductor');
    $trip = makeTripForAcceptTest($conductor->company_user_id, null, $conductor->company_user_id);

    $accepted = app(TripService::class)->acceptTrip($trip->trip_id, 'conductor', $conductor->company_user_id);

    expect($accepted->conductor_accepted_at)->not->toBeNull();
});

test('a driver cannot accept a trip assigned to someone else', function () {
    [, $driverA] = makeStaffMemberForTripAccept('driver');
    [, $driverB] = makeStaffMemberForTripAccept('driver');
    $trip = makeTripForAcceptTest($driverA->company_user_id, $driverA->company_user_id, null);

    app(TripService::class)->acceptTrip($trip->trip_id, 'driver', $driverB->company_user_id);
})->throws(ValidationException::class);

test('a departed trip can no longer be accepted', function () {
    [, $driver] = makeStaffMemberForTripAccept('driver');
    $trip = makeTripForAcceptTest($driver->company_user_id, $driver->company_user_id, null, 'departed');

    app(TripService::class)->acceptTrip($trip->trip_id, 'driver', $driver->company_user_id);
})->throws(ValidationException::class);

test('reassigning a driver resets acceptance and requires re-accepting', function () {
    [, $driverA] = makeStaffMemberForTripAccept('driver');
    [, $driverB] = makeStaffMemberForTripAccept('driver');
    $trip = makeTripForAcceptTest($driverA->company_user_id, $driverA->company_user_id, null);

    app(TripService::class)->acceptTrip($trip->trip_id, 'driver', $driverA->company_user_id);
    expect($trip->fresh()->driver_accepted_at)->not->toBeNull();

    app(TripService::class)->assignDriver($trip->trip_id, ['driver_id' => $driverB->company_user_id]);

    $reassigned = $trip->fresh();
    expect((int) $reassigned->driver_id)->toBe($driverB->company_user_id);
    expect($reassigned->driver_accepted_at)->toBeNull();
});

test('declining a trip clears acceptance for that role', function () {
    [, $driver] = makeStaffMemberForTripAccept('driver');
    $trip = makeTripForAcceptTest($driver->company_user_id, $driver->company_user_id, null);

    app(TripService::class)->acceptTrip($trip->trip_id, 'driver', $driver->company_user_id);
    expect($trip->fresh()->driver_accepted_at)->not->toBeNull();

    app(TripService::class)->declineTrip($trip->trip_id, 'driver', $driver->company_user_id);

    $declined = $trip->fresh();
    expect($declined->driver_id)->toBeNull();
    expect($declined->driver_accepted_at)->toBeNull();
});
