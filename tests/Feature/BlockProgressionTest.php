<?php

use App\Models\ConductorShiftLog;
use App\Models\DriverShiftLog;
use App\Models\Fleet;
use App\Models\FleetDailyPin;
use App\Models\FleetRoute;
use App\Models\Payment;
use App\Models\Route;
use App\Models\RouteStop;
use App\Models\ShiftBlock;
use App\Models\StaffUser;
use App\Models\Stop;
use App\Models\Ticket;
use App\Models\Trip;
use App\Models\User;
use App\Repositories\FleetLocationRepository;
use App\Repositories\RouteStopRepository;
use App\Services\FleetPairingService;
use App\Services\LegProgressionService;
use App\Services\ShiftBlockService;
use App\Support\TripTiming;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

// Batch 27 Part 2, Task 1: pairing and the daily PIN belong to the shift block; legs progress in order.

function bpStaff(string $role): User
{
    $user = User::create(['username' => $role.Str::random(6), 'email' => Str::random(8).'@example.com', 'password' => bcrypt('password123'), 'role' => $role]);
    StaffUser::create(['user_id' => $user->user_id, 'company_user_uuid' => (string) Str::uuid(), 'name' => ucfirst($role).' '.Str::random(3), 'phone_num' => '0917', 'address' => 'x']);

    return $user->fresh();
}

function bpCid(User $user): int
{
    return (int) $user->companyProfile->company_user_id;
}

/** One route with a terminal at each end, one fleet, one block of $legs legs for a driver and a Chauffeur. */
function bpBlock($test, int $legs = 4, string $date = '2026-10-04', ?array $crew = null, ?FleetRoute $fleetRoute = null): array
{
    config(['shift_blocks.legs_per_block' => $legs, 'shift_blocks.leg_location_rule_enabled' => false]);
    $operator = $crew['operator'] ?? bpStaff('operator');
    $driver = $crew['driver'] ?? bpStaff('driver');
    $conductor = $crew['conductor'] ?? bpStaff('conductor');

    if (! $fleetRoute) {
        $route = Route::create(['route_name' => 'Block Route', 'origin' => 'Alpha', 'destination' => 'Omega']);
        foreach ([['Alpha', 7.00, 0, 'terminal'], ['Omega', 7.09, 10, 'terminal']] as $i => [$name, $lat, $km, $type]) {
            $stop = Stop::create(['stop_name' => $name, 'latitude' => $lat, 'longitude' => 125.0, 'municipality' => 'Mati City']);
            RouteStop::create(['route_id' => $route->route_id, 'stop_id' => $stop->stop_id, 'stop_order' => $i + 1, 'distance_from_origin_km' => $km, 'stop_type' => $type]);
        }
        $fleet = Fleet::create(['company_user_id' => bpCid($operator), 'plate_number' => 'BP-'.fake()->unique()->numerify('####'), 'capacity' => 40, 'seated_capacity' => 30, 'standing_capacity' => 10, 'status' => 'active']);
        $fleetRoute = FleetRoute::create(['fleet_id' => $fleet->fleet_id, 'route_id' => $route->route_id, 'start_time' => '05:00', 'end_time' => '22:00', 'status' => 'active']);
    }

    $times = collect(range(0, $legs - 1))->map(fn ($i) => sprintf('%02d:00', 8 + $i * 3))->all();
    $blockId = $test->actingAs($operator)->postJson('/api/operator/shift-blocks', [
        'fleet_route_id' => $fleetRoute->fleet_route_id, 'driver_id' => bpCid($driver), 'conductor_id' => bpCid($conductor),
        'block_type' => 'morning', 'scheduled_date' => $date, 'leg_departure_times' => $times,
    ])->assertSuccessful()->json('data.shift_block_id');

    // Batch 29: a shift starts and a pairing key is issued only after the assignment is accepted.
    Trip::where('shift_block_id', $blockId)->update(['driver_accepted_at' => now(), 'conductor_accepted_at' => now()]);
    $block = ShiftBlock::find($blockId);
    $tripLegs = Trip::where('shift_block_id', $blockId)->orderBy('departure_time')->get();

    return [$block, $tripLegs, compact('operator', 'driver', 'conductor'), $fleetRoute];
}

