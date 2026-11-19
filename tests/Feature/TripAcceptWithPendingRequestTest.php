<?php

use App\Models\Fleet;
use App\Models\FleetRoute;
use App\Models\Route;
use App\Models\StaffUser;
use App\Models\Trip;
use App\Models\TripAssignmentRequest;
use App\Models\User;
use App\Services\TripAssignmentRequestService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

// L-28: the SERVER refuses to accept a trip while the crew member has a pending request to be released from it. The
// screen already hid the Accept button; these tests go through the HTTP route a script could call.

function aprStaff(string $role): array
{
    $user = User::create(['username' => $role.'_'.uniqid(), 'email' => $role.'+'.uniqid().'@smarttransit.test', 'password' => bcrypt('password123'), 'role' => $role]);
    $staff = StaffUser::create(['company_user_uuid' => (string) Str::uuid(), 'user_id' => $user->user_id, 'name' => ucfirst($role).' Apr', 'phone_num' => '09170000101', 'address' => 'x']);

    return [$user->fresh(), $staff];
}

function aprTrip(int $ownerId, ?int $driverId, ?int $conductorId): Trip
{
    $route = Route::create(['route_name' => 'Apr Route', 'origin' => 'A', 'destination' => 'B']);
    $fleet = Fleet::create(['company_user_id' => $ownerId, 'plate_number' => 'APR-'.fake()->unique()->numerify('###'), 'capacity' => 40, 'seated_capacity' => 30, 'standing_capacity' => 10, 'status' => 'active']);
    $fleetRoute = FleetRoute::create(['fleet_id' => $fleet->fleet_id, 'route_id' => $route->route_id, 'start_time' => '06:00', 'end_time' => '22:00', 'status' => 'active']);

    return Trip::create([
        'fleet_route_id' => $fleetRoute->fleet_route_id, 'company_user_id' => $ownerId, 'trip_date' => now()->addDay()->toDateString(), 'departure_time' => '08:30',
        'trip_type' => 'one_way', 'status' => 'scheduled', 'current_seated_capacity' => 0, 'current_standing_capacity' => 0, 'total_occupancy' => 0,
        'driver_id' => $driverId, 'conductor_id' => $conductorId,
    ]);
}

it('refuses to accept a trip over HTTP while a request to be released is pending, for a driver and for a Chauffeur', function (string $role) {
    [$user, $staff] = aprStaff($role);
    $trip = aprTrip($staff->company_user_id, $role === 'driver' ? $staff->company_user_id : null, $role === 'conductor' ? $staff->company_user_id : null);

    app(TripAssignmentRequestService::class)->requestDecline($trip->trip_id, $role, $staff->company_user_id, 'sick', null);

    $response = $this->actingAs($user)->patchJson("/api/$role/trips/{$trip->trip_id}/accept");

    $response->assertStatus(422);
    expect(json_encode($response->json()))->toContain('asked to be released');
    expect($trip->fresh()->{$role.'_accepted_at'})->toBeNull();
})->with(['driver', 'conductor']);

it('accepts the same trip over HTTP when there is no request', function (string $role) {
    [$user, $staff] = aprStaff($role);
    $trip = aprTrip($staff->company_user_id, $role === 'driver' ? $staff->company_user_id : null, $role === 'conductor' ? $staff->company_user_id : null);

    $this->actingAs($user)->patchJson("/api/$role/trips/{$trip->trip_id}/accept")->assertOk();

    expect($trip->fresh()->{$role.'_accepted_at'})->not->toBeNull();
})->with(['driver', 'conductor']);

it('lets the crew member accept again once the Operator has answered the request', function () {
    [$user, $staff] = aprStaff('driver');
    $trip = aprTrip($staff->company_user_id, $staff->company_user_id, null);
    app(TripAssignmentRequestService::class)->requestDecline($trip->trip_id, 'driver', $staff->company_user_id, 'sick', null);
    TripAssignmentRequest::where('trip_id', $trip->trip_id)->update(['status' => TripAssignmentRequest::REJECTED]);   // the Operator said no: the trip stays theirs

    $this->actingAs($user)->patchJson("/api/driver/trips/{$trip->trip_id}/accept")->assertOk();

    expect($trip->fresh()->driver_accepted_at)->not->toBeNull();
});

it('only looks at the requests of the person accepting, not their partner\'s', function () {
    [$driverUser, $driver] = aprStaff('driver');
    [, $conductor] = aprStaff('conductor');
    $trip = aprTrip($driver->company_user_id, $driver->company_user_id, $conductor->company_user_id);
    app(TripAssignmentRequestService::class)->requestDecline($trip->trip_id, 'conductor', $conductor->company_user_id, 'sick', null);

    $this->actingAs($driverUser)->patchJson("/api/driver/trips/{$trip->trip_id}/accept")->assertOk();
});
