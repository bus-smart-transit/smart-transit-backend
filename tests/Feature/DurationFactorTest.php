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
use App\Services\RouteDurationFactorService;
use App\Services\ShiftBlockService;
use App\Services\TripService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

// Batch 28 Stage 3, item 7: peak-hour duration factors. Configured by a person, never measured here.
// Fixture route: A(0) B(10) C(20, pass-through) D(30) E(40); stop offsets in minutes equal the kilometres, so the
// ordinary run takes 40 minutes. Fixture dates: 2026-10-05 is a Monday (weekday), 2026-10-10 a Saturday (weekend).

function dfStaff(string $role): User
{
    $user = User::create(['username' => 'df'.Str::random(8), 'email' => Str::random(8).'@example.com', 'password' => bcrypt('password123'), 'role' => $role]);
    StaffUser::create(['user_id' => $user->user_id, 'company_user_uuid' => (string) Str::uuid(), 'name' => 'Df '.ucfirst($role), 'phone_num' => '09170000061', 'address' => 'x']);

    return $user->fresh();
}

function dfFixture(): array
{
    $operator = dfStaff('operator');
    $route = Route::create(['route_name' => 'Df Route', 'origin' => 'A', 'destination' => 'E']);
    $stops = [];
    foreach ([['A', 0, 'terminal'], ['B', 10, 'stop'], ['C', 20, 'pass_through'], ['D', 30, 'stop'], ['E', 40, 'terminal']] as $i => [$name, $km, $type]) {
        $stop = Stop::create(['stop_name' => "Stop $name", 'latitude' => 7.0 + $km / 100, 'longitude' => 125.0, 'municipality' => 'Mati City']);
        RouteStop::create(['route_id' => $route->route_id, 'stop_id' => $stop->stop_id, 'stop_order' => $i + 1, 'distance_from_origin_km' => $km, 'distance_from_origin_m' => $km * 1000, 'stop_type' => $type, 'scheduled_offset_min' => $km]);
        $stops[$name] = $stop;
    }
    $company = $operator->companyProfile->company_user_id;
    $fleet = Fleet::create(['company_user_id' => $company, 'plate_number' => 'DF-'.fake()->unique()->numerify('####'), 'capacity' => 40, 'seated_capacity' => 30, 'standing_capacity' => 10, 'status' => 'active', 'vehicle_class' => 'aircon']);
    $fleetRoute = FleetRoute::create(['fleet_id' => $fleet->fleet_id, 'route_id' => $route->route_id, 'start_time' => '06:00', 'end_time' => '22:00', 'status' => 'active']);
    $mk = fn (string $date, string $time, string $direction = 'outbound', string $status = 'scheduled') => Trip::create([
        'fleet_route_id' => $fleetRoute->fleet_route_id, 'company_user_id' => $company, 'trip_date' => $date, 'departure_time' => $time, 'trip_type' => 'one_way',
        'leg_direction' => $direction, 'status' => $status, 'current_seated_capacity' => 0, 'current_standing_capacity' => 0, 'total_occupancy' => 0,
    ]);

    return compact('operator', 'route', 'stops', 'fleet', 'fleetRoute', 'mk', 'company');
}

function dfWindow(int $routeId, string $start, string $end, float $factor, array $over = []): RouteDurationFactor
{
    return RouteDurationFactor::create(array_merge(['route_id' => $routeId, 'start_time' => $start, 'end_time' => $end, 'factor' => $factor, 'source' => 'manual'], $over));
}

function dfEta($test, int $tripId, array $stops): array
{
    $rows = $test->getJson("/api/trips/{$tripId}/timeline?boarding_stop_id={$stops['A']->stop_id}&alighting_stop_id={$stops['E']->stop_id}")->assertSuccessful()->json('data.stops');

    return collect($rows)->pluck('eta_time', 'name')->all();
}

beforeEach(function () {
    Carbon::setTestNow(Carbon::parse('2026-10-04 07:00:00', 'Asia/Manila'));
    config(['leg_planning.max_factor' => 5, 'shift_blocks.terminal_turnaround_buffer_minutes' => 30]);
});
afterEach(fn () => Carbon::setTestNow());