/** Driver and Chauffeur pair through the real token + scan flow, on the staff member's current leg. */
function bpPair($test, array $crew): void
{
    $token = $test->actingAs($crew['driver'])->getJson('/api/driver/pairing-token')->assertSuccessful()->json('data.token');
    $test->actingAs($crew['conductor'])->postJson('/api/conductor/pair', ['token' => $token])->assertSuccessful();
}

/**
 * Runs one leg end to end as the driver: boarding, depart, the final stop, complete. Phase A: the clock moves to the
 * leg's departure (never back), because Start Boarding and Depart wait for it.
 */
function bpRunLeg($test, User $driver, Trip $leg): void
{
    bpAtDeparture($leg);
    $test->actingAs($driver)->patchJson("/api/driver/trips/{$leg->trip_id}/boarding")->assertSuccessful();
    $test->actingAs($driver)->patchJson("/api/driver/trips/{$leg->trip_id}/depart")->assertSuccessful();
    bpAcknowledgeFinalStop($test, $driver, $leg);
    $test->actingAs($driver)->patchJson("/api/driver/trips/{$leg->trip_id}/complete")->assertSuccessful();
}

/** Moves the test clock to the leg's scheduled departure, unless it is already later. */
function bpAtDeparture(Trip $leg): void
{
    $departure = TripTiming::departureAt($leg->fresh());
    if ($departure && Carbon::now()->lt($departure)) {
        Carbon::setTestNow($departure);
    }
}

/** The driver acknowledges the leg's last stop in travel order, which End Trip requires. */
function bpAcknowledgeFinalStop($test, User $driver, Trip $leg): void
{
    $leg = $leg->fresh('fleetRoute');
    $final = app(RouteStopRepository::class)->getOrderedStopsForDirection($leg->fleetRoute->route_id, $leg->leg_direction)->last();
    if (! $final) {
        return; // a route without stops has nothing to acknowledge, and End Trip does not wait for one
    }
    $test->actingAs($driver)->postJson("/api/driver/trips/current/stops/{$final->stop_id}/acknowledge")->assertSuccessful();
}

beforeEach(fn () => Carbon::setTestNow(Carbon::parse('2026-10-04 07:00:00', 'Asia/Manila')));
afterEach(fn () => Carbon::setTestNow());

it('makes one pairing and one PIN per block per day, valid for every leg', function () {
    [$block, $legs, $crew] = bpBlock($this);
    $this->actingAs($crew['driver'])->postJson('/api/driver/shift/start')->assertSuccessful();
    $this->actingAs($crew['conductor'])->postJson('/api/conductor/shift/start')->assertSuccessful();
    bpPair($this, $crew);

    expect(FleetDailyPin::count())->toBe(1);
    $pin = FleetDailyPin::first();
    expect($pin->shift_block_id)->toBe($block->shift_block_id);
    expect($pin->trip_id)->toBe($legs->first()->trip_id); // the first leg stands for the block

    foreach ($legs as $leg) {
        expect(app(FleetPairingService::class)->getPairingStatus($leg->trip_id)['paired'])->toBeTrue();
    }
});

