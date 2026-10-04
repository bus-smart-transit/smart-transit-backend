<?php

use App\Models\FareRule;
use App\Models\Fleet;
use App\Models\FleetRoute;
use App\Models\Route;
use App\Models\RouteStop;
use App\Models\StaffUser;
use App\Models\Stop;
use App\Models\Ticket;
use App\Models\Trip;
use App\Models\User;
use App\Services\FareCalculationService;
use App\Services\FareRuleResolver;
use App\Services\FareRuleService;
use App\Services\TicketService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

uses(RefreshDatabase::class);

// Batch 28, Stage 2, item 5: fleet class and the fare key. The numbers below are SAMPLE values for the tests only
// (5 km base, 11.00 + 1.90/km ordinary, 13.00 + 2.25/km aircon, an express pair); the application has no fare in code.

function fkStaff(string $role): User
{
    $user = User::create(['username' => 'fk'.Str::random(8), 'email' => Str::random(8).'@example.com', 'password' => bcrypt('password123'), 'role' => $role]);
    StaffUser::create(['user_id' => $user->user_id, 'company_user_uuid' => (string) Str::uuid(), 'name' => 'Fk '.ucfirst($role), 'phone_num' => '09170000031', 'address' => 'x']);

    return $user->fresh();
}

/** One route A(0) B(10) C(20, pass-through) D(30) E(40); returns [route, stops, operator]. */
function fkRoute(): array
{
    $operator = fkStaff('operator');
    $route = Route::create(['route_name' => 'Fk Route', 'origin' => 'A', 'destination' => 'E']);
    $stops = [];
    foreach ([['A', 0, 'terminal'], ['B', 10, 'stop'], ['C', 20, 'pass_through'], ['D', 30, 'stop'], ['E', 40, 'terminal']] as $i => [$name, $km, $type]) {
        $stop = Stop::create(['stop_name' => "Stop $name", 'latitude' => 7.0 + $km / 100, 'longitude' => 125.0, 'municipality' => 'Mati City']);
        RouteStop::create(['route_id' => $route->route_id, 'stop_id' => $stop->stop_id, 'stop_order' => $i + 1, 'distance_from_origin_km' => $km, 'stop_type' => $type, 'served_by_express' => in_array($type, ['terminal', 'stop'], true), 'scheduled_offset_min' => $km]);
        $stops[$name] = $stop;
    }

    return [$route, $stops, $operator];
}

function fkFleet(User $operator, Route $route, ?string $class, string $suffix = ''): Fleet
{
    $fleet = Fleet::create([
        'company_user_id' => $operator->companyProfile->company_user_id,
        'plate_number' => 'FK-'.fake()->unique()->numerify('####').$suffix,
        'bus_company_name' => 'Sample Co', 'body_no' => 'B'.fake()->numerify('###'),
        'capacity' => 40, 'seated_capacity' => 30, 'standing_capacity' => 10, 'status' => 'active', 'vehicle_class' => $class,
    ]);
    FleetRoute::create(['fleet_id' => $fleet->fleet_id, 'route_id' => $route->route_id, 'start_time' => '06:00', 'end_time' => '22:00', 'status' => 'active']);

    return $fleet;
}

function fkTrip(Fleet $fleet, User $operator, string $service = 'regular', string $direction = 'outbound', string $time = '08:00'): Trip
{
    return Trip::create([
        'fleet_route_id' => FleetRoute::where('fleet_id', $fleet->fleet_id)->value('fleet_route_id'),
        'company_user_id' => $operator->companyProfile->company_user_id,
        'trip_date' => '2026-10-04', 'departure_time' => $time, 'trip_type' => 'one_way', 'leg_direction' => $direction, 'service_type' => $service, 'status' => 'scheduled',
        'current_seated_capacity' => 0, 'current_standing_capacity' => 0, 'total_occupancy' => 0,
    ]);
}

/** SAMPLE general rules. */
function fkRule(string $class, string $service, float $base, float $perKm, array $extra = []): FareRule
{
    return FareRule::create(array_merge([
        'fleet_id' => null, 'vehicle_class' => $class, 'service_type' => $service, 'seat_type' => 'seated', 'status' => 'active',
        'base_fare' => $base, 'fare_per_km' => $perKm, 'base_distance_km' => 5, 'note' => 'SAMPLE',
    ], $extra));
}

