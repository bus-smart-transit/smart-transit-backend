<?php

use App\Jobs\FillStopTown;
use App\Models\Fleet;
use App\Models\FleetRoute;
use App\Models\Route;
use App\Models\RouteStop;
use App\Models\StaffUser;
use App\Models\Stop;
use App\Models\Trip;
use App\Models\User;
use App\Repositories\StopRepository;
use App\Services\RegionPlaceService;
use App\Services\ReverseGeocodingService;
use App\Services\StopService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

// Batch 52 item 1 (user, 2026-11-09): after "Are you in {place}?" the homepage lists the trips you can board in your
// town (any direction, buses passing through included), each from your nearest stop there that the bus has not passed;
// with none in the town, the province. Town names come from the region list (config), never written here. Coordinates
// are SAMPLE test values.

function tfaTowns(): array
{
    $provinces = config('region.provinces');
    $province = collect($provinces)->keys()->first(fn ($p) => count($provinces[$p]) >= 2);

    return ['province' => $province, 'a' => $provinces[$province][0], 'b' => $provinces[$province][1], 'other' => collect($provinces)->except($province)->flatten()->first()];
}

/** A route of stops [name => town]; a trip on it today at 10:00. */
function tfaTrip(array $stops, string $direction = 'outbound'): array
{
    $user = User::create(['username' => 'tfa'.Str::random(8), 'email' => Str::random(8).'@example.com', 'password' => bcrypt('password123'), 'role' => 'operator']);
    StaffUser::create(['user_id' => $user->user_id, 'company_user_uuid' => (string) Str::uuid(), 'name' => 'Tfa Operator', 'phone_num' => '09170000093', 'address' => 'x']);
    $companyUserId = $user->fresh()->companyProfile->company_user_id;
    $route = Route::create(['route_name' => 'Tfa '.Str::random(4), 'origin' => 'A', 'destination' => 'B']);
    $ids = [];
    $i = 0;
    foreach ($stops as $name => $town) {
        $stop = Stop::create(['stop_name' => $name, 'latitude' => 7.0 + $i / 10, 'longitude' => 125.0, 'municipality' => $town]);
        RouteStop::create(['route_id' => $route->route_id, 'stop_id' => $stop->stop_id, 'stop_order' => $i + 1, 'distance_from_origin_km' => $i * 10, 'stop_type' => 'stop', 'scheduled_offset_min' => $i * 10]);
        $ids[$name] = $stop->stop_id;
        $i++;
    }
    $fleet = Fleet::create(['company_user_id' => $companyUserId, 'plate_number' => 'TFA-'.fake()->unique()->numerify('####'), 'bus_company_name' => 'Tfa Co', 'body_no' => '1', 'capacity' => 30, 'seated_capacity' => 30, 'standing_capacity' => 0, 'status' => 'active', 'vehicle_class' => 'ordinary']);
    $fr = FleetRoute::create(['fleet_id' => $fleet->fleet_id, 'route_id' => $route->route_id, 'start_time' => '05:00', 'end_time' => '22:00', 'status' => 'active']);
    $trip = Trip::create(['fleet_route_id' => $fr->fleet_route_id, 'company_user_id' => $companyUserId, 'trip_date' => '2026-11-10', 'departure_time' => '10:00', 'trip_type' => 'one_way', 'leg_direction' => $direction, 'status' => 'scheduled', 'current_seated_capacity' => 0, 'current_standing_capacity' => 0, 'total_occupancy' => 0]);

    return ['trip' => $trip, 'stops' => $ids];
}

function tfaList($test, array $query): array
{
    return $test->getJson('/api/booking/trips?date=2026-11-10&'.http_build_query($query))->assertOk()->json('data');
}

beforeEach(fn () => Carbon::setTestNow(Carbon::parse('2026-11-10 08:00', 'Asia/Manila')));
afterEach(fn () => Carbon::setTestNow());

