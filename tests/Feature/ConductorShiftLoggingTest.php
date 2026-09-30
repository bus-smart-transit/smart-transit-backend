<?php

use App\Models\Fleet;
use App\Models\FleetDailyPin;
use App\Models\FleetRoute;
use App\Models\Route;
use App\Models\StaffUser;
use App\Models\Trip;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

function makeConductorShiftStaffUser(string $role): User
{
    $user = User::create([
        'username' => fake()->unique()->userName(),
        'email' => fake()->unique()->safeEmail(),
        'password' => bcrypt('password123'),
        'role' => $role,
    ]);

    StaffUser::create([
        'user_id' => $user->user_id,
        'company_user_uuid' => (string) Str::uuid(),
        'name' => fake()->name(),
        'phone_num' => fake()->phoneNumber(),
        'address' => fake()->address(),
    ]);

    return $user->fresh();
}

function makeConductorShiftFleetRoute(): FleetRoute
{
    $route = Route::create([
        'route_name' => 'Conductor Shift Test Route',
        'origin' => 'Terminal A',
        'destination' => 'Terminal B',
    ]);

    $operator = makeConductorShiftStaffUser('operator');

    $fleet = Fleet::create([
        'company_user_id' => $operator->companyProfile->company_user_id,
        'plate_number' => 'CND-'.fake()->unique()->numerify('###'),
        'capacity' => 50,
        'seated_capacity' => 40,
        'standing_capacity' => 10,
        'status' => 'active',
    ]);

    return FleetRoute::create([
        'fleet_id' => $fleet->fleet_id,
        'route_id' => $route->route_id,
        'start_time' => '06:00',
        'end_time' => '22:00',
        'status' => 'active',
    ]);
}

function makeAssignedConductorTrip(User $conductor, string $status = 'scheduled'): Trip
{
    $fleetRoute = makeConductorShiftFleetRoute();

    return Trip::create([
        'fleet_route_id' => $fleetRoute->fleet_route_id,
        'company_user_id' => $conductor->companyProfile->company_user_id,
        'trip_date' => now()->toDateString(),
        'status' => $status,
        'conductor_id' => $conductor->companyProfile->company_user_id,
        'current_seated_capacity' => 0,
        'current_standing_capacity' => 0,
        'total_occupancy' => 0,
    ]);
}

test('conductor can start shift and retrieve open shift status', function () {
    $conductor = makeConductorShiftStaffUser('conductor');
    $trip = makeAssignedConductorTrip($conductor, 'scheduled');

    $startResponse = $this->actingAs($conductor)->postJson('/api/conductor/shift/start');

    $startResponse
        ->assertSuccessful()
        ->assertJsonPath('status', 'success')
        ->assertJsonPath('message', 'Conductor shift started')
        ->assertJsonPath('data.shift.trip_id', $trip->trip_id);

    $this->assertDatabaseHas('conductor_shift_logs', [
        'conductor_company_user_id' => $conductor->companyProfile->company_user_id,
        'trip_id' => $trip->trip_id,
        'end_source' => null,
    ]);

    $statusResponse = $this->actingAs($conductor)->getJson('/api/conductor/shift/status');

    $statusResponse
        ->assertSuccessful()
        ->assertJsonPath('data.has_open_shift', true)
        ->assertJsonPath('data.open_shift.trip_id', $trip->trip_id);
});

