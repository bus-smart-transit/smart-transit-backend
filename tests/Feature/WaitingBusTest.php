<?php

use App\Models\Fleet;
use App\Models\FleetRoute;
use App\Models\Route;
use App\Models\RouteStop;
use App\Models\StaffUser;
use App\Models\Stop;
use App\Models\Trip;
use App\Models\User;
use App\Repositories\FleetLocationRepository;
use App\Services\WaitingBusService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

// 2026-11-08 (user): a bus that has not departed shows still at its first stop on the public map, from 60 minutes
// before its departure (today only) until the Driver presses Depart; then it is the live position. Coordinates are
// SAMPLE test values.

function wbFixture(): array
{
    $user = User::create(['username' => 'wb'.Str::random(8), 'email' => Str::random(8).'@example.com', 'password' => bcrypt('password123'), 'role' => 'operator']);
    StaffUser::create(['user_id' => $user->user_id, 'company_user_uuid' => (string) Str::uuid(), 'name' => 'Wb Operator', 'phone_num' => '09170000091', 'address' => 'x']);
    $companyUserId = $user->fresh()->companyProfile->company_user_id;
    $route = Route::create(['route_name' => 'Wb Route', 'origin' => 'Wb Stop A', 'destination' => 'Wb Stop C']);
    $stops = [];
    foreach ([['Wb Stop A', 7.00, 0], ['Wb Stop B', 7.09, 10], ['Wb Stop C', 7.18, 20]] as $i => [$name, $lat, $km]) {
        $stop = Stop::create(['stop_name' => $name, 'latitude' => $lat, 'longitude' => 125.0, 'municipality' => 'Sample City']);
        RouteStop::create(['route_id' => $route->route_id, 'stop_id' => $stop->stop_id, 'stop_order' => $i + 1, 'distance_from_origin_km' => $km, 'stop_type' => 'stop', 'scheduled_offset_min' => $km]);
        $stops[substr($name, -1)] = $stop;
    }
    $bus = function (string $body) use ($companyUserId, $route) {
        $fleet = Fleet::create([
            'company_user_id' => $companyUserId, 'plate_number' => 'WB-'.fake()->unique()->numerify('####'), 'bus_company_name' => 'Wb Co', 'body_no' => $body,
            'capacity' => 30, 'seated_capacity' => 30, 'standing_capacity' => 0, 'status' => 'active', 'vehicle_class' => 'aircon', 'fleet_type' => 'public',
        ]);

        return FleetRoute::create(['fleet_id' => $fleet->fleet_id, 'route_id' => $route->route_id, 'start_time' => '05:00', 'end_time' => '22:00', 'status' => 'active']);
    };
    $trip = fn (FleetRoute $fr, string $date, string $time, string $status, string $leg = 'outbound') => Trip::create([
        'fleet_route_id' => $fr->fleet_route_id, 'company_user_id' => $companyUserId, 'trip_date' => $date, 'departure_time' => $time,
        'trip_type' => 'one_way', 'leg_direction' => $leg, 'status' => $status, 'current_seated_capacity' => 0, 'current_standing_capacity' => 0, 'total_occupancy' => 0,
    ]);

    return compact('stops', 'bus', 'trip');
}

function wbRows(string $at): array
{
    return app(WaitingBusService::class)->rows(Carbon::parse($at, 'Asia/Manila'))->all();
}

it('shows a bus at its first stop from 60 minutes before departure, today only', function () {
    $f = wbFixture();
    $fr = ($f['bus'])('1');
    ($f['trip'])($fr, '2026-11-09', '08:00', 'scheduled');
    ($f['trip'])(($f['bus'])('2'), '2026-11-10', '08:00', 'scheduled'); // tomorrow: never

    expect(wbRows('2026-11-09 06:59'))->toBe([]);

    $rows = wbRows('2026-11-09 07:00');
    expect($rows)->toHaveCount(1)
        ->and($rows[0]->waiting)->toBeTrue()
        ->and($rows[0]->departs_at)->toBe('08:00')
        ->and($rows[0]->waiting_at)->toBe('Wb Stop A')
        ->and((float) $rows[0]->latitude)->toBe(7.0)
        ->and($rows[0]->trip_status)->toBe('scheduled');

    // Still shown while late (delayed), gone once departed or cancelled.
    Trip::query()->where('trip_date', '2026-11-09')->update(['status' => 'delayed']);
    expect(wbRows('2026-11-09 08:20'))->toHaveCount(1);
    Trip::query()->where('trip_date', '2026-11-09')->update(['status' => 'departed']);
    expect(wbRows('2026-11-09 08:20'))->toBe([]);
});

