<?php

use App\Models\Fleet;
use App\Models\FleetRoute;
use App\Models\Route;
use App\Models\StaffUser;
use App\Models\Trip;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

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
        'company_user_uuid' => (string) \Illuminate\Support\Str::uuid(),
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
        'plate_number' => 'CND-' . fake()->unique()->numerify('###'),
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