it('keeps the driver shift open across the legs and closes it at End Shift, not at each End Trip', function () {
    [, $legs, $crew] = bpBlock($this, 2);
    $this->actingAs($crew['driver'])->postJson('/api/driver/shift/start')->assertSuccessful();
    $this->actingAs($crew['conductor'])->postJson('/api/conductor/shift/start')->assertSuccessful();
    bpPair($this, $crew);

    bpRunLeg($this, $crew['driver'], $legs[0]);

    // Leg 1 is over, the shift is not: leg 2 is now the current trip for both.
    $this->actingAs($crew['driver'])->getJson('/api/driver/shift/status')->assertJsonPath('data.has_open_shift', true);
    $this->actingAs($crew['driver'])->getJson('/api/driver/trips/current')->assertSuccessful()->assertJsonPath('data.trip_id', $legs[1]->trip_id);
    $this->actingAs($crew['conductor'])->getJson('/api/conductor/trips/current')->assertSuccessful()->assertJsonPath('data.trip_id', $legs[1]->trip_id);

    // Leg 2 needs no new shift, no new pairing and no new PIN.
    bpRunLeg($this, $crew['driver'], $legs[1]);
    expect(FleetDailyPin::count())->toBe(1);
    expect(DriverShiftLog::whereNull('ended_at')->count())->toBe(1);

    $this->actingAs($crew['driver'])->postJson('/api/driver/shift/end')->assertSuccessful();
    $this->actingAs($crew['conductor'])->postJson('/api/conductor/shift/end')->assertSuccessful();
    expect(DriverShiftLog::whereNull('ended_at')->count())->toBe(0);
    expect(ConductorShiftLog::whereNull('ended_at')->count())->toBe(0);
});

it('runs a four-leg block with even (reverse) legs in order on one shift and one pairing', function () {
    [, $legs, $crew] = bpBlock($this, 4);
    expect($legs->pluck('leg_direction')->all())->toBe(['outbound', 'reverse', 'outbound', 'reverse']);
    $this->actingAs($crew['driver'])->postJson('/api/driver/shift/start')->assertSuccessful();
    $this->actingAs($crew['conductor'])->postJson('/api/conductor/shift/start')->assertSuccessful();
    bpPair($this, $crew);

    foreach ($legs as $leg) {
        bpRunLeg($this, $crew['driver'], $leg);
    }

    expect(Trip::where('shift_block_id', $legs[0]->shift_block_id)->where('status', 'completed')->count())->toBe(4);
    expect(FleetDailyPin::count())->toBe(1);
    $this->actingAs($crew['driver'])->postJson('/api/driver/shift/end')->assertSuccessful();
});

it('refuses an out-of-order start: leg N+1 only after leg N is completed or cancelled', function () {
    [, $legs, $crew] = bpBlock($this, 4);
    $this->actingAs($crew['driver'])->postJson('/api/driver/shift/start')->assertSuccessful();
    $this->actingAs($crew['conductor'])->postJson('/api/conductor/shift/start')->assertSuccessful();
    bpPair($this, $crew);

    $this->actingAs($crew['driver'])->patchJson("/api/driver/trips/{$legs[2]->trip_id}/boarding")
        ->assertStatus(422)->assertJsonPath('message', fn ($m) => str_contains($m, 'Finish leg 1'));
    $this->actingAs($crew['driver'])->patchJson("/api/driver/trips/{$legs[1]->trip_id}/depart")->assertStatus(422);
    // The dispatcher is held to the same order.
    $this->actingAs($crew['operator'])->patchJson("/api/operator/trips/{$legs[1]->trip_id}/boarding")->assertStatus(422);
    expect($legs[2]->fresh()->status)->not->toBe('boarding');

    // A cancelled leg counts as finished.
    $legs[0]->update(['status' => 'cancelled']);
    bpAtDeparture($legs[1]);
    $this->actingAs($crew['driver'])->patchJson("/api/driver/trips/{$legs[1]->trip_id}/boarding")->assertSuccessful();
});