it('matches the window that contains the leg\'s scheduled departure, and uses 1.0 when none does', function () {
    $f = dfFixture();
    $service = app(RouteDurationFactorService::class);
    dfWindow($f['route']->route_id, '07:00', '09:00', 1.5);

    $at = fn (string $time, string $date = '2026-10-05') => Carbon::parse("$date $time", 'Asia/Manila');
    expect($service->factorFor($f['route']->route_id, 'outbound', $at('08:00')))->toBe(1.5);
    expect($service->factorFor($f['route']->route_id, 'outbound', $at('12:00')))->toBe(1.0)   // no window: 1.0, documented
        ->and($service->factorFor($f['route']->route_id, null, $at('06:59:59')))->toBe(1.0);
    // The Manila edge: start inclusive, end exclusive.
    expect($service->factorFor($f['route']->route_id, 'outbound', $at('07:00:00')))->toBe(1.5);
    expect($service->factorFor($f['route']->route_id, 'outbound', $at('08:59:59')))->toBe(1.5);
    expect($service->factorFor($f['route']->route_id, 'outbound', $at('09:00:00')))->toBe(1.0);
    // The same instant given in UTC is still judged by Manila wall-clock time (23:30 UTC the day before = 07:30 Manila).
    expect($service->factorFor($f['route']->route_id, 'outbound', Carbon::parse('2026-10-04 23:30:00', 'UTC')))->toBe(1.5);
});

it('can be limited to one direction and one day type', function () {
    $f = dfFixture();
    $service = app(RouteDurationFactorService::class);
    dfWindow($f['route']->route_id, '07:00', '09:00', 1.4, ['direction' => 'outbound', 'day_type' => 'weekday']);
    $monday = Carbon::parse('2026-10-05 08:00', 'Asia/Manila');
    $saturday = Carbon::parse('2026-10-10 08:00', 'Asia/Manila');

    expect($service->factorFor($f['route']->route_id, 'outbound', $monday))->toBe(1.4)
        ->and($service->factorFor($f['route']->route_id, 'reverse', $monday))->toBe(1.0)
        ->and($service->factorFor($f['route']->route_id, 'outbound', $saturday))->toBe(1.0);
});

it('rejects an overlapping window on save, allows an adjacent one, and refuses nonsense values', function () {
    $f = dfFixture();
    $url = '/api/operator/duration-factors';
    $body = fn (array $o = []) => array_merge(['route_id' => $f['route']->route_id, 'start_time' => '07:00', 'end_time' => '09:00', 'factor' => 1.5], $o);

    $this->actingAs($f['operator'])->postJson($url, $body())->assertCreated()->assertJsonPath('data.label', 'configured');
    $this->actingAs($f['operator'])->postJson($url, $body(['start_time' => '08:00', 'end_time' => '10:00']))->assertStatus(422);       // overlaps
    $this->actingAs($f['operator'])->postJson($url, $body(['start_time' => '06:00', 'end_time' => '07:01']))->assertStatus(422);       // overlaps by a minute
    $this->actingAs($f['operator'])->postJson($url, $body(['direction' => 'reverse', 'start_time' => '08:00', 'end_time' => '10:00']))->assertStatus(422); // "both" overlaps a direction
    $this->actingAs($f['operator'])->postJson($url, $body(['start_time' => '09:00', 'end_time' => '11:00', 'factor' => 1.2]))->assertCreated(); // adjacent: fine
    $this->actingAs($f['operator'])->postJson($url, $body(['start_time' => '16:00', 'end_time' => '15:00']))->assertStatus(422);        // end before start
    $this->actingAs($f['operator'])->postJson($url, $body(['start_time' => '16:00', 'end_time' => '18:00', 'factor' => 0]))->assertStatus(422);
    $this->actingAs($f['operator'])->postJson($url, $body(['start_time' => '16:00', 'end_time' => '18:00', 'factor' => 25]))->assertStatus(422);
    $this->actingAs($f['operator'])->postJson($url, $body(['start_time' => '25:00', 'end_time' => '26:00']))->assertStatus(422);
    expect(RouteDurationFactor::count())->toBe(2);

    // Different days do not collide; editing a window does not collide with itself.
    $first = RouteDurationFactor::where('start_time', '07:00:00')->firstOrFail();
    $this->actingAs($f['operator'])->postJson($url, $body(['day_type' => 'weekend', 'direction' => 'outbound', 'start_time' => '07:00', 'end_time' => '09:00']))->assertStatus(422); // null day type overlaps weekend
    $this->actingAs($f['operator'])->putJson("$url/{$first->id}", $body(['end_time' => '08:30', 'factor' => 1.6]))->assertSuccessful();
    expect($first->fresh()->factor)->toBe(1.6);
    $this->actingAs($f['operator'])->putJson("$url/{$first->id}", $body(['end_time' => '09:30']))->assertStatus(422); // now runs into the 09:00 window
});

