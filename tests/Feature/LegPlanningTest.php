<?php

use App\Models\Fleet;
use App\Models\FleetRoute;
use App\Models\Route;
use App\Models\RouteDurationFactor;
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

// 2026-10-27 (smarttransit_prompt.md §4.2 and §5): how many legs fit is worked out from the route's travel time (no
// fixed number); duty and driving limits are flags for the Operator; a leg never leaves before the previous one is
// back plus the turnaround; the Operator can move a leg that has not started.
// Fixture: stops at 0, 20 and 40 km with stop times 0, 20 and 40 minutes, so a leg takes 40 minutes by default.
// 2026-10-05 is a Monday.

function lplStaff(string $role): User
{
    $user = User::create(['username' => 'lpl'.Str::random(8), 'email' => Str::random(8).'@example.com', 'password' => bcrypt('password123'), 'role' => $role]);
    StaffUser::create(['user_id' => $user->user_id, 'company_user_uuid' => (string) Str::uuid(), 'name' => 'Lpl '.ucfirst($role), 'phone_num' => '09170000062', 'address' => 'x']);

    return $user->fresh();
}

function lplFixture(int $legMinutes = 40): array
{
    $operator = lplStaff('operator');
    $route = Route::create(['route_name' => 'Lpl Route', 'origin' => 'A', 'destination' => 'C']);
    foreach ([['A', 0, 'terminal'], ['B', 20, 'stop'], ['C', 40, 'terminal']] as $i => [$name, $km, $type]) {
        $stop = Stop::create(['stop_name' => "Stop $name", 'latitude' => 7.0 + $km / 100, 'longitude' => 125.0]);
        RouteStop::create([
            'route_id' => $route->route_id, 'stop_id' => $stop->stop_id, 'stop_order' => $i + 1, 'distance_from_origin_km' => $km,
            'distance_from_origin_m' => $km * 1000, 'stop_type' => $type, 'scheduled_offset_min' => (int) round($km / 40 * $legMinutes),
        ]);
    }
    $company = $operator->companyProfile->company_user_id;
    $fleet = Fleet::create(['company_user_id' => $company, 'plate_number' => 'LPL-'.fake()->unique()->numerify('####'), 'capacity' => 40, 'seated_capacity' => 30, 'standing_capacity' => 10, 'status' => 'active']);
    $fleetRoute = FleetRoute::create(['fleet_id' => $fleet->fleet_id, 'route_id' => $route->route_id, 'start_time' => '05:00', 'end_time' => '23:00', 'status' => 'active']);

    return compact('operator', 'route', 'fleetRoute', 'company');
}

function lplPlan($test, array $f, string $first = '06:00', array $extra = []): array
{
    $query = http_build_query(array_merge(['fleet_route_id' => $f['fleetRoute']->fleet_route_id, 'scheduled_date' => '2026-10-05', 'first_departure_time' => $first], $extra));

    return $test->actingAs($f['operator'])->getJson("/api/operator/shift-blocks/plan-legs?$query")->assertSuccessful()->json('data');
}

function lplCodes(array $flags): array
{
    return array_column($flags, 'code');
}

function lplBlock($test, array $f, array $times)
{
    $driver = lplStaff('driver');
    $conductor = lplStaff('conductor');

    return $test->actingAs($f['operator'])->postJson('/api/operator/shift-blocks', [
        'fleet_route_id' => $f['fleetRoute']->fleet_route_id, 'driver_id' => $driver->companyProfile->company_user_id,
        'conductor_id' => $conductor->companyProfile->company_user_id, 'block_type' => 'morning', 'scheduled_date' => '2026-10-05',
        'leg_departure_times' => $times,
    ]);
}

beforeEach(function () {
    Carbon::setTestNow(Carbon::parse('2026-10-04 07:00:00', 'Asia/Manila'));
    config(['shift_blocks.terminal_turnaround_buffer_minutes' => 30, 'shift_blocks.max_legs_per_block' => 8, 'leg_planning.report_min_samples' => 10]);
});
afterEach(fn () => Carbon::setTestNow());

