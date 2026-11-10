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
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

// Batch 54 (user, 2026-11-10): the All available trips page filters by bus type (Ordinary or Aircon, the classes in
// config/fares.php) and each card can list its route stops, choosing a drop-off by its stop code. Coordinates are SAMPLE.

/** A two-stop route with one trip today at 10:00 on a bus of the given class. */
function tpfTrip(?string $class): Trip
{
    $user = User::create(['username' => 'tpf'.Str::random(8), 'email' => Str::random(8).'@example.com', 'password' => bcrypt('password123'), 'role' => 'operator']);
    StaffUser::create(['user_id' => $user->user_id, 'company_user_uuid' => (string) Str::uuid(), 'name' => 'Tpf Operator', 'phone_num' => '09170000094', 'address' => 'x']);
    $companyUserId = $user->fresh()->companyProfile->company_user_id;
    $route = Route::create(['route_name' => 'Tpf '.Str::random(4), 'origin' => 'A', 'destination' => 'B']);
    foreach (['Tpf Start '.Str::random(3), 'Tpf End '.Str::random(3)] as $i => $name) {
        $stop = Stop::create(['stop_name' => $name, 'latitude' => 7.0 + $i / 10, 'longitude' => 125.0]);
        RouteStop::create(['route_id' => $route->route_id, 'stop_id' => $stop->stop_id, 'stop_order' => $i + 1, 'distance_from_origin_km' => $i * 10, 'stop_type' => 'stop', 'scheduled_offset_min' => $i * 15]);
    }
    $fleet = Fleet::create(['company_user_id' => $companyUserId, 'plate_number' => 'TPF-'.fake()->unique()->numerify('####'), 'bus_company_name' => 'Tpf Co', 'body_no' => '1', 'capacity' => 30, 'seated_capacity' => 30, 'standing_capacity' => 0, 'status' => 'active', 'vehicle_class' => $class]);
    $fr = FleetRoute::create(['fleet_id' => $fleet->fleet_id, 'route_id' => $route->route_id, 'start_time' => '05:00', 'end_time' => '22:00', 'status' => 'active']);

    return Trip::create(['fleet_route_id' => $fr->fleet_route_id, 'company_user_id' => $companyUserId, 'trip_date' => '2026-11-10', 'departure_time' => '10:00', 'trip_type' => 'one_way', 'status' => 'scheduled', 'current_seated_capacity' => 0, 'current_standing_capacity' => 0, 'total_occupancy' => 0]);
}

beforeEach(fn () => Carbon::setTestNow(Carbon::parse('2026-11-10 08:00', 'Asia/Manila')));
afterEach(fn () => Carbon::setTestNow());

it('says each trip\'s bus class and lists only one class when asked', function () {
    $classes = array_keys(config('fares.vehicle_classes'));
    [$first, $second] = [$classes[0], $classes[1]];
    $a = tpfTrip($first);
    $b = tpfTrip($second);

    $all = collect($this->getJson('/api/booking/trips?date=2026-11-10')->assertOk()->json('data.trips'))->keyBy('trip_id');
    expect($all)->toHaveCount(2)
        ->and($all[$a->trip_id]['vehicle_class'])->toBe($first)
        ->and($all[$a->trip_id]['vehicle_class_label'])->toBe(config('fares.vehicle_classes')[$first]);

    $only = $this->getJson('/api/booking/trips?date=2026-11-10&bus_type='.$second)->assertOk()->json('data.trips');
    expect(array_column($only, 'trip_id'))->toBe([$b->trip_id]);
});

it('gives the bus types for the filter with the stops, from the config', function () {
    $types = $this->getJson('/api/booking/stops')->assertOk()->json('data.meta.bus_types');
    expect(array_column($types, 'key'))->toBe(array_keys(config('fares.vehicle_classes')))
        ->and(array_column($types, 'label'))->toBe(array_values(config('fares.vehicle_classes')));
});

it('refuses a bus type that is not a known class, and reads Not provided as null for a bus without one', function () {
    $none = tpfTrip(null);

    $this->getJson('/api/booking/trips?date=2026-11-10&bus_type=spaceship')->assertStatus(422)->assertJsonValidationErrors('bus_type');
    $trip = collect($this->getJson('/api/booking/trips?date=2026-11-10')->json('data.trips'))->firstWhere('trip_id', $none->trip_id);
    expect($trip['vehicle_class'])->toBeNull()->and($trip['vehicle_class_label'])->toBeNull();
});

it('gives each route stop on the timeline its stop code, so a drop-off can be chosen', function () {
    $trip = tpfTrip(array_key_first(config('fares.vehicle_classes')));

    $stops = $this->getJson("/api/trips/{$trip->trip_id}/timeline")->assertOk()->json('data.stops');
    $codes = Stop::whereIn('stop_id', array_column($stops, 'stop_id'))->pluck('stop_code', 'stop_id');
    expect($stops)->toHaveCount(2);
    foreach ($stops as $stop) {
        expect($stop['stop_code'])->not->toBeEmpty()->toBe($codes[$stop['stop_id']]);
    }
});