it('shows a boarding trip whatever its time, a reverse leg at its own first stop, and one row per bus', function () {
    $f = wbFixture();
    $fr = ($f['bus'])('1');
    ($f['trip'])($fr, '2026-11-09', '10:00', 'boarding');
    ($f['trip'])($fr, '2026-11-09', '10:30', 'scheduled'); // the same bus later: not a second icon
    ($f['trip'])(($f['bus'])('2'), '2026-11-09', '10:15', 'scheduled', 'reverse');

    $rows = collect(wbRows('2026-11-09 09:30'))->keyBy('departs_at');
    expect($rows)->toHaveCount(2)
        ->and($rows['10:00']->waiting_at)->toBe('Wb Stop A')
        ->and($rows['10:15']->waiting_at)->toBe('Wb Stop C')
        ->and($rows['10:15']->leg_direction)->toBe('reverse');
});

it('lists waiting buses on the public map, and a departed bus at its live position instead', function () {
    config(['tracking.active_locations_cache_seconds' => 0]);
    Carbon::setTestNow(Carbon::parse('2026-11-09 07:30', 'Asia/Manila'));
    $f = wbFixture();
    $waitingTrip = ($f['trip'])(($f['bus'])('1'), '2026-11-09', '08:00', 'boarding');
    $runningFr = ($f['bus'])('2');
    $running = ($f['trip'])($runningFr, '2026-11-09', '07:00', 'departed');
    ($f['trip'])($runningFr, '2026-11-09', '08:10', 'scheduled'); // its next trip: it stays live, not waiting too

    // The phone of the boarding bus reports a position away from its first stop; the map still shows it at the stop.
    $live = (object) ['fleet_id' => $runningFr->fleet_id, 'trip_id' => $running->trip_id, 'route_id' => null, 'latitude' => 7.05, 'longitude' => 125.0, 'age_seconds' => 5, 'trip_status' => 'departed', 'plate_number' => 'X', 'bus_company_name' => 'Wb Co', 'body_no' => '2'];
    $phone = (object) ['fleet_id' => $waitingTrip->fleetRoute->fleet_id, 'trip_id' => $waitingTrip->trip_id, 'route_id' => null, 'latitude' => 7.5, 'longitude' => 125.5, 'age_seconds' => 5, 'trip_status' => 'boarding', 'plate_number' => 'Y', 'bus_company_name' => 'Wb Co', 'body_no' => '1'];
    $locations = Mockery::mock(FleetLocationRepository::class);
    $locations->shouldReceive('getAllActiveLocations')->andReturn(collect([$live, $phone]));
    $locations->shouldReceive('getCurrentForTrip')->andReturn(null); // the timelines read no position here
    app()->instance(FleetLocationRepository::class, $locations);

    $rows = collect($this->getJson('/api/fleet/locations')->assertOk()->json('data'))->keyBy('trip_id');

    expect($rows)->toHaveCount(2)
        ->and($rows[$running->trip_id]['waiting'])->toBeFalse()
        ->and((float) $rows[$running->trip_id]['latitude'])->toBe(7.05)
        ->and($rows[$waitingTrip->trip_id]['waiting'])->toBeTrue()
        ->and((float) $rows[$waitingTrip->trip_id]['latitude'])->toBe(7.0)
        ->and($rows[$waitingTrip->trip_id]['departs_at'])->toBe('08:00')
        ->and($rows[$waitingTrip->trip_id])->not->toHaveKey('plate_number');

    Carbon::setTestNow();
});