it('fits as many round trips as end inside the normal work day on a short route', function () {
    $f = lplFixture(40);

    $plan = lplPlan($this, $f);

    // 06:00 + 40 = 06:40, + 30 turnaround = 07:10 ... the 4th round trip would end 14:50, after 14:00 (8 hours).
    expect(array_column($plan['legs'], 'suggested_departure'))->toBe(['06:00', '07:10', '08:20', '09:30', '10:40', '11:50'])
        ->and($plan['summary']['expected_finish'])->toBe('12:30')
        ->and($plan['summary']['driving_minutes'])->toBe(240)
        ->and(array_unique(array_column($plan['legs'], 'duration_source')))->toBe(['estimated'])
        ->and(lplCodes($plan['flags']))->toContain('estimated_time')
        ->and(lplCodes($plan['flags']))->not->toContain('overtime');
});

it('suggests one round trip on a long route and flags overtime, the driving limit and the missing rest', function () {
    $f = lplFixture(300);

    $plan = lplPlan($this, $f);

    expect(array_column($plan['legs'], 'suggested_departure'))->toBe(['06:00', '11:30'])
        ->and($plan['summary']['work_minutes'])->toBe(630)
        ->and(lplCodes($plan['flags']))->toContain('overtime', 'driving_limit', 'no_rest_break')
        ->and(lplCodes($plan['flags']))->not->toContain('long_leg');
});

it('flags a leg longer than the driving limit for an alternate driver', function () {
    $f = lplFixture(400);

    expect(lplCodes(lplPlan($this, $f)['flags']))->toContain('long_leg', 'over_max_work_hours');
});

it('asks the Operator to set the times when the route has no travel time, instead of inventing one', function () {
    $f = lplFixture();
    RouteStop::where('route_id', $f['route']->route_id)->update(['scheduled_offset_min' => null]);

    $plan = lplPlan($this, $f);

    expect($plan['legs'])->toHaveCount(1)
        ->and($plan['legs'][0]['duration_source'])->toBeNull()
        ->and(lplCodes($plan['flags']))->toContain('travel_time_unknown');
});

it('uses a peak-hour time the Operator set before measured or estimated times', function () {
    $f = lplFixture(40);
    RouteDurationFactor::create(['route_id' => $f['route']->route_id, 'start_time' => '06:00', 'end_time' => '07:00', 'factor' => 1.5, 'source' => 'manual']);

    $legs = lplPlan($this, $f, '06:00', ['leg_count' => 2])['legs'];

    expect($legs[0]['duration_source'])->toBe('operator_set')->and($legs[0]['expected_duration_minutes'])->toBe(60)
        ->and($legs[1]['duration_source'])->toBe('estimated')->and($legs[1]['suggested_departure'])->toBe('07:30');
});

it('uses the measured time of past trips when there are enough of them', function () {
    $f = lplFixture(40);
    config(['leg_planning.report_min_samples' => 3]);
    foreach (range(1, 3) as $day) {
        $trip = Trip::create([
            'fleet_route_id' => $f['fleetRoute']->fleet_route_id, 'company_user_id' => $f['company'], 'trip_date' => "2026-09-0{$day}",
            'departure_time' => '06:00', 'trip_type' => 'one_way', 'leg_direction' => 'outbound', 'status' => 'completed',
            'current_seated_capacity' => 0, 'current_standing_capacity' => 0, 'total_occupancy' => 0,
        ]);
        $trip->forceFill(['departed_at' => "2026-09-0{$day} 06:00:00", 'completed_at' => "2026-09-0{$day} 06:55:00"])->save();
    }

    $leg = lplPlan($this, $f, '06:00', ['leg_count' => 1])['legs'][0];

    expect($leg['duration_source'])->toBe('measured')->and($leg['expected_duration_minutes'])->toBe(55)->and($leg['expected_end'])->toBe('06:55');
});

it('refuses a leg that would leave before the previous one is back plus the turnaround', function () {
    $f = lplFixture(40);

    lplBlock($this, $f, ['06:00', '06:50'])
        ->assertStatus(422)
        ->assertJsonFragment(['Leg 2 leaves at 06:50, but leg 1 is expected to arrive at 06:40 and needs a 30 minute turnaround. Choose 07:10 or later.']);
    expect(ShiftBlock::count())->toBe(0);
});

