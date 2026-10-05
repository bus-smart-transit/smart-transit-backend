<?php

use App\Models\Fleet;
use App\Models\FleetRoute;
use App\Models\Route;
use App\Models\ShiftBlock;
use App\Models\ShiftBlockAssignment;
use App\Models\StaffUser;
use App\Models\Trip;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

// Batch 29, issues 1 and 3: ONE status over the leg statuses for every portal, the Manila day decides the section
// (today / upcoming / past / overdue), and a date that has passed can no longer be accepted or declined.
// Self-contained fixtures with made-up names; nothing here knows a real route or place.

const SC_PAST = 'This date has passed. Contact your operator.';

function scStaff(string $role): User
{
    $user = User::create(['username' => $role.Str::random(6), 'email' => Str::random(8).'@example.com', 'password' => bcrypt('password123'), 'role' => $role]);
    StaffUser::create(['user_id' => $user->user_id, 'company_user_uuid' => (string) Str::uuid(), 'name' => ucfirst($role).' '.Str::random(3), 'phone_num' => '0917', 'address' => 'x']);

    return $user->fresh();
}

function scCid(User $user): int
{
    return (int) $user->companyProfile->company_user_id;
}

/** A four-leg block created through the real endpoint (future date), then moved to $date directly. */
function scBlock($test, string $date, array $times = ['06:00', '09:00', '12:00', '15:00']): array
{
    $operator = scStaff('operator');
    $driver = scStaff('driver');
    $conductor = scStaff('conductor');
    $route = Route::create(['route_name' => 'Sched Route', 'origin' => 'Alpha', 'destination' => 'Omega']);
    $fleet = Fleet::create(['company_user_id' => scCid($operator), 'plate_number' => 'SC-'.fake()->unique()->numerify('####'), 'capacity' => 40, 'seated_capacity' => 30, 'standing_capacity' => 10, 'status' => 'active']);
    $fleetRoute = FleetRoute::create(['fleet_id' => $fleet->fleet_id, 'route_id' => $route->route_id, 'start_time' => '06:00', 'end_time' => '22:00', 'status' => 'active']);

    $blockId = $test->actingAs($operator)->postJson('/api/operator/shift-blocks', [
        'fleet_route_id' => $fleetRoute->fleet_route_id, 'driver_id' => scCid($driver), 'conductor_id' => scCid($conductor),
        'block_type' => config('shift_blocks.block_types')[0], 'scheduled_date' => '2026-12-20', 'leg_departure_times' => $times,
    ])->assertSuccessful()->json('data.shift_block_id');

    ShiftBlock::where('shift_block_id', $blockId)->update(['scheduled_date' => $date]);
    Trip::where('shift_block_id', $blockId)->update(['trip_date' => $date]);

    return [ShiftBlock::find($blockId), $operator, $driver, $conductor, $fleetRoute];
}

/** Set the leg statuses in departure order (e.g. ['completed', 'scheduled', ...]). */
function scLegs(ShiftBlock $block, array $statuses): void
{
    $legs = Trip::where('shift_block_id', $block->shift_block_id)->orderBy('departure_time')->get();
    foreach ($legs as $i => $leg) {
        $leg->update(['status' => $statuses[$i] ?? 'scheduled']);
    }
}

function scCard($test, User $user, ShiftBlock $block, string $portal): ?array
{
    $card = collect($test->actingAs($user)->getJson("/api/$portal/assignments")->assertSuccessful()->json('data'))
        ->firstWhere('shift_block_id', $block->shift_block_id);

    return $card ?: null;
}

beforeEach(fn () => Carbon::setTestNow(Carbon::parse('2026-10-04 07:00:00', 'Asia/Manila')));
afterEach(fn () => Carbon::setTestNow());

it('derives the card status from the legs, the same on the Driver, Chauffeur and Operator cards', function () {
    [$block, $operator, $driver, $conductor] = scBlock($this, '2026-10-04');

    $cases = [
        'every leg completed' => [['completed', 'completed', 'completed', 'completed'], 'completed'],
        'a leg done, the next not started' => [['completed', 'scheduled', 'scheduled', 'scheduled'], 'in-progress'],
        'a leg boarding' => [['scheduled', 'boarding', 'scheduled', 'scheduled'], 'boarding'],
        'a leg departed' => [['completed', 'departed', 'scheduled', 'scheduled'], 'departed'],
        'completed and cancelled legs only' => [['completed', 'completed', 'cancelled', 'cancelled'], 'completed'],
        'nothing started' => [['scheduled', 'scheduled', 'scheduled', 'scheduled'], 'scheduled'],
    ];

    foreach ($cases as $name => [$legs, $expected]) {
        scLegs($block, $legs);
        // The stored block column is workflow state and stays "scheduled" throughout: it must not leak into any card.
        expect($block->fresh()->status)->toBe('scheduled');

        $seen = [
            'driver' => scCard($this, $driver, $block, 'driver')['status'],
            'conductor' => scCard($this, $conductor, $block, 'conductor')['status'],
            'operator' => scCard($this, $operator, $block, 'operator')['status'],
        ];
        expect($seen)->toBe(['driver' => $expected, 'conductor' => $expected, 'operator' => $expected], $name);
    }
});