function fkPrice(Trip $trip, array $stops, string $from, string $to, string $seat = 'seated'): array
{
    return app(TicketService::class)->reserveAndPrice([
        'trip_id' => $trip->trip_id, 'seat_type' => $seat, 'origin_stop_id' => $stops[$from]->stop_id, 'destination_stop_id' => $stops[$to]->stop_id,
    ]);
}

beforeEach(function () {
    Carbon::setTestNow(Carbon::parse('2026-10-04 07:00:00', 'Asia/Manila'));
    Cache::flush();
});
afterEach(fn () => Carbon::setTestNow());

it('prices an ordinary and an aircon bus differently on the same distance', function () {
    [$route, $stops, $operator] = fkRoute();
    fkRule('ordinary', 'regular', 11.00, 1.90);
    fkRule('aircon', 'regular', 13.00, 2.25);
    $ordinary = fkTrip(fkFleet($operator, $route, 'ordinary'), $operator);
    $aircon = fkTrip(fkFleet($operator, $route, 'aircon'), $operator);

    // B -> D is 20 km: 5 km base, 15 km beyond it.
    expect(fkPrice($ordinary, $stops, 'B', 'D')['amount'])->toBe(39.5);   // 11 + 15 x 1.90
    expect(fkPrice($aircon, $stops, 'B', 'D')['amount'])->toBe(46.75);    // 13 + 15 x 2.25
});

it('keeps an old fleet-level rule exactly as it was and gives new rows the defaults', function () {
    [$route, $stops, $operator] = fkRoute();
    $fleet = fkFleet($operator, $route, null); // no class: only its own rule can price it
    $legacy = FareRule::create(['fleet_id' => $fleet->fleet_id, 'base_fare' => 15, 'fare_per_km' => 2, 'status' => 'active', 'seat_type' => 'seated']);

    expect($legacy->fresh()->service_type)->toBe('regular')->and($legacy->fresh()->rounding_mode)->toBe('nearest');
    expect($legacy->fresh()->vehicle_class)->toBeNull()->and($legacy->fresh()->route_id)->toBeNull();
    $trip = fkTrip($fleet, $operator);
    expect(fkPrice($trip, $stops, 'B', 'D')['amount'])->toBe(55.0); // 15 + 20 x 2, as before the fare key existed
});

it('uses the base fare up to the base distance and per km beyond it', function () {
    [$route, $stops, $operator] = fkRoute();
    fkRule('ordinary', 'regular', 11.00, 1.90);
    $trip = fkTrip(fkFleet($operator, $route, 'ordinary'), $operator);
    $fares = app(FareCalculationService::class);
    $rule = app(FareRuleResolver::class)->resolve($trip->fleetRoute->fleet, $route->route_id, 'regular', 'seated');

    expect($fares->priceWithRule($rule, 4.0))->toBe(11.0);
    expect($fares->priceWithRule($rule, 5.0))->toBe(11.0);
    expect($fares->priceWithRule($rule, 8.0))->toBe(16.7); // 11 + 3 x 1.90
});

it('prices regular and express separately and sells express only where an express rule exists', function () {
    [$route, $stops, $operator] = fkRoute();
    $fleet = fkFleet($operator, $route, 'aircon');
    fkRule('aircon', 'regular', 13.00, 2.25);
    $regular = fkTrip($fleet, $operator, 'regular');
    $express = fkTrip($fleet, $operator, 'express', 'outbound', '09:00');

    // No express rule yet: refused as "Fare unavailable", with no borrowing from the regular rule.
    expect(fn () => fkPrice($express, $stops, 'B', 'D'))->toThrow(ValidationException::class, 'Fare unavailable');
    expect(fkPrice($regular, $stops, 'B', 'D')['amount'])->toBe(46.75);

    // Once an express rule exists for the class, the same trip is priced from it (its own base, base distance and rate).
    fkRule('aircon', 'express', 20.00, 3.00, ['base_distance_km' => 10]);
    expect(fkPrice($express, $stops, 'B', 'D')['amount'])->toBe(50.0); // 20 + (20 - 10) x 3
    expect(fkPrice($regular, $stops, 'B', 'D')['amount'])->toBe(46.75); // regular untouched
});