it('requires the bus at the leg origin terminal from the second leg on, for the driver', function () {
    [, $legs, $crew] = bpBlock($this, 2);
    $this->actingAs($crew['driver'])->postJson('/api/driver/shift/start')->assertSuccessful();
    $this->actingAs($crew['conductor'])->postJson('/api/conductor/shift/start')->assertSuccessful();
    bpPair($this, $crew);
    config(['shift_blocks.leg_location_rule_enabled' => true]);

    // Leg 1 starts wherever the day begins: the rule is for the legs after it.
    bpRunLeg($this, $crew['driver'], $legs[0]);

    $fleetId = $legs[1]->fleetRoute->fleet_id;
    $url = "/api/driver/trips/{$legs[1]->trip_id}/boarding";

    // No GPS at all: refused, never guessed.
    $this->actingAs($crew['driver'])->patchJson($url)->assertStatus(422)->assertJsonPath('message', fn ($m) => str_contains($m, 'No GPS position'));

    // Leg 2 is a reverse leg: it starts at the route's LAST stop (Omega, 7.09). The first stop is 10 km away.
    app(FleetLocationRepository::class)->upsertLocation(['fleet_id' => $fleetId, 'trip_id' => $legs[0]->trip_id, 'latitude' => 7.00, 'longitude' => 125.0]);
    $this->actingAs($crew['driver'])->patchJson($url)->assertStatus(422)->assertJsonPath('message', fn ($m) => str_contains($m, 'Omega'));

    app(FleetLocationRepository::class)->upsertLocation(['fleet_id' => $fleetId, 'trip_id' => $legs[0]->trip_id, 'latitude' => 7.0901, 'longitude' => 125.0]);
    bpAtDeparture($legs[1]);
    $this->actingAs($crew['driver'])->patchJson($url)->assertSuccessful();
});

it('lets the Operator, as dispatcher, start a leg without the location check but never out of order', function () {
    [, $legs, $crew] = bpBlock($this, 2);
    $this->actingAs($crew['driver'])->postJson('/api/driver/shift/start')->assertSuccessful();
    $this->actingAs($crew['conductor'])->postJson('/api/conductor/shift/start')->assertSuccessful();
    bpPair($this, $crew);
    config(['shift_blocks.leg_location_rule_enabled' => true]);

    $this->actingAs($crew['operator'])->patchJson("/api/operator/trips/{$legs[1]->trip_id}/boarding")->assertStatus(422); // order
    $legs[0]->update(['status' => 'completed']);
    bpAtDeparture($legs[1]);
    $this->actingAs($crew['operator'])->patchJson("/api/operator/trips/{$legs[1]->trip_id}/boarding")->assertSuccessful(); // no GPS needed
});

it('does not let a client use another block\'s shift or pairing', function () {
    [, $legsA, $crew] = bpBlock($this, 2, '2026-10-04');
    [, $legsB] = bpBlock($this, 2, '2026-10-05', $crew, $legsA[0]->fleetRoute);
    $this->actingAs($crew['driver'])->postJson('/api/driver/shift/start')->assertSuccessful();
    $this->actingAs($crew['conductor'])->postJson('/api/conductor/shift/start')->assertSuccessful();
    bpPair($this, $crew);

    // Block B's first leg: the driver's shift is on block A.
    $this->actingAs($crew['driver'])->patchJson("/api/driver/trips/{$legsB[0]->trip_id}/boarding")->assertStatus(422);
    // And block A's pairing says nothing about block B.
    expect(app(FleetPairingService::class)->getPairingStatus($legsB[0]->trip_id)['paired'])->toBeFalse();

    // Someone else's trip is refused outright.
    $stranger = bpStaff('driver');
    $this->actingAs($stranger)->patchJson("/api/driver/trips/{$legsA[0]->trip_id}/boarding")->assertForbidden();
});

it('blocks End Shift while a leg is unfinished, for the driver and the Chauffeur', function () {
    [, $legs, $crew] = bpBlock($this, 2);
    $this->actingAs($crew['driver'])->postJson('/api/driver/shift/start')->assertSuccessful();
    $this->actingAs($crew['conductor'])->postJson('/api/conductor/shift/start')->assertSuccessful();
    bpPair($this, $crew);
    bpRunLeg($this, $crew['driver'], $legs[0]);

    $this->actingAs($crew['driver'])->postJson('/api/driver/shift/end')->assertStatus(422)->assertJsonValidationErrors('shift');
    $this->actingAs($crew['conductor'])->postJson('/api/conductor/shift/end')->assertStatus(422)->assertJsonValidationErrors('shift');
    $this->actingAs($crew['driver'])->getJson('/api/driver/shift/status')
        ->assertJsonPath('data.is_block_shift', true)
        ->assertJsonPath('data.end_blocked_reason', fn ($r) => str_contains((string) $r, '1 leg(s)'));
});

