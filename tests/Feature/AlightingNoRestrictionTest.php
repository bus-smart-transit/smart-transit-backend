<?php

use App\Models\FareRule;
use App\Models\Fleet;
use App\Models\FleetDailyPin;
use App\Models\FleetRoute;
use App\Models\Route;
use App\Models\RouteStop;
use App\Models\StaffUser;
use App\Models\Stop;
use App\Models\Ticket;
use App\Models\Trip;
use App\Models\User;
use App\Repositories\RouteGeometryRepository;
use App\Services\TicketService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

// Batch 27 Part 2, Task 3: there is NO alighting restriction. The earlier "nobody may alight inside Davao City" idea
// was a misreading and is cancelled (SDD.md, Adviser decisions). These tests pin that down in the three flows a
// passenger or Chauffeur can use: booking, the Chauffeur's cash ticket, and the trip timeline. The municipality
// here is plain test data on the stop; nothing in the application knows the name.

function altStaff(string $role): User
{
    $user = User::create(['username' => 'alt'.Str::random(8), 'email' => Str::random(8).'@example.com', 'password' => bcrypt('password123'), 'role' => $role]);
    StaffUser::create(['user_id' => $user->user_id, 'company_user_uuid' => (string) Str::uuid(), 'name' => 'Alt '.ucfirst($role), 'phone_num' => '09170000029', 'address' => 'x']);

    return $user->fresh();
}

/** Digos City -> two stops inside Davao City -> Tagum City, with a path and a trip today at 08:00. */
function altFixture(): array
{
    $operator = altStaff('operator');
    $driver = altStaff('driver');
    $conductor = altStaff('conductor');

    $route = Route::create(['route_name' => 'Alt Route', 'origin' => 'Digos', 'destination' => 'Tagum']);
    $defs = [
        ['Digos Terminal', 6.75, 125.36, 0, 0, 'terminal', 'Digos City'],
        ['Toril Stop', 7.01, 125.50, 20, 30, 'stop', 'Davao City'],
        ['Ecoland Stop', 7.05, 125.60, 30, 45, 'stop', 'Davao City'],
        ['Tagum Terminal', 7.45, 125.80, 80, 120, 'terminal', 'Tagum City'],
    ];
    $stops = [];
    foreach ($defs as $i => [$name, $lat, $lng, $km, $offset, $type, $municipality]) {
        $stop = Stop::create(['stop_name' => $name, 'latitude' => $lat, 'longitude' => $lng, 'municipality' => $municipality]);
        RouteStop::create(['route_id' => $route->route_id, 'stop_id' => $stop->stop_id, 'stop_order' => $i + 1, 'distance_from_origin_km' => $km, 'stop_type' => $type, 'scheduled_offset_min' => $offset]);
        $stops[$name] = $stop;
    }
    app(RouteGeometryRepository::class)->save($route->route_id, [
        'type' => 'LineString',
        'coordinates' => collect($defs)->map(fn ($d) => [$d[2], $d[1]])->all(),
    ]);

    $fleet = Fleet::create([
        'company_user_id' => $operator->companyProfile->company_user_id,
        'plate_number' => 'ALT-'.fake()->unique()->numerify('####'),
        'capacity' => 40, 'seated_capacity' => 30, 'standing_capacity' => 10, 'status' => 'active',
    ]);
    FareRule::create(['fleet_id' => $fleet->fleet_id, 'base_fare' => 15, 'fare_per_km' => 2, 'status' => 'active', 'seat_type' => 'seated']);
    $fleetRoute = FleetRoute::create(['fleet_id' => $fleet->fleet_id, 'route_id' => $route->route_id, 'start_time' => '06:00', 'end_time' => '22:00', 'status' => 'active']);
    $trip = Trip::create([
        'fleet_route_id' => $fleetRoute->fleet_route_id,
        'company_user_id' => $operator->companyProfile->company_user_id,
        'driver_id' => $driver->companyProfile->company_user_id,
        'conductor_id' => $conductor->companyProfile->company_user_id,
        'driver_accepted_at' => now(), 'conductor_accepted_at' => now(), // Batch 29: the assignment is accepted
        'trip_date' => '2026-10-04', 'departure_time' => '08:00', 'trip_type' => 'one_way', 'leg_direction' => 'outbound', 'status' => 'scheduled',
        'current_seated_capacity' => 0, 'current_standing_capacity' => 0, 'total_occupancy' => 0,
    ]);
    FleetDailyPin::create([
        'trip_id' => $trip->trip_id, 'driver_id' => $driver->companyProfile->company_user_id, 'conductor_id' => $conductor->companyProfile->company_user_id,
        'pin_code' => '123456', 'pin_date' => '2026-10-04', 'driver_verified_at' => now(), 'conductor_verified_at' => now(), 'paired_at' => now(),
    ]);

    return [$conductor, $trip, $stops];
}

