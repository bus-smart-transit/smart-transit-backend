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

// Batch 77 (user, 2026-11-17): the "Next arrivals" card of a stop pin on Track Bus. SAMPLE route: five stops 10 minutes
// apart in the timetable; it is 07:00 in Manila.

function saFixture(): array
{
    $user = User::create(['username' => 'sa'.Str::random(8), 'email' => Str::random(8).'@example.com', 'password' => bcrypt('password123'), 'role' => 'operator']);
    StaffUser::create(['user_id' => $user->user_id, 'company_user_uuid' => (string) Str::uuid(), 'name' => 'Sa Operator', 'phone_num' => '09170000088', 'address' => 'x']);
    $companyUserId = $user->fresh()->companyProfile->company_user_id;
    $route = Route::create(['route_name' => 'Sa Route', 'origin' => 'A', 'destination' => 'E']);
    $stops = [];
    foreach (['A', 'B', 'C', 'D', 'E'] as $i => $name) {
        $stop = Stop::create(['stop_name' => "Sa Stop $name", 'latitude' => 7.0 + $i / 10, 'longitude' => 125.0, 'municipality' => 'Sample City']);
        RouteStop::create(['route_id' => $route->route_id, 'stop_id' => $stop->stop_id, 'stop_order' => $i + 1, 'distance_from_origin_km' => $i * 10, 'stop_type' => in_array($name, ['A', 'E'], true) ? 'terminal' : 'stop', 'scheduled_offset_min' => $i * 10]);
        $stops[$name] = $stop;
    }
    $fleet = Fleet::create([
        'company_user_id' => $companyUserId, 'plate_number' => 'SA-'.fake()->unique()->numerify('####'), 'bus_company_name' => 'Sample Lines', 'body_no' => '1024',
        'capacity' => 30, 'seated_capacity' => 30, 'standing_capacity' => 0, 'status' => 'active', 'vehicle_class' => 'aircon',
    ]);
    $fleetRoute = FleetRoute::create(['fleet_id' => $fleet->fleet_id, 'route_id' => $route->route_id, 'start_time' => '05:00', 'end_time' => '22:00', 'status' => 'active']);
    $trip = fn (string $time, string $status = 'scheduled') => Trip::create([
        'fleet_route_id' => $fleetRoute->fleet_route_id, 'company_user_id' => $companyUserId, 'trip_date' => '2026-10-05', 'departure_time' => $time,
        'trip_type' => 'one_way', 'leg_direction' => 'outbound', 'status' => $status, 'current_seated_capacity' => 0, 'current_standing_capacity' => 0, 'total_occupancy' => 0,
    ]);

    return compact('stops', 'trip');
}

beforeEach(function () {
    Carbon::setTestNow(Carbon::parse('2026-10-05 07:00:00', 'Asia/Manila'));
    Cache::flush();
});
afterEach(fn () => Carbon::setTestNow());

it('lists the buses coming to a stop, soonest first, with company, body number, destination and minutes', function () {
    $f = saFixture();
    ($f['trip'])('07:30');
    ($f['trip'])('07:05');

    $data = $this->getJson('/api/stops/'.$f['stops']['C']->stop_code.'/arrivals')->assertOk()->json('data');

    expect($data['stop']['name'])->toBe('Sa Stop C')
        ->and(collect($data['arrivals'])->pluck('eta_time')->all())->toBe(['07:25', '07:50'])
        ->and($data['arrivals'][0])->toMatchArray(['bus_company' => 'Sample Lines', 'body_no' => '1024', 'destination' => 'Sa Stop E', 'eta_minutes' => 25, 'live' => false, 'delayed' => false]);
});

it('leaves out a bus that has passed the stop, and a finished or cancelled trip', function () {
    $f = saFixture();
    $passed = ($f['trip'])('06:30', 'departed');
    $passed->forceFill(['last_acknowledged_stop_id' => $f['stops']['D']->stop_id])->save();
    ($f['trip'])('06:40', 'completed');
    ($f['trip'])('07:10', 'cancelled');
    ($f['trip'])('07:20');

    $data = $this->getJson('/api/stops/'.$f['stops']['C']->stop_code.'/arrivals')->assertOk()->json('data');

    expect(collect($data['arrivals'])->pluck('eta_time')->all())->toBe(['07:40']);
});

it('shows three at first and more on request, and says how many there are', function () {
    $f = saFixture();
    foreach (['07:05', '07:15', '07:25', '07:35', '07:45'] as $time) {
        ($f['trip'])($time);
    }

    expect($this->getJson('/api/stops/'.$f['stops']['B']->stop_code.'/arrivals')->json('data.arrivals'))->toHaveCount(3)
        ->and($this->getJson('/api/stops/'.$f['stops']['B']->stop_code.'/arrivals')->json('data.total'))->toBe(5)
        ->and($this->getJson('/api/stops/'.$f['stops']['B']->stop_code.'/arrivals?limit=10')->json('data.arrivals'))->toHaveCount(5);
});

// Batch 79 (user, 2026-11-18): the expected (timetable) time beside the estimate, and Delayed when the estimate is behind.
it('gives the timetable time beside the estimate and flags a bus running behind it as delayed', function () {
    $f = saFixture();
    $late = ($f['trip'])('06:40', 'departed'); // reaches B at 06:50 by the timetable; the Driver tapped it at 07:00
    $late->forceFill(['last_acknowledged_stop_id' => $f['stops']['B']->stop_id, 'last_acknowledged_at' => Carbon::parse('2026-10-05 07:00:00', 'Asia/Manila')])->save();
    ($f['trip'])('07:30');

    $arrivals = $this->getJson('/api/stops/'.$f['stops']['D']->stop_code.'/arrivals')->assertOk()->json('data.arrivals');

    expect($arrivals[0])->toMatchArray(['scheduled_time' => '07:10', 'eta_time' => '07:20', 'late_by_minutes' => 10, 'live' => true, 'delayed' => true])
        ->and($arrivals[1])->toMatchArray(['scheduled_time' => '08:00', 'eta_time' => '08:00', 'late_by_minutes' => 0, 'delayed' => false]);
});

it('answers 404 for an unknown stop', function () {
    $this->getJson('/api/stops/no-such-stop/arrivals')->assertNotFound();
});