it('hides a block whose every leg is cancelled and never calls a block completed while a leg is unfinished', function () {
    [$block, , $driver] = scBlock($this, '2026-10-04');

    scLegs($block, ['completed', 'completed', 'completed', 'scheduled']);
    expect(scCard($this, $driver, $block, 'driver')['status'])->not->toBe('completed');

    scLegs($block, ['cancelled', 'cancelled', 'cancelled', 'cancelled']);
    expect(scCard($this, $driver, $block, 'driver'))->toBeNull();
});

it('puts a finished earlier day in Past, an unfinished one in Overdue, never in Today or "scheduled"', function () {
    [$done, , $driverA] = scBlock($this, '2026-10-03');
    scLegs($done, ['completed', 'completed', 'completed', 'completed']);
    $past = scCard($this, $driverA, $done, 'driver');
    expect($past['section'])->toBe('past');
    expect($past['status'])->toBe('completed');
    expect($past['is_overdue'])->toBeFalse();

    [$stale, , $driverB, $conductorB] = scBlock($this, '2026-10-01');
    foreach ([[$driverB, 'driver'], [$conductorB, 'conductor']] as [$user, $portal]) {
        $card = scCard($this, $user, $stale, $portal);
        expect($card['section'])->toBe('overdue');
        expect($card['is_overdue'])->toBeTrue();
        expect($card['overdue_reason'])->toBe(SC_PAST);
        expect($card['section'])->not->toBe('today');
    }

    // A leg finished but the block was left half done: still Overdue, and it says it is part way through.
    scLegs($stale, ['completed', 'scheduled', 'scheduled', 'scheduled']);
    $half = scCard($this, $driverB, $stale, 'driver');
    expect($half['section'])->toBe('overdue');
    expect($half['status'])->toBe('in-progress');
});

it('decides today, upcoming and overdue by the Manila day, across midnight', function () {
    [$block, , $driver] = scBlock($this, '2026-10-04');

    Carbon::setTestNow(Carbon::parse('2026-10-04 23:59:00', 'Asia/Manila')); // 15:59 UTC, still the 4th
    expect(scCard($this, $driver, $block, 'driver')['section'])->toBe('today');

    Carbon::setTestNow(Carbon::parse('2026-10-05 00:01:00', 'Asia/Manila')); // 16:01 UTC on the 4th: Manila is already the 5th
    $card = scCard($this, $driver, $block, 'driver');
    expect($card['section'])->toBe('overdue');
    expect($card['can_respond'])->toBeFalse();

    Carbon::setTestNow(Carbon::parse('2026-10-03 23:59:00', 'Asia/Manila'));
    expect(scCard($this, $driver, $block, 'driver')['section'])->toBe('upcoming');
});

it('refuses to accept or decline a block whose date has passed, and says why on the card', function () {
    [$block, , $driver, $conductor] = scBlock($this, '2026-10-03');

    $card = scCard($this, $driver, $block, 'driver');
    expect($card['assignment_status'])->toBe('pending');
    expect($card['can_respond'])->toBeFalse();
    expect($card['can_decline'])->toBeFalse();
    expect($card['respond_blocked_reason'])->toBe(SC_PAST);

    $base = "/api/driver/assignments/block-{$block->shift_block_id}";
    $this->actingAs($driver)->patchJson("$base/accept")->assertUnprocessable()->assertJsonValidationErrors('status');
    expect($this->actingAs($driver)->patchJson("$base/accept")->json('errors.status.0'))->toBe(SC_PAST);
    $this->actingAs($driver)->patchJson("$base/decline", ['reason_code' => 'sick'])->assertUnprocessable()->assertJsonValidationErrors('status');
    $this->actingAs($conductor)->patchJson("/api/conductor/assignments/block-{$block->shift_block_id}/accept")->assertUnprocessable();

    // Nothing was written.
    expect(ShiftBlockAssignment::where('shift_block_id', $block->shift_block_id)->where('status', '!=', 'pending')->count())->toBe(0);
    expect(Trip::where('shift_block_id', $block->shift_block_id)->whereNotNull('driver_accepted_at')->count())->toBe(0);
});