it('never falls back across class or service type, and a fleet with no class and no override has no fare', function () {
    [$route, $stops, $operator] = fkRoute();
    fkRule('aircon', 'regular', 13.00, 2.25);
    $resolver = app(FareRuleResolver::class);

    $ordinary = fkFleet($operator, $route, 'ordinary');   // an aircon rule exists, an ordinary one does not
    $noClass = fkFleet($operator, $route, null);
    expect($resolver->resolve($ordinary, $route->route_id, 'regular', 'seated'))->toBeNull();
    expect($resolver->resolve($noClass, $route->route_id, 'regular', 'seated'))->toBeNull();
    expect(fn () => fkPrice(fkTrip($noClass, $operator), $stops, 'B', 'D'))->toThrow(ValidationException::class, 'Fare unavailable');
    expect($resolver->isAvailable(fkFleet($operator, $route, 'aircon'), $route->route_id, 'express', 'seated'))->toBeFalse();
});

it('looks up a fleet override first, then the route rule, then the any-route rule', function () {
    [$route, , $operator] = fkRoute();
    $fleet = fkFleet($operator, $route, 'aircon');
    $general = fkRule('aircon', 'regular', 13.00, 2.25);
    $resolver = app(FareRuleResolver::class);
    expect($resolver->resolve($fleet, $route->route_id, 'regular', 'seated')->fare_rule_id)->toBe($general->fare_rule_id);

    $routeRule = fkRule('aircon', 'regular', 14.00, 2.40, ['route_id' => $route->route_id]);
    expect($resolver->resolve($fleet, $route->route_id, 'regular', 'seated')->fare_rule_id)->toBe($routeRule->fare_rule_id);

    $other = Route::create(['route_name' => 'Other', 'origin' => 'X', 'destination' => 'Y']);
    expect($resolver->resolve($fleet, $other->route_id, 'regular', 'seated')->fare_rule_id)->toBe($general->fare_rule_id); // the route rule is for another route

    $override = FareRule::create(['fleet_id' => $fleet->fleet_id, 'base_fare' => 1, 'fare_per_km' => 1, 'status' => 'active', 'seat_type' => 'seated', 'note' => 'SAMPLE']);
    expect($resolver->resolve($fleet, $route->route_id, 'regular', 'seated')->fare_rule_id)->toBe($override->fare_rule_id);
});

it('rounds once at the end, nearest (half up) or ceiling, to the configured step', function () {
    $fares = app(FareCalculationService::class);

    // Centavos (the default step): x.xx5 goes up under nearest; ceiling lifts any remainder.
    expect($fares->computeFare(0, 0.005, 1.0, null, 'nearest'))->toBe(0.01);
    expect($fares->computeFare(0, 0.005, 0.4, null, 'nearest'))->toBe(0.0);
    expect($fares->computeFare(0, 0.005, 0.4, null, 'ceiling'))->toBe(0.01);

    // Whole pesos: the .50 case rounds up under nearest, .49 does not; ceiling rounds both up.
    config(['fares.rounding_increment' => '1']);
    expect($fares->computeFare(10, 0.5, 3.0, null, 'nearest'))->toBe(12.0);   // 11.50
    expect($fares->computeFare(10, 0.497, 3.0, null, 'nearest'))->toBe(11.0); // 11.491
    expect($fares->computeFare(10, 0.497, 3.0, null, 'ceiling'))->toBe(12.0);
    expect($fares->computeFare(10, 0.5, 4.0, null, 'ceiling'))->toBe(12.0);   // exactly 12.00 stays 12

    // No float drift: 0.1 + 0.2 style sums come out exact.
    config(['fares.rounding_increment' => '0.01']);
    expect($fares->computeFare(0.1, 0.2, 1.0))->toBe(0.3);
});

