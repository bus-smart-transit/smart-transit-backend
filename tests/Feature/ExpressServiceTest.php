<?php

use App\Models\FareRule;
use App\Models\Fleet;
use App\Models\FleetRoute;
use App\Models\Route;
use App\Models\RouteStop;
use App\Models\StaffUser;
use App\Models\Stop;
use App\Models\Trip;
use App\Models\User;
use App\Repositories\RouteGeometryRepository;
use App\Repositories\RouteStopRepository;
use App\Services\TicketService;
use App\Support\ServiceType;
use App\Support\TripRunInfo;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

uses(RefreshDatabase::class);

// Batch 28, item 3: service type regular | express belongs to the trip. On an express trip the stops it does
// not serve are pass-throughs. Express has no fare yet, so it is listed but cannot be booked.

function exStaff(string $role): User
{
    $user = User::create(['username' => $role.Str::random(6), 'email' => Str::random(8).'@example.com', 'password' => bcrypt('password123'), 'role' => $role]);
    joinTestCompany(StaffUser::create(['user_id' => $user->user_id, 'company_user_uuid' => (string) Str::uuid(), 'name' => ucfirst($role), 'phone_num' => '0917', 'address' => 'x']));

    return $user->fresh();
}

/**
 * Five stops on a straight line. Express serves $expressNames (default A, D, E): B is a plain stop that express skips.
 *
 * @param  array<int, string>  $expressNames
 */
function exRoute(string $prefix = 'Stop ', array $expressNames = ['A', 'D', 'E'], ?User $operator = null): array
{
    $operator ??= exStaff('operator');
    $route = Route::create(['route_name' => $prefix.'Route', 'origin' => 'A', 'destination' => 'E']);
    $defs = [['A', 7.00, 0, 0, 'terminal'], ['B', 7.09, 10, 15, 'stop'], ['C', 7.18, 20, 30, 'pass_through'], ['D', 7.27, 30, 45, 'stop'], ['E', 7.36, 40, 60, 'terminal']];
    $stops = [];
    $routeStops = [];
    foreach ($defs as $i => [$name, $lat, $km, $offset, $type]) {
        $stop = Stop::create(['stop_name' => $prefix.$name, 'latitude' => $lat, 'longitude' => 125.0, 'municipality' => 'Mati City']);
        $routeStops[$name] = RouteStop::create([
            'route_id' => $route->route_id, 'stop_id' => $stop->stop_id, 'stop_order' => $i + 1, 'distance_from_origin_km' => $km,
            'stop_type' => $type, 'scheduled_offset_min' => $offset, 'served_by_express' => in_array($name, $expressNames, true),
        ]);
        $stops[$name] = $stop;
    }
    app(RouteGeometryRepository::class)->save($route->route_id, ['type' => 'LineString', 'coordinates' => collect($defs)->map(fn ($d) => [125.0, $d[1]])->all()]);

    $fleet = Fleet::create(['company_user_id' => $operator->companyProfile->company_user_id, 'plate_number' => 'EX-'.fake()->unique()->numerify('####'), 'capacity' => 40, 'seated_capacity' => 30, 'standing_capacity' => 10, 'status' => 'active']);
    $fare = FareRule::create(['fleet_id' => $fleet->fleet_id, 'base_fare' => 15, 'fare_per_km' => 2, 'status' => 'active', 'seat_type' => 'seated']);
    $fleetRoute = FleetRoute::create(['fleet_id' => $fleet->fleet_id, 'route_id' => $route->route_id, 'start_time' => '06:00', 'end_time' => '22:00', 'status' => 'active']);

    return [$route, $stops, $fleetRoute, $operator, $routeStops, $fare];
}