beforeEach(function () {
    Carbon::setTestNow(Carbon::parse('2026-10-04 07:00:00', 'Asia/Manila'));
    Cache::flush();
});

afterEach(fn () => Carbon::setTestNow());

it('lets a passenger book a trip that ends inside Davao City: the stops are listed, resolved and priced', function () {
    [, $trip, $stops] = altFixture();

    $groups = collect($this->getJson('/api/booking/stops?origin_stop_id='.$stops['Digos Terminal']->stop_id)->assertSuccessful()->json('data.groups'));
    $inCity = $groups->firstWhere('municipality', 'Davao City');
    expect(collect($inCity['stops'])->pluck('name')->sort()->values()->all())->toBe(['Ecoland Stop', 'Toril Stop']);

    $resolved = $this->getJson('/api/booking/resolve?'.http_build_query([
        'mode' => 'now', 'origin_stop_id' => $stops['Digos Terminal']->stop_id, 'destination_stop_id' => $stops['Ecoland Stop']->stop_id,
    ]))->assertSuccessful()->json('data');
    expect($resolved['trip']['trip_id'])->toBe($trip->trip_id);

    $reserved = app(TicketService::class)->reserveAndPrice([
        'trip_id' => $trip->trip_id, 'seat_type' => 'seated',
        'origin_stop_id' => $stops['Digos Terminal']->stop_id, 'destination_stop_id' => $stops['Ecoland Stop']->stop_id,
    ]);
    expect((float) $reserved['amount'])->toBe(15 + 30 * 2.0);
});

it('lets the Chauffeur sell a cash ticket that boards or ends inside Davao City', function () {
    [$conductor, $trip, $stops] = altFixture();
    $trip->update(['status' => 'boarding']);
    $this->actingAs($conductor)->postJson('/api/conductor/shift/start')->assertSuccessful();
    $sell = fn (string $from, string $to) => $this->actingAs($conductor)->postJson('/api/conductor/checkout', ['items' => [[
        'trip_id' => $trip->trip_id, 'seat_type' => 'seated', 'origin_stop_id' => $stops[$from]->stop_id, 'destination_stop_id' => $stops[$to]->stop_id,
    ]]]);

    $toCity = $sell('Digos Terminal', 'Toril Stop')->assertSuccessful();
    expect((float) $toCity->json('data.tickets.0.amount'))->toBe(15 + 20 * 2.0);
    $withinCity = $sell('Toril Stop', 'Ecoland Stop')->assertSuccessful();
    expect((float) $withinCity->json('data.tickets.0.amount'))->toBe(15 + 10 * 2.0);
    $fromCity = $sell('Ecoland Stop', 'Tagum Terminal')->assertSuccessful();
    expect((float) $fromCity->json('data.tickets.0.amount'))->toBe(15 + 50 * 2.0);

    expect(Ticket::where('trip_id', $trip->trip_id)->count())->toBe(3);
});

it('lets the timeline choose an alighting stop inside Davao City and recompute the journey', function () {
    [, $trip, $stops] = altFixture();

    $data = $this->getJson("/api/trips/{$trip->trip_id}/timeline?".http_build_query([
        'boarding_stop_id' => $stops['Digos Terminal']->stop_id, 'alighting_stop_id' => $stops['Ecoland Stop']->stop_id, 'seat_type' => 'seated',
    ]))->assertSuccessful()->json('data');

    $rows = collect($data['stops'])->keyBy('name');
    expect($rows['Toril Stop']['selectable'])->toBeTrue();
    expect($rows['Ecoland Stop']['selectable'])->toBeTrue();
    expect($rows['Ecoland Stop']['is_alighting'])->toBeTrue();
    expect($data['header']['distance_km'])->toEqual(30);
    expect($data['fare']['amount'])->toEqual(75); // 15 + 30 * 2
});

it('has no alighting-restriction table, flag or rule anywhere in the schema', function () {
    expect(\Illuminate\Support\Facades\Schema::hasTable('alighting_restrictions'))->toBeFalse();
    foreach (['stops', 'route_stops', 'routes'] as $table) {
        $columns = \Illuminate\Support\Facades\Schema::getColumnListing($table);
        expect(collect($columns)->filter(fn ($c) => str_contains($c, 'alight') && str_contains($c, 'restrict')))->toBeEmpty();
    }
});