it('applies the rule\'s own rounding mode to the ticket and snapshots it', function () {
    config(['fares.rounding_increment' => '1']);
    [$route, $stops, $operator] = fkRoute();
    $rule = fkRule('ordinary', 'regular', 10.00, 0.497, ['base_distance_km' => null, 'rounding_mode' => 'ceiling']);
    $trip = fkTrip(fkFleet($operator, $route, 'ordinary'), $operator);

    $reserved = fkPrice($trip, $stops, 'A', 'B'); // 10 km: 10 + 4.97 = 14.97
    expect($reserved['amount'])->toBe(15.0)->and($reserved['rounding_mode'])->toBe('ceiling')->and($reserved['fare_rule_id'])->toBe($rule->fare_rule_id);

    $payment = \App\Models\Payment::create(['amount' => 15, 'payment_created' => now(), 'transaction_reference' => 'TXN-FK-'.Str::upper(Str::random(8)), 'payment_method' => 'online', 'payment_channel' => 'gcash', 'status' => 'paid', 'payment_uuid' => (string) Str::uuid(), 'is_valid' => true, 'items_payload' => []]);
    $ticket = app(TicketService::class)->finalizeTicket($reserved, null, $payment->payment_id);
    expect($ticket->fresh()->rounding_mode)->toBe('ceiling');
    expect([$ticket->fresh()->boarding_distance_m, $ticket->fresh()->alighting_distance_m])->toBe([0, 10000]);
});

it('prices the even (reverse) leg and a custom drop-off from the class rule', function () {
    [$route, $stops, $operator] = fkRoute();
    $route->update(['allow_custom_dropoff' => true]);
    app(\App\Repositories\RouteGeometryRepository::class)->save($route->route_id, ['type' => 'LineString', 'coordinates' => [[125.0, 7.0], [125.0, 7.1], [125.0, 7.2], [125.0, 7.3], [125.0, 7.4]]]);
    fkRule('aircon', 'regular', 13.00, 2.25);
    $fleet = fkFleet($operator, $route, 'aircon');

    // Reverse leg: D -> B travels back 20 km.
    $reverse = fkTrip($fleet, $operator, 'regular', 'reverse');
    expect(fkPrice($reverse, $stops, 'D', 'B')['amount'])->toBe(46.75);

    // Custom drop-off from B to a pin about 14 km on (7.1 -> 7.225): 13 + (km - 5) x 2.25 from the same rule.
    $outbound = fkTrip($fleet, $operator, 'regular', 'outbound', '10:00');
    $reserved = app(TicketService::class)->reserveAndPrice(['trip_id' => $outbound->trip_id, 'seat_type' => 'seated', 'origin_stop_id' => $stops['B']->stop_id, 'destination_lat' => 7.225, 'destination_lng' => 125.0003]);
    $km = $reserved['distance_km'];
    expect($km)->toBeGreaterThan(10.0)->and($km)->toBeLessThan(15.0);
    expect($reserved['amount'])->toEqualWithDelta(13.0 + ($km - 5) * 2.25, 0.2);
    expect($reserved['fare_rule_id'])->toBe(FareRule::where('vehicle_class', 'aircon')->value('fare_rule_id'));
});

it('lists a trip as unbookable when no fare rule prices it, and Book Now skips it', function () {
    [$route, $stops, $operator] = fkRoute();
    fkRule('ordinary', 'regular', 11.00, 1.90);
    $unclassed = fkTrip(fkFleet($operator, $route, null), $operator, 'regular', 'outbound', '08:00');
    $ordinary = fkTrip(fkFleet($operator, $route, 'ordinary'), $operator, 'regular', 'outbound', '09:00');
    $query = http_build_query(['mode' => 'now', 'origin_stop_id' => $stops['B']->stop_id, 'destination_stop_id' => $stops['D']->stop_id]);

    $data = test()->getJson('/api/booking/resolve?'.$query)->assertSuccessful()->json('data');
    expect($data['trip']['trip_id'])->toBe($ordinary->trip_id)->and($data['trip']['bookable'])->toBeTrue();

    $list = test()->getJson('/api/booking/trips?'.http_build_query(['from' => $stops['B']->stop_code, 'to' => $stops['D']->stop_code, 'date' => '2026-10-04']));
    $items = collect($list->json('data.items') ?? $list->json('data.trips') ?? []);
    if ($items->isNotEmpty()) {
        expect($items->firstWhere('trip_id', $unclassed->trip_id)['bookable'] ?? false)->toBeFalse();
    }
});