function exTrip(FleetRoute $fleetRoute, User $operator, string $service = 'regular', string $time = '08:00', string $direction = 'outbound'): Trip
{
    return Trip::create([
        'fleet_route_id' => $fleetRoute->fleet_route_id, 'company_user_id' => $operator->companyProfile->company_user_id,
        'trip_date' => '2026-10-04', 'departure_time' => $time, 'trip_type' => 'one_way', 'leg_direction' => $direction, 'service_type' => $service, 'status' => 'scheduled',
        'current_seated_capacity' => 0, 'current_standing_capacity' => 0, 'total_occupancy' => 0,
    ]);
}

beforeEach(function () {
    Carbon::setTestNow(Carbon::parse('2026-10-04 06:00:00', 'Asia/Manila'));
    Cache::flush();
});
afterEach(fn () => Carbon::setTestNow());

it('is regular by default and normalises anything else to regular', function () {
    [, , $fleetRoute, $operator] = exRoute();
    $trip = Trip::create([
        'fleet_route_id' => $fleetRoute->fleet_route_id, 'company_user_id' => $operator->companyProfile->company_user_id, 'trip_date' => '2026-10-04',
        'departure_time' => '08:00', 'trip_type' => 'one_way', 'leg_direction' => 'outbound', 'status' => 'scheduled',
        'current_seated_capacity' => 0, 'current_standing_capacity' => 0, 'total_occupancy' => 0,
    ]);

    expect($trip->fresh()->service_type)->toBe('regular');
    expect(ServiceType::normalize(null))->toBe('regular');
    expect(ServiceType::normalize('EXPRESS'))->toBe('express');
    expect(ServiceType::normalize('turbo'))->toBe('regular');
});

it('marks only terminals as served by express when the migration backfills existing data', function () {
    // The migration ran on an empty table here; apply its rule to prove it matches the stored column.
    [, , , , $routeStops] = exRoute('Stop ', []);
    RouteStop::query()->where('stop_type', 'terminal')->update(['served_by_express' => true]);

    expect($routeStops['A']->fresh()->served_by_express)->toBeTrue();
    expect($routeStops['E']->fresh()->served_by_express)->toBeTrue();
    expect($routeStops['B']->fresh()->served_by_express)->toBeFalse();
});

// 2026-11-21 (user): express is "Non-stop": only the route's first and last stop, even where stops are marked.
it('shows every stop between the ends as a pass-through on a non-stop timeline, even marked ones', function () {
    [, $stops, $fleetRoute, $operator] = exRoute(); // D is marked "served by express"
    $express = exTrip($fleetRoute, $operator, 'express');

    $data = $this->getJson("/api/trips/{$express->trip_id}/timeline")->assertSuccessful()->json('data');
    $byName = collect($data['stops'])->keyBy('name');

    expect($data['trip']['service_type'])->toBe('express');
    expect($byName['Stop B']['type'])->toBe('pass_through');
    expect($byName['Stop D']['type'])->toBe('pass_through');
    expect(collect($data['stops'])->where('selectable', true)->pluck('name')->values()->all())->toBe(['Stop A', 'Stop E']);
    // No express offsets exist: the two ends take the regular timetable as the estimate, nothing between them.
    expect(collect($data['stops'])->pluck('eta_time')->filter()->values()->all())->toBe(['08:00', '09:00']);
    expect($data['header']['via'])->toBe([]);
    expect($data['allow_custom_dropoff'])->toBeFalse();
});

it('leaves a regular trip on the same route unchanged, including stops express skips', function () {
    [, $stops, $fleetRoute, $operator] = exRoute();
    $regular = exTrip($fleetRoute, $operator, 'regular');

    $data = $this->getJson("/api/trips/{$regular->trip_id}/timeline?".http_build_query([
        'boarding_stop_id' => $stops['B']->stop_id, 'alighting_stop_id' => $stops['D']->stop_id, 'seat_type' => 'seated',
    ]))->assertSuccessful()->json('data');

    expect($data['trip']['service_type'])->toBe('regular');
    expect(collect($data['stops'])->firstWhere('name', 'Stop B')['type'])->toBe('stop');
    expect(collect($data['stops'])->pluck('eta_time')->filter()->first())->toBe('08:00');
    expect($data['fare']['amount'])->toEqual(55.0); // 15 + 20 km * 2
});

