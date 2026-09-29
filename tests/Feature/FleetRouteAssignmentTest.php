<?php

use App\Models\Fleet;
use App\Models\Route;
use App\Models\StaffUser;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

function makeOperatorForFleetRouteTest(): User
{
    $user = User::create([
        'username' => 'operator_' . Str::random(6),
        'email' => 'operator_' . Str::random(6) . '@example.com',
        'password' => bcrypt('password'),
        'role' => 'operator',
    ]);

    StaffUser::create([
        'user_id' => $user->user_id,
        'company_user_uuid' => (string) Str::uuid(),
        'name' => 'Operator Test',
        'phone_num' => '09123456789',
        'address' => 'Davao City',
    ]);

    return $user->fresh();
}

it('assigns a route to a fleet with required operating window', function () {
    $operator = makeOperatorForFleetRouteTest();

    $fleet = Fleet::create([
        'company_user_id' => $operator->companyProfile->company_user_id,
        'plate_number' => 'B3-101',
        'capacity' => 50,
        'seated_capacity' => 40,
        'standing_capacity' => 10,
        'fleet_type' => 'public',
        'status' => 'active',
    ]);

    $route = Route::create([
        'origin' => 'Davao',
        'destination' => 'Tagum',
        'route_name' => 'Davao - Tagum',
    ]);

    $this->actingAs($operator)
        ->postJson("/api/operator/fleets/{$fleet->fleet_id}/routes", [
            'route_id' => $route->route_id,
            'start_time' => '06:00',
            'end_time' => '22:00',
        ])
        ->assertSuccessful()
        ->assertJsonPath('status', 'success');

    $this->assertDatabaseHas('fleets_routes', [
        'fleet_id' => $fleet->fleet_id,
        'route_id' => $route->route_id,
        'start_time' => '06:00',
        'end_time' => '22:00',
        'status' => 'active',
    ]);
});

it('rejects route assignment when start/end time is missing', function () {
    $operator = makeOperatorForFleetRouteTest();

    $fleet = Fleet::create([
        'company_user_id' => $operator->companyProfile->company_user_id,
        'plate_number' => 'B3-102',
        'capacity' => 50,
        'seated_capacity' => 40,
        'standing_capacity' => 10,
        'fleet_type' => 'public',
        'status' => 'active',
    ]);

    $route = Route::create([
        'origin' => 'Davao',
        'destination' => 'Tagum',
        'route_name' => 'Davao - Tagum 2',
    ]);

    $this->actingAs($operator)
        ->postJson("/api/operator/fleets/{$fleet->fleet_id}/routes", [
            'route_id' => $route->route_id,
        ])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['start_time', 'end_time']);
});

it('creates a fleet together with its routes and lists them back', function () {
    $operator = makeOperatorForFleetRouteTest();

    $route = Route::create([
        'origin' => 'Davao',
        'destination' => 'Tagum',
        'route_name' => 'Davao - Tagum 3',
    ]);

    $this->actingAs($operator)
        ->postJson('/api/operator/fleets', [
            'plate_number' => 'B3-201',
            'seated_capacity' => 40,
            'standing_capacity' => 10,
            'fleet_type' => 'public',
            'routes' => [
                ['route_id' => $route->route_id, 'start_time' => '06:00', 'end_time' => '22:00'],
            ],
        ])
        ->assertSuccessful()
        ->assertJsonPath('data.fleet_routes.0.route_id', $route->route_id);

    $this->assertDatabaseHas('fleets_routes', [
        'route_id' => $route->route_id,
        'start_time' => '06:00',
        'status' => 'active',
    ]);

    $this->getJson('/api/operator/fleets')
        ->assertSuccessful()
        ->assertJsonPath('data.0.fleet_routes.0.route.route_name', 'Davao - Tagum 3');
});

it('does not create the fleet when a route in the payload is invalid', function () {
    $operator = makeOperatorForFleetRouteTest();

    $this->actingAs($operator)
        ->postJson('/api/operator/fleets', [
            'plate_number' => 'B3-202',
            'seated_capacity' => 40,
            'standing_capacity' => 10,
            'fleet_type' => 'public',
            'routes' => [
                ['route_id' => 999999, 'start_time' => '06:00', 'end_time' => '22:00'],
            ],
        ])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['routes.0.route_id']);

    $this->assertDatabaseMissing('fleets', ['plate_number' => 'B3-202']);
});
