<?php

use App\Models\Company;
use App\Models\FareRule;
use App\Models\Fleet;
use App\Models\FleetRoute;
use App\Models\Route;
use App\Models\StaffUser;
use App\Models\Trip;
use App\Models\User;
use App\Services\FcmService;
use App\Support\OperatingWindow;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

// 2026-11-21 (user, Dynamic Route Scheduling): a bus has one operating window, set when it is added; the route is
// chosen when a trip is scheduled. The bus-and-route row that trips, tickets and fares are kept on is found or made
// then, never by hand. SAMPLE places and times.

beforeEach(function () {
    $this->mock(FcmService::class, fn ($mock) => $mock->shouldReceive('sendToUser')->andReturn(true));
    Carbon::setTestNow(Carbon::parse('2026-10-05 07:00:00', 'Asia/Manila'));
});
afterEach(fn () => Carbon::setTestNow());

function dsOperator(Company $company): User
{
    $user = User::create(['username' => 'ds'.Str::random(6), 'email' => 'ds.'.Str::lower(Str::random(6)).'@example.com', 'password' => 'Right-password-1', 'role' => 'operator']);
    StaffUser::create(['user_id' => $user->user_id, 'company_user_uuid' => (string) Str::uuid(), 'name' => 'Ds Op', 'phone_num' => '+639171234567', 'address' => 'Tagum', 'company_id' => $company->company_id, 'is_company_admin' => true]);

    return $user->fresh();
}

function dsBus(User $owner, string $plate, ?string $from = '06:00', ?string $to = '20:00', string $status = 'active'): Fleet
{
    $fleet = Fleet::create(['company_user_id' => $owner->companyProfile->company_user_id, 'company_id' => $owner->companyProfile->company_id, 'plate_number' => $plate, 'capacity' => 50, 'seated_capacity' => 40, 'standing_capacity' => 10, 'status' => $status, 'operating_start_time' => $from, 'operating_end_time' => $to]);
    FareRule::create(['fleet_id' => $fleet->fleet_id, 'service_type' => 'regular', 'seat_type' => 'seated', 'status' => 'active', 'base_fare' => 15, 'fare_per_km' => 2, 'note' => 'SAMPLE: a bus needs a fare to be scheduled']);

    return $fleet;
}

function dsRoute(Company $company, string $name = 'Ds Route'): Route
{
    return Route::create(['route_name' => $name, 'origin' => 'A', 'destination' => 'B', 'company_id' => $company->company_id]);
}

function dsTrip($test, Fleet $fleet, Route $route, string $time = '08:00')
{
    return $test->postJson('/api/operator/trips', ['fleet_id' => $fleet->fleet_id, 'route_id' => $route->route_id, 'trip_date' => '2026-10-06', 'departure_time' => $time, 'trip_type' => 'one_way']);
}

it('gives one window that covers several, including ones past midnight', function () {
    expect(OperatingWindow::cover([['12:00', '05:00'], ['06:00', '22:00']]))->toBe(['06:00', '05:00'])
        ->and(OperatingWindow::cover([['06:00', '22:00'], ['04:00', '22:00']]))->toBe(['04:00', '22:00'])
        ->and(OperatingWindow::cover([['22:00', '02:00']]))->toBe(['22:00', '02:00'])
        ->and(OperatingWindow::cover([['00:00', '12:00'], ['12:00', '23:59']]))->toBe(['00:00', '23:59'])
        ->and(OperatingWindow::cover([]))->toBeNull()
        ->and(OperatingWindow::contains('22:00', '02:00', '01:30'))->toBeTrue()
        ->and(OperatingWindow::contains('22:00', '02:00', '12:00'))->toBeFalse();
});

it('schedules a trip on a bus and a route chosen together, reusing their row the next time', function () {
    $company = Company::create(['company_name' => 'Ds Co']);
    $operator = dsOperator($company);
    $bus = dsBus($operator, 'DS-001');
    $route = dsRoute($company);
    $this->actingAs($operator, 'sanctum');

    $first = dsTrip($this, $bus, $route, '08:00')->assertSuccessful()->json('data');
    dsTrip($this, $bus, $route, '15:00')->assertSuccessful();

    $links = FleetRoute::where('fleet_id', $bus->fleet_id)->where('route_id', $route->route_id)->get();
    expect($links)->toHaveCount(1)
        ->and($links[0]->status)->toBe('active')
        ->and(substr((string) $links[0]->start_time, 0, 5))->toBe('06:00')
        ->and(Trip::find($first['trip_id'])->fleet_route_id)->toBe($links[0]->fleet_route_id);
});

it('makes a removed bus-and-route row active again instead of adding another', function () {
    $company = Company::create(['company_name' => 'Ds Co']);
    $operator = dsOperator($company);
    $bus = dsBus($operator, 'DS-002');
    $route = dsRoute($company);
    $old = FleetRoute::create(['fleet_id' => $bus->fleet_id, 'route_id' => $route->route_id, 'start_time' => '06:00', 'end_time' => '20:00', 'status' => 'inactive']);

    $this->actingAs($operator, 'sanctum');
    dsTrip($this, $bus, $route)->assertSuccessful();

    expect(FleetRoute::where('fleet_id', $bus->fleet_id)->count())->toBe(1)
        ->and($old->fresh()->status)->toBe('active');
});