it('gives a second day its own pairing and PIN', function () {
    [$blockA, $legsA, $crew] = bpBlock($this, 2, '2026-10-04');
    $this->actingAs($crew['driver'])->postJson('/api/driver/shift/start')->assertSuccessful();
    $this->actingAs($crew['conductor'])->postJson('/api/conductor/shift/start')->assertSuccessful();
    bpPair($this, $crew);
    bpRunLeg($this, $crew['driver'], $legsA[0]);
    bpRunLeg($this, $crew['driver'], $legsA[1]);
    $this->actingAs($crew['driver'])->postJson('/api/driver/shift/end')->assertSuccessful();
    $this->actingAs($crew['conductor'])->postJson('/api/conductor/shift/end')->assertSuccessful();

    [$blockB, $legsB] = bpBlock($this, 2, '2026-10-05', $crew, $legsA[0]->fleetRoute);
    Carbon::setTestNow(Carbon::parse('2026-10-05 07:00:00', 'Asia/Manila'));
    // Rest Rule aside, day two starts clean: no pairing yet.
    expect(app(FleetPairingService::class)->getPairingStatus($legsB[0]->trip_id)['paired'])->toBeFalse();
    $this->actingAs($crew['driver'])->postJson('/api/driver/shift/start')->assertSuccessful();
    $this->actingAs($crew['conductor'])->postJson('/api/conductor/shift/start')->assertSuccessful();
    bpPair($this, $crew);

    expect(FleetDailyPin::count())->toBe(2);
    expect(FleetDailyPin::pluck('shift_block_id')->sort()->values()->all())->toBe([$blockA->shift_block_id, $blockB->shift_block_id]);
    expect(FleetDailyPin::pluck('pin_code')->unique()->count())->toBeGreaterThanOrEqual(1);
});

it('keeps hand-off between blocks working: the incoming driver is paired for all of their legs', function () {
    [$blockA, $legsA, $crewA] = bpBlock($this, 2, '2026-10-04');
    foreach ($legsA as $leg) {
        $leg->update(['status' => 'completed']);
    }
    $blockA->update(['status' => 'in_progress']);
    app(ShiftBlockService::class)->initiateHandoff($blockA->shift_block_id, null, true); // dispatcher override
    expect($blockA->fresh()->status)->toBe('completed');

    // The incoming driver (with the same Chauffeur) takes over the next block on the same bus.
    $incoming = bpStaff('driver');
    [$blockB, $legsB, $crewB] = bpBlock($this, 2, '2026-10-05', ['operator' => $crewA['operator'], 'driver' => $incoming, 'conductor' => bpStaff('conductor')], $legsA[0]->fleetRoute);
    app(ShiftBlockService::class)->confirmTakeover($blockB->shift_block_id, null, true);
    expect($blockB->fresh()->status)->toBe('in_progress');

    Carbon::setTestNow(Carbon::parse('2026-10-05 07:00:00', 'Asia/Manila'));
    $this->actingAs($crewB['driver'])->postJson('/api/driver/shift/start')->assertSuccessful();
    $this->actingAs($crewB['conductor'])->postJson('/api/conductor/shift/start')->assertSuccessful();
    bpPair($this, $crewB);

    foreach ($legsB as $leg) {
        expect(app(FleetPairingService::class)->getPairingStatus($leg->trip_id)['paired'])->toBeTrue();
    }
    expect(FleetDailyPin::where('shift_block_id', $blockB->shift_block_id)->first()->driver_id)->toBe(bpCid($incoming));
});