it('scales the timeline ETAs by the window of the leg\'s scheduled departure and leaves unaffected legs alone', function () {
    $f = dfFixture();
    $peak = $f['mk']('2026-10-05', '08:00');
    $quiet = $f['mk']('2026-10-05', '13:00');
    dfWindow($f['route']->route_id, '07:00', '09:00', 1.5);

    $eta = dfEta($this, $peak->trip_id, $f['stops']);
    expect($eta)->toMatchArray(['Stop A' => '08:00', 'Stop B' => '08:15', 'Stop C' => '08:30', 'Stop D' => '08:45', 'Stop E' => '09:00']);
    $quietEta = dfEta($this, $quiet->trip_id, $f['stops']);
    expect($quietEta)->toMatchArray(['Stop B' => '13:10', 'Stop D' => '13:30', 'Stop E' => '13:40']);

    $header = $this->getJson("/api/trips/{$peak->trip_id}/timeline?boarding_stop_id={$f['stops']['A']->stop_id}&alighting_stop_id={$f['stops']['E']->stop_id}")->json('data');
    expect($header['header']['duration_minutes'])->toBe(60)->and($header['trip']['duration_factor'])->toEqual(1.5);
});

it('scales the booking list: boarding and arrival times and the journey time', function () {
    $f = dfFixture();
    $f['mk']('2026-10-05', '08:00');
    dfWindow($f['route']->route_id, '07:00', '09:00', 2.0);

    $departure = $this->getJson("/api/booking/departures?origin_stop_id={$f['stops']['B']->stop_id}&destination_stop_id={$f['stops']['D']->stop_id}&date=2026-10-05")
        ->assertSuccessful()->json('data.departures.0');

    // B is 10 min, D 30 min after departure on an ordinary run; the window doubles them: 20 and 60.
    expect($departure['boarding_time'])->toBe('08:20')->and($departure['arrival_time'])->toBe('09:00')->and($departure['duration_minutes'])->toBe(40);
});

it('suggests each next leg at the previous leg\'s expected end plus the turnaround buffer, scaled by the window of its own start (4 legs)', function () {
    $f = dfFixture();
    dfWindow($f['route']->route_id, '06:00', '08:00', 2.0);
    // 2026-10-27: leg_count asks for a set number of legs (without it the planner decides from the travel time).
    $query = http_build_query(['fleet_route_id' => $f['fleetRoute']->fleet_route_id, 'scheduled_date' => '2026-10-05', 'first_departure_time' => '06:00', 'leg_count' => 4]);

    $plan = $this->actingAs($f['operator'])->getJson("/api/operator/shift-blocks/plan-legs?$query")->assertSuccessful()->json('data');
    $legs = collect($plan['legs']);

    expect($plan['buffer_minutes'])->toBe(30)->and($legs)->toHaveCount(4);
    // Leg 1 starts 06:00 inside the window: 40 x 2.0 = 80 min -> ends 07:20. Leg 2 = 07:20 + 30 = 07:50 (still inside): 80 -> 09:10.
    // Leg 3 = 09:10 + 30 = 09:40 (outside): 40 -> 10:20. Leg 4 = 10:20 + 30 = 10:50: 40 -> 11:30.
    expect($legs->pluck('suggested_departure')->all())->toBe(['06:00', '07:50', '09:40', '10:50']);
    expect($legs->pluck('expected_duration_minutes')->all())->toBe([80, 80, 40, 40]);
    expect($legs->pluck('expected_end')->all())->toBe(['07:20', '09:10', '10:20', '11:30']);
    expect($legs->pluck('direction')->all())->toBe(['outbound', 'reverse', 'outbound', 'reverse']);
    expect($legs[0]['factor_label'])->toBe('configured')->and($legs[2]['factor_label'])->toBeNull()->and($legs[2]['factor'])->toEqual(1.0);
    expect($plan['note'])->toContain('suggestion');
});

