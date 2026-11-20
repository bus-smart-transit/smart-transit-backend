<?php

use App\Models\Fleet;
use App\Models\FleetRoute;
use App\Models\Route;
use App\Models\RouteStop;
use App\Models\StaffUser;
use App\Models\Stop;
use App\Models\Trip;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

// 2026-11-20 (user): a province picture on the homepage opens the routes serving that province; a route has its own
// page with its stops and today's departures. Places are SAMPLE names, matched through config region.provinces.

function prOperator(): User
{
    $user = User::create(['username' => 'pr'.Str::random(8), 'email' => Str::random(8).'@example.com', 'password' => bcrypt('password123'), 'role' => 'operator']);
    StaffUser::create(['user_id' => $user->user_id, 'company_user_uuid' => (string) Str::uuid(), 'name' => 'Pr Operator', 'phone_num' => '09170000077', 'address' => 'x']);

    return $user->fresh();
}

/** A route through the given towns (one stop each); with $bus, an active bus runs it. */
function prRoute(User $operator, string $name, array $towns, bool $bus = true): array
{
    $route = Route::create(['route_name' => $name, 'origin' => $towns[0].' Terminal', 'destination' => end($towns).' Terminal']);
    foreach ($towns as $i => $town) {
        $stop = Stop::create(['stop_name' => "$town Stop", 'latitude' => 7.0 + $i / 10, 'longitude' => 125.0, 'municipality' => $town]);
        RouteStop::create(['route_id' => $route->route_id, 'stop_id' => $stop->stop_id, 'stop_order' => $i + 1, 'distance_from_origin_km' => $i * 10, 'stop_type' => $i === 0 || $i === count($towns) - 1 ? 'terminal' : 'stop', 'scheduled_offset_min' => $i * 15]);
    }
    $fleetRoute = null;
    if ($bus) {
        $fleet = Fleet::create(['company_user_id' => $operator->companyProfile->company_user_id, 'plate_number' => 'PR-'.fake()->unique()->numerify('####'), 'bus_company_name' => 'Sample Lines', 'body_no' => '77', 'capacity' => 30, 'seated_capacity' => 30, 'standing_capacity' => 0, 'status' => 'active', 'vehicle_class' => 'ordinary']);
        $fleetRoute = FleetRoute::create(['fleet_id' => $fleet->fleet_id, 'route_id' => $route->route_id, 'start_time' => '05:00', 'end_time' => '22:00', 'status' => 'active']);
    }

    return [$route, $fleetRoute];
}

beforeEach(function () {
    config(['region.provinces' => [
        'Sample Oriental' => ['East Town', 'Far Town'],
        'Sample del Norte' => ['North Town'],
        'Sample City' => ['Sample City'],
    ]]);
    Carbon::setTestNow(Carbon::parse('2026-10-05 07:00:00', 'Asia/Manila'));
    Cache::flush();
});
afterEach(fn () => Carbon::setTestNow());

it('lists the routes a bus runs through a province, with the province towns each one stops in', function () {
    $operator = prOperator();
    [$east] = prRoute($operator, 'City to Far Town', ['Sample City', 'East Town', 'Far Town']);
    prRoute($operator, 'City to North Town', ['Sample City', 'North Town']);
    prRoute($operator, 'Unused East Route', ['Sample City', 'East Town'], false); // no bus runs it

    $data = $this->getJson('/api/routes?province=sample oriental')->assertOk()->json('data');

    expect($data['province'])->toBe('Sample Oriental')
        ->and(collect($data['routes'])->pluck('route_name')->all())->toBe(['City to Far Town'])
        ->and($data['routes'][0])->toMatchArray(['route_id' => $east->route_id, 'stop_count' => 3, 'towns_in_province' => ['East Town', 'Far Town'], 'bus_companies' => ['Sample Lines']]);
});

it('answers 404 for a province the region does not have, and asks for one', function () {
    $this->getJson('/api/routes?province=Nowhere')->assertNotFound();
    $this->getJson('/api/routes')->assertUnprocessable()->assertJsonValidationErrors('province');
});

it('gives a route page its summary, its stops with codes, and today\'s departures both ways', function () {
    $operator = prOperator();
    [$route, $fleetRoute] = prRoute($operator, 'City to Far Town', ['Sample City', 'East Town', 'Far Town']);
    $trip = fn (string $time, string $direction, string $status = 'scheduled') => Trip::create([
        'fleet_route_id' => $fleetRoute->fleet_route_id, 'company_user_id' => $operator->companyProfile->company_user_id, 'trip_date' => '2026-10-05',
        'departure_time' => $time, 'trip_type' => 'one_way', 'leg_direction' => $direction, 'status' => $status,
        'current_seated_capacity' => 0, 'current_standing_capacity' => 0, 'total_occupancy' => 0,
    ]);
    $trip('09:00', 'reverse');
    $trip('06:30', 'outbound', 'delayed');
    $trip('08:00', 'outbound', 'cancelled');

    $this->getJson("/api/routes/{$route->route_id}")->assertOk()
        ->assertJsonPath('data.route_name', 'City to Far Town')
        ->assertJsonPath('data.provinces', ['Sample Oriental', 'Sample City']);
    expect(collect($this->getJson("/api/routes/{$route->route_id}/stops")->json('data'))->pluck('stop_code')->filter()->count())->toBe(3);

    $departures = $this->getJson("/api/routes/{$route->route_id}/departures")->assertOk()->json('data.departures');
    expect(collect($departures)->map(fn ($d) => [$d['departure_time'], $d['from'], $d['to'], $d['status']])->all())->toBe([
        ['06:30', 'Sample City Terminal', 'Far Town Terminal', 'delayed'],
        ['09:00', 'Far Town Terminal', 'Sample City Terminal', 'scheduled'],
    ]);
});

it('answers 404 for a route no bus runs', function () {
    [$route] = prRoute(prOperator(), 'Unused Route', ['Sample City', 'East Town'], false);

    $this->getJson("/api/routes/{$route->route_id}")->assertNotFound();
    $this->getJson("/api/routes/{$route->route_id}/departures")->assertNotFound();
});