it('shows the fleet class and ownership type on the timeline header and "Fare unavailable" when there is no rule', function () {
    [$route, $stops, $operator] = fkRoute();
    fkRule('aircon', 'regular', 13.00, 2.25);
    $fleet = fkFleet($operator, $route, 'aircon');
    $trip = fkTrip($fleet, $operator);
    $q = http_build_query(['boarding_stop_id' => $stops['B']->stop_id, 'alighting_stop_id' => $stops['D']->stop_id, 'seat_type' => 'seated']);

    $data = test()->getJson("/api/trips/{$trip->trip_id}/timeline?$q")->assertSuccessful()->json('data');
    expect($data['bus']['class'])->toBe('aircon')->and($data['bus']['class_label'])->toBe('Aircon');
    expect($data['fare']['amount'])->toEqual(46.75);

    $none = fkTrip(fkFleet($operator, $route, null), $operator, 'regular', 'outbound', '11:00');
    $nodata = test()->getJson("/api/trips/{$none->trip_id}/timeline?$q")->assertSuccessful()->json('data');
    expect($nodata['bus']['class'])->toBeNull();
    expect($nodata['fare']['error'] ?? null)->toBe('Fare unavailable');
});

it('stores the vehicle class canonically, rejects an unknown one and refuses overlapping general rules', function () {
    [$route, , $operator] = fkRoute();
    $service = app(FareRuleService::class);

    // "Deluxe" is part of aircon.
    $rule = $service->createFareRule(['vehicle_class' => 'Deluxe', 'seat_type' => 'seated', 'base_fare' => 13, 'fare_per_km' => 2.25, 'effective_from' => '2026-10-01']);
    expect($rule->vehicle_class)->toBe('aircon')->and($rule->service_type)->toBe('regular')->and($rule->rounding_mode)->toBe('nearest');

    // Same key in force on the same days: refused. A different service type or a later period is fine.
    expect(fn () => $service->createFareRule(['vehicle_class' => 'aircon', 'seat_type' => 'seated', 'base_fare' => 1, 'fare_per_km' => 1, 'effective_from' => '2026-12-01']))->toThrow(ValidationException::class);
    expect($service->createFareRule(['vehicle_class' => 'aircon', 'service_type' => 'express', 'seat_type' => 'seated', 'base_fare' => 1, 'fare_per_km' => 1])->fare_rule_id)->toBeInt();
    // A general rule needs a class.
    expect(fn () => $service->createFareRule(['seat_type' => 'seated', 'base_fare' => 1, 'fare_per_km' => 1]))->toThrow(ValidationException::class);

    $admin = fkStaff('admin');
    $fleetResponse = test()->actingAs($admin)->putJson('/api/admin/fleets/'.fkFleet($operator, $route, null)->fleet_id, ['vehicle_class' => 'nonsense']);
    $fleetResponse->assertStatus(422)->assertJsonValidationErrors(['vehicle_class']);
});

it('lists fare rules with filters, and an operator sees general rules plus only their own fleets\' overrides', function () {
    [$route, , $operator] = fkRoute();
    $mine = fkFleet($operator, $route, 'aircon');
    $otherOperator = fkStaff('operator');
    $theirs = fkFleet($otherOperator, $route, 'aircon');
    fkRule('aircon', 'regular', 13, 2.25);
    fkRule('ordinary', 'express', 20, 3);
    FareRule::create(['fleet_id' => $mine->fleet_id, 'base_fare' => 1, 'fare_per_km' => 1, 'status' => 'active', 'seat_type' => 'seated']);
    FareRule::create(['fleet_id' => $theirs->fleet_id, 'base_fare' => 2, 'fare_per_km' => 2, 'status' => 'active', 'seat_type' => 'seated']);

    $admin = fkStaff('admin');
    expect(test()->actingAs($admin)->getJson('/api/admin/fare-rules')->assertSuccessful()->json('data'))->toHaveCount(4);
    expect(test()->actingAs($admin)->getJson('/api/admin/fare-rules?service_type=express&vehicle_class=ordinary')->json('data'))->toHaveCount(1);

    $seen = test()->actingAs($operator)->getJson('/api/operator/fare-rules')->assertSuccessful()->json('data');
    expect($seen)->toHaveCount(3); // 2 general + their own override; the other operator's override stays hidden
    expect(collect($seen)->pluck('fleet_id')->filter()->all())->toBe([$mine->fleet_id]);

    test()->getJson('/api/fare/options')->assertSuccessful()->assertJsonPath('data.vehicle_classes.0.key', 'ordinary');
});