test('conductor cannot end shift while trip is active but can end after completion', function () {
    $conductor = makeConductorShiftStaffUser('conductor');
    $trip = makeAssignedConductorTrip($conductor, 'boarding');

    $this->actingAs($conductor)->postJson('/api/conductor/shift/start')->assertSuccessful();

    $this->actingAs($conductor)->postJson('/api/conductor/shift/end')
        ->assertStatus(422)
        ->assertJsonValidationErrors(['shift']);

    $trip->status = 'completed';
    $trip->save();

    $endResponse = $this->actingAs($conductor)->postJson('/api/conductor/shift/end');

    $endResponse
        ->assertSuccessful()
        ->assertJsonPath('status', 'success')
        ->assertJsonPath('message', 'Conductor shift ended');

    $shiftLogId = $endResponse->json('data.shift.shift_log_id');
    expect($shiftLogId)->not()->toBeNull();

    $this->assertDatabaseHas('conductor_shift_logs', [
        'shift_log_id' => $shiftLogId,
        'conductor_company_user_id' => $conductor->companyProfile->company_user_id,
        'trip_id' => $trip->trip_id,
        'end_source' => 'dashboard',
    ]);
});

test('shift status tells the dashboard why End Shift is blocked, matching the server guard', function () {
    $conductor = makeConductorShiftStaffUser('conductor');
    $trip = makeAssignedConductorTrip($conductor, 'boarding');

    $this->actingAs($conductor)->getJson('/api/conductor/shift/status')
        ->assertSuccessful()
        ->assertJsonPath('data.has_open_shift', false)
        ->assertJsonPath('data.end_blocked_reason', null);

    $this->actingAs($conductor)->postJson('/api/conductor/shift/start')->assertSuccessful();

    $blocked = $this->actingAs($conductor)->getJson('/api/conductor/shift/status')
        ->assertSuccessful()
        ->json('data.end_blocked_reason');
    expect($blocked)->toContain('boarding or in transit');

    $this->actingAs($conductor)->postJson('/api/conductor/shift/end')
        ->assertStatus(422)
        ->assertJsonPath('errors.shift.0', $blocked);

    $trip->status = 'completed';
    $trip->save();

    $this->actingAs($conductor)->getJson('/api/conductor/shift/status')
        ->assertJsonPath('data.end_blocked_reason', null);
});

test('conductor current trip prefers the open shift trip when another same-day trip exists', function () {
    $conductor = makeShiftStaffUser('conductor');
    $operator = makeShiftStaffUser('operator');
    $tripA = makeAssignedConductorTrip($conductor, 'scheduled');

    $this->actingAs($conductor)->postJson('/api/conductor/shift/start')->assertSuccessful();

    // /trips/current is gated by the 'paired' middleware — establish a
    // completed pairing for tripA (the shift's actual trip) so the request
    // isn't rejected with 423 regardless of which trip gets resolved.
    FleetDailyPin::create([
        'trip_id' => $tripA->trip_id,
        'conductor_id' => $conductor->companyProfile->company_user_id,
        'pin_code' => '123456',
        'pin_date' => now()->toDateString(),
        'driver_verified_at' => now(),
        'conductor_verified_at' => now(),
    ]);

    $fleetRoute = makeShiftFleetRoute();
    Trip::create([
        'fleet_route_id' => $fleetRoute->fleet_route_id,
        'company_user_id' => $operator->companyProfile->company_user_id,
        'trip_date' => now()->toDateString(),
        'status' => 'boarding',
        'conductor_id' => $conductor->companyProfile->company_user_id,
        'current_seated_capacity' => 0,
        'current_standing_capacity' => 0,
        'total_occupancy' => 0,
    ]);

    $this->actingAs($conductor)
        ->getJson('/api/conductor/trips/current')
        ->assertSuccessful()
        ->assertJsonPath('data.trip_id', $tripA->trip_id);
});

test('conductor current trip is unavailable after the shift is closed', function () {
    $conductor = makeShiftStaffUser('conductor');
    $trip = makeAssignedConductorTrip($conductor, 'scheduled');

    $this->actingAs($conductor)->postJson('/api/conductor/shift/start')->assertSuccessful();

    $trip->status = 'completed';
    $trip->save();

    $this->actingAs($conductor)->postJson('/api/conductor/shift/end')->assertSuccessful();

    $this->actingAs($conductor)
        ->getJson('/api/conductor/trips/current')
        ->assertStatus(404);
});