it('plans a 2-leg block with the configured leg count, and the operator can still send any times', function () {
    $f = dfFixture();
    config(['shift_blocks.legs_per_block' => 2]);
    dfWindow($f['route']->route_id, '06:00', '08:00', 1.5);
    $query = http_build_query(['fleet_route_id' => $f['fleetRoute']->fleet_route_id, 'scheduled_date' => '2026-10-05', 'first_departure_time' => '06:30', 'leg_count' => 2]);

    $legs = collect($this->actingAs($f['operator'])->getJson("/api/operator/shift-blocks/plan-legs?$query")->json('data.legs'));
    expect($legs->pluck('suggested_departure')->all())->toBe(['06:30', '08:00']); // 06:30 + 60 = 07:30, + 30 buffer = 08:00: the window's end is exclusive, so leg 2 runs at 1.0
    expect($legs->pluck('expected_end')->all())->toBe(['07:30', '08:40']);

    // Creating a block takes exactly the operator's own times (the suggestion is not enforced).
    $driver = dfStaff('driver');
    $conductor = dfStaff('conductor');
    $block = app(ShiftBlockService::class)->createBlock([
        'fleet_route_id' => $f['fleetRoute']->fleet_route_id, 'driver_id' => $driver->companyProfile->company_user_id, 'conductor_id' => $conductor->companyProfile->company_user_id,
        'company_user_id' => $f['company'], 'block_type' => 'morning', 'scheduled_date' => '2026-10-05', 'leg_departure_times' => ['06:30', '09:15'],
    ]);
    expect($block->legs->pluck('departure_time')->map(fn ($t) => substr($t, 0, 5))->all())->toBe(['06:30', '09:15']);
});

it('says nothing can be suggested when the route has no stop offsets, instead of inventing durations', function () {
    $f = dfFixture();
    RouteStop::where('route_id', $f['route']->route_id)->update(['scheduled_offset_min' => null]);
    $query = http_build_query(['fleet_route_id' => $f['fleetRoute']->fleet_route_id, 'scheduled_date' => '2026-10-05', 'first_departure_time' => '06:00']);

    $legs = $this->actingAs($f['operator'])->getJson("/api/operator/shift-blocks/plan-legs?$query")->json('data.legs');
    expect($legs)->toHaveCount(1)->and($legs[0]['expected_duration_minutes'])->toBeNull()->and($legs[0]['suggested_departure'])->toBe('06:00');
});

it('keeps the factor a leg started with: editing the window later never moves a leg already under way', function () {
    $f = dfFixture();
    $started = $f['mk']('2026-10-05', '08:00');
    $waiting = $f['mk']('2026-10-05', '08:30');
    $window = dfWindow($f['route']->route_id, '07:00', '09:00', 1.5);

    app(TripService::class)->startBoarding($started->trip_id);
    expect($started->fresh()->duration_factor)->toEqual(1.5);

    $window->update(['factor' => 2.0]);

    expect(dfEta($this, $started->trip_id, $f['stops'])['Stop E'])->toBe('09:00');   // 08:00 + 40 x 1.5: unchanged
    expect(dfEta($this, $waiting->trip_id, $f['stops'])['Stop E'])->toBe('09:50');    // 08:30 + 40 x 2.0: follows the edit
});

it('never moves an issued ticket\'s date or time when a window is added', function () {
    $f = dfFixture();
    $trip = $f['mk']('2026-10-05', '08:00');
    $before = $trip->fresh()->only(['trip_date', 'departure_time', 'status']);
    dfWindow($f['route']->route_id, '07:00', '09:00', 3.0);

    expect($trip->fresh()->only(['trip_date', 'departure_time', 'status']))->toEqual($before);
});

