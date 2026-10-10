<?php

use App\Models\Fleet;
use App\Models\FleetRoute;
use App\Models\Route;
use App\Models\RouteDurationFactor;
use App\Models\RouteStop;
use App\Models\StaffUser;
use App\Models\Stop;
use App\Models\Trip;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

// Scenario check of the peak-hour duration factors on a route shaped like the demo route "Tagum - Panabo": three stops
// at 0, 15 and 24 minutes. 2026-10-05 is a Monday, 2026-10-10 a Saturday (Manila). Factors are typed in by a person.

function dfsOperator(): User
{
    $user = User::create(['username' => 'dfs'.Str::random(8), 'email' => Str::random(8).'@example.com', 'password' => bcrypt('password123'), 'role' => 'operator']);
    StaffUser::create(['user_id' => $user->user_id, 'company_user_uuid' => (string) Str::uuid(), 'name' => 'Dfs Operator', 'phone_num' => '09170000071', 'address' => 'x']);

    return $user->fresh();
}

function dfsRoute(): array
{
    $operator = dfsOperator();
    $route = Route::create(['route_name' => 'Dfs Route', 'origin' => 'Tagum', 'destination' => 'Panabo']);
    $stops = [];
    foreach ([['A', 0, 'terminal'], ['B', 15, 'stop'], ['C', 24, 'terminal']] as $i => [$name, $min, $type]) {
        $stop = Stop::create(['stop_name' => "Dfs $name", 'latitude' => 7.4 - $i / 20, 'longitude' => 125.8, 'municipality' => 'Tagum City']);
        RouteStop::create(['route_id' => $route->route_id, 'stop_id' => $stop->stop_id, 'stop_order' => $i + 1, 'distance_from_origin_km' => $min, 'distance_from_origin_m' => $min * 1000, 'stop_type' => $type, 'scheduled_offset_min' => $min]);
        $stops[$name] = $stop;
    }
    $company = $operator->companyProfile->company_user_id;
    $fleet = Fleet::create(['company_user_id' => $company, 'plate_number' => 'DFS-'.fake()->unique()->numerify('####'), 'capacity' => 40, 'seated_capacity' => 30, 'standing_capacity' => 10, 'status' => 'active', 'vehicle_class' => 'aircon']);
    $fleetRoute = FleetRoute::create(['fleet_id' => $fleet->fleet_id, 'route_id' => $route->route_id, 'start_time' => '05:00', 'end_time' => '21:00', 'status' => 'active']);

    return compact('operator', 'route', 'stops', 'fleetRoute', 'company');
}

function dfsPlan($test, array $f, string $date, string $first): array
{
    $query = http_build_query(['fleet_route_id' => $f['fleetRoute']->fleet_route_id, 'scheduled_date' => $date, 'first_departure_time' => $first]);

    return $test->actingAs($f['operator'])->getJson("/api/operator/shift-blocks/plan-legs?$query")->assertSuccessful()->json('data');
}

beforeEach(function () {
    Carbon::setTestNow(Carbon::parse('2026-10-04 07:00:00', 'Asia/Manila'));
    config(['leg_planning.max_factor' => 5, 'shift_blocks.terminal_turnaround_buffer_minutes' => 30]);
});
afterEach(fn () => Carbon::setTestNow());

function dfsLegs(array $plan): array
{
    return collect($plan['legs'])->map(fn ($l) => [$l['suggested_departure'], $l['expected_duration_minutes'], $l['expected_end'], (float) $l['factor']])->all();
}

it('a weekday morning peak stretches the legs that run inside it, and only those', function () {
    $f = dfsRoute();
    RouteDurationFactor::create(['route_id' => $f['route']->route_id, 'start_time' => '07:00', 'end_time' => '09:00', 'factor' => 1.5, 'day_type' => 'weekday', 'source' => 'manual']);

    // Monday. Leg 1 06:30 is before the window (24 min). Leg 2 07:24 and leg 3 08:30 are inside it (24 x 1.5 = 36 min).
    // Leg 4 09:36 is after it. Each leg starts at the previous leg's expected end plus the 30 minute turnaround.
    expect(dfsLegs(dfsPlan($this, $f, '2026-10-05', '06:30')))->toBe([
        ['06:30', 24, '06:54', 1.0],
        ['07:24', 36, '08:00', 1.5],
        ['08:30', 36, '09:06', 1.5],
        ['09:36', 24, '10:00', 1.0],
    ]);
});

it('the same weekday window does not apply on a Saturday', function () {
    $f = dfsRoute();
    RouteDurationFactor::create(['route_id' => $f['route']->route_id, 'start_time' => '07:00', 'end_time' => '09:00', 'factor' => 1.5, 'day_type' => 'weekday', 'source' => 'manual']);

    expect(dfsLegs(dfsPlan($this, $f, '2026-10-10', '06:30')))->toBe([
        ['06:30', 24, '06:54', 1.0],
        ['07:24', 24, '07:48', 1.0],
        ['08:18', 24, '08:42', 1.0],
        ['09:12', 24, '09:36', 1.0],
    ]);
});

it('a stop that lands on half a minute is rounded to the nearest minute in the passenger timeline', function () {
    $f = dfsRoute();
    RouteDurationFactor::create(['route_id' => $f['route']->route_id, 'start_time' => '07:00', 'end_time' => '09:00', 'factor' => 1.5, 'day_type' => 'weekday', 'source' => 'manual']);
    $trip = Trip::create(['fleet_route_id' => $f['fleetRoute']->fleet_route_id, 'company_user_id' => $f['company'], 'trip_date' => '2026-10-05', 'departure_time' => '08:00', 'trip_type' => 'one_way', 'leg_direction' => 'outbound', 'status' => 'scheduled', 'current_seated_capacity' => 0, 'current_standing_capacity' => 0, 'total_occupancy' => 0]);

    $rows = $this->getJson("/api/trips/{$trip->trip_id}/timeline?boarding_stop_id={$f['stops']['A']->stop_id}&alighting_stop_id={$f['stops']['C']->stop_id}")->assertSuccessful()->json('data.stops');
    // 15 min x 1.5 = 22.5 -> 08:23 (rounded up); 24 min x 1.5 = 36 -> 08:36.
    expect(collect($rows)->pluck('eta_time', 'name')->all())->toBe(['Dfs A' => '08:00', 'Dfs B' => '08:23', 'Dfs C' => '08:36']);
});

it('a window cannot cross midnight: it is refused with a plain message, and two windows can be used instead', function () {
    $f = dfsRoute();
    $url = '/api/operator/duration-factors';
    $body = fn (array $over) => array_merge(['route_id' => $f['route']->route_id, 'direction' => 'reverse', 'day_type' => 'weekday', 'factor' => 1.2], $over);

    $refused = $this->actingAs($f['operator'])->postJson($url, $body(['start_time' => '22:00', 'end_time' => '00:00']));
    $refused->assertStatus(422);
    expect($refused->json('errors.end_time.0'))->toContain('must be after the start');

    $this->actingAs($f['operator'])->postJson($url, $body(['start_time' => '22:00', 'end_time' => '23:59']))->assertStatus(201);
});