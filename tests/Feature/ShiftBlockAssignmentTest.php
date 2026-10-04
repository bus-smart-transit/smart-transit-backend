<?php

use App\Models\Fleet;
use App\Models\FleetRoute;
use App\Models\Route;
use App\Models\ShiftBlock;
use App\Models\ShiftBlockAssignment;
use App\Models\StaffUser;
use App\Models\Trip;
use App\Models\TripAssignmentRequest;
use App\Models\User;
use App\Support\Notifications\NotificationLink;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

// Batch 28, item 1: a shift block is one card; accept / decline happen once, on the block.

function saStaff(string $role): User
{
    $user = User::create(['username' => $role.Str::random(6), 'email' => Str::random(8).'@example.com', 'password' => bcrypt('password123'), 'role' => $role]);
    StaffUser::create(['user_id' => $user->user_id, 'company_user_uuid' => (string) Str::uuid(), 'name' => ucfirst($role).' '.Str::random(3), 'phone_num' => '0917', 'address' => 'x']);

    return $user->fresh();
}

function saCid(User $user): int
{
    return (int) $user->companyProfile->company_user_id;
}

/** An operator, a fleet route, and one four-leg block for a driver and a Chauffeur, created through the real endpoint. */
function saBlock($test, string $date = '2026-10-12'): array
{
    $operator = saStaff('operator');
    $driver = saStaff('driver');
    $conductor = saStaff('conductor');
    $route = Route::create(['route_name' => 'Block Route', 'origin' => 'Alpha', 'destination' => 'Omega']);
    $fleet = Fleet::create(['company_user_id' => saCid($operator), 'plate_number' => 'SA-'.fake()->unique()->numerify('####'), 'capacity' => 40, 'seated_capacity' => 30, 'standing_capacity' => 10, 'status' => 'active']);
    $fleetRoute = FleetRoute::create(['fleet_id' => $fleet->fleet_id, 'route_id' => $route->route_id, 'start_time' => '06:00', 'end_time' => '22:00', 'status' => 'active']);

    $legs = (int) config('shift_blocks.legs_per_block');
    $times = collect(range(0, $legs - 1))->map(fn ($i) => sprintf('%02d:00', 6 + $i * 3))->all();
    $blockId = $test->actingAs($operator)->postJson('/api/operator/shift-blocks', [
        'fleet_route_id' => $fleetRoute->fleet_route_id, 'driver_id' => saCid($driver), 'conductor_id' => saCid($conductor),
        'block_type' => config('shift_blocks.block_types')[0], 'scheduled_date' => $date, 'leg_departure_times' => $times,
    ])->assertSuccessful()->json('data.shift_block_id');

    return [ShiftBlock::find($blockId), $operator, $driver, $conductor, $fleetRoute];
}

beforeEach(fn () => Carbon::setTestNow(Carbon::parse('2026-10-04 07:00:00', 'Asia/Manila')));
afterEach(fn () => Carbon::setTestNow());

it('shows one card per block with the route, date, time range, number of legs and status', function () {
    [$block, , $driver] = saBlock($this);

    $cards = $this->actingAs($driver)->getJson('/api/driver/assignments')->assertSuccessful()->json('data');

    expect($cards)->toHaveCount(1); // not one row per leg
    $card = $cards[0];
    expect($card['key'])->toBe('block-'.$block->shift_block_id);
    expect($card['kind'])->toBe('block');
    expect($card['route_name'])->toBe('Block Route');
    expect($card['scheduled_date'])->toBe('2026-10-12');
    expect($card['start_time'])->toBe('06:00');
    expect($card['legs_count'])->toBe((int) config('shift_blocks.legs_per_block'));
    expect($card['status'])->toBe('scheduled');
    expect($card['assignment_status'])->toBe('pending');
    expect($card['can_respond'])->toBeTrue();
    expect(array_column($card['legs'], 'departure_time')[0])->toBe('06:00');
    expect($card['legs'][0]['origin'])->toBe('Alpha');
});