it('checks the bus\'s own operating hours, not the hours of its row', function () {
    $company = Company::create(['company_name' => 'Ds Co']);
    $operator = dsOperator($company);
    $bus = dsBus($operator, 'DS-003', '05:00', '21:00');
    $route = dsRoute($company);
    FleetRoute::create(['fleet_id' => $bus->fleet_id, 'route_id' => $route->route_id, 'start_time' => '08:00', 'end_time' => '09:00', 'status' => 'active']);
    $this->actingAs($operator, 'sanctum');

    dsTrip($this, $bus, $route, '18:00')->assertSuccessful(); // outside the row's hours, inside the bus's
    dsTrip($this, $bus, $route, '22:00')->assertUnprocessable()
        ->assertJsonPath('errors.departure_time.0', 'This bus runs between 05:00 and 21:00. A 22:00 departure is outside those hours.');
});

it('refuses another company\'s bus or route, and a bus that is not in service', function () {
    $company = Company::create(['company_name' => 'Ds Co']);
    $other = Company::create(['company_name' => 'Other Co']);
    $operator = dsOperator($company);
    $rival = dsOperator($other);
    $mine = dsBus($operator, 'DS-004');
    $parked = dsBus($operator, 'DS-005', status: 'inactive');
    $theirs = dsBus($rival, 'OT-001');
    $route = dsRoute($company);
    $theirRoute = dsRoute($other, 'Their Route');
    $this->actingAs($operator, 'sanctum');

    dsTrip($this, $theirs, $route)->assertUnprocessable()->assertJsonPath('errors.fleet_id.0', 'Choose one of your own buses.');
    dsTrip($this, $mine, $theirRoute)->assertUnprocessable()->assertJsonPath('errors.route_id.0', 'Choose one of your own routes.');
    dsTrip($this, $parked, $route)->assertUnprocessable()->assertJsonPath('errors.fleet_id.0', 'This bus is not in service. Choose an active bus.');
    $this->postJson('/api/operator/trips', ['trip_date' => '2026-10-06', 'departure_time' => '08:00', 'trip_type' => 'one_way'])
        ->assertUnprocessable()->assertJsonValidationErrors(['fleet_id', 'route_id']);

    expect(FleetRoute::count())->toBe(0);
});

it('moves a trip to another bus named by itself, on the trip\'s own route', function () {
    $company = Company::create(['company_name' => 'Ds Co']);
    $operator = dsOperator($company);
    $first = dsBus($operator, 'DS-006');
    $second = dsBus($operator, 'DS-007');
    $route = dsRoute($company);
    $this->actingAs($operator, 'sanctum');
    $trip = dsTrip($this, $first, $route)->assertSuccessful()->json('data');

    $this->patchJson("/api/operator/trips/{$trip['trip_id']}/fleet", ['fleet_id' => $second->fleet_id])->assertSuccessful();

    $moved = Trip::with('fleetRoute')->find($trip['trip_id']);
    expect($moved->fleetRoute->fleet_id)->toBe($second->fleet_id)
        ->and($moved->fleetRoute->route_id)->toBe($route->route_id);
});

it('adds a bus with its operating hours, or with the window that covers the routes it was added with', function () {
    $company = Company::create(['company_name' => 'Ds Co']);
    $operator = dsOperator($company);
    $route = dsRoute($company);
    $this->actingAs($operator, 'sanctum');

    $base = ['seated_capacity' => 40, 'standing_capacity' => 10, 'fleet_type' => 'public'];
    $own = $this->postJson('/api/operator/fleets', $base + ['plate_number' => 'DS-101', 'operating_start_time' => '05:30', 'operating_end_time' => '21:30'])->assertSuccessful()->json('data');
    $fromRoutes = $this->postJson('/api/operator/fleets', $base + ['plate_number' => 'DS-102', 'routes' => [['route_id' => $route->route_id, 'start_time' => '12:00', 'end_time' => '05:00']]])->assertSuccessful()->json('data');
    $this->postJson('/api/operator/fleets', $base + ['plate_number' => 'DS-103', 'operating_start_time' => '05:30'])->assertUnprocessable()->assertJsonValidationErrors('operating_end_time');

    expect(substr((string) Fleet::find($own['fleet_id'])->operating_start_time, 0, 5))->toBe('05:30')
        ->and(substr((string) Fleet::find($fromRoutes['fleet_id'])->operating_end_time, 0, 5))->toBe('05:00');
});

it('says which buses can take a trip on a route at a time, and why the others cannot', function () {
    $company = Company::create(['company_name' => 'Ds Co']);
    $operator = dsOperator($company);
    $free = dsBus($operator, 'DS-201');
    $busy = dsBus($operator, 'DS-202');
    $early = dsBus($operator, 'DS-203', '12:00', '20:00');
    $theirs = dsBus(dsOperator(Company::create(['company_name' => 'Other Co'])), 'OT-201');
    $route = dsRoute($company);
    $this->actingAs($operator, 'sanctum');
    dsTrip($this, $busy, $route, '08:00')->assertSuccessful();

    $rows = collect($this->getJson('/api/operator/fleets/availability?'.http_build_query(['route_id' => $route->route_id, 'trip_date' => '2026-10-06', 'departure_time' => '08:10']))
        ->assertOk()->json('data'))->keyBy('plate_number');

    expect($rows->keys()->all())->toBe(['DS-201', 'DS-202', 'DS-203'])
        ->and($rows['DS-201'])->toMatchArray(['fleet_id' => $free->fleet_id, 'available' => true, 'reason' => null, 'operating_start_time' => '06:00'])
        ->and($rows['DS-202'])->toMatchArray(['available' => false, 'reason_code' => 'busy'])
        ->and($rows['DS-203']['reason_code'])->toBe('outside_hours')
        ->and($rows['DS-202']['reason'])->toContain('This bus is already on Ds Route from 08:00')
        ->and($rows['DS-203']['reason'])->toBe('This bus runs between 12:00 and 20:00. A 08:10 departure is outside those hours.')
        ->and($rows->has($theirs->plate_number))->toBeFalse();
});