it('uses express offsets only when the data has them', function () {
    [, $stops, $fleetRoute, $operator, $routeStops] = exRoute();
    $express = exTrip($fleetRoute, $operator, 'express');
    $routeStops['A']->update(['express_offset_min' => 0]);
    $routeStops['D']->update(['express_offset_min' => 25]);
    $routeStops['E']->update(['express_offset_min' => 35]);

    $rows = collect($this->getJson("/api/trips/{$express->trip_id}/timeline")->assertSuccessful()->json('data.stops'))->keyBy('name');
    expect($rows['Stop D']['eta_time'])->toBe('08:25');
    expect($rows['Stop E']['eta_time'])->toBe('08:35');
    expect($rows['Stop B']['eta_time'])->toBeNull();
});

it('rejects a tampered non-express stop on the timeline', function () {
    [, $stops, $fleetRoute, $operator] = exRoute();
    $express = exTrip($fleetRoute, $operator, 'express');

    $this->getJson("/api/trips/{$express->trip_id}/timeline?".http_build_query(['boarding_stop_id' => $stops['B']->stop_id]))
        ->assertUnprocessable()->assertJsonValidationErrors('boarding_stop_id');
    $this->getJson("/api/trips/{$express->trip_id}/timeline?".http_build_query(['boarding_stop_id' => $stops['A']->stop_id, 'alighting_stop_id' => $stops['B']->stop_id]))
        ->assertUnprocessable()->assertJsonValidationErrors('alighting_stop_id');
});

it('says "Fare unavailable" for an express journey and never falls back to the regular fare', function () {
    [, $stops, $fleetRoute, $operator] = exRoute();
    $express = exTrip($fleetRoute, $operator, 'express');

    $fare = $this->getJson("/api/trips/{$express->trip_id}/timeline?".http_build_query([
        'boarding_stop_id' => $stops['A']->stop_id, 'alighting_stop_id' => $stops['E']->stop_id, 'seat_type' => 'seated',
    ]))->assertSuccessful()->json('data.fare');

    expect($fare['error'])->toBe('Fare unavailable');
    expect($fare)->not->toHaveKey('amount');
});

it('refuses to sell an express ticket, and refuses a tampered stop for what it is', function () {
    [, $stops, $fleetRoute, $operator] = exRoute();
    $express = exTrip($fleetRoute, $operator, 'express');
    $regular = exTrip($fleetRoute, $operator, 'regular', '09:00');
    $item = fn (Trip $trip, string $from, string $to) => ['trip_id' => $trip->trip_id, 'seat_type' => 'seated', 'origin_stop_id' => $stops[$from]->stop_id, 'destination_stop_id' => $stops[$to]->stop_id];

    // A served journey: still not for sale in Stage 1.
    expect(fn () => app(TicketService::class)->reserveAndPrice($item($express, 'A', 'E')))
        ->toThrow(ValidationException::class, 'Fare unavailable');

    // A stop express skips: refused as a stop problem, before the fare rule is even considered.
    $thrown = null;
    try {
        app(TicketService::class)->reserveAndPrice($item($express, 'A', 'B'));
    } catch (ValidationException $e) {
        $thrown = $e;
    }
    expect($thrown)->not->toBeNull();
    expect($thrown->errors())->toHaveKey('destination');
    expect($express->fresh()->current_seated_capacity)->toBe(0); // nothing was reserved

    // The same journey on a regular trip is sold at the regular fare.
    $priced = app(TicketService::class)->reserveAndPrice($item($regular, 'A', 'B'));
    expect((float) $priced['amount'])->toBe(35.0);
});