it('accepts the whole block once and writes the block record and every leg together', function () {
    [$block, , $driver, $conductor] = saBlock($this);

    $card = $this->actingAs($driver)->patchJson("/api/driver/assignments/block-{$block->shift_block_id}/accept")->assertSuccessful()->json('data');

    expect($card['assignment_status'])->toBe('accepted');
    expect($card['can_respond'])->toBeFalse();
    expect(Trip::where('shift_block_id', $block->shift_block_id)->whereNull('driver_accepted_at')->count())->toBe(0);
    expect(Trip::where('shift_block_id', $block->shift_block_id)->whereNotNull('conductor_accepted_at')->count())->toBe(0); // the Chauffeur is separate
    expect(ShiftBlockAssignment::where('shift_block_id', $block->shift_block_id)->where('role', 'driver')->value('status'))->toBe('accepted');

    // The Chauffeur's own acceptance is independent.
    $this->actingAs($conductor)->patchJson("/api/conductor/assignments/block-{$block->shift_block_id}/accept")->assertSuccessful();
    expect(Trip::where('shift_block_id', $block->shift_block_id)->whereNull('conductor_accepted_at')->count())->toBe(0);
});

it('treats a second accept as a no-op and refuses to decline after accepting', function () {
    [$block, , $driver] = saBlock($this);
    $base = "/api/driver/assignments/block-{$block->shift_block_id}";

    $this->actingAs($driver)->patchJson("$base/accept")->assertSuccessful();
    $this->actingAs($driver)->patchJson("$base/accept")->assertSuccessful()->assertJsonPath('data.assignment_status', 'accepted');
    $this->actingAs($driver)->patchJson("$base/decline", ['reason_code' => 'sick'])->assertUnprocessable()->assertJsonValidationErrors('status');
    expect(TripAssignmentRequest::count())->toBe(0);
});

it('needs a described reason for Others, keeps it plain text and limits its length', function () {
    [$block, , $driver] = saBlock($this);
    $url = "/api/driver/assignments/block-{$block->shift_block_id}/decline";

    $this->actingAs($driver)->patchJson($url, ['reason_code' => 'other'])->assertUnprocessable()->assertJsonValidationErrors('reason_text');
    $this->actingAs($driver)->patchJson($url, ['reason_code' => 'other', 'reason_text' => str_repeat('x', 301)])->assertUnprocessable()->assertJsonValidationErrors('reason_text');
    $this->actingAs($driver)->patchJson($url, ['reason_code' => 'nonsense'])->assertUnprocessable()->assertJsonValidationErrors('reason_code');
    expect(ShiftBlockAssignment::where('shift_block_id', $block->shift_block_id)->where('role', 'driver')->value('status'))->toBe('pending'); // nothing half-written

    $card = $this->actingAs($driver)->patchJson($url, ['reason_code' => 'other', 'reason_text' => '<script>alert(1)</script>  Family   emergency'])->assertSuccessful()->json('data');

    expect($card['assignment_status'])->toBe('for_approval');
    expect($card['reason_text'])->toBe('alert(1) Family emergency'); // tags stripped, spaces squished
    expect(TripAssignmentRequest::where('role', 'driver')->count())->toBe((int) config('shift_blocks.legs_per_block')); // one per leg, written with the block
});

