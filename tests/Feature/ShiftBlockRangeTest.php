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
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

// Phase B (smarttransit_prompt.md §4.13): the Operator schedules the same block on every day From..To. The server
// previews each day with the single-block rules plus the rest-day rule, then creates the clean days in one go.

function sbrStaff(string $role): User
{
    $user = User::create(['username' => 'sbr'.Str::random(8), 'email' => Str::random(8).'@example.com', 'password' => bcrypt('password123'), 'role' => $role]);
    StaffUser::create(['user_id' => $user->user_id, 'company_user_uuid' => (string) Str::uuid(), 'name' => 'Sbr '.ucfirst($role), 'phone_num' => '09170000050', 'address' => 'x']);

    return $user->fresh();
}

function sbrId(User $user): int
{
    return (int) $user->companyProfile->company_user_id;
}

/** An Operator's bus on a two-stop route (05:00 to 22:00), a Driver and a Chauffeur; blocks have 2 legs. */
function sbrFixture(): array
{
    config(['shift_blocks.legs_per_block' => 2]);
    $operator = sbrStaff('operator');
    $driver = sbrStaff('driver');
    $conductor = sbrStaff('conductor');

    $route = Route::create(['route_name' => 'Range Route', 'origin' => 'Alpha', 'destination' => 'Omega']);
    foreach ([['Alpha', 0, 'terminal'], ['Omega', 10, 'terminal']] as $i => [$name, $km, $type]) {
        $stop = Stop::create(['stop_name' => $name, 'latitude' => 7.0 + $km / 100, 'longitude' => 125.0, 'municipality' => 'Mati City']);
        RouteStop::create(['route_id' => $route->route_id, 'stop_id' => $stop->stop_id, 'stop_order' => $i + 1, 'distance_from_origin_km' => $km, 'stop_type' => $type]);
    }
    $fleet = Fleet::create(['company_user_id' => sbrId($operator), 'plate_number' => 'SBR-'.fake()->unique()->numerify('####'), 'capacity' => 40, 'seated_capacity' => 30, 'standing_capacity' => 10, 'status' => 'active']);
    $fleetRoute = FleetRoute::create(['fleet_id' => $fleet->fleet_id, 'route_id' => $route->route_id, 'start_time' => '05:00', 'end_time' => '22:00', 'status' => 'active']);

    return compact('operator', 'driver', 'conductor', 'fleetRoute');
}

function sbrBody(array $f, string $from, string $to): array
{
    return [
        'fleet_route_id' => $f['fleetRoute']->fleet_route_id, 'driver_id' => sbrId($f['driver']), 'conductor_id' => sbrId($f['conductor']),
        'block_type' => 'morning', 'from_date' => $from, 'to_date' => $to, 'leg_departure_times' => ['08:00', '11:00'],
    ];
}

/** A trip for this person on $date that is not part of a block, so the day counts as a work day. */
function sbrWorkDay(array $f, string $role, string $date, string $time = '15:00'): Trip
{
    return Trip::create([
        'fleet_route_id' => $f['fleetRoute']->fleet_route_id, 'company_user_id' => sbrId($f['operator']), 'trip_date' => $date, 'departure_time' => $time,
        'trip_type' => 'one_way', 'leg_direction' => 'outbound', 'status' => 'completed',
        $role.'_id' => sbrId($f[$role]), 'current_seated_capacity' => 0, 'current_standing_capacity' => 0, 'total_occupancy' => 0,
    ]);
}

beforeEach(fn () => Carbon::setTestNow(Carbon::parse('2026-10-06 07:00:00', 'Asia/Manila')));
afterEach(fn () => Carbon::setTestNow());

it('previews every day of the range and saves nothing', function () {
    $f = sbrFixture();

    $preview = $this->actingAs($f['operator'])->postJson('/api/operator/shift-blocks/range/preview', sbrBody($f, '2026-10-07', '2026-10-11'))
        ->assertSuccessful()->json('data');

    expect(collect($preview['days'])->pluck('date')->all())->toBe(['2026-10-07', '2026-10-08', '2026-10-09', '2026-10-10', '2026-10-11'])
        ->and(collect($preview['days'])->every(fn ($d) => $d['ok'] && $d['reason'] === null))->toBeTrue()
        ->and($preview['create_count'])->toBe(5)->and($preview['skip_count'])->toBe(0);
    expect(ShiftBlock::count())->toBe(0)->and(Trip::count())->toBe(0);
});

it('creates one block with its legs on every clean day', function () {
    $f = sbrFixture();

    $result = $this->actingAs($f['operator'])->postJson('/api/operator/shift-blocks/range', sbrBody($f, '2026-10-07', '2026-10-11'))
        ->assertSuccessful()->assertJsonPath('message', '5 days scheduled')->json('data');

    expect($result['create_count'])->toBe(5);
    expect(ShiftBlock::orderBy('scheduled_date')->get()->map(fn ($b) => $b->scheduled_date->toDateString())->all())
        ->toBe(['2026-10-07', '2026-10-08', '2026-10-09', '2026-10-10', '2026-10-11']);
    foreach ($result['days'] as $day) {
        $legs = Trip::where('shift_block_id', $day['shift_block_id'])->orderBy('departure_time')->get();
        expect($legs->pluck('leg_direction')->all())->toBe(['outbound', 'reverse'])
            ->and($legs->every(fn ($t) => $t->trip_date->toDateString() === $day['date'] && $t->status === 'scheduled'))->toBeTrue();
    }
});

