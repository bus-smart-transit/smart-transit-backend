<?php

use App\Models\Fleet;
use App\Models\FleetRoute;
use App\Models\Route;
use App\Models\StaffUser;
use App\Models\Trip;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

function makeStaff(string $role): User
{
    $user = User::create([
        'username' => $role.'_'.uniqid(),
        'email' => $role.'+'.uniqid().'@smarttransit.test',
        'password' => bcrypt('password123'),
        'role' => $role,
    ]);

    StaffUser::create([
        'company_user_uuid' => (string) Str::uuid(),
        'user_id' => $user->user_id,
        'name' => Str::title($role).' User',
        'phone_num' => '09170000000',
        'address' => 'Test Address',
    ]);

    return $user->fresh();
}

function makeOwnedFleet(User $operator): Fleet
{
    return Fleet::create([
        'company_user_id' => $operator->companyProfile->company_user_id,
        'plate_number' => 'SEC-'.strtoupper(substr((string) Str::uuid(), 0, 6)),
        'capacity' => 40,
        'seated_capacity' => 30,
        'standing_capacity' => 10,
        'status' => 'active',
        'fleet_type' => 'public',
    ]);
}

function makeFleetRouteForOperator(User $operator): FleetRoute
{
    $route = Route::create([
        'route_name' => 'Security Test Route',
        'origin' => 'Origin A',
        'destination' => 'Destination B',
    ]);

    $fleet = makeOwnedFleet($operator);

    return FleetRoute::create([
        'fleet_id' => $fleet->fleet_id,
        'route_id' => $route->route_id,
        'start_time' => '06:00',
        'end_time' => '22:00',
        'status' => 'active',
    ]);
}

function issueStepUpTokenForUser(User $user, string $rawToken): void
{
    DB::table('step_up_tokens')->insert([
        'guard' => 'sanctum',
        'user_id' => $user->user_id,
        'token_hash' => hash('sha256', $rawToken),
        'expires_at' => now()->addMinutes(15),
        'created_at' => now(),
    ]);
}

it('rejects invalid bearer token on optional auth routes', function () {
    $response = $this
        ->withHeader('Authorization', 'Bearer not-a-real-token')
        ->postJson('/api/checkout', []);

    $response
        ->assertStatus(401)
        ->assertJsonPath('message', 'Invalid or expired authentication token.');
});

it('still allows true guests on optional auth routes', function () {
    $response = $this->postJson('/api/checkout', []);

    $response->assertStatus(422);
});

it('rejects step-up token when token user does not match authenticated user', function () {
    $operatorA = makeStaff('operator');
    $operatorB = makeStaff('operator');
    $fleetA = makeOwnedFleet($operatorA);

    $rawStepUpToken = bin2hex(random_bytes(32));
    issueStepUpTokenForUser($operatorB, $rawStepUpToken);

    $payload = [
        'fleet_id' => $fleetA->fleet_id,
        'base_fare' => 12,
        'fare_per_km' => 2,
        'seat_type' => 'seated',
    ];

    $response = $this
        ->actingAs($operatorA)
        ->withHeader('X-Step-Up-Token', $rawStepUpToken)
        ->postJson('/api/operator/fare-rules', $payload);

    $response
        ->assertStatus(403)
        ->assertJsonPath('message', 'Step-up token does not match the authenticated user.');
});

it('prevents operator from assigning driver on another operators trip', function () {
    $operatorA = makeStaff('operator');
    $operatorB = makeStaff('operator');
    $driver = makeStaff('driver');

    $fleetRouteOwnedByB = makeFleetRouteForOperator($operatorB);

    $tripOwnedByB = Trip::create([
        'fleet_route_id' => $fleetRouteOwnedByB->fleet_route_id,
        'company_user_id' => $operatorB->companyProfile->company_user_id,
        'trip_date' => now()->toDateString(),
        'status' => 'scheduled',
        'current_seated_capacity' => 0,
        'current_standing_capacity' => 0,
        'total_occupancy' => 0,
    ]);

    $response = $this
        ->actingAs($operatorA)
        ->patchJson('/api/operator/trips/'.$tripOwnedByB->trip_id.'/driver', [
            'driver_id' => $driver->companyProfile->company_user_id,
        ]);

    $response
        ->assertStatus(403)
        ->assertJsonPath('message', 'You do not have permission to modify this trip.');
});

it('prevents operator from creating fare rules on another operators fleet', function () {
    $operatorA = makeStaff('operator');
    $operatorB = makeStaff('operator');

    $fleetOwnedByB = makeOwnedFleet($operatorB);

    $rawStepUpToken = bin2hex(random_bytes(32));
    issueStepUpTokenForUser($operatorA, $rawStepUpToken);

    $response = $this
        ->actingAs($operatorA)
        ->withHeader('X-Step-Up-Token', $rawStepUpToken)
        ->postJson('/api/operator/fare-rules', [
            'fleet_id' => $fleetOwnedByB->fleet_id,
            'base_fare' => 11,
            'fare_per_km' => 1.75,
            'seat_type' => 'seated',
        ]);

    $response
        ->assertStatus(403)
        ->assertJsonPath('message', 'You do not have permission to manage fare rules for this fleet.');
});

it('prevents operator from viewing gps history on another operators trip', function () {
    $operatorA = makeStaff('operator');
    $operatorB = makeStaff('operator');

    $fleetRouteOwnedByB = makeFleetRouteForOperator($operatorB);

    $tripOwnedByB = Trip::create([
        'fleet_route_id' => $fleetRouteOwnedByB->fleet_route_id,
        'company_user_id' => $operatorB->companyProfile->company_user_id,
        'trip_date' => now()->toDateString(),
        'status' => 'scheduled',
        'current_seated_capacity' => 0,
        'current_standing_capacity' => 0,
        'total_occupancy' => 0,
    ]);

    $response = $this
        ->actingAs($operatorA)
        ->getJson('/api/operator/trips/'.$tripOwnedByB->trip_id.'/gps-history');

    $response
        ->assertStatus(403)
        ->assertJsonPath('message', 'You do not have permission to access GPS history for this trip.');
});