it('shows the Operator one request for the block and releases the person from every leg on approval', function () {
    [$block, $operator, $driver] = saBlock($this);
    $legs = (int) config('shift_blocks.legs_per_block');
    $this->actingAs($driver)->patchJson("/api/driver/assignments/block-{$block->shift_block_id}/decline", ['reason_code' => 'sick'])->assertSuccessful();

    $list = $this->actingAs($operator)->getJson('/api/operator/trip-requests')->assertSuccessful()->json('data');
    expect($list['pending_count'])->toBe(1);
    expect($list['requests'])->toHaveCount(1);
    expect($list['requests'][0]['shift_block_id'])->toBe($block->shift_block_id);
    expect($list['requests'][0]['legs_count'])->toBe($legs);

    $this->actingAs($operator)->patchJson('/api/operator/trip-requests/'.$list['requests'][0]['id'].'/approve')->assertSuccessful();

    expect(Trip::where('shift_block_id', $block->shift_block_id)->whereNotNull('driver_id')->count())->toBe(0); // every leg
    expect($block->fresh()->driver_id)->toBeNull();
    expect(TripAssignmentRequest::where('status', 'approved')->count())->toBe($legs);
    expect(ShiftBlockAssignment::where('shift_block_id', $block->shift_block_id)->where('role', 'driver')->value('status'))->toBe('released');
    $this->actingAs($driver)->getJson('/api/driver/assignments')->assertSuccessful()->assertJsonCount(0, 'data');

    $operatorCard = collect($this->actingAs($operator)->getJson('/api/operator/assignments')->json('data'))->firstWhere('shift_block_id', $block->shift_block_id);
    expect($operatorCard['assignments']['driver']['needs_replacement'])->toBeTrue();
    expect($operatorCard['assignments']['conductor']['needs_replacement'])->toBeFalse();
});

it('gives a replacement a clean slate that must accept for themselves', function () {
    [$block, $operator, $driver] = saBlock($this);
    $this->actingAs($driver)->patchJson("/api/driver/assignments/block-{$block->shift_block_id}/accept")->assertSuccessful();
    $replacement = saStaff('driver');

    $this->actingAs($operator)->patchJson("/api/operator/shift-blocks/{$block->shift_block_id}", ['driver_id' => saCid($replacement)])->assertSuccessful();

    expect(Trip::where('shift_block_id', $block->shift_block_id)->whereNotNull('driver_accepted_at')->count())->toBe(0); // not inherited
    $card = $this->actingAs($replacement)->getJson('/api/driver/assignments')->assertSuccessful()->json('data.0');
    expect($card['assignment_status'])->toBe('pending');
    $this->actingAs($driver)->getJson('/api/driver/assignments')->assertJsonCount(0, 'data');
});

it('keeps the person assigned when the Operator rejects, who may then accept but not decline again', function () {
    [$block, $operator, $driver] = saBlock($this);
    $base = "/api/driver/assignments/block-{$block->shift_block_id}";
    $this->actingAs($driver)->patchJson("$base/decline", ['reason_code' => 'schedule_conflict'])->assertSuccessful();
    $requestId = $this->actingAs($operator)->getJson('/api/operator/trip-requests')->json('data.requests.0.id');

    $this->actingAs($operator)->patchJson("/api/operator/trip-requests/{$requestId}/reject")->assertSuccessful();

    expect(Trip::where('shift_block_id', $block->shift_block_id)->where('driver_id', saCid($driver))->count())->toBe((int) config('shift_blocks.legs_per_block'));
    expect($block->fresh()->driver_id)->toBe(saCid($driver));
    $this->actingAs($driver)->patchJson("$base/decline", ['reason_code' => 'sick'])->assertUnprocessable();
    $this->actingAs($driver)->patchJson("$base/accept")->assertSuccessful()->assertJsonPath('data.assignment_status', 'accepted');
});

it('refuses to accept or decline once the first leg has started', function () {
    [$block, , $driver] = saBlock($this);
    Trip::where('shift_block_id', $block->shift_block_id)->orderBy('trip_id')->first()->update(['status' => 'departed']);
    $base = "/api/driver/assignments/block-{$block->shift_block_id}";

    $this->actingAs($driver)->patchJson("$base/accept")->assertUnprocessable()->assertJsonValidationErrors('status');
    $this->actingAs($driver)->patchJson("$base/decline", ['reason_code' => 'sick'])->assertUnprocessable()->assertJsonValidationErrors('status');
    $card = $this->actingAs($driver)->getJson('/api/driver/assignments')->json('data.0');
    expect($card['can_respond'])->toBeFalse();
});