it('refuses the older per-trip accept and decline endpoints for a date that has passed', function () {
    [$block, , $driver, $conductor] = scBlock($this, '2026-10-03');
    $tripId = Trip::where('shift_block_id', $block->shift_block_id)->orderBy('departure_time')->value('trip_id');

    $this->actingAs($driver)->patchJson("/api/driver/trips/$tripId/accept")->assertUnprocessable();
    $this->actingAs($driver)->patchJson("/api/driver/trips/$tripId/decline", ['reason_code' => 'sick'])->assertUnprocessable();
    $this->actingAs($conductor)->patchJson("/api/conductor/trips/$tripId/accept")->assertUnprocessable();
    expect(Trip::find($tripId)->driver_accepted_at)->toBeNull();
});

it('still lets the person answer a block dated today or later', function () {
    [$block, , $driver] = scBlock($this, '2026-10-04');

    $card = scCard($this, $driver, $block, 'driver');
    expect($card['can_respond'])->toBeTrue();
    expect($card['respond_blocked_reason'])->toBeNull();
    $this->actingAs($driver)->patchJson("/api/driver/assignments/block-{$block->shift_block_id}/accept")->assertSuccessful();
});

it('explains an unanswerable card whose first leg has already started', function () {
    [$block, , $driver] = scBlock($this, '2026-10-04');
    scLegs($block, ['departed', 'scheduled', 'scheduled', 'scheduled']);

    $card = scCard($this, $driver, $block, 'driver');
    expect($card['can_respond'])->toBeFalse();
    expect($card['respond_blocked_reason'])->toContain('already started');
});

it('cancels only blocks whose legs have not started, never overwriting a finished leg', function () {
    [$block, $operator] = scBlock($this, '2026-10-04');
    scLegs($block, ['completed', 'scheduled', 'scheduled', 'scheduled']);

    $this->actingAs($operator)->patchJson("/api/operator/shift-blocks/{$block->shift_block_id}/cancel")->assertUnprocessable()->assertJsonValidationErrors('status');
    expect(Trip::where('shift_block_id', $block->shift_block_id)->orderBy('departure_time')->pluck('status')->all())->toBe(['completed', 'scheduled', 'scheduled', 'scheduled']);

    scLegs($block, ['scheduled', 'scheduled', 'scheduled', 'scheduled']);
    $this->actingAs($operator)->patchJson("/api/operator/shift-blocks/{$block->shift_block_id}/cancel")->assertSuccessful();
    expect(Trip::where('shift_block_id', $block->shift_block_id)->pluck('status')->unique()->all())->toBe(['cancelled']);
});

it('counts a block that ended normally (all legs completed) for the Rest Rule', function () {
    [$block, $operator, $driver, $conductor, $fleetRoute] = scBlock($this, '2026-10-03');
    scLegs($block, ['completed', 'completed', 'completed', 'completed']);
    // The stored status stays "scheduled" (only a hand-off sets it); the last leg ended 10 hours before the next start.
    Trip::where('shift_block_id', $block->shift_block_id)->update(['completed_at' => '2026-10-03 20:00:00']);

    $payload = [
        'fleet_route_id' => $fleetRoute->fleet_route_id, 'driver_id' => scCid($driver), 'conductor_id' => scCid($conductor),
        'block_type' => config('shift_blocks.block_types')[0], 'leg_departure_times' => ['06:00', '09:00', '12:00', '15:00'],
    ];
    $this->actingAs($operator)->postJson('/api/operator/shift-blocks', $payload + ['scheduled_date' => '2026-10-04'])
        ->assertUnprocessable()->assertJsonValidationErrors('driver_id');

    // Rested long enough: allowed.
    $this->actingAs($operator)->postJson('/api/operator/shift-blocks', $payload + ['scheduled_date' => '2026-10-06'])->assertSuccessful();
});

it('lists the Operator blocks by the derived status filter', function () {
    [$block, $operator] = scBlock($this, '2026-10-04');
    scLegs($block, ['completed', 'completed', 'completed', 'completed']); // stored status still "scheduled"

    $ids = fn (string $filter) => collect($this->actingAs($operator)->getJson("/api/operator/shift-blocks?status_filter=$filter")->assertSuccessful()->json('data'))->pluck('shift_block_id')->all();

    expect($ids('completed'))->toContain($block->shift_block_id);
    expect($ids('scheduled'))->not->toContain($block->shift_block_id);
    $row = collect($this->actingAs($operator)->getJson('/api/operator/shift-blocks')->json('data'))->firstWhere('shift_block_id', $block->shift_block_id);
    expect($row['lifecycle_status'])->toBe('completed');
    expect($row['schedule_section'])->toBe('today');
});