it('saves a block with the planner\'s number of legs and returns its flags', function () {
    $f = lplFixture(40);

    $response = lplBlock($this, $f, ['06:00', '07:10', '08:20', '09:30', '10:40', '11:50'])->assertSuccessful();

    expect($response->json('data.legs'))->toHaveCount(6)
        ->and($response->json('data.planning.summary.leg_count'))->toBe(6)
        ->and(lplCodes($response->json('data.planning.flags')))->toContain('estimated_time');
});

it('refuses a block with more legs than the setting allows', function () {
    $f = lplFixture(10);
    config(['shift_blocks.max_legs_per_block' => 2]);

    lplBlock($this, $f, ['06:00', '07:00', '08:00'])->assertStatus(422)->assertJsonFragment(['A shift block has 1 to 2 legs.']);
});

it('lets the Operator move a leg later, and keeps the turnaround to the next leg', function () {
    $f = lplFixture(40);
    $block = lplBlock($this, $f, ['06:00', '07:10', '08:30', '09:40'])->assertSuccessful()->json('data');
    $leg2 = $block['legs'][1]['trip_id'];

    $moved = $this->actingAs($f['operator'])->patchJson("/api/operator/shift-blocks/{$block['shift_block_id']}/legs/{$leg2}", ['departure_time' => '07:20'])->assertSuccessful();
    expect(substr((string) Trip::find($leg2)->departure_time, 0, 5))->toBe('07:20')
        ->and($moved->json('data.planning.summary.leg_count'))->toBe(4);

    // 07:40 + 40 = 08:20 arrival: leg 3 at 08:30 would have only 10 minutes.
    $this->actingAs($f['operator'])->patchJson("/api/operator/shift-blocks/{$block['shift_block_id']}/legs/{$leg2}", ['departure_time' => '07:40'])
        ->assertStatus(422)
        ->assertJsonFragment(['Leg 3 leaves at 08:30, but leg 2 is expected to arrive at 08:20 and needs a 30 minute turnaround. Choose 08:50 or later.']);
});

it('keeps the legs in order and never moves a leg with passengers earlier', function () {
    $f = lplFixture(40);
    $block = lplBlock($this, $f, ['06:00', '07:10', '08:20', '09:40'])->assertSuccessful()->json('data');
    $leg3 = $block['legs'][2]['trip_id'];
    $url = "/api/operator/shift-blocks/{$block['shift_block_id']}/legs/{$leg3}";

    $this->actingAs($f['operator'])->patchJson($url, ['departure_time' => '06:30'])->assertStatus(422)->assertJsonFragment(['The legs must stay in order. Move the next leg first.']);

    Trip::whereKey($leg3)->update(['total_occupancy' => 2]);
    $this->actingAs($f['operator'])->patchJson($url, ['departure_time' => '08:15'])
        ->assertStatus(422)
        ->assertJsonFragment(['Passengers already hold tickets for 08:20. You can move this leg later, not earlier.']);
    $this->actingAs($f['operator'])->patchJson($url, ['departure_time' => '08:25'])->assertSuccessful();
});

it('does not move a leg that has started boarding, or another Operator\'s leg', function () {
    $f = lplFixture(40);
    $block = lplBlock($this, $f, ['06:00', '07:10'])->assertSuccessful()->json('data');
    $leg1 = $block['legs'][0]['trip_id'];

    Trip::whereKey($leg1)->update(['status' => 'boarding']);
    $this->actingAs($f['operator'])->patchJson("/api/operator/shift-blocks/{$block['shift_block_id']}/legs/{$leg1}", ['departure_time' => '06:10'])
        ->assertStatus(422)->assertJsonFragment(['Only a leg that has not started boarding can be moved.']);

    app('auth')->forgetGuards();
    $other = lplStaff('operator');
    $this->actingAs($other)->patchJson("/api/operator/shift-blocks/{$block['shift_block_id']}/legs/{$block['legs'][1]['trip_id']}", ['departure_time' => '07:30'])->assertForbidden();
});