it('does not let another driver, or another Operator, act on the block', function () {
    [$block, $operator, $driver] = saBlock($this);
    $other = saStaff('driver');
    $this->actingAs($driver)->patchJson("/api/driver/assignments/block-{$block->shift_block_id}/decline", ['reason_code' => 'sick'])->assertSuccessful();

    $this->actingAs($other)->patchJson("/api/driver/assignments/block-{$block->shift_block_id}/accept")->assertUnprocessable();
    $this->actingAs($other)->patchJson("/api/driver/assignments/block-999999/accept")->assertUnprocessable(); // not found looks the same as not yours

    $requestId = $this->actingAs($operator)->getJson('/api/operator/trip-requests')->json('data.requests.0.id');
    $rival = saStaff('operator');
    $this->actingAs($rival)->patchJson("/api/operator/trip-requests/{$requestId}/approve")->assertForbidden();
    expect($block->fresh()->driver_id)->toBe(saCid($driver));
});

it('answers a one-way trip as a block of one leg, and refuses leg-level answers for block legs', function () {
    [$block, $operator, $driver, $conductor, $fleetRoute] = saBlock($this);
    $trip = Trip::create([
        'fleet_route_id' => $fleetRoute->fleet_route_id, 'company_user_id' => saCid($operator), 'trip_date' => '2026-10-20', 'departure_time' => '09:00',
        'trip_type' => 'one_way', 'leg_direction' => 'outbound', 'status' => 'scheduled', 'driver_id' => saCid($driver), 'conductor_id' => saCid($conductor),
        'current_seated_capacity' => 0, 'current_standing_capacity' => 0, 'total_occupancy' => 0,
    ]);

    $cards = collect($this->actingAs($driver)->getJson('/api/driver/assignments')->json('data'));
    $single = $cards->firstWhere('key', 'trip-'.$trip->trip_id);
    expect($single['kind'])->toBe('trip');
    expect($single['legs_count'])->toBe(1);

    $this->actingAs($driver)->patchJson("/api/driver/assignments/trip-{$trip->trip_id}/accept")->assertSuccessful();
    expect($trip->fresh()->driver_accepted_at)->not->toBeNull();

    $leg = Trip::where('shift_block_id', $block->shift_block_id)->first();
    $this->actingAs($driver)->patchJson("/api/driver/trips/{$leg->trip_id}/accept")->assertUnprocessable();
    $this->actingAs($driver)->patchJson("/api/driver/trips/{$leg->trip_id}/decline", ['reason_code' => 'sick'])->assertUnprocessable();
});

it('folds in a leg-level acceptance made before the block record existed', function () {
    [$block, , $driver] = saBlock($this);
    Trip::where('shift_block_id', $block->shift_block_id)->update(['driver_accepted_at' => now()]);

    $card = $this->actingAs($driver)->getJson('/api/driver/assignments')->json('data.0');

    expect($card['assignment_status'])->toBe('accepted');
});

it('builds the "new trip assigned" link to the block card and keeps the trip link for a single trip', function () {
    expect(NotificationLink::blockAssignment('driver', 5))->toBe('/employee/driver/dashboard?tab=dashboard&block=5');
    expect(NotificationLink::blockAssignment('conductor', 5))->toBe('/employee/conductor/dashboard?tab=dashboard&block=5');
    expect(NotificationLink::blockAssignedData('driver', 5))->toBe([
        'type' => 'shift_block_assigned', 'shift_block_id' => 5, 'role' => 'driver', 'link' => '/employee/driver/dashboard?tab=dashboard&block=5',
    ]);
    expect(NotificationLink::tripAssignment('driver', 9))->toBe('/employee/driver/dashboard?tab=dashboard&trip=9');
});
