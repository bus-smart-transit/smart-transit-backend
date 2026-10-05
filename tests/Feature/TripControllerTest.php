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

// ─── helpers ────────────────────────────────────────────────────────────────

/**
 * Create a staff User with an associated StaffUser (companyProfile) record.
 */
function makeStaffUser(string $role): User
{
    $user = User::create([
        'username' => fake()->unique()->userName(),
        'email' => fake()->unique()->safeEmail(),
        'password' => bcrypt('password'),
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

/**
 * Create a FleetRoute with its parent Fleet and Route records.
 * The Fleet requires an owning company_user, so we create a minimal operator for it.
 */
function makeFleetRoute(): FleetRoute
{
    // Route requires origin + destination; refresh() ensures custom PK is populated
    $route = Route::create([
        'route_name' => 'Test Route',
        'origin' => 'Stop A',
        'destination' => 'Stop B',
    ]);
    $route->refresh();

    // Fleet requires a company_user_id FK
    $operatorForFleet = makeStaffUser('operator');

    $fleet = Fleet::create([
        'company_user_id' => $operatorForFleet->companyProfile->company_user_id,
        'plate_number' => 'ABC-'.fake()->unique()->numerify('###'),
        'capacity' => 50,
        'seated_capacity' => 40,
        'standing_capacity' => 10,
        'status' => 'active',
    ]);
    $fleet->refresh();

    $fleetRoute = FleetRoute::create([
        'fleet_id' => $fleet->fleet_id,
        'route_id' => $route->route_id,
        'start_time' => '06:00',
        'end_time' => '22:00',
        'status' => 'active',
    ]);
    $fleetRoute->refresh();

    return $fleetRoute;
}

// ─── Trip scheduling (store) ─────────────────────────────────────────────────

test('operator can schedule a trip', function () {
    $operator = makeStaffUser('operator');
    $driver = makeStaffUser('driver');
    $conductor = makeStaffUser('conductor');
    $fleetRoute = makeFleetRoute();

    $response = $this->actingAs($operator)->postJson('/api/operator/trips', [
        'fleet_route_id' => $fleetRoute->fleet_route_id,
        'trip_date' => now()->toDateString(),
        'departure_time' => '08:30',
        'trip_type' => 'one_way',
        'driver_id' => $driver->companyProfile->company_user_id,
        'conductor_id' => $conductor->companyProfile->company_user_id,
    ]);

    $response->assertSuccessful()
        ->assertJsonPath('status', 'success')
        ->assertJsonPath('message', 'Trip scheduled successfully');

    $this->assertDatabaseHas('trips', [
        'fleet_route_id' => $fleetRoute->fleet_route_id,
        'status' => 'scheduled',
        'trip_type' => 'one_way',
    ]);
});

test('store validates required fields', function () {
    $operator = makeStaffUser('operator');

    $this->actingAs($operator)->postJson('/api/operator/trips', [])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['fleet_route_id', 'trip_date']);
});

test('scheduling a trip rejects a non-driver as driver_id and does not create a PIN', function () {
    $operator = makeStaffUser('operator');
    $notADriver = makeStaffUser('conductor');
    $conductor = makeStaffUser('conductor');
    $fleetRoute = makeFleetRoute();

    $response = $this->actingAs($operator)->postJson('/api/operator/trips', [
        'fleet_route_id' => $fleetRoute->fleet_route_id,
        'trip_date' => now()->toDateString(),
        'departure_time' => '08:30',
        'trip_type' => 'one_way',
        'driver_id' => $notADriver->companyProfile->company_user_id,
        'conductor_id' => $conductor->companyProfile->company_user_id,
    ]);

    $response->assertStatus(422)
        ->assertJsonValidationErrors(['driver_id']);

    $this->assertDatabaseMissing('trips', [
        'fleet_route_id' => $fleetRoute->fleet_route_id,
    ]);
    $this->assertDatabaseCount('fleet_daily_pins', 0);
});

test('scheduling a trip rejects a non-conductor as conductor_id and does not create a PIN', function () {
    $operator = makeStaffUser('operator');
    $driver = makeStaffUser('driver');
    $notAConductor = makeStaffUser('driver');
    $fleetRoute = makeFleetRoute();

    $response = $this->actingAs($operator)->postJson('/api/operator/trips', [
        'fleet_route_id' => $fleetRoute->fleet_route_id,
        'trip_date' => now()->toDateString(),
        'departure_time' => '08:30',
        'trip_type' => 'one_way',
        'driver_id' => $driver->companyProfile->company_user_id,
        'conductor_id' => $notAConductor->companyProfile->company_user_id,
    ]);

    $response->assertStatus(422)
        ->assertJsonValidationErrors(['conductor_id']);

    $this->assertDatabaseMissing('trips', [
        'fleet_route_id' => $fleetRoute->fleet_route_id,
    ]);
    $this->assertDatabaseCount('fleet_daily_pins', 0);
});

test('scheduling a valid trip for today still auto-generates a PIN', function () {
    $operator = makeStaffUser('operator');
    $driver = makeStaffUser('driver');
    $conductor = makeStaffUser('conductor');
    $fleetRoute = makeFleetRoute();

    $response = $this->actingAs($operator)->postJson('/api/operator/trips', [
        'fleet_route_id' => $fleetRoute->fleet_route_id,
        'trip_date' => now()->toDateString(),
        'departure_time' => '08:30',
        'trip_type' => 'one_way',
        'driver_id' => $driver->companyProfile->company_user_id,
        'conductor_id' => $conductor->companyProfile->company_user_id,
    ]);

    $response->assertSuccessful();

    $this->assertDatabaseHas('fleet_daily_pins', [
        'driver_id' => $driver->companyProfile->company_user_id,
        'conductor_id' => $conductor->companyProfile->company_user_id,
    ]);
});

test('FleetDailyPinService refuses to generate a PIN for IDs with the wrong roles even when called directly', function () {
    $driver = makeStaffUser('driver');
    $notAConductor = makeStaffUser('driver');
    $fleetRoute = makeFleetRoute();
    $operator = makeStaffUser('operator');

    $trip = Trip::create([
        'fleet_route_id' => $fleetRoute->fleet_route_id,
        'company_user_id' => $operator->companyProfile->company_user_id,
        'trip_date' => now()->toDateString(),
        'status' => 'scheduled',
        'current_seated_capacity' => 0,
        'current_standing_capacity' => 0,
        'total_occupancy' => 0,
    ]);

    $pinService = app(\App\Services\FleetDailyPinService::class);

    expect(fn () => $pinService->generateOrGet(
        $trip->trip_id,
        $driver->companyProfile->company_user_id,
        $notAConductor->companyProfile->company_user_id,
    ))->toThrow(\Illuminate\Validation\ValidationException::class);

    $this->assertDatabaseCount('fleet_daily_pins', 0);
});

test('FleetDailyPinService::generateOrGet is idempotent for the same trip/day', function () {
    // Regression test for FleetDailyPinRepository::findTodayByTrip() using
    // ->whereDate('pin_date', ...) instead of an exact ->where(...) match —
    // the exact-match version could fail to find an already-created PIN if
    // pin_date ever carried a time component, causing a second call to
    // attempt a duplicate insert and violate the (trip_id, pin_date) unique
    // constraint instead of returning the existing row.
    $driver = makeStaffUser('driver');
    $conductor = makeStaffUser('conductor');
    $operator = makeStaffUser('operator');
    $fleetRoute = makeFleetRoute();

    $trip = Trip::create([
        'fleet_route_id' => $fleetRoute->fleet_route_id,
        'company_user_id' => $operator->companyProfile->company_user_id,
        'trip_date' => now()->toDateString(),
        'status' => 'scheduled',
        'current_seated_capacity' => 0,
        'current_standing_capacity' => 0,
        'total_occupancy' => 0,
    ]);

    $pinService = app(\App\Services\FleetDailyPinService::class);

    $first = $pinService->generateOrGet(
        $trip->trip_id,
        $driver->companyProfile->company_user_id,
        $conductor->companyProfile->company_user_id,
    );

    $second = $pinService->generateOrGet(
        $trip->trip_id,
        $driver->companyProfile->company_user_id,
        $conductor->companyProfile->company_user_id,
    );

    expect($second->id)->toBe($first->id);
    expect($second->pin_code)->toBe($first->pin_code);
    $this->assertDatabaseCount('fleet_daily_pins', 1);
});

test('operator can schedule a round trip with required departure time', function () {
    $operator = makeStaffUser('operator');
    $driver = makeStaffUser('driver');
    $conductor = makeStaffUser('conductor');
    $fleetRoute = makeFleetRoute();

    $response = $this->actingAs($operator)->postJson('/api/operator/trips', [
        'fleet_route_id' => $fleetRoute->fleet_route_id,
        'trip_date' => now()->toDateString(),
        'departure_time' => '08:30',
        'trip_type' => 'round_trip',
        'return_departure_time' => '18:15',
        'driver_id' => $driver->companyProfile->company_user_id,
        'conductor_id' => $conductor->companyProfile->company_user_id,
    ]);

    $response->assertSuccessful();

    $this->assertDatabaseHas('trips', [
        'fleet_route_id' => $fleetRoute->fleet_route_id,
        'trip_type' => 'round_trip',
        'return_departure_time' => '18:15',
    ]);

    $this->actingAs($operator)->postJson('/api/operator/trips', [
        'fleet_route_id' => $fleetRoute->fleet_route_id,
        'trip_date' => now()->toDateString(),
        'driver_id' => $driver->companyProfile->company_user_id,
        'conductor_id' => $conductor->companyProfile->company_user_id,
    ])->assertStatus(422)
        ->assertJsonValidationErrors(['departure_time']);
});

// ─── Assign driver ────────────────────────────────────────────────────────────

test('operator can assign a driver to a trip', function () {
    $operator = makeStaffUser('operator');
    $driver = makeStaffUser('driver');
    $fleetRoute = makeFleetRoute();

    $trip = Trip::create([
        'fleet_route_id' => $fleetRoute->fleet_route_id,
        'company_user_id' => $operator->companyProfile->company_user_id,
        'trip_date' => now()->toDateString(),
        'status' => 'scheduled',
        'current_seated_capacity' => 0,
        'current_standing_capacity' => 0,
        'total_occupancy' => 0,
    ]);

    $response = $this->actingAs($operator)->patchJson("/api/operator/trips/{$trip->trip_id}/driver", [
        'driver_id' => $driver->companyProfile->company_user_id,
    ]);

    $response->assertSuccessful()
        ->assertJsonPath('message', 'Driver assigned successfully');

    $this->assertDatabaseHas('trips', [
        'trip_id' => $trip->trip_id,
        'driver_id' => $driver->companyProfile->company_user_id,
    ]);
});

test('assigning a non-driver user as driver fails', function () {
    $operator = makeStaffUser('operator');
    $conductor = makeStaffUser('conductor');
    $fleetRoute = makeFleetRoute();

    $trip = Trip::create([
        'fleet_route_id' => $fleetRoute->fleet_route_id,
        'company_user_id' => $operator->companyProfile->company_user_id,
        'trip_date' => now()->toDateString(),
        'status' => 'scheduled',
        'current_seated_capacity' => 0,
        'current_standing_capacity' => 0,
        'total_occupancy' => 0,
    ]);

    $this->actingAs($operator)->patchJson("/api/operator/trips/{$trip->trip_id}/driver", [
        'driver_id' => $conductor->companyProfile->company_user_id,
    ])->assertStatus(422)
        ->assertJsonValidationErrors(['driver_id']);
});

// S2 (Batch 17): narrow double-booking guard — same staff member, same
// date+departure_time, two different trips. See TripRepository::
// hasConflictingAssignment() for why this doesn't attempt full range
// overlap (schema has no trip end/arrival time, and the buffer-window
// business decision is still pending).
test('assigning a driver already booked at the same date and departure time is rejected', function () {
    $operator = makeStaffUser('operator');
    $driver = makeStaffUser('driver');
    $fleetRoute = makeFleetRoute();
    $tripDate = now()->toDateString();

    $tripA = Trip::create([
        'fleet_route_id' => $fleetRoute->fleet_route_id,
        'company_user_id' => $operator->companyProfile->company_user_id,
        'trip_date' => $tripDate,
        'departure_time' => '08:00:00',
        'status' => 'scheduled',
        'driver_id' => $driver->companyProfile->company_user_id,
        'current_seated_capacity' => 0,
        'current_standing_capacity' => 0,
        'total_occupancy' => 0,
    ]);

    $tripB = Trip::create([
        'fleet_route_id' => $fleetRoute->fleet_route_id,
        'company_user_id' => $operator->companyProfile->company_user_id,
        'trip_date' => $tripDate,
        'departure_time' => '08:00:00',
        'status' => 'scheduled',
        'current_seated_capacity' => 0,
        'current_standing_capacity' => 0,
        'total_occupancy' => 0,
    ]);

    $this->actingAs($operator)->patchJson("/api/operator/trips/{$tripB->trip_id}/driver", [
        'driver_id' => $driver->companyProfile->company_user_id,
    ])->assertStatus(422)
        ->assertJsonValidationErrors(['driver_id']);

    $this->assertDatabaseMissing('trips', [
        'trip_id' => $tripB->trip_id,
        'driver_id' => $driver->companyProfile->company_user_id,
    ]);

    expect($tripA->trip_id)->not->toBe($tripB->trip_id);
});

// S2 corrected model (Batch 17 continuation): supersedes the earlier
// "different departure time is allowed" test — the reporter's corrected
// spec is hard one-trip-per-day exclusivity, regardless of time, not a
// time-overlap check. A second, separate trip assignment on the same date
// is always rejected now (the only sanctioned same-day multi-assignment
// path is a driver hand-off SEGMENT of the SAME trip — see
// TripDriverSegmentTest.php).
test('assigning a driver to a second separate trip on the same date is rejected even at a different departure time', function () {
    $operator = makeStaffUser('operator');
    $driver = makeStaffUser('driver');
    $fleetRoute = makeFleetRoute();
    $tripDate = now()->toDateString();

    Trip::create([
        'fleet_route_id' => $fleetRoute->fleet_route_id,
        'company_user_id' => $operator->companyProfile->company_user_id,
        'trip_date' => $tripDate,
        'departure_time' => '08:00:00',
        'status' => 'scheduled',
        'driver_id' => $driver->companyProfile->company_user_id,
        'current_seated_capacity' => 0,
        'current_standing_capacity' => 0,
        'total_occupancy' => 0,
    ]);

    $tripB = Trip::create([
        'fleet_route_id' => $fleetRoute->fleet_route_id,
        'company_user_id' => $operator->companyProfile->company_user_id,
        'trip_date' => $tripDate,
        'departure_time' => '18:00:00',
        'status' => 'scheduled',
        'current_seated_capacity' => 0,
        'current_standing_capacity' => 0,
        'total_occupancy' => 0,
    ]);

    $this->actingAs($operator)->patchJson("/api/operator/trips/{$tripB->trip_id}/driver", [
        'driver_id' => $driver->companyProfile->company_user_id,
    ])->assertStatus(422)
        ->assertJsonValidationErrors(['driver_id']);

    $this->assertDatabaseMissing('trips', [
        'trip_id' => $tripB->trip_id,
        'driver_id' => $driver->companyProfile->company_user_id,
    ]);
});

// ─── Assign conductor ─────────────────────────────────────────────────────────

test('operator can assign a conductor to a trip', function () {
    $operator = makeStaffUser('operator');
    $conductor = makeStaffUser('conductor');
    $fleetRoute = makeFleetRoute();

    $trip = Trip::create([
        'fleet_route_id' => $fleetRoute->fleet_route_id,
        'company_user_id' => $operator->companyProfile->company_user_id,
        'trip_date' => now()->toDateString(),
        'status' => 'scheduled',
        'current_seated_capacity' => 0,
        'current_standing_capacity' => 0,
        'total_occupancy' => 0,
    ]);

    $response = $this->actingAs($operator)->patchJson("/api/operator/trips/{$trip->trip_id}/conductor", [
        'conductor_id' => $conductor->companyProfile->company_user_id,
    ]);

    $response->assertSuccessful()
        ->assertJsonPath('message', 'Conductor assigned successfully');

    $this->assertDatabaseHas('trips', [
        'trip_id' => $trip->trip_id,
        'conductor_id' => $conductor->companyProfile->company_user_id,
    ]);
});

// ─── Trip status transitions ──────────────────────────────────────────────────

test('operator can start boarding', function () {
    $operator = makeStaffUser('operator');
    $fleetRoute = makeFleetRoute();

    $trip = Trip::create([
        'fleet_route_id' => $fleetRoute->fleet_route_id,
        'company_user_id' => $operator->companyProfile->company_user_id,
        'trip_date' => now()->toDateString(),
        'status' => 'scheduled',
        'current_seated_capacity' => 0,
        'current_standing_capacity' => 0,
        'total_occupancy' => 0,
    ]);

    $this->actingAs($operator)->patchJson("/api/operator/trips/{$trip->trip_id}/boarding")
        ->assertSuccessful()
        ->assertJsonPath('message', 'Boarding started');

    $this->assertDatabaseHas('trips', ['trip_id' => $trip->trip_id, 'status' => 'boarding']);
});

test('operator can persist a reroute dispatch decision on a trip', function () {
    $operator = makeStaffUser('operator');
    $fleetRoute = makeFleetRoute();

    $trip = Trip::create([
        'fleet_route_id' => $fleetRoute->fleet_route_id,
        'company_user_id' => $operator->companyProfile->company_user_id,
        'trip_date' => now()->toDateString(),
        'status' => 'scheduled',
        'current_seated_capacity' => 0,
        'current_standing_capacity' => 0,
        'total_occupancy' => 0,
    ]);

    $response = $this->actingAs($operator)->patchJson("/api/operator/trips/{$trip->trip_id}/dispatch-decision", [
        'decision' => 'accept',
        'route' => 'Route A via North Bypass',
        'reason' => 'High congestion on the assigned route.',
    ]);

    $response->assertSuccessful()
        ->assertJsonPath('message', 'Dispatch decision saved successfully');

    $this->assertDatabaseHas('trips', [
        'trip_id' => $trip->trip_id,
        'dispatch_decision' => 'accept',
        'dispatch_route' => 'Route A via North Bypass',
    ]);
});

test('driver can depart a trip', function () {
    $driver = makeStaffUser('driver');
    $conductor = makeStaffUser('conductor');
    $operator = makeStaffUser('operator');
    $fleetRoute = makeFleetRoute();

    $trip = Trip::create([
        'fleet_route_id' => $fleetRoute->fleet_route_id,
        'company_user_id' => $operator->companyProfile->company_user_id,
        'driver_id' => $driver->companyProfile->company_user_id,
        'conductor_id' => $conductor->companyProfile->company_user_id,
        'trip_date' => now()->toDateString(),
        'driver_accepted_at' => now(), // Batch 29: accepted
        'status' => 'boarding',
        'current_seated_capacity' => 0,
        'current_standing_capacity' => 0,
        'total_occupancy' => 0,
    ]);

    FleetDailyPin::create([
        'trip_id' => $trip->trip_id,
        'driver_id' => $driver->companyProfile->company_user_id,
        'conductor_id' => $conductor->companyProfile->company_user_id,
        'pin_code' => '123456',
        'pin_date' => now()->toDateString(),
        'driver_verified_at' => now(),
        'conductor_verified_at' => now(),
    ]);

    $this->actingAs($driver)->postJson('/api/driver/shift/start')->assertSuccessful();

    $this->actingAs($driver)->patchJson("/api/driver/trips/{$trip->trip_id}/depart")
        ->assertSuccessful()
        ->assertJsonPath('message', 'Trip departed');

    $this->assertDatabaseHas('trips', ['trip_id' => $trip->trip_id, 'status' => 'departed']);
});

test('driver cannot depart a trip without an active shift', function () {
    $driver = makeStaffUser('driver');
    $conductor = makeStaffUser('conductor');
    $operator = makeStaffUser('operator');
    $fleetRoute = makeFleetRoute();

    $trip = Trip::create([
        'fleet_route_id' => $fleetRoute->fleet_route_id,
        'company_user_id' => $operator->companyProfile->company_user_id,
        'driver_id' => $driver->companyProfile->company_user_id,
        'conductor_id' => $conductor->companyProfile->company_user_id,
        'trip_date' => now()->toDateString(),
        'status' => 'boarding',
        'current_seated_capacity' => 0,
        'current_standing_capacity' => 0,
        'total_occupancy' => 0,
    ]);

    FleetDailyPin::create([
        'trip_id' => $trip->trip_id,
        'driver_id' => $driver->companyProfile->company_user_id,
        'conductor_id' => $conductor->companyProfile->company_user_id,
        'pin_code' => '123456',
        'pin_date' => now()->toDateString(),
        'driver_verified_at' => now(),
        'conductor_verified_at' => now(),
    ]);

    $this->actingAs($driver)->patchJson("/api/driver/trips/{$trip->trip_id}/depart")
        ->assertStatus(422)
        ->assertJsonPath('message', 'Start your shift first.');

    $this->assertDatabaseHas('trips', ['trip_id' => $trip->trip_id, 'status' => 'boarding']);
});

test('driver cannot depart before pairing is completed', function () {
    $driver = makeStaffUser('driver');
    $conductor = makeStaffUser('conductor');
    $operator = makeStaffUser('operator');
    $fleetRoute = makeFleetRoute();

    $trip = Trip::create([
        'fleet_route_id' => $fleetRoute->fleet_route_id,
        'company_user_id' => $operator->companyProfile->company_user_id,
        'driver_id' => $driver->companyProfile->company_user_id,
        'conductor_id' => $conductor->companyProfile->company_user_id,
        'trip_date' => now()->toDateString(),
        'status' => 'boarding',
        'current_seated_capacity' => 0,
        'current_standing_capacity' => 0,
        'total_occupancy' => 0,
    ]);

    FleetDailyPin::create([
        'trip_id' => $trip->trip_id,
        'driver_id' => $driver->companyProfile->company_user_id,
        'conductor_id' => $conductor->companyProfile->company_user_id,
        'pin_code' => '123456',
        'pin_date' => now()->toDateString(),
    ]);

    $this->actingAs($driver)->patchJson("/api/driver/trips/{$trip->trip_id}/depart")
        ->assertStatus(423);

    $this->assertDatabaseHas('trips', ['trip_id' => $trip->trip_id, 'status' => 'boarding']);
});

test('driver can complete a trip', function () {
    $driver = makeStaffUser('driver');
    $operator = makeStaffUser('operator');
    $fleetRoute = makeFleetRoute();

    $trip = Trip::create([
        'fleet_route_id' => $fleetRoute->fleet_route_id,
        'company_user_id' => $operator->companyProfile->company_user_id,
        'driver_id' => $driver->companyProfile->company_user_id,
        'trip_date' => now()->toDateString(),
        'status' => 'departed',
        'current_seated_capacity' => 0,
        'current_standing_capacity' => 0,
        'total_occupancy' => 0,
    ]);

    $this->actingAs($driver)->patchJson("/api/driver/trips/{$trip->trip_id}/complete")
        ->assertSuccessful()
        ->assertJsonPath('message', 'Trip completed');

    $this->assertDatabaseHas('trips', ['trip_id' => $trip->trip_id, 'status' => 'completed']);
});

// ─── Manual status override (S1) ─────────────────────────────────────────────

test('operator can mark an already-departed trip as completed via status override', function () {
    $operator = makeStaffUser('operator');
    $fleetRoute = makeFleetRoute();

    $trip = Trip::create([
        'fleet_route_id' => $fleetRoute->fleet_route_id,
        'company_user_id' => $operator->companyProfile->company_user_id,
        'trip_date' => now()->toDateString(),
        'status' => 'departed',
        'current_seated_capacity' => 0,
        'current_standing_capacity' => 0,
        'total_occupancy' => 0,
    ]);

    $this->actingAs($operator)->patchJson("/api/operator/trips/{$trip->trip_id}/status", [
        'status' => 'completed',
    ])->assertSuccessful()
        ->assertJsonPath('message', 'Trip status updated');

    $this->assertDatabaseHas('trips', ['trip_id' => $trip->trip_id, 'status' => 'completed']);
    $this->assertDatabaseHas('audit_logs', ['action' => 'trip.status_override']);
});

test('operator cannot override status of a trip that never departed', function () {
    $operator = makeStaffUser('operator');
    $fleetRoute = makeFleetRoute();

    $trip = Trip::create([
        'fleet_route_id' => $fleetRoute->fleet_route_id,
        'company_user_id' => $operator->companyProfile->company_user_id,
        'trip_date' => now()->subDay()->toDateString(),
        'status' => 'scheduled',
        'current_seated_capacity' => 0,
        'current_standing_capacity' => 0,
        'total_occupancy' => 0,
    ]);

    $this->actingAs($operator)->patchJson("/api/operator/trips/{$trip->trip_id}/status", [
        'status' => 'completed',
    ])->assertStatus(422)
        ->assertJsonValidationErrors(['status']);

    $this->assertDatabaseHas('trips', ['trip_id' => $trip->trip_id, 'status' => 'scheduled']);
});

test('operator cannot override a trip to cancelled', function () {
    $operator = makeStaffUser('operator');
    $fleetRoute = makeFleetRoute();

    $trip = Trip::create([
        'fleet_route_id' => $fleetRoute->fleet_route_id,
        'company_user_id' => $operator->companyProfile->company_user_id,
        'trip_date' => now()->toDateString(),
        'status' => 'departed',
        'current_seated_capacity' => 0,
        'current_standing_capacity' => 0,
        'total_occupancy' => 0,
    ]);

    $this->actingAs($operator)->patchJson("/api/operator/trips/{$trip->trip_id}/status", [
        'status' => 'cancelled',
    ])->assertStatus(422)
        ->assertJsonValidationErrors(['status']);
});

test('operator cannot override status of a trip belonging to another operator', function () {
    $operator = makeStaffUser('operator');
    $otherOperator = makeStaffUser('operator');
    $fleetRoute = makeFleetRoute();

    $trip = Trip::create([
        'fleet_route_id' => $fleetRoute->fleet_route_id,
        'company_user_id' => $otherOperator->companyProfile->company_user_id,
        'trip_date' => now()->toDateString(),
        'status' => 'departed',
        'current_seated_capacity' => 0,
        'current_standing_capacity' => 0,
        'total_occupancy' => 0,
    ]);

    $this->actingAs($operator)->patchJson("/api/operator/trips/{$trip->trip_id}/status", [
        'status' => 'completed',
    ])->assertStatus(403);
});

test('admin can force any status override on a trip that never departed', function () {
    $admin = makeStaffUser('admin');
    $operator = makeStaffUser('operator');
    $fleetRoute = makeFleetRoute();

    $trip = Trip::create([
        'fleet_route_id' => $fleetRoute->fleet_route_id,
        'company_user_id' => $operator->companyProfile->company_user_id,
        'trip_date' => now()->subDay()->toDateString(),
        'status' => 'scheduled',
        'current_seated_capacity' => 0,
        'current_standing_capacity' => 0,
        'total_occupancy' => 0,
    ]);

    $this->actingAs($admin)->patchJson("/api/admin/trips/{$trip->trip_id}/status", [
        'status' => 'cancelled',
    ])->assertSuccessful();

    $this->assertDatabaseHas('trips', ['trip_id' => $trip->trip_id, 'status' => 'cancelled']);
    $this->assertDatabaseHas('audit_logs', [
        'action' => 'trip.status_override',
    ]);
});

test('status override rejects an invalid status value', function () {
    $operator = makeStaffUser('operator');
    $fleetRoute = makeFleetRoute();

    $trip = Trip::create([
        'fleet_route_id' => $fleetRoute->fleet_route_id,
        'company_user_id' => $operator->companyProfile->company_user_id,
        'trip_date' => now()->toDateString(),
        'status' => 'departed',
        'current_seated_capacity' => 0,
        'current_standing_capacity' => 0,
        'total_occupancy' => 0,
    ]);

    $this->actingAs($operator)->patchJson("/api/operator/trips/{$trip->trip_id}/status", [
        'status' => 'not-a-real-status',
    ])->assertStatus(422)
        ->assertJsonValidationErrors(['status']);
});

test('operator trips endpoint supports completed and scheduled-active filters', function () {
    $operator = makeStaffUser('operator');
    $fleetRoute = makeFleetRoute();

    $completedTrip = Trip::create([
        'fleet_route_id' => $fleetRoute->fleet_route_id,
        'company_user_id' => $operator->companyProfile->company_user_id,
        'trip_date' => now()->subDay()->toDateString(),
        'departure_time' => '08:00',
        'status' => 'completed',
        'current_seated_capacity' => 0,
        'current_standing_capacity' => 0,
        'total_occupancy' => 0,
    ]);

    $scheduledTrip = Trip::create([
        'fleet_route_id' => $fleetRoute->fleet_route_id,
        'company_user_id' => $operator->companyProfile->company_user_id,
        'trip_date' => now()->toDateString(),
        'departure_time' => '08:00',
        'status' => 'scheduled',
        'current_seated_capacity' => 0,
        'current_standing_capacity' => 0,
        'total_occupancy' => 0,
    ]);

    $this->actingAs($operator)
        ->getJson('/api/operator/trips?status_filter=completed')
        ->assertSuccessful()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.trip_id', $completedTrip->trip_id);

    $this->actingAs($operator)
        ->getJson('/api/operator/trips?status_filter=scheduled')
        ->assertSuccessful()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.trip_id', $scheduledTrip->trip_id);

    $this->actingAs($operator)
        ->getJson('/api/operator/trips?status_filter=all')
        ->assertSuccessful()
        ->assertJsonCount(2, 'data');
});

test('driver trips endpoint returns completed trips when requested', function () {
    $driver = makeStaffUser('driver');
    $conductor = makeStaffUser('conductor');
    $operator = makeStaffUser('operator');
    $fleetRoute = makeFleetRoute();

    $completedTrip = Trip::create([
        'fleet_route_id' => $fleetRoute->fleet_route_id,
        'company_user_id' => $operator->companyProfile->company_user_id,
        'driver_id' => $driver->companyProfile->company_user_id,
        'conductor_id' => $conductor->companyProfile->company_user_id,
        'trip_date' => now()->subDay()->toDateString(),
        'departure_time' => '08:00',
        'status' => 'completed',
        'current_seated_capacity' => 0,
        'current_standing_capacity' => 0,
        'total_occupancy' => 0,
    ]);

    Trip::create([
        'fleet_route_id' => $fleetRoute->fleet_route_id,
        'company_user_id' => $operator->companyProfile->company_user_id,
        'driver_id' => $driver->companyProfile->company_user_id,
        'conductor_id' => $conductor->companyProfile->company_user_id,
        'trip_date' => now()->toDateString(),
        'departure_time' => '08:00',
        'status' => 'scheduled',
        'current_seated_capacity' => 0,
        'current_standing_capacity' => 0,
        'total_occupancy' => 0,
    ]);

    $this->actingAs($driver)
        ->getJson('/api/driver/trips?status_filter=completed')
        ->assertSuccessful()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.trip_id', $completedTrip->trip_id);
});

test('public available trips can include same-day scheduled trip by requested date even after departure time', function () {
    $fleetRoute = makeFleetRoute();
    $operator = makeStaffUser('operator');

    $trip = Trip::create([
        'fleet_route_id' => $fleetRoute->fleet_route_id,
        'company_user_id' => $operator->companyProfile->company_user_id,
        'trip_date' => now()->toDateString(),
        'departure_time' => '00:15',
        'status' => 'scheduled',
        'current_seated_capacity' => 0,
        'current_standing_capacity' => 0,
        'total_occupancy' => 0,
    ]);

    $this->getJson('/api/trips?trip_date='.now()->toDateString())
        ->assertSuccessful()
        ->assertJsonFragment(['trip_id' => $trip->trip_id]);
});

test('trip_date serializes as a plain calendar date, not shifted by UTC conversion', function () {
    // Regression test: Trip::trip_date is cast as a pure date (no time-of-day
    // meaning). The old bare 'date' cast serialized it via Carbon's UTC-based
    // toJSON(), which shifted an Asia/Manila midnight backward by 8 hours —
    // rendering as the PREVIOUS calendar day once the date-only prefix was
    // read on the frontend. 'date:Y-m-d' must serialize as a plain,
    // timezone-agnostic "YYYY-MM-DD" string that exactly matches storage.
    $fleetRoute = makeFleetRoute();
    $operator = makeStaffUser('operator');
    $futureDate = now()->addDays(5)->toDateString();

    $trip = Trip::create([
        'fleet_route_id' => $fleetRoute->fleet_route_id,
        'company_user_id' => $operator->companyProfile->company_user_id,
        'trip_date' => $futureDate,
        'status' => 'scheduled',
        'current_seated_capacity' => 0,
        'current_standing_capacity' => 0,
        'total_occupancy' => 0,
    ]);

    $this->getJson('/api/trips?trip_date='.$futureDate)
        ->assertSuccessful()
        ->assertJsonPath('data.0.trip_date', $futureDate);

    expect($trip->fresh()->getRawOriginal('trip_date'))->toBe($futureDate);
});

test('operator upcoming trips excludes trips whose scheduled datetime has already passed', function () {
    // Frozen mid-day Manila time: the scheduled-datetime comparison is anchored to Asia/Manila, so a real
    // clock near midnight (or a different server timezone) must not decide whether a trip has passed.
    $this->travelTo(\Illuminate\Support\Carbon::parse('2026-10-01 10:00:00', 'Asia/Manila'));

    $operator = makeStaffUser('operator');
    $fleetRoute = makeFleetRoute();

    $pastTrip = Trip::create([
        'fleet_route_id' => $fleetRoute->fleet_route_id,
        'company_user_id' => $operator->companyProfile->company_user_id,
        'trip_date' => '2026-10-01',
        'departure_time' => '09:55:00',
        'status' => 'scheduled',
        'current_seated_capacity' => 0,
        'current_standing_capacity' => 0,
        'total_occupancy' => 0,
    ]);

    $futureTrip = Trip::create([
        'fleet_route_id' => $fleetRoute->fleet_route_id,
        'company_user_id' => $operator->companyProfile->company_user_id,
        'trip_date' => '2026-10-01',
        'departure_time' => '10:30:00',
        'status' => 'scheduled',
        'current_seated_capacity' => 0,
        'current_standing_capacity' => 0,
        'total_occupancy' => 0,
    ]);

    $this->actingAs($operator)
        ->getJson('/api/operator/trips')
        ->assertSuccessful()
        ->assertJsonPath('data.0.trip_id', $futureTrip->trip_id)
        ->assertJsonMissing(['trip_id' => $pastTrip->trip_id]);
});

// ─── companyProfile relationship ──────────────────────────────────────────────

test('staff user has a companyProfile relationship', function () {
    $driver = makeStaffUser('driver');

    expect($driver->companyProfile)->not->toBeNull()
        ->and($driver->companyProfile->company_user_id)->toBeInt();
});

// ─── conductor_id fillable fix ────────────────────────────────────────────────

test('conductor_id is mass-assignable on Trip', function () {
    $operator = makeStaffUser('operator');
    $conductor = makeStaffUser('conductor');
    $fleetRoute = makeFleetRoute();

    $trip = Trip::create([
        'fleet_route_id' => $fleetRoute->fleet_route_id,
        'company_user_id' => $operator->companyProfile->company_user_id,
        'conductor_id' => $conductor->companyProfile->company_user_id,
        'trip_date' => now()->toDateString(),
        'status' => 'scheduled',
        'current_seated_capacity' => 0,
        'current_standing_capacity' => 0,
        'total_occupancy' => 0,
    ]);

    $this->assertDatabaseHas('trips', [
        'trip_id' => $trip->trip_id,
        'conductor_id' => $conductor->companyProfile->company_user_id,
    ]);
});

// ─── S2 (Batch 12): decline assigned trip ────────────────────────────────────

test('driver decline goes For Approval: the assignment stays until the operator decides', function () {
    $operator = makeStaffUser('operator');
    $driver = makeStaffUser('driver');
    $conductor = makeStaffUser('conductor');
    $fleetRoute = makeFleetRoute();

    $trip = Trip::create([
        'fleet_route_id' => $fleetRoute->fleet_route_id,
        'company_user_id' => $operator->companyProfile->company_user_id,
        'driver_id' => $driver->companyProfile->company_user_id,
        'conductor_id' => $conductor->companyProfile->company_user_id,
        'trip_date' => now()->toDateString(),
        'status' => 'scheduled',
        'current_seated_capacity' => 0,
        'current_standing_capacity' => 0,
        'total_occupancy' => 0,
    ]);

    \App\Models\FleetDailyPin::create([
        'trip_id' => $trip->trip_id,
        'driver_id' => $driver->companyProfile->company_user_id,
        'conductor_id' => $conductor->companyProfile->company_user_id,
        'pin_code' => '123456',
        'pin_date' => now()->toDateString(),
    ]);

    $this->actingAs($driver)->patchJson("/api/driver/trips/{$trip->trip_id}/decline", [
        'reason_code' => 'sick',
    ])->assertSuccessful()
        ->assertJsonPath('message', 'Request sent. It is now waiting for Operator approval.')
        ->assertJsonPath('data.driver_request_status', 'for_approval');

    // Assignment-level status only (D6): the driver, PIN and trip status are untouched.
    $this->assertDatabaseHas('trips', [
        'trip_id' => $trip->trip_id,
        'driver_id' => $driver->companyProfile->company_user_id,
        'conductor_id' => $conductor->companyProfile->company_user_id,
        'status' => 'scheduled',
    ]);
    $this->assertDatabaseHas('fleet_daily_pins', ['trip_id' => $trip->trip_id]);
    $this->assertDatabaseHas('trip_assignment_requests', [
        'trip_id' => $trip->trip_id,
        'role' => 'driver',
        'status' => 'for_approval',
        'reason_code' => 'sick',
    ]);
    $this->assertDatabaseHas('audit_logs', ['action' => 'trip.decline_requested']);
    $this->assertDatabaseHas('trip_notifications', [
        'trip_id' => $trip->trip_id,
        'type' => 'trip_decline_requested',
    ]);
});

test('conductor can request to decline their assigned trip', function () {
    $operator = makeStaffUser('operator');
    $driver = makeStaffUser('driver');
    $conductor = makeStaffUser('conductor');
    $fleetRoute = makeFleetRoute();

    $trip = Trip::create([
        'fleet_route_id' => $fleetRoute->fleet_route_id,
        'company_user_id' => $operator->companyProfile->company_user_id,
        'driver_id' => $driver->companyProfile->company_user_id,
        'conductor_id' => $conductor->companyProfile->company_user_id,
        'trip_date' => now()->toDateString(),
        'status' => 'delayed',
        'current_seated_capacity' => 0,
        'current_standing_capacity' => 0,
        'total_occupancy' => 0,
    ]);

    $this->actingAs($conductor)->patchJson("/api/conductor/trips/{$trip->trip_id}/decline")
        ->assertSuccessful()
        ->assertJsonPath('data.conductor_request_status', 'for_approval');

    $this->assertDatabaseHas('trips', [
        'trip_id' => $trip->trip_id,
        'conductor_id' => $conductor->companyProfile->company_user_id,
        'driver_id' => $driver->companyProfile->company_user_id,
    ]);
});

test('a staff member who is not the assigned driver cannot decline the trip', function () {
    $operator = makeStaffUser('operator');
    $driver = makeStaffUser('driver');
    $otherDriver = makeStaffUser('driver');
    $fleetRoute = makeFleetRoute();

    $trip = Trip::create([
        'fleet_route_id' => $fleetRoute->fleet_route_id,
        'company_user_id' => $operator->companyProfile->company_user_id,
        'driver_id' => $driver->companyProfile->company_user_id,
        'trip_date' => now()->toDateString(),
        'status' => 'scheduled',
        'current_seated_capacity' => 0,
        'current_standing_capacity' => 0,
        'total_occupancy' => 0,
    ]);

    $this->actingAs($otherDriver)->patchJson("/api/driver/trips/{$trip->trip_id}/decline")
        ->assertStatus(422);

    $this->assertDatabaseHas('trips', [
        'trip_id' => $trip->trip_id,
        'driver_id' => $driver->companyProfile->company_user_id,
    ]);
});

test('a trip that has already departed can no longer be declined', function () {
    $operator = makeStaffUser('operator');
    $driver = makeStaffUser('driver');
    $fleetRoute = makeFleetRoute();

    $trip = Trip::create([
        'fleet_route_id' => $fleetRoute->fleet_route_id,
        'company_user_id' => $operator->companyProfile->company_user_id,
        'driver_id' => $driver->companyProfile->company_user_id,
        'trip_date' => now()->toDateString(),
        'status' => 'departed',
        'current_seated_capacity' => 0,
        'current_standing_capacity' => 0,
        'total_occupancy' => 0,
    ]);

    $this->actingAs($driver)->patchJson("/api/driver/trips/{$trip->trip_id}/decline")
        ->assertStatus(422);

    $this->assertDatabaseHas('trips', ['trip_id' => $trip->trip_id, 'status' => 'departed']);
});

test('operator can list and mark trip decline notifications as read', function () {
    $operator = makeStaffUser('operator');
    $driver = makeStaffUser('driver');
    $fleetRoute = makeFleetRoute();

    $trip = Trip::create([
        'fleet_route_id' => $fleetRoute->fleet_route_id,
        'company_user_id' => $operator->companyProfile->company_user_id,
        'driver_id' => $driver->companyProfile->company_user_id,
        'trip_date' => now()->toDateString(),
        'status' => 'scheduled',
        'current_seated_capacity' => 0,
        'current_standing_capacity' => 0,
        'total_occupancy' => 0,
    ]);

    $this->actingAs($driver)->patchJson("/api/driver/trips/{$trip->trip_id}/decline")
        ->assertSuccessful();

    $response = $this->actingAs($operator)->getJson('/api/operator/notifications')
        ->assertSuccessful()
        ->assertJsonPath('data.unread_count', 1);

    $notificationId = $response->json('data.notifications.0.id');

    $this->actingAs($operator)->patchJson("/api/operator/notifications/{$notificationId}/read")
        ->assertSuccessful();

    $this->actingAs($operator)->getJson('/api/operator/notifications')
        ->assertSuccessful()
        ->assertJsonPath('data.unread_count', 0);
});
