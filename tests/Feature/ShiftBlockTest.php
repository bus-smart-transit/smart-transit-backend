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
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

// Batch 18: Shift Block Hand-off System — concrete implementation of Batch
// 17 S2's state machine. Supersedes the earlier trip_driver_segments design.
function makeBlockTestStaff(string $role): array
{
    $user = User::create([
        'username' => $role . '_block_' . uniqid(),
        'email' => $role . '+block' . uniqid() . '@smarttransit.test',
        'password' => bcrypt('password123'),
        'role' => $role,
    ]);

    $staff = StaffUser::create([
        'company_user_uuid' => (string) Str::uuid(),
        'user_id' => $user->user_id,
        'name' => ucfirst($role) . ' Block Test',
        'phone_num' => '09170000040',
        'address' => 'Test Address',
    ]);

    return [$user->fresh(), $staff];
}

function makeBlockTestFleetRoute(int $operatorCompanyUserId): array
{
    $origin = Stop::create(['stop_name' => 'Davao Terminal', 'latitude' => 7.0731, 'longitude' => 125.6128, 'geofence_radius_m' => 100]);
    $destination = Stop::create(['stop_name' => 'Tagum Terminal', 'latitude' => 7.4478, 'longitude' => 125.8078]);

    $route = Route::create(['route_name' => 'Davao - Tagum', 'origin' => 'Davao', 'destination' => 'Tagum']);
    RouteStop::create(['route_id' => $route->route_id, 'stop_id' => $origin->stop_id, 'stop_order' => 1, 'distance_from_origin_km' => 0]);
    RouteStop::create(['route_id' => $route->route_id, 'stop_id' => $destination->stop_id, 'stop_order' => 2, 'distance_from_origin_km' => 62]);

    $fleet = Fleet::create([
        'company_user_id' => $operatorCompanyUserId,
        'plate_number' => 'BLK-' . fake()->unique()->numerify('###'),
        'capacity' => 50,
        'seated_capacity' => 40,
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

    return [$fleetRoute, $fleet, $origin];
}

test('operator can create a shift block with 4 legs and it locks driver+conductor for the day', function () {
    [$operatorUser, $operator] = makeBlockTestStaff('operator');
    [, $driver] = makeBlockTestStaff('driver');
    [, $conductor] = makeBlockTestStaff('conductor');
    [$fleetRoute] = makeBlockTestFleetRoute($operator->company_user_id);

    $response = $this->actingAs($operatorUser)->postJson('/api/operator/shift-blocks', [
        'fleet_route_id' => $fleetRoute->fleet_route_id,
        'driver_id' => $driver->company_user_id,
        'conductor_id' => $conductor->company_user_id,
        'block_type' => 'morning',
        'scheduled_date' => now()->toDateString(),
        'leg_departure_times' => ['06:00', '08:30', '11:00', '13:30'],
    ]);

    $response->assertSuccessful();
    $blockId = $response->json('data.shift_block_id');

    $this->assertDatabaseHas('shift_blocks', [
        'shift_block_id' => $blockId,
        'status' => 'scheduled',
        'audit_status' => 'not_applicable',
    ]);
    $this->assertDatabaseCount('trips', 4);
    expect(Trip::where('shift_block_id', $blockId)->count())->toBe(4);

    // Same driver cannot be assigned to a second, separate trip that day.
    $this->actingAs($operatorUser)->patchJson(
        "/api/operator/trips/" . Trip::first()->trip_id . "/driver",
        ['driver_id' => $driver->company_user_id],
    );
    // (Trip already belongs to the block and has this driver — instead
    // prove exclusivity via a brand-new separate trip/fleet-route.)
    [$fleetRoute2] = makeBlockTestFleetRoute($operator->company_user_id);
    $otherTrip = Trip::create([
        'fleet_route_id' => $fleetRoute2->fleet_route_id,
        'company_user_id' => $operator->company_user_id,
        'trip_date' => now()->toDateString(),
        'departure_time' => '18:00:00',
        'status' => 'scheduled',
        'current_seated_capacity' => 0,
        'current_standing_capacity' => 0,
        'total_occupancy' => 0,
    ]);

    $this->actingAs($operatorUser)
        ->patchJson("/api/operator/trips/{$otherTrip->trip_id}/driver", ['driver_id' => $driver->company_user_id])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['driver_id']);
});

test('a second shift block for the same driver on the same date is rejected', function () {
    [$operatorUser, $operator] = makeBlockTestStaff('operator');
    [, $driver] = makeBlockTestStaff('driver');
    [, $conductorA] = makeBlockTestStaff('conductor');
    [, $conductorB] = makeBlockTestStaff('conductor');
    [$fleetRoute] = makeBlockTestFleetRoute($operator->company_user_id);

    $payload = [
        'fleet_route_id' => $fleetRoute->fleet_route_id,
        'driver_id' => $driver->company_user_id,
        'conductor_id' => $conductorA->company_user_id,
        'block_type' => 'morning',
        'scheduled_date' => now()->toDateString(),
        'leg_departure_times' => ['06:00', '08:30', '11:00', '13:30'],
    ];

    $this->actingAs($operatorUser)->postJson('/api/operator/shift-blocks', $payload)->assertSuccessful();

    $payload['conductor_id'] = $conductorB->company_user_id;
    $payload['block_type'] = 'afternoon';
    $payload['leg_departure_times'] = ['14:00', '16:30', '19:00', '21:30'];

    $this->actingAs($operatorUser)->postJson('/api/operator/shift-blocks', $payload)
        ->assertStatus(422)
        ->assertJsonValidationErrors(['driver_id']);
});

test('hand-off eligibility is false until the final leg is completed and fleet is within the terminal geofence', function () {
    [$operatorUser, $operator] = makeBlockTestStaff('operator');
    [, $driver] = makeBlockTestStaff('driver');
    [$conductorUser, $conductor] = makeBlockTestStaff('conductor');
    [$fleetRoute, $fleet, $originStop] = makeBlockTestFleetRoute($operator->company_user_id);

    $response = $this->actingAs($operatorUser)->postJson('/api/operator/shift-blocks', [
        'fleet_route_id' => $fleetRoute->fleet_route_id,
        'driver_id' => $driver->company_user_id,
        'conductor_id' => $conductor->company_user_id,
        'block_type' => 'morning',
        'scheduled_date' => now()->toDateString(),
        'leg_departure_times' => ['06:00', '08:30', '11:00', '13:30'],
    ]);
    $blockId = $response->json('data.shift_block_id');

    // Not eligible yet — final leg not completed, no GPS at all.
    $eligibility = $this->actingAs($conductorUser)
        ->getJson("/api/conductor/shift-blocks/{$blockId}/handoff-eligibility")
        ->json('data');
    expect($eligibility['eligible'])->toBeFalse();

    // Mark final leg completed but fleet is far from the terminal.
    $finalLeg = Trip::where('shift_block_id', $blockId)->orderByDesc('trip_id')->first();
    $finalLeg->update(['status' => 'completed']);
    DB::table('fleet_locations')->updateOrInsert(['fleet_id' => $fleet->fleet_id], [
        'location' => '8.0,126.5', // far away
        'recorded_at' => now(),
        'updated_at' => now(),
    ]);

    $eligibility = $this->actingAs($conductorUser)
        ->getJson("/api/conductor/shift-blocks/{$blockId}/handoff-eligibility")
        ->json('data');
    expect($eligibility['eligible'])->toBeFalse();

    // Now put the fleet within the origin terminal's geofence.
    DB::table('fleet_locations')->where('fleet_id', $fleet->fleet_id)->update([
        'location' => $originStop->latitude . ',' . $originStop->longitude,
    ]);

    $eligibility = $this->actingAs($conductorUser)
        ->getJson("/api/conductor/shift-blocks/{$blockId}/handoff-eligibility")
        ->json('data');
    expect($eligibility['eligible'])->toBeTrue();
});

test('conductor-initiated hand-off sets audit_status to pending_review and revokes crew tokens', function () {
    [$operatorUser, $operator] = makeBlockTestStaff('operator');
    [$driverUser, $driver] = makeBlockTestStaff('driver');
    [$conductorUser, $conductor] = makeBlockTestStaff('conductor');
    [$fleetRoute, $fleet, $originStop] = makeBlockTestFleetRoute($operator->company_user_id);

    $response = $this->actingAs($operatorUser)->postJson('/api/operator/shift-blocks', [
        'fleet_route_id' => $fleetRoute->fleet_route_id,
        'driver_id' => $driver->company_user_id,
        'conductor_id' => $conductor->company_user_id,
        'block_type' => 'morning',
        'scheduled_date' => now()->toDateString(),
        'leg_departure_times' => ['06:00', '08:30', '11:00', '13:30'],
    ]);
    $blockId = $response->json('data.shift_block_id');

    ShiftBlock::where('shift_block_id', $blockId)->update(['status' => 'in_progress']);
    Trip::where('shift_block_id', $blockId)->orderByDesc('trip_id')->first()->update(['status' => 'completed']);
    DB::table('fleet_locations')->updateOrInsert(['fleet_id' => $fleet->fleet_id], [
        'location' => $originStop->latitude . ',' . $originStop->longitude,
        'recorded_at' => now(),
        'updated_at' => now(),
    ]);

    // Give the conductor a live token to prove it gets revoked.
    $conductorToken = $conductorUser->createToken('test')->plainTextToken;
    $driverUser->createToken('test');

    $this->actingAs($conductorUser)
        ->postJson("/api/conductor/shift-blocks/{$blockId}/initiate-handoff")
        ->assertSuccessful();

    $this->assertDatabaseHas('shift_blocks', [
        'shift_block_id' => $blockId,
        'status' => 'completed',
        'audit_status' => 'pending_review',
    ]);

    expect($conductorUser->fresh()->tokens()->count())->toBe(0);
    expect($driverUser->fresh()->tokens()->count())->toBe(0);
});

test('operator can list pending-review blocks and mark them reviewed', function () {
    [$operatorUser, $operator] = makeBlockTestStaff('operator');
    [, $driver] = makeBlockTestStaff('driver');
    [, $conductor] = makeBlockTestStaff('conductor');
    [$fleetRoute] = makeBlockTestFleetRoute($operator->company_user_id);

    $response = $this->actingAs($operatorUser)->postJson('/api/operator/shift-blocks', [
        'fleet_route_id' => $fleetRoute->fleet_route_id,
        'driver_id' => $driver->company_user_id,
        'conductor_id' => $conductor->company_user_id,
        'block_type' => 'morning',
        'scheduled_date' => now()->toDateString(),
        'leg_departure_times' => ['06:00', '08:30', '11:00', '13:30'],
    ]);
    $blockId = $response->json('data.shift_block_id');
    ShiftBlock::where('shift_block_id', $blockId)->update(['audit_status' => 'pending_review']);

    $this->actingAs($operatorUser)
        ->getJson('/api/operator/shift-blocks/pending-review')
        ->assertSuccessful()
        ->assertJsonCount(1, 'data');

    $this->actingAs($operatorUser)
        ->patchJson("/api/operator/shift-blocks/{$blockId}/mark-reviewed")
        ->assertSuccessful();

    $this->assertDatabaseHas('shift_blocks', ['shift_block_id' => $blockId, 'audit_status' => 'reviewed']);
});

test('dispatcher override can force hand-off regardless of GPS state', function () {
    [$operatorUser, $operator] = makeBlockTestStaff('operator');
    [, $driver] = makeBlockTestStaff('driver');
    [, $conductor] = makeBlockTestStaff('conductor');
    [$fleetRoute] = makeBlockTestFleetRoute($operator->company_user_id);

    $response = $this->actingAs($operatorUser)->postJson('/api/operator/shift-blocks', [
        'fleet_route_id' => $fleetRoute->fleet_route_id,
        'driver_id' => $driver->company_user_id,
        'conductor_id' => $conductor->company_user_id,
        'block_type' => 'morning',
        'scheduled_date' => now()->toDateString(),
        'leg_departure_times' => ['06:00', '08:30', '11:00', '13:30'],
    ]);
    $blockId = $response->json('data.shift_block_id');
    ShiftBlock::where('shift_block_id', $blockId)->update(['status' => 'in_progress']);

    // No GPS data at all, final leg not completed — would fail the normal check.
    $this->actingAs($operatorUser)
        ->postJson("/api/operator/shift-blocks/{$blockId}/override-handoff")
        ->assertSuccessful();

    $this->assertDatabaseHas('shift_blocks', ['shift_block_id' => $blockId, 'status' => 'completed', 'audit_status' => 'pending_review']);
});
