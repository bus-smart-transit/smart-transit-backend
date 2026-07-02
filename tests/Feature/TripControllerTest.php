<?php

use App\Models\Fleet;
use App\Models\FleetRoute;
use App\Models\Route;
use App\Models\StaffUser;
use App\Models\Trip;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

// ─── helpers ────────────────────────────────────────────────────────────────

/**
 * Create a staff User with an associated StaffUser (companyProfile) record.
 */
function makeStaffUser(string $role): User
{
    $user = User::create([
        'username' => fake()->unique()->userName(),
        'email'    => fake()->unique()->safeEmail(),
        'password' => bcrypt('password'),
        'role'     => $role,
    ]);

    StaffUser::create([
        'user_id'           => $user->user_id,
        'company_user_uuid' => (string) \Illuminate\Support\Str::uuid(),
        'name'              => fake()->name(),
        'phone_num'         => fake()->phoneNumber(),
        'address'           => fake()->address(),
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
        'route_name'  => 'Test Route',
        'origin'      => 'Stop A',
        'destination' => 'Stop B',
    ]);
    $route->refresh();

    // Fleet requires a company_user_id FK
    $operatorForFleet = makeStaffUser('operator');

    $fleet = Fleet::create([
        'company_user_id'   => $operatorForFleet->companyProfile->company_user_id,
        'plate_number'      => 'ABC-' . fake()->unique()->numerify('###'),
        'capacity'          => 50,
        'seated_capacity'   => 40,
        'standing_capacity' => 10,
        'status'            => 'active',
    ]);
    $fleet->refresh();

    $fleetRoute = FleetRoute::create([
        'fleet_id'   => $fleet->fleet_id,
        'route_id'   => $route->route_id,
        'start_time' => '06:00',
        'end_time'   => '22:00',
        'status'     => 'active',
    ]);
    $fleetRoute->refresh();

    return $fleetRoute;
}

// ─── Trip scheduling (store) ─────────────────────────────────────────────────

test('operator can schedule a trip', function () {
    $operator   = makeStaffUser('operator');
    $fleetRoute = makeFleetRoute();

    $response = $this->actingAs($operator)->postJson('/api/operator/trips', [
        'fleet_route_id' => $fleetRoute->fleet_route_id,
        'trip_date'      => now()->toDateString(),
    ]);

    $response->assertSuccessful()
             ->assertJsonPath('status', 'success')
             ->assertJsonPath('message', 'Trip scheduled successfully');

    $this->assertDatabaseHas('trips', [
        'fleet_route_id' => $fleetRoute->fleet_route_id,
        'status'         => 'scheduled',
    ]);
});

test('store validates required fields', function () {
    $operator = makeStaffUser('operator');

    $this->actingAs($operator)->postJson('/api/operator/trips', [])
         ->assertStatus(422)
         ->assertJsonValidationErrors(['fleet_route_id', 'trip_date']);
});

// ─── Assign driver ────────────────────────────────────────────────────────────

test('operator can assign a driver to a trip', function () {
    $operator   = makeStaffUser('operator');
    $driver     = makeStaffUser('driver');
    $fleetRoute = makeFleetRoute();

    $trip = Trip::create([
        'fleet_route_id'            => $fleetRoute->fleet_route_id,
        'company_user_id'           => $operator->companyProfile->company_user_id,
        'trip_date'                 => now()->toDateString(),
        'status'                    => 'scheduled',
        'current_seated_capacity'   => 0,
        'current_standing_capacity' => 0,
        'total_occupancy'           => 0,
    ]);

    $response = $this->actingAs($operator)->patchJson("/api/operator/trips/{$trip->trip_id}/driver", [
        'driver_id' => $driver->companyProfile->company_user_id,
    ]);

    $response->assertSuccessful()
             ->assertJsonPath('message', 'Driver assigned successfully');

    $this->assertDatabaseHas('trips', [
        'trip_id'   => $trip->trip_id,
        'driver_id' => $driver->companyProfile->company_user_id,
    ]);
});

test('assigning a non-driver user as driver fails', function () {
    $operator   = makeStaffUser('operator');
    $conductor  = makeStaffUser('conductor');
    $fleetRoute = makeFleetRoute();

    $trip = Trip::create([
        'fleet_route_id'            => $fleetRoute->fleet_route_id,
        'company_user_id'           => $operator->companyProfile->company_user_id,
        'trip_date'                 => now()->toDateString(),
        'status'                    => 'scheduled',
        'current_seated_capacity'   => 0,
        'current_standing_capacity' => 0,
        'total_occupancy'           => 0,
    ]);

    $this->actingAs($operator)->patchJson("/api/operator/trips/{$trip->trip_id}/driver", [
        'driver_id' => $conductor->companyProfile->company_user_id,
    ])->assertStatus(422)
      ->assertJsonValidationErrors(['driver_id']);
});

// ─── Assign conductor ─────────────────────────────────────────────────────────