it('requires the assignment to be accepted before a shift starts, for the Driver and the Chauffeur', function () {
    [$block, , $driver, $conductor] = scBlock($this, '2026-10-04');

    $this->actingAs($driver)->postJson('/api/driver/shift/start')->assertUnprocessable()->assertJsonValidationErrors('shift');
    $this->actingAs($conductor)->postJson('/api/conductor/shift/start')->assertUnprocessable()->assertJsonValidationErrors('shift');
    expect($this->actingAs($driver)->postJson('/api/driver/shift/start')->json('errors.shift.0'))->toContain('Accept your assignment first');

    $this->actingAs($driver)->patchJson("/api/driver/assignments/block-{$block->shift_block_id}/accept")->assertSuccessful();
    $this->actingAs($driver)->postJson('/api/driver/shift/start')->assertSuccessful();
    // The Chauffeur's acceptance is their own: the Driver's does not start the Chauffeur's shift.
    $this->actingAs($conductor)->postJson('/api/conductor/shift/start')->assertUnprocessable();
    $this->actingAs($conductor)->patchJson("/api/conductor/assignments/block-{$block->shift_block_id}/accept")->assertSuccessful();
    $this->actingAs($conductor)->postJson('/api/conductor/shift/start')->assertSuccessful();
});

it('issues no pairing key and no PIN until the assignment is accepted', function () {
    [$block, , $driver, $conductor] = scBlock($this, '2026-10-04');

    $this->actingAs($driver)->getJson('/api/driver/pairing-token')->assertUnprocessable()->assertJsonValidationErrors('assignment');
    $this->actingAs($driver)->getJson('/api/driver/pin')->assertUnprocessable();
    $this->actingAs($conductor)->getJson('/api/conductor/pairing-token')->assertUnprocessable();
    $this->actingAs($conductor)->postJson('/api/conductor/pair', ['pin_code' => '123456'])->assertUnprocessable();

    $this->actingAs($driver)->patchJson("/api/driver/assignments/block-{$block->shift_block_id}/accept")->assertSuccessful();
    $this->actingAs($driver)->getJson('/api/driver/pairing-token')->assertSuccessful()->assertJsonStructure(['data' => ['token', 'pin_code']]);
});

it('lets a replacement accept the legs still to run after earlier legs finished, but not once one is under way', function () {
    [$block, $operator, $driver] = scBlock($this, '2026-10-04');
    $this->actingAs($driver)->patchJson("/api/driver/assignments/block-{$block->shift_block_id}/accept")->assertSuccessful();
    scLegs($block, ['completed', 'scheduled', 'scheduled', 'scheduled']);

    $replacement = scStaff('driver');
    $this->actingAs($operator)->patchJson("/api/operator/shift-blocks/{$block->shift_block_id}", ['driver_id' => scCid($replacement)])->assertSuccessful();

    $this->actingAs($replacement)->postJson('/api/driver/shift/start')->assertUnprocessable(); // not accepted yet
    expect(scCard($this, $replacement, $block, 'driver')['can_respond'])->toBeTrue();
    $this->actingAs($replacement)->patchJson("/api/driver/assignments/block-{$block->shift_block_id}/accept")->assertSuccessful();
    $this->actingAs($replacement)->postJson('/api/driver/shift/start')->assertSuccessful();

    // A leg under way: nobody can still accept (or decline) it.
    scLegs($block, ['completed', 'departed', 'scheduled', 'scheduled']);
    $other = scStaff('driver');
    $this->actingAs($operator)->patchJson("/api/operator/shift-blocks/{$block->shift_block_id}", ['driver_id' => scCid($other)])->assertSuccessful();
    $this->actingAs($other)->patchJson("/api/driver/assignments/block-{$block->shift_block_id}/accept")->assertUnprocessable();
});

it('does not keep a person busy for a day because of a block whose every leg was cancelled', function () {
    [$block, $operator, $driver, $conductor, $fleetRoute] = scBlock($this, '2026-10-06');
    $payload = [
        'fleet_route_id' => $fleetRoute->fleet_route_id, 'driver_id' => scCid($driver), 'conductor_id' => scCid($conductor),
        'block_type' => config('shift_blocks.block_types')[0], 'scheduled_date' => '2026-10-06', 'leg_departure_times' => ['06:00', '09:00', '12:00', '15:00'],
    ];

    // A second block the same day is refused while the first has live legs...
    $this->actingAs($operator)->postJson('/api/operator/shift-blocks', $payload)->assertUnprocessable();

    // ...but not once every leg of the first was cancelled one by one (its stored status is still "scheduled").
    scLegs($block, ['cancelled', 'cancelled', 'cancelled', 'cancelled']);
    expect($block->fresh()->status)->toBe('scheduled');
    $this->actingAs($operator)->postJson('/api/operator/shift-blocks', $payload)->assertSuccessful();
});