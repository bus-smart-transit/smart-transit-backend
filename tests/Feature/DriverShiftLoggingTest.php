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

function makeShiftStaffUser(string $role): User
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

function makeShiftFleetRoute(): FleetRoute
{
    $route = Route::create([
        'route_name' => 'Driver Shift Test Route',
        'origin' => 'Terminal A',
        'destination' => 'Terminal B',
    ]);

    $operator = makeShiftStaffUser('operator');

    $fleet = Fleet::create([
        'company_user_id' => $operator->companyProfile->company_user_id,
        'plate_number' => 'DRV-'.fake()->unique()->numerify('###'),
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

function makeAssignedDriverTrip(User $driver, string $status = 'scheduled'): Trip
{
    $fleetRoute = makeShiftFleetRoute();

    return Trip::create([
        'fleet_route_id' => $fleetRoute->fleet_route_id,
        'company_user_id' => $driver->companyProfile->company_user_id,
        'trip_date' => now()->toDateString(),
        'status' => $status,
        'driver_id' => $driver->companyProfile->company_user_id,
        'driver_accepted_at' => now(), 'conductor_accepted_at' => now(), // Batch 29: the assignment is accepted
        'current_seated_capacity' => 0,
        'current_standing_capacity' => 0,
        'total_occupancy' => 0,
    ]);
}

test('driver can start shift and retrieve open shift status', function () {
    $driver = makeShiftStaffUser('driver');
    $trip = makeAssignedDriverTrip($driver, 'scheduled');

    $startResponse = $this->actingAs($driver)->postJson('/api/driver/shift/start');

    $startResponse
        ->assertSuccessful()
        ->assertJsonPath('status', 'success')
        ->assertJsonPath('message', 'Driver shift started')
        ->assertJsonPath('data.shift.trip_id', $trip->trip_id);

    $this->assertDatabaseHas('driver_shift_logs', [
        'driver_company_user_id' => $driver->companyProfile->company_user_id,
        'trip_id' => $trip->trip_id,
        'end_source' => null,
    ]);

    $statusResponse = $this->actingAs($driver)->getJson('/api/driver/shift/status');

    $statusResponse
        ->assertSuccessful()
        ->assertJsonPath('data.has_open_shift', true)
        ->assertJsonPath('data.open_shift.trip_id', $trip->trip_id);
});

test('driver cannot end a shift (403); End Trip closes the driver shift instead', function () {
    $driver = makeShiftStaffUser('driver');
    $trip = makeAssignedDriverTrip($driver, 'departed');

    $this->actingAs($driver)->postJson('/api/driver/shift/start')->assertSuccessful();

    // B5: only the Chauffeur ends a shift; enforced by the API, not just the UI.
    $this->actingAs($driver)->postJson('/api/driver/shift/end')
        ->assertForbidden()
        ->assertJsonPath('status', 'error');

    $this->assertDatabaseHas('driver_shift_logs', [
        'driver_company_user_id' => $driver->companyProfile->company_user_id,
        'trip_id' => $trip->trip_id,
        'ended_at' => null,
    ]);

    $this->actingAs($driver)->patchJson("/api/driver/trips/{$trip->trip_id}/complete")->assertSuccessful();

    $this->assertDatabaseMissing('driver_shift_logs', [
        'trip_id' => $trip->trip_id,
        'ended_at' => null,
    ]);
    $this->assertDatabaseHas('driver_shift_logs', [
        'trip_id' => $trip->trip_id,
        'end_source' => 'trip_status_change',
    ]);
});

test('driver current trip prefers the open shift trip when another same-day trip exists', function () {
    $driver = makeShiftStaffUser('driver');
    $operator = makeShiftStaffUser('operator');
    $tripA = makeAssignedDriverTrip($driver, 'scheduled');

    $this->actingAs($driver)->postJson('/api/driver/shift/start')->assertSuccessful();

    // /trips/current is gated by the 'paired' middleware — establish a
    // completed pairing for tripA (the shift's actual trip) so the request
    // isn't rejected with 423 regardless of which trip gets resolved.
    FleetDailyPin::create([
        'trip_id' => $tripA->trip_id,
        'driver_id' => $driver->companyProfile->company_user_id,
        'driver_accepted_at' => now(), 'conductor_accepted_at' => now(), // Batch 29: the assignment is accepted
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
        'driver_id' => $driver->companyProfile->company_user_id,
        'driver_accepted_at' => now(), 'conductor_accepted_at' => now(), // Batch 29: the assignment is accepted
        'current_seated_capacity' => 0,
        'current_standing_capacity' => 0,
        'total_occupancy' => 0,
    ]);

    $this->actingAs($driver)
        ->getJson('/api/driver/trips/current')
        ->assertSuccessful()
        ->assertJsonPath('data.trip_id', $tripA->trip_id);
});

test('driver can start shift and then open boarding for the same trip', function () {
    $driver = makeShiftStaffUser('driver');
    $trip = makeAssignedDriverTrip($driver, 'scheduled');

    $this->actingAs($driver)->postJson('/api/driver/shift/start')->assertSuccessful();

    $this->actingAs($driver)
        ->patchJson("/api/driver/trips/{$trip->trip_id}/boarding")
        ->assertSuccessful()
        ->assertJsonPath('message', 'Boarding started');

    $this->assertDatabaseHas('trips', [
        'trip_id' => $trip->trip_id,
        'status' => 'boarding',
    ]);
});

test('driver current trip is unavailable after the shift is closed', function () {
    $driver = makeShiftStaffUser('driver');
    $trip = makeAssignedDriverTrip($driver, 'scheduled');

    $this->actingAs($driver)->postJson('/api/driver/shift/start')->assertSuccessful();

    $trip->status = 'completed';
    $trip->save();

    // End Trip (not End Shift) closes the driver's shift.
    $this->actingAs($driver)->patchJson("/api/driver/trips/{$trip->trip_id}/complete")->assertSuccessful();

    $this->actingAs($driver)
        ->getJson('/api/driver/trips/current')
        ->assertStatus(404);
});

// Batch 17, Issue #4: a shift left open after its trip completed (driver
// never pressed "End Shift") was permanently blocking every later shift
// start for a genuinely available, paired driver on a brand new same-day
// trip. Self-heal: auto-close the stale shift instead of hard-blocking.
test('starting a new shift auto-closes a stale open shift left on an already-completed trip', function () {
    $driver = makeShiftStaffUser('driver');
    $staleTrip = makeAssignedDriverTrip($driver, 'boarding');

    $this->actingAs($driver)->postJson('/api/driver/shift/start')->assertSuccessful();

    // Trip finished, but nobody ever pressed "End Shift".
    $staleTrip->status = 'completed';
    $staleTrip->save();

    $newTrip = makeAssignedDriverTrip($driver, 'scheduled');

    $response = $this->actingAs($driver)->postJson('/api/driver/shift/start');

    $response
        ->assertSuccessful()
        ->assertJsonPath('data.shift.trip_id', $newTrip->trip_id);

    $this->assertDatabaseHas('driver_shift_logs', [
        'trip_id' => $staleTrip->trip_id,
        'end_source' => 'auto_closed_stale',
    ]);

    $this->assertDatabaseHas('driver_shift_logs', [
        'trip_id' => $newTrip->trip_id,
        'end_source' => null,
    ]);
});

// Batch 17, Issue #4 prevention half: completing a trip should proactively
// close any shift still left open for it, so it can never even become a
// stale blocker in the first place.
test('completing a trip auto-closes any still-open shift for it', function () {
    $driver = makeShiftStaffUser('driver');
    $trip = makeAssignedDriverTrip($driver, 'boarding');

    $this->actingAs($driver)->postJson('/api/driver/shift/start')->assertSuccessful();

    app(\App\Services\TripService::class)->completeTrip($trip->trip_id);

    $this->assertDatabaseHas('driver_shift_logs', [
        'trip_id' => $trip->trip_id,
        'end_source' => 'trip_status_change',
    ]);
});