it('lists an express departure as not bookable, and Book Now never picks it', function () {
    [, $stops, $fleetRoute, $operator] = exRoute();
    exTrip($fleetRoute, $operator, 'express', '07:00');
    $regular = exTrip($fleetRoute, $operator, 'regular', '09:00');
    $q = ['origin_stop_id' => $stops['A']->stop_id, 'destination_stop_id' => $stops['E']->stop_id];

    $trips = $this->getJson('/api/booking/trips?'.http_build_query(['from' => $stops['A']->fresh()->stop_code, 'to' => $stops['E']->fresh()->stop_code, 'date' => '2026-10-04']))->assertSuccessful()->json('data.trips');
    $byService = collect($trips)->keyBy('service_type');
    expect($byService['express']['bookable'])->toBeFalse();
    expect($byService['express']['fare'])->toBeNull();
    expect($byService['regular']['bookable'])->toBeTrue();
    expect($byService['regular']['fare'])->toEqual(95.0); // 15 + 40 km * 2: the regular fare is not cached onto express

    $best = $this->getJson('/api/booking/resolve?'.http_build_query($q + ['mode' => 'now']))->assertSuccessful()->json('data.trip');
    expect($best['trip_id'])->toBe($regular->trip_id);
});

it('does not offer an express trip for a journey that uses a stop it skips', function () {
    [, $stops, $fleetRoute, $operator] = exRoute();
    exTrip($fleetRoute, $operator, 'express', '07:00');

    $trips = $this->getJson('/api/booking/trips?'.http_build_query([
        'from' => $stops['B']->fresh()->stop_code, 'to' => $stops['D']->fresh()->stop_code, 'date' => '2026-10-04',
    ]))->assertSuccessful()->json('data.trips');

    expect(collect($trips)->pluck('service_type')->all())->toBe([]);
});

// 2026-11-21 (user): marked stops no longer change a non-stop trip, on any route.
it('runs non-stop on every route, whichever stops each route marks', function () {
    $operator = exStaff('operator');
    [, $stopsOne, $frOne] = exRoute('One ', ['A', 'D', 'E'], $operator);
    [, $stopsTwo, $frTwo] = exRoute('Two ', ['A', 'B', 'E'], $operator); // this route's express DOES stop at B
    $tripOne = exTrip($frOne, $operator, 'express');
    $tripTwo = exTrip($frTwo, $operator, 'express');

    $one = collect($this->getJson("/api/trips/{$tripOne->trip_id}/timeline")->json('data.stops'))->keyBy('name');
    $two = collect($this->getJson("/api/trips/{$tripTwo->trip_id}/timeline")->json('data.stops'))->keyBy('name');

    expect($one['One B']['type'])->toBe('pass_through');
    expect($two['Two B']['type'])->toBe('pass_through');
    expect($one['One D']['type'])->toBe('pass_through');
    expect($two['Two D']['type'])->toBe('pass_through');
    expect($one['One A']['selectable'])->toBeTrue()->and($two['Two E']['selectable'])->toBeTrue();
});

it('mirrors served stops and express offsets on a reverse leg', function () {
    [, , $fleetRoute, $operator, $routeStops] = exRoute();
    $routeStops['A']->update(['express_offset_min' => 0]);
    $routeStops['E']->update(['express_offset_min' => 40]);
    $reverse = exTrip($fleetRoute, $operator, 'express', '08:00', 'reverse');

    $rows = collect($this->getJson("/api/trips/{$reverse->trip_id}/timeline")->assertSuccessful()->json('data.stops'));
    expect($rows->pluck('name')->all())->toBe(['Stop E', 'Stop D', 'Stop C', 'Stop B', 'Stop A']);
    expect($rows->firstWhere('name', 'Stop E')['eta_time'])->toBe('08:00');
    expect($rows->firstWhere('name', 'Stop A')['eta_time'])->toBe('08:40');
    expect($rows->firstWhere('name', 'Stop B')['type'])->toBe('pass_through');
});

