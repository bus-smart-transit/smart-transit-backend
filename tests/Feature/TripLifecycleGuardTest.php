<?php

use App\Models\Fleet;
use App\Models\FleetRoute;
use App\Models\Route;
use App\Models\RouteStop;
use App\Models\ShiftBlock;
use App\Models\StaffUser;
use App\Models\Stop;
use App\Models\Trip;
use App\Models\User;
use App\Repositories\TripRepository;
use App\Services\DriverNavigationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

function lgUser(string $role): User
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

function lgFleetRoute(User $operator): FleetRoute
{
    $route = Route::create(['route_name' => 'Lifecycle ' . Str::random(4), 'origin' => 'A', 'destination' => 'B']);
    $fleet = Fleet::create([
        'company_user_id' => $operator->companyProfile->company_user_id,
        'plate_number' => 'LG-' . fake()->unique()->numerify('###'),
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

function lgTrip(FleetRoute $fleetRoute, User $operator, array $attrs = []): Trip
{
    return Trip::create(array_merge([
        'fleet_route_id' => $fleetRoute->fleet_route_id,
        'company_user_id' => $operator->companyProfile->company_user_id,
        'trip_date' => now()->toDateString(),
        'status' => 'scheduled',
        'current_seated_capacity' => 0,
        'current_standing_capacity' => 0,
        'total_occupancy' => 0,
    ], $attrs));
}

test('a driver who is not assigned to the trip cannot complete it', function () {
    $operator = lgUser('operator');
    $assigned = lgUser('driver');
    $other = lgUser('driver');
    $trip = lgTrip(lgFleetRoute($operator), $operator, [
        'status' => 'departed',
        'driver_id' => $assigned->companyProfile->company_user_id,
    ]);

    $this->actingAs($other)->patchJson("/api/driver/trips/{$trip->trip_id}/complete")
        ->assertForbidden();

    $this->assertDatabaseHas('trips', ['trip_id' => $trip->trip_id, 'status' => 'departed']);
});

test('End Trip is idempotent and keeps the first server-side timestamp', function () {
    $operator = lgUser('operator');
    $driver = lgUser('driver');
    $trip = lgTrip(lgFleetRoute($operator), $operator, [
        'status' => 'departed',
        'driver_id' => $driver->companyProfile->company_user_id,
    ]);

    $this->actingAs($driver)->patchJson("/api/driver/trips/{$trip->trip_id}/complete")->assertSuccessful();
    $first = Trip::find($trip->trip_id)->completed_at;
    expect($first)->not->toBeNull();

    $this->travel(5)->minutes();

    $this->actingAs($driver)->patchJson("/api/driver/trips/{$trip->trip_id}/complete")
        ->assertSuccessful()
        ->assertJsonPath('message', 'Trip completed');

    expect(Trip::find($trip->trip_id)->completed_at->toDateTimeString())->toBe($first->toDateTimeString());
});

test('a trip that has not departed cannot be completed', function () {
    $operator = lgUser('operator');
    $driver = lgUser('driver');
    $trip = lgTrip(lgFleetRoute($operator), $operator, [
        'status' => 'scheduled',
        'driver_id' => $driver->companyProfile->company_user_id,
    ]);

    $this->actingAs($driver)->patchJson("/api/driver/trips/{$trip->trip_id}/complete")
        ->assertStatus(422);

    $this->assertDatabaseHas('trips', ['trip_id' => $trip->trip_id, 'status' => 'scheduled']);
});

test('starting boarding twice is a no-op the second time and records boarding_at once', function () {
    $operator = lgUser('operator');
    $trip = lgTrip(lgFleetRoute($operator), $operator, ['status' => 'scheduled']);

    $this->actingAs($operator)->patchJson("/api/operator/trips/{$trip->trip_id}/boarding")->assertSuccessful();
    $first = Trip::find($trip->trip_id)->boarding_at;
    expect($first)->not->toBeNull();

    $this->travel(2)->minutes();

    $this->actingAs($operator)->patchJson("/api/operator/trips/{$trip->trip_id}/boarding")->assertSuccessful();
    expect(Trip::find($trip->trip_id)->boarding_at->toDateTimeString())->toBe($first->toDateTimeString());
});

test('the current leg is the earliest unfinished leg of the shift block, whatever the insert order', function () {
    $operator = lgUser('operator');
    $driver = lgUser('driver');
    $fleetRoute = lgFleetRoute($operator);

    foreach (['11:00', '06:00', '08:30'] as $time) {
        lgTrip($fleetRoute, $operator, [
            'departure_time' => $time,
            'status' => 'scheduled',
            'driver_id' => $driver->companyProfile->company_user_id,
        ]);
    }

    $repo = app(TripRepository::class);
    $driverId = $driver->companyProfile->company_user_id;

    expect(substr((string) $repo->findCurrentByDriver($driverId)->departure_time, 0, 5))->toBe('06:00');
    expect(substr((string) $repo->findTodayByDriver($driverId)->departure_time, 0, 5))->toBe('06:00');
});

test('End Shift is refused until every leg of the shift block is completed or cancelled', function () {
    $operator = lgUser('operator');
    $driver = lgUser('driver');
    $conductor = lgUser('conductor');
    $fleetRoute = lgFleetRoute($operator);

    $block = ShiftBlock::create([
        'fleet_id' => $fleetRoute->fleet_id,
        'driver_id' => $driver->companyProfile->company_user_id,
        'conductor_id' => $conductor->companyProfile->company_user_id,
        'company_user_id' => $operator->companyProfile->company_user_id,
        'block_type' => 'morning',
        'status' => 'in_progress',
        'audit_status' => 'not_applicable',
        'scheduled_date' => now()->toDateString(),
    ]);

    $legs = [];
    foreach (['06:00' => 'completed', '08:30' => 'scheduled'] as $time => $status) {
        $legs[] = lgTrip($fleetRoute, $operator, [
            'departure_time' => $time,
            'status' => $status,
            'driver_id' => $driver->companyProfile->company_user_id,
            'conductor_id' => $conductor->companyProfile->company_user_id,
            'shift_block_id' => $block->shift_block_id,
        ]);
    }

    $this->actingAs($conductor)->postJson('/api/conductor/shift/start')->assertSuccessful();

    $this->actingAs($conductor)->postJson('/api/conductor/shift/end')
        ->assertStatus(422)
        ->assertJsonValidationErrors(['shift']);

    $legs[1]->update(['status' => 'completed']);

    $this->actingAs($conductor)->postJson('/api/conductor/shift/end')
        ->assertSuccessful()
        ->assertJsonPath('message', 'Conductor shift ended');
});

test('a Chauffeur shift on a shift-block leg stays open when a leg completes', function () {
    $operator = lgUser('operator');
    $driver = lgUser('driver');
    $conductor = lgUser('conductor');
    $fleetRoute = lgFleetRoute($operator);

    $block = ShiftBlock::create([
        'fleet_id' => $fleetRoute->fleet_id,
        'driver_id' => $driver->companyProfile->company_user_id,
        'conductor_id' => $conductor->companyProfile->company_user_id,
        'company_user_id' => $operator->companyProfile->company_user_id,
        'block_type' => 'morning',
        'status' => 'in_progress',
        'audit_status' => 'not_applicable',
        'scheduled_date' => now()->toDateString(),
    ]);

    $leg = lgTrip($fleetRoute, $operator, [
        'departure_time' => '06:00',
        'status' => 'departed',
        'driver_id' => $driver->companyProfile->company_user_id,
        'conductor_id' => $conductor->companyProfile->company_user_id,
        'shift_block_id' => $block->shift_block_id,
    ]);
    lgTrip($fleetRoute, $operator, [
        'departure_time' => '08:30',
        'status' => 'scheduled',
        'driver_id' => $driver->companyProfile->company_user_id,
        'conductor_id' => $conductor->companyProfile->company_user_id,
        'shift_block_id' => $block->shift_block_id,
    ]);

    $this->actingAs($conductor)->postJson('/api/conductor/shift/start')->assertSuccessful();
    $this->actingAs($operator)->patchJson("/api/operator/trips/{$leg->trip_id}/complete")->assertSuccessful();

    $this->assertDatabaseHas('conductor_shift_logs', ['trip_id' => $leg->trip_id, 'ended_at' => null]);
});

test('stop acknowledgement is idempotent and never moves backwards', function () {
    $operator = lgUser('operator');
    $driver = lgUser('driver');
    $fleetRoute = lgFleetRoute($operator);

    $stopIds = [];
    foreach ([1, 2, 3] as $order) {
        $stop = Stop::create(['stop_name' => "Ack {$order}", 'latitude' => 7.0 + $order / 100, 'longitude' => 125.6]);
        RouteStop::create([
            'route_id' => $fleetRoute->route_id,
            'stop_id' => $stop->stop_id,
            'stop_order' => $order,
            'distance_from_origin_km' => $order * 5,
        ]);
        $stopIds[$order] = $stop->stop_id;
    }

    $trip = lgTrip($fleetRoute, $operator, [
        'status' => 'departed',
        'driver_id' => $driver->companyProfile->company_user_id,
    ]);

    $service = app(DriverNavigationService::class);
    $driverId = $driver->companyProfile->company_user_id;

    expect($service->acknowledgeStop($driverId, $stopIds[2]))->toBeTrue();
    expect(Trip::find($trip->trip_id)->last_acknowledged_stop_id)->toBe($stopIds[2]);

    expect($service->acknowledgeStop($driverId, $stopIds[1]))->toBeTrue();
    expect($service->acknowledgeStop($driverId, $stopIds[2]))->toBeTrue();
    expect(Trip::find($trip->trip_id)->last_acknowledged_stop_id)->toBe($stopIds[2]);

    expect($service->acknowledgeStop($driverId, $stopIds[3]))->toBeTrue();
    expect(Trip::find($trip->trip_id)->last_acknowledged_stop_id)->toBe($stopIds[3]);

    expect($service->acknowledgeStop($driverId, 999999))->toBeFalse();
});