it('lists the trips you can board in your town, from a stop there, also when the bus only passes through', function () {
    $t = tfaTowns();
    $starts = tfaTrip(['Start Here' => $t['a'], 'Far Away' => $t['other']]);
    $passes = tfaTrip(['Elsewhere' => $t['other'], 'Pass Stop' => $t['a'], 'End' => $t['other']]);
    tfaTrip(['Not Mine' => $t['other'], 'Not Mine Either' => $t['other']]);

    $data = tfaList($this, ['town' => $t['a']]);
    $byTrip = collect($data['trips'])->keyBy('trip_id');

    expect($byTrip)->toHaveCount(2)
        ->and($byTrip[$starts['trip']->trip_id]['origin']['name'])->toBe('Start Here')
        ->and($byTrip[$passes['trip']->trip_id]['origin']['name'])->toBe('Pass Stop')
        ->and($data['area'])->toMatchArray(['level' => 'town', 'name' => $t['a']]);
});

it('boards at the stop nearest the passenger, in the order the device sent', function () {
    $t = tfaTowns();
    $f = tfaTrip(['First In Town' => $t['a'], 'Second In Town' => $t['a'], 'Out' => $t['other']]);

    $default = tfaList($this, ['town' => $t['a']])['trips'][0]['origin']['name'];
    $near = tfaList($this, ['town' => $t['a'], 'prefer' => $f['stops']['Second In Town'].','.$f['stops']['First In Town']])['trips'][0]['origin']['name'];

    expect($default)->toBe('First In Town')->and($near)->toBe('Second In Town');
});

it('lists the province when asked, and nothing for a name the region does not have', function () {
    $t = tfaTowns();
    tfaTrip(['In B' => $t['b'], 'Out' => $t['other']]);

    expect(tfaList($this, ['town' => $t['a']])['trips'])->toBe([]);
    $province = tfaList($this, ['province' => $t['province']]);
    expect($province['trips'])->toHaveCount(1)
        ->and($province['area'])->toMatchArray(['level' => 'province', 'name' => $t['province']]);
    expect(tfaList($this, ['town' => 'Not A Region Town'])['trips'])->toBe([]);
});

it('fills a stop town from its position, never over one already set', function () {
    Cache::flush();
    $t = tfaTowns();
    $fake = Mockery::mock(ReverseGeocodingService::class);
    $fake->shouldReceive('townNames')->andReturn([$t['a']]);
    app()->instance(ReverseGeocodingService::class, $fake);
    $center = config('region.center');
    $empty = Stop::create(['stop_name' => 'No Town', 'latitude' => $center['lat'], 'longitude' => $center['lng']]);
    $set = Stop::create(['stop_name' => 'Has Town', 'latitude' => $center['lat'], 'longitude' => $center['lng'], 'municipality' => $t['b']]);

    (new FillStopTown($empty->stop_id))->handle(app(StopRepository::class), app(RegionPlaceService::class));
    (new FillStopTown($set->stop_id))->handle(app(StopRepository::class), app(RegionPlaceService::class));

    expect($empty->fresh()->municipality)->toBe($t['a'])->and($set->fresh()->municipality)->toBe($t['b']);
});

// Batch 56 (user, 2026-11-10): the town shown for a stop comes from its pin, so moving the pin looks it up again.
it('looks the town up again when a stop is moved, and keeps it when only the name changes', function () {
    Bus::fake([FillStopTown::class]);
    $t = tfaTowns();
    $center = config('region.center');
    $stop = Stop::create(['stop_name' => 'Moving Stop', 'latitude' => $center['lat'], 'longitude' => $center['lng'], 'municipality' => $t['a']]);
    $service = app(StopService::class);

    $service->updateStop($stop->stop_id, ['stop_name' => 'Renamed Stop']);
    expect($stop->fresh()->municipality)->toBe($t['a']);
    Bus::assertNotDispatched(FillStopTown::class);

    $service->updateStop($stop->stop_id, ['latitude' => $center['lat'] + 0.01, 'longitude' => $center['lng']]);
    expect($stop->fresh()->municipality)->toBeNull();
    Bus::assertDispatched(FillStopTown::class, fn ($job) => $job->stopId === $stop->stop_id);
});