test('operator can assign a conductor to a trip', function () {
    $operator   = makeStaffUser('operator');
    $conductor  = makeStaffUser('conductor');
    $fleetRoute = makeFleetRoute();

    $trip = Trip::create([
        'fleet_route_id'            => $fleetRoute->fleet_route_id,
        'company_user_id'           => $operator->companyProfile->company_user_id,
        'trip_date'                 => now()->toDateString(),
        'status'                    => 'scheduled',
        'current_seated_capacity'   => 0,
        'current_standing_capacity' => 0,
        'total_occupancy'           => 0,
    ]);

    $response = $this->actingAs($operator)->patchJson("/api/operator/trips/{$trip->trip_id}/conductor", [
        'conductor_id' => $conductor->companyProfile->company_user_id,
    ]);

    $response->assertSuccessful()
             ->assertJsonPath('message', 'Conductor assigned successfully');

    $this->assertDatabaseHas('trips', [
        'trip_id'      => $trip->trip_id,
        'conductor_id' => $conductor->companyProfile->company_user_id,
    ]);
});

// ─── Trip status transitions ──────────────────────────────────────────────────

test('operator can start boarding', function () {
    $operator   = makeStaffUser('operator');
    $fleetRoute = makeFleetRoute();

    $trip = Trip::create([
        'fleet_route_id'            => $fleetRoute->fleet_route_id,
        'company_user_id'           => $operator->companyProfile->company_user_id,
        'trip_date'                 => now()->toDateString(),
        'status'                    => 'scheduled',
        'current_seated_capacity'   => 0,
        'current_standing_capacity' => 0,
        'total_occupancy'           => 0,
    ]);

    $this->actingAs($operator)->patchJson("/api/operator/trips/{$trip->trip_id}/boarding")
         ->assertSuccessful()
         ->assertJsonPath('message', 'Boarding started');

    $this->assertDatabaseHas('trips', ['trip_id' => $trip->trip_id, 'status' => 'boarding']);
});

test('driver can depart a trip', function () {
    $driver     = makeStaffUser('driver');
    $operator   = makeStaffUser('operator');
    $fleetRoute = makeFleetRoute();

    $trip = Trip::create([
        'fleet_route_id'            => $fleetRoute->fleet_route_id,
        'company_user_id'           => $operator->companyProfile->company_user_id,
        'driver_id'                 => $driver->companyProfile->company_user_id,
        'trip_date'                 => now()->toDateString(),
        'status'                    => 'boarding',
        'current_seated_capacity'   => 0,
        'current_standing_capacity' => 0,
        'total_occupancy'           => 0,
    ]);

    $this->actingAs($driver)->patchJson("/api/driver/trips/{$trip->trip_id}/depart")
         ->assertSuccessful()
         ->assertJsonPath('message', 'Trip departed');

    $this->assertDatabaseHas('trips', ['trip_id' => $trip->trip_id, 'status' => 'departed']);
});

test('driver can complete a trip', function () {
    $driver     = makeStaffUser('driver');
    $operator   = makeStaffUser('operator');
    $fleetRoute = makeFleetRoute();

    $trip = Trip::create([
        'fleet_route_id'            => $fleetRoute->fleet_route_id,
        'company_user_id'           => $operator->companyProfile->company_user_id,
        'driver_id'                 => $driver->companyProfile->company_user_id,
        'trip_date'                 => now()->toDateString(),
        'status'                    => 'departed',
        'current_seated_capacity'   => 0,
        'current_standing_capacity' => 0,
        'total_occupancy'           => 0,
    ]);

    $this->actingAs($driver)->patchJson("/api/driver/trips/{$trip->trip_id}/complete")
         ->assertSuccessful()
         ->assertJsonPath('message', 'Trip completed');

    $this->assertDatabaseHas('trips', ['trip_id' => $trip->trip_id, 'status' => 'completed']);
});

// ─── companyProfile relationship ──────────────────────────────────────────────

test('staff user has a companyProfile relationship', function () {
    $driver = makeStaffUser('driver');

    expect($driver->companyProfile)->not->toBeNull()
        ->and($driver->companyProfile->company_user_id)->toBeInt();
});

// ─── conductor_id fillable fix ────────────────────────────────────────────────

test('conductor_id is mass-assignable on Trip', function () {
    $operator   = makeStaffUser('operator');
    $conductor  = makeStaffUser('conductor');
    $fleetRoute = makeFleetRoute();

    $trip = Trip::create([
        'fleet_route_id'            => $fleetRoute->fleet_route_id,
        'company_user_id'           => $operator->companyProfile->company_user_id,
        'conductor_id'              => $conductor->companyProfile->company_user_id,
        'trip_date'                 => now()->toDateString(),
        'status'                    => 'scheduled',
        'current_seated_capacity'   => 0,
        'current_standing_capacity' => 0,
        'total_occupancy'           => 0,
    ]);

    $this->assertDatabaseHas('trips', [
        'trip_id'      => $trip->trip_id,
        'conductor_id' => $conductor->companyProfile->company_user_id,
    ]);
});