it('resets pairing for the incoming driver when the Operator replaces the driver mid-block', function () {
    [$block, $legs, $crew] = bpBlock($this, 2);
    $this->actingAs($crew['driver'])->postJson('/api/driver/shift/start')->assertSuccessful();
    $this->actingAs($crew['conductor'])->postJson('/api/conductor/shift/start')->assertSuccessful();
    bpPair($this, $crew);
    bpRunLeg($this, $crew['driver'], $legs[0]);

    $replacement = bpStaff('driver');
    $this->actingAs($crew['operator'])->patchJson("/api/operator/shift-blocks/{$block->shift_block_id}", ['driver_id' => bpCid($replacement)])->assertSuccessful();

    // The old pairing does not carry over to the new person; the block's pin row is the one that is re-made.
    // Neither does the old acceptance: the replacement must accept for themselves before starting.
    $this->actingAs($replacement)->postJson('/api/driver/shift/start')->assertUnprocessable();
    $this->actingAs($replacement)->patchJson("/api/driver/assignments/block-{$block->shift_block_id}/accept")->assertSuccessful();
    $this->actingAs($replacement)->postJson('/api/driver/shift/start')->assertSuccessful();
    $token = $this->actingAs($replacement)->getJson('/api/driver/pairing-token')->assertSuccessful()->json('data.token');
    expect(FleetDailyPin::where('shift_block_id', $block->shift_block_id)->count())->toBe(1);
    // The conductor's pairing call re-makes the block's pin row for the new driver (verifications reset).
    $this->actingAs($crew['conductor'])->postJson('/api/conductor/pair', ['token' => $token])->assertSuccessful();
    expect(FleetDailyPin::where('shift_block_id', $block->shift_block_id)->count())->toBe(1);
    expect(FleetDailyPin::first()->driver_id)->toBe(bpCid($replacement));
});

it('keeps a standalone trip as before: End Trip closes the driver shift, and a driver cannot End Shift', function () {
    config(['shift_blocks.leg_location_rule_enabled' => false]);
    $operator = bpStaff('operator');
    $driver = bpStaff('driver');
    $conductor = bpStaff('conductor');
    $route = Route::create(['route_name' => 'Solo', 'origin' => 'A', 'destination' => 'B']);
    $fleet = Fleet::create(['company_user_id' => bpCid($operator), 'plate_number' => 'SO-'.fake()->unique()->numerify('###'), 'capacity' => 40, 'seated_capacity' => 30, 'standing_capacity' => 10, 'status' => 'active']);
    $fr = FleetRoute::create(['fleet_id' => $fleet->fleet_id, 'route_id' => $route->route_id, 'start_time' => '05:00', 'end_time' => '22:00', 'status' => 'active']);
    $trip = Trip::create(['fleet_route_id' => $fr->fleet_route_id, 'company_user_id' => bpCid($operator), 'trip_date' => '2026-10-04', 'departure_time' => '08:00', 'trip_type' => 'one_way', 'leg_direction' => 'outbound', 'status' => 'scheduled', 'driver_id' => bpCid($driver), 'conductor_id' => bpCid($conductor), 'driver_accepted_at' => now(), 'conductor_accepted_at' => now(), 'current_seated_capacity' => 0, 'current_standing_capacity' => 0, 'total_occupancy' => 0]);

    $this->actingAs($driver)->postJson('/api/driver/shift/start')->assertSuccessful();
    $this->actingAs($conductor)->postJson('/api/conductor/shift/start')->assertSuccessful();
    bpPair($this, compact('driver', 'conductor'));
    expect(FleetDailyPin::first()->shift_block_id)->toBeNull();

    $this->actingAs($driver)->postJson('/api/driver/shift/end')->assertForbidden();
    bpRunLeg($this, $driver, $trip);
    expect(DriverShiftLog::whereNull('ended_at')->count())->toBe(0);
});