it('lets an operator create a trip with a service type and rejects an unknown one', function () {
    [, , $fleetRoute, $operator] = exRoute();
    // Batch 50: a bus is scheduled only with a fare in force for that service type (an express trip needs an express rule).
    FareRule::create(['fleet_id' => $fleetRoute->fleet_id, 'service_type' => 'express', 'seat_type' => 'seated', 'status' => 'active', 'base_fare' => 20, 'fare_per_km' => 2.5, 'note' => 'SAMPLE']);
    $driver = exStaff('driver');
    $conductor = exStaff('conductor');
    $payload = [
        'fleet_route_id' => $fleetRoute->fleet_route_id, 'trip_date' => '2026-10-06', 'departure_time' => '08:00', 'trip_type' => 'one_way',
        'driver_id' => $driver->companyProfile->company_user_id, 'conductor_id' => $conductor->companyProfile->company_user_id,
    ];

    $this->actingAs($operator)->postJson('/api/operator/trips', $payload + ['service_type' => 'turbo'])->assertUnprocessable()->assertJsonValidationErrors('service_type');
    $id = $this->actingAs($operator)->postJson('/api/operator/trips', $payload + ['service_type' => 'express'])->assertSuccessful()->json('data.trip_id');

    expect(Trip::find($id)->service_type)->toBe('express');
});

it('creates every leg of a shift block with the chosen service type', function () {
    [, , $fleetRoute, $operator] = exRoute();
    // Batch 50: a bus is scheduled only with a fare in force for that service type (an express trip needs an express rule).
    FareRule::create(['fleet_id' => $fleetRoute->fleet_id, 'service_type' => 'express', 'seat_type' => 'seated', 'status' => 'active', 'base_fare' => 20, 'fare_per_km' => 2.5, 'note' => 'SAMPLE']);
    $driver = exStaff('driver');
    $conductor = exStaff('conductor');
    $legs = (int) config('shift_blocks.legs_per_block');
    $times = collect(range(0, $legs - 1))->map(fn ($i) => sprintf('%02d:00', 6 + $i * 3))->all();

    $id = $this->actingAs($operator)->postJson('/api/operator/shift-blocks', [
        'fleet_route_id' => $fleetRoute->fleet_route_id, 'driver_id' => $driver->companyProfile->company_user_id, 'conductor_id' => $conductor->companyProfile->company_user_id,
        'block_type' => config('shift_blocks.block_types')[0], 'scheduled_date' => '2026-10-09', 'service_type' => 'express', 'leg_departure_times' => $times,
    ])->assertSuccessful()->json('data.shift_block_id');

    expect(Trip::where('shift_block_id', $id)->pluck('service_type')->unique()->all())->toBe(['express']);
});

it('lets an operator mark a stop as served by express, with validation', function () {
    [$route, , , $operator, $routeStops] = exRoute();

    testCompanyClaimsRoutesAndStops(); // 2026-11-06: routes and stops belong to a company
    $this->actingAs($operator)->patchJson("/api/operator/routes/{$route->route_id}/stops/{$routeStops['B']->id}/express", ['served_by_express' => true, 'express_offset_min' => 20])->assertSuccessful();
    expect($routeStops['B']->fresh()->only(['served_by_express', 'express_offset_min']))->toBe(['served_by_express' => true, 'express_offset_min' => 20]);

    $this->actingAs($operator)->patchJson("/api/operator/routes/{$route->route_id}/stops/{$routeStops['B']->id}/express", ['served_by_express' => 'maybe'])->assertUnprocessable();
    $this->actingAs($operator)->patchJson("/api/operator/routes/{$route->route_id}/stops/999999/express", ['served_by_express' => true])->assertNotFound();
});