it('reports the median actual leg duration per route, direction and hour, with sample counts, and proposes a factor only with enough data', function () {
    $f = dfFixture();
    config(['leg_planning.report_min_samples' => 5]);
    // 6 completed morning legs outbound: 50, 50, 52, 54, 60, 50 minutes -> median 51.0? sorted 50,50,50,52,54,60 -> (50+52)/2 = 51
    foreach ([50, 50, 52, 54, 60, 50] as $i => $minutes) {
        $trip = $f['mk']('2026-09-2'.($i + 1), '08:00', 'outbound', 'completed');
        $trip->forceFill(['departed_at' => '2026-09-2'.($i + 1).' 08:00:00', 'completed_at' => Carbon::parse('2026-09-2'.($i + 1).' 08:00:00', 'Asia/Manila')->addMinutes($minutes)->format('Y-m-d H:i:s')])->save();
    }
    // 2 legs reverse at 17:00: too few for a proposal.
    foreach ([45, 47] as $i => $minutes) {
        $trip = $f['mk']('2026-09-3'.$i, '17:00', 'reverse', 'completed');
        $trip->forceFill(['departed_at' => "2026-09-3{$i} 17:00:00", 'completed_at' => Carbon::parse("2026-09-3{$i} 17:00:00", 'Asia/Manila')->addMinutes($minutes)->format('Y-m-d H:i:s')])->save();
    }
    $f['mk']('2026-10-02', '08:00', 'outbound', 'cancelled'); // not a completed leg: ignored

    $report = $this->actingAs($f['operator'])->getJson('/api/operator/duration-report')->assertSuccessful()->json('data');
    $rows = collect($report['rows'])->keyBy(fn ($r) => $r['direction'].'-'.$r['hour']);

    expect($rows['outbound-8']['samples'])->toBe(6)->and($rows['outbound-8']['median_minutes'])->toEqual(51.0)->and($rows['outbound-8']['base_minutes'])->toBe(40);
    expect($rows['outbound-8']['enough_data'])->toBeTrue()->and($rows['outbound-8']['proposed_factor'])->toEqual(1.275)->and($rows['outbound-8']['note'])->toContain('Proposal only');
    expect($rows['reverse-17']['samples'])->toBe(2)->and($rows['reverse-17']['enough_data'])->toBeFalse()->and($rows['reverse-17']['proposed_factor'])->toBeNull()->and($rows['reverse-17']['note'])->toContain('Not enough data');
    expect($report['source'])->toContain('departed_at');

    // Read-only: nothing was created or applied.
    expect(RouteDurationFactor::count())->toBe(0);
});

it('keeps an operator to the routes their buses run, lets an admin manage any, and labels every factor configured', function () {
    $f = dfFixture();
    $rival = dfStaff('operator');
    $admin = dfStaff('admin');
    $payload = ['route_id' => $f['route']->route_id, 'start_time' => '07:00', 'end_time' => '09:00', 'factor' => 1.3, 'source' => 'measured'];

    $this->actingAs($rival)->postJson('/api/operator/duration-factors', $payload)->assertStatus(403);
    $created = $this->actingAs($admin)->postJson('/api/admin/duration-factors', $payload)->assertCreated()->json('data');
    expect($created['label'])->toBe('configured (copied from the duration report)')->and($created['label'])->not->toContain('heavy')->and(json_encode($created))->not->toMatch('/heavy|moderate|normal/i');

    $this->actingAs($rival)->getJson('/api/operator/duration-factors')->assertSuccessful()->assertJsonCount(0, 'data');
    $this->actingAs($f['operator'])->getJson('/api/operator/duration-factors')->assertJsonCount(1, 'data');
    $this->actingAs($rival)->deleteJson("/api/operator/duration-factors/{$created['id']}")->assertStatus(403);
    $this->actingAs($f['operator'])->deleteJson("/api/operator/duration-factors/{$created['id']}")->assertSuccessful();
    expect(RouteDurationFactor::count())->toBe(0);
    $this->assertDatabaseHas('audit_logs', ['action' => 'duration_factor.delete']);
});