it('repairs stale dev state by dry run first, and never touches started, completed or ticketed legs', function () {
    config(['shift_blocks.leg_location_rule_enabled' => false]);
    [$block, $legs, $crew] = bpBlock($this, 4, '2026-10-01'); // earlier than "today" (2026-10-04)
    $legs[0]->update(['status' => 'boarding']);
    $legs[1]->update(['status' => 'delayed']);
    $legs[2]->update(['status' => 'delayed']);
    $legs[3]->update(['status' => 'delayed']);
    $ticketed = $legs[3];
    $payment = Payment::create(['amount' => 10, 'payment_created' => now(), 'transaction_reference' => 'TXN-BP-'.Str::random(6), 'payment_method' => 'online', 'payment_channel' => 'gcash', 'status' => 'paid', 'payment_uuid' => (string) Str::uuid(), 'is_valid' => true, 'items_payload' => []]);
    Ticket::create(['fleet_route_id' => $ticketed->fleet_route_id, 'trip_id' => $ticketed->trip_id, 'payment_id' => $payment->payment_id, 'ticket_uuid' => (string) Str::uuid(), 'status' => 'valid', 'amount' => 10, 'distance_km' => 1, 'seat_type' => 'seated']);
    ConductorShiftLog::create(['conductor_company_user_id' => bpCid($crew['conductor']), 'trip_id' => $legs[0]->trip_id, 'fleet_id' => $legs[0]->fleetRoute->fleet_id, 'shift_date' => '2026-10-01', 'started_at' => now()->subDays(3)]);

    // A started block on another day is left whole.
    [$other, $otherLegs] = bpBlock($this, 2, '2026-10-02', ['operator' => $crew['operator'], 'driver' => bpStaff('driver'), 'conductor' => bpStaff('conductor')], $legs[0]->fleetRoute);
    $otherLegs[0]->update(['status' => 'departed']);
    $otherLegs[1]->update(['status' => 'delayed']);

    Artisan::call('shift:repair-stale');
    expect($legs[0]->fresh()->status)->toBe('boarding'); // dry run changed nothing
    expect(ConductorShiftLog::whereNull('ended_at')->count())->toBe(1);

    Artisan::call('shift:repair-stale', ['--apply' => true]);
    expect($legs[0]->fresh()->status)->toBe('cancelled');
    expect($legs[1]->fresh()->status)->toBe('cancelled');
    expect($legs[3]->fresh()->status)->toBe('delayed'); // valid ticket: left for the Operator
    expect($block->fresh()->status)->toBe('scheduled'); // one live leg remains
    expect($otherLegs[0]->fresh()->status)->toBe('departed');
    expect($otherLegs[1]->fresh()->status)->toBe('delayed');
    expect($other->fresh()->status)->toBe('scheduled');
    expect(ConductorShiftLog::whereNull('ended_at')->count())->toBe(1); // the block is not finished (ticketed leg), so its shift stays

    Ticket::where('trip_id', $ticketed->trip_id)->delete();
    Artisan::call('shift:repair-stale', ['--apply' => true]);
    expect($legs[3]->fresh()->status)->toBe('cancelled');
    expect($block->fresh()->status)->toBe('cancelled');
    expect(ConductorShiftLog::whereNull('ended_at')->count())->toBe(0);
    expect(ConductorShiftLog::first()->end_source)->toBe('repair_stale');
});

it('says the leg order from data: the current leg is the first unfinished one', function () {
    [$block, $legs] = bpBlock($this, 4);
    $svc = app(LegProgressionService::class);
    expect($svc->currentLeg($block->shift_block_id)->trip_id)->toBe($legs[0]->trip_id);
    $legs[0]->update(['status' => 'completed']);
    $legs[1]->update(['status' => 'cancelled']);
    expect($svc->currentLeg($block->shift_block_id)->trip_id)->toBe($legs[2]->trip_id);
    expect($svc->pairingScopeTripId($legs[3]->trip_id))->toBe($legs[0]->trip_id);
});