// 2026-11-20 (user): a route that marks no stop runs its express trips non-stop between its own origin and destination.
it('runs express non-stop between the route ends when the route marks no express stop', function () {
    [$route, $stops, $fleetRoute, $operator] = exRoute('Plain ', []);
    $express = exTrip($fleetRoute, $operator, 'express');

    $data = $this->getJson("/api/trips/{$express->trip_id}/timeline")->assertSuccessful()->json('data');
    $rows = collect($data['stops'])->keyBy('name');

    expect($rows['Plain A']['selectable'])->toBeTrue()
        ->and($rows['Plain E']['selectable'])->toBeTrue()
        ->and($rows['Plain B']['type'])->toBe('pass_through')
        ->and($rows['Plain D']['type'])->toBe('pass_through')
        // No express times are set, so the two ends take the regular timetable as the estimate; nothing between them.
        ->and($rows['Plain A']['eta_time'])->toBe('08:00')
        ->and($rows['Plain E']['eta_time'])->toBe('09:00')
        ->and($rows['Plain B']['eta_time'])->toBeNull();

    $service = TripRunInfo::service('express', RouteStop::where('route_id', $route->route_id)->orderBy('stop_order')->get());
    expect($service)->toMatchArray(['label' => 'Non-stop', 'non_stop' => true])
        ->and(collect($service['served_stops'])->pluck('name')->all())->toBe(['Plain A', 'Plain E']);

    // A middle stop cannot be used on this express trip.
    $this->getJson("/api/trips/{$express->trip_id}/timeline?".http_build_query(['boarding_stop_id' => $stops['B']->stop_id]))
        ->assertUnprocessable()->assertJsonValidationErrors('boarding_stop_id');
});

// 2026-11-21 (user): marking a stop no longer changes a non-stop trip (it used to switch to the marked stops).
it('keeps a non-stop trip on its two ends when the route marks a stop', function () {
    [$route, , $fleetRoute, $operator, $routeStops] = exRoute('Switch ', []);
    $express = exTrip($fleetRoute, $operator, 'express');
    $selectable = fn () => collect($this->getJson("/api/trips/{$express->trip_id}/timeline")->assertSuccessful()->json('data.stops'))
        ->where('selectable', true)->pluck('name')->all();

    expect($selectable())->toBe(['Switch A', 'Switch E']);

    // The same write the route page's express switch makes.
    expect(app(RouteStopRepository::class)->updateExpress($route->route_id, $routeStops['D']->id, ['served_by_express' => true]))->toBeTrue();
    expect($selectable())->toBe(['Switch A', 'Switch E']);

    $routeStops['D']->fresh()->update(['served_by_express' => false]);
    expect($selectable())->toBe(['Switch A', 'Switch E']);
});

it('leaves regular trips on a route without express stops unchanged', function () {
    [, , $fleetRoute, $operator] = exRoute('Reg ', []);
    $regular = exTrip($fleetRoute, $operator, 'regular');

    $rows = collect($this->getJson("/api/trips/{$regular->trip_id}/timeline")->assertSuccessful()->json('data.stops'))->keyBy('name');
    expect($rows['Reg B']['type'])->toBe('stop')->and($rows['Reg B']['eta_time'])->toBe('08:15');
});

// 2026-11-20 (user: an express trip did not show on the available trips page): on a route that marks no express stop,
// the express trip is listed, bookable and priced for its two ends, and not for a journey to a stop it skips.
it('lists a non-stop express between the route ends on the available trips page when its bus has an express fare', function () {
    [, $stops, $fleetRoute, $operator] = exRoute('List ', []);
    FareRule::create(['fleet_id' => $fleetRoute->fleet_id, 'service_type' => 'express', 'seat_type' => 'seated', 'base_fare' => 12, 'fare_per_km' => 2.75, 'status' => 'active']);
    $express = exTrip($fleetRoute, $operator, 'express', '07:00');
    $search = fn (string $to) => collect($this->getJson('/api/booking/trips?'.http_build_query([
        'from' => $stops['A']->fresh()->stop_code, 'to' => $stops[$to]->fresh()->stop_code, 'date' => '2026-10-04',
    ]))->assertSuccessful()->json('data.trips'));

    $listed = $search('E')->firstWhere('trip_id', $express->trip_id);
    expect($listed)->not->toBeNull()
        ->and($listed['bookable'])->toBeTrue()
        ->and((float) $listed['fare'])->toEqual(122.0); // 12 + 40 km * 2.75

    expect($search('D')->firstWhere('trip_id', $express->trip_id))->toBeNull(); // D is skipped by the non-stop express
});