it('skips a day the Driver is already booked and never changes the existing block', function () {
    $f = sbrFixture();
    $single = $this->actingAs($f['operator'])->postJson('/api/operator/shift-blocks', array_merge(
        collect(sbrBody($f, '', ''))->except(['from_date', 'to_date'])->all(),
        ['scheduled_date' => '2026-10-09'],
    ))->assertSuccessful()->json('data');
    $before = ShiftBlock::find($single['shift_block_id'])->toArray();

    $result = $this->actingAs($f['operator'])->postJson('/api/operator/shift-blocks/range', sbrBody($f, '2026-10-08', '2026-10-10'))
        ->assertSuccessful()->assertJsonPath('message', '2 days scheduled, 1 skipped')->json('data');

    $skipped = collect($result['days'])->firstWhere('date', '2026-10-09');
    expect($skipped['ok'])->toBeFalse()->and($skipped['reason'])->toBe('This driver already has a shift block scheduled for this date.')
        ->and($skipped)->not->toHaveKey('shift_block_id');
    expect(ShiftBlock::count())->toBe(3);
    expect(ShiftBlock::find($single['shift_block_id'])->toArray())->toEqual($before);
});

it('skips a day when the bus is already on another trip at a leg time', function () {
    $f = sbrFixture();
    sbrWorkDay($f, 'driver', '2026-10-08', '08:00')->update(['status' => 'scheduled', 'driver_id' => null]);

    $preview = $this->actingAs($f['operator'])->postJson('/api/operator/shift-blocks/range/preview', sbrBody($f, '2026-10-07', '2026-10-09'))
        ->assertSuccessful()->json('data');

    $day = collect($preview['days'])->firstWhere('date', '2026-10-08');
    expect($day['ok'])->toBeFalse()->and($day['reason'])->toContain('This bus is already on');
    expect($preview['create_count'])->toBe(2);
});

it('needs a rest day after six work days in a row, counting earlier days of the range', function () {
    $f = sbrFixture();

    $preview = $this->actingAs($f['operator'])->postJson('/api/operator/shift-blocks/range/preview', sbrBody($f, '2026-10-07', '2026-10-14'))
        ->assertSuccessful()->json('data');

    $byDate = collect($preview['days'])->keyBy('date');
    expect($byDate['2026-10-12']['ok'])->toBeTrue(); // the 6th day in a row
    expect($byDate['2026-10-13']['ok'])->toBeFalse()
        ->and($byDate['2026-10-13']['reason'])->toBe('The Driver would work 7 days in a row. A rest day is needed after 6 work days.');
    expect($byDate['2026-10-14']['ok'])->toBeTrue(); // the 13th is the rest day
});

it('counts work the Chauffeur already has before the range', function () {
    $f = sbrFixture();
    foreach (['2026-10-04', '2026-10-05', '2026-10-06'] as $date) {
        sbrWorkDay($f, 'conductor', $date);
    }

    $preview = $this->actingAs($f['operator'])->postJson('/api/operator/shift-blocks/range/preview', sbrBody($f, '2026-10-07', '2026-10-10'))
        ->assertSuccessful()->json('data');

    $byDate = collect($preview['days'])->keyBy('date');
    expect($byDate['2026-10-09']['ok'])->toBeTrue(); // Oct 4 to 9: six days
    expect($byDate['2026-10-10']['reason'])->toBe('The Chauffeur would work 7 days in a row. A rest day is needed after 6 work days.');
});

it('creates nothing twice when the same range is submitted again', function () {
    $f = sbrFixture();
    $body = sbrBody($f, '2026-10-07', '2026-10-09');

    $this->actingAs($f['operator'])->postJson('/api/operator/shift-blocks/range', $body)->assertSuccessful();
    $again = $this->actingAs($f['operator'])->postJson('/api/operator/shift-blocks/range', $body)
        ->assertSuccessful()->assertJsonPath('message', '0 days scheduled, 3 skipped')->json('data');

    expect($again['create_count'])->toBe(0)
        ->and(collect($again['days'])->pluck('reason')->unique()->all())->toBe(['This driver already has a shift block scheduled for this date.']);
    expect(ShiftBlock::count())->toBe(3)->and(Trip::count())->toBe(6);
});

it('accepts at most 31 days, a To date on or after the From date, and no past From date', function () {
    $f = sbrFixture();
    $preview = fn (string $from, string $to) => $this->actingAs($f['operator'])->postJson('/api/operator/shift-blocks/range/preview', sbrBody($f, $from, $to));

    $preview('2026-10-07', '2026-11-06')->assertSuccessful(); // 31 days
    $preview('2026-10-07', '2026-11-07')->assertStatus(422)->assertJsonValidationErrors(['to_date'])->assertJsonFragment(['Choose at most 31 days.']);
    $preview('2026-10-09', '2026-10-08')->assertStatus(422)->assertJsonFragment(['The To date must be on or after the From date.']);
    $preview('2026-10-05', '2026-10-08')->assertStatus(422)->assertJsonFragment(['The From date cannot be in the past.']);

    config(['shift_blocks.range_max_days' => 7]);
    $preview('2026-10-07', '2026-10-14')->assertStatus(422)->assertJsonFragment(['Choose at most 7 days.']);
    expect(ShiftBlock::count())->toBe(0);
});

it('is for the Operator only', function () {
    $f = sbrFixture();

    $this->actingAs($f['driver'])->postJson('/api/operator/shift-blocks/range', sbrBody($f, '2026-10-07', '2026-10-08'))->assertForbidden();
    expect(ShiftBlock::count())->toBe(0);
});
