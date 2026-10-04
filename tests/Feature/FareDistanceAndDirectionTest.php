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
use App\Repositories\FareRuleRepository;
use App\Services\FareCalculationService;
use App\Services\FareRuleService;
use App\Services\TicketService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

uses(RefreshDatabase::class);

// Batch 27 Part 2, Tasks 2 and 4: a ticket is priced between two points measured the way the bus travels, the server
// refuses a destination behind the boarding point or a stop the bus does not make, and the fare rule can carry a
// base distance and effective dates. The ticket stores the points it was priced between.

function fdStaff(string $role): User
{
    $user = User::create(['username' => 'fd'.Str::random(8), 'email' => Str::random(8).'@example.com', 'password' => bcrypt('password123'), 'role' => $role]);
    StaffUser::create(['user_id' => $user->user_id, 'company_user_uuid' => (string) Str::uuid(), 'name' => 'Fd '.ucfirst($role), 'phone_num' => '09170000030', 'address' => 'x']);

    return $user->fresh();
}

/** Stops A(0) B(10) C pass-through(20) D(30) E(40) km; fare 15 + 2 per km. Trip is 'boarding' with a paired crew. */
function fdFixture(string $direction = 'outbound', array $rule = []): array
{
    $operator = fdStaff('operator');
    $driver = fdStaff('driver');
    $conductor = fdStaff('conductor');

    $route = Route::create(['route_name' => 'Fd Route', 'origin' => 'A', 'destination' => 'E']);
    $stops = [];
    foreach ([['A', 0, 'terminal'], ['B', 10, 'stop'], ['C', 20, 'pass_through'], ['D', 30, 'stop'], ['E', 40, 'terminal']] as $i => [$name, $km, $type]) {
        $stop = Stop::create(['stop_name' => "Stop $name", 'latitude' => 7.0 + $km / 100, 'longitude' => 125.0, 'municipality' => 'Mati City']);
        RouteStop::create(['route_id' => $route->route_id, 'stop_id' => $stop->stop_id, 'stop_order' => $i + 1, 'distance_from_origin_km' => $km, 'stop_type' => $type]);
        $stops[$name] = $stop;
    }

    $fleet = Fleet::create([
        'company_user_id' => $operator->companyProfile->company_user_id,
        'plate_number' => 'FD-'.fake()->unique()->numerify('####'),
        'capacity' => 40, 'seated_capacity' => 30, 'standing_capacity' => 10, 'status' => 'active',
    ]);
    $fare = FareRule::create(array_merge(['fleet_id' => $fleet->fleet_id, 'base_fare' => 15, 'fare_per_km' => 2, 'status' => 'active', 'seat_type' => 'seated'], $rule));
    $fleetRoute = FleetRoute::create(['fleet_id' => $fleet->fleet_id, 'route_id' => $route->route_id, 'start_time' => '06:00', 'end_time' => '22:00', 'status' => 'active']);
    $trip = Trip::create([
        'fleet_route_id' => $fleetRoute->fleet_route_id,
        'company_user_id' => $operator->companyProfile->company_user_id,
        'driver_id' => $driver->companyProfile->company_user_id,
        'conductor_id' => $conductor->companyProfile->company_user_id,
        'trip_date' => '2026-10-04', 'departure_time' => '08:00', 'trip_type' => 'one_way', 'leg_direction' => $direction, 'status' => 'boarding',
        'current_seated_capacity' => 0, 'current_standing_capacity' => 0, 'total_occupancy' => 0,
    ]);
    FleetDailyPin::create([
        'trip_id' => $trip->trip_id, 'driver_id' => $driver->companyProfile->company_user_id, 'conductor_id' => $conductor->companyProfile->company_user_id,
        'pin_code' => '123456', 'pin_date' => '2026-10-04', 'driver_verified_at' => now(), 'conductor_verified_at' => now(), 'paired_at' => now(),
    ]);

    return [$conductor, $trip, $stops, $fleet, $fare];
}

function fdPrice(Trip $trip, array $stops, string $from, string $to, string $seat = 'seated'): array
{
    return app(TicketService::class)->reserveAndPrice([
        'trip_id' => $trip->trip_id, 'seat_type' => $seat, 'origin_stop_id' => $stops[$from]->stop_id, 'destination_stop_id' => $stops[$to]->stop_id,
    ]);
}

beforeEach(fn () => Carbon::setTestNow(Carbon::parse('2026-10-04 07:00:00', 'Asia/Manila')));
afterEach(fn () => Carbon::setTestNow());

// ---------------------------------------------------------------- Task 2: direction and stopping types

it('prices an outbound leg forwards and refuses a destination behind or at the boarding stop', function () {
    [, $trip, $stops] = fdFixture();

    expect(fdPrice($trip, $stops, 'B', 'D')['amount'])->toBe(15 + 20 * 2.0);

    foreach ([['D', 'B'], ['B', 'A'], ['B', 'B']] as [$from, $to]) {
        try {
            fdPrice($trip, $stops, $from, $to);
            $this->fail("Expected a 422 for $from -> $to");
        } catch (ValidationException $e) {
            expect($e->errors())->toHaveKey('destination');
        }
    }
    expect($trip->fresh()->total_occupancy)->toBe(1); // only the valid sale held a seat; the refused ones held nothing
});

it('measures along a reverse leg: forwards is travel order, the outbound order is behind', function () {
    [, $trip, $stops] = fdFixture('reverse');

    expect(fdPrice($trip, $stops, 'D', 'B')['amount'])->toBe(15 + 20 * 2.0);
    expect(fn () => fdPrice($trip, $stops, 'B', 'D'))->toThrow(ValidationException::class);
});

it('refuses a pass-through stop as boarding or destination', function () {
    [, $trip, $stops] = fdFixture();

    try {
        fdPrice($trip, $stops, 'B', 'C');
        $this->fail('Expected a 422');
    } catch (ValidationException $e) {
        expect($e->errors())->toHaveKey('destination');
    }
    try {
        fdPrice($trip, $stops, 'C', 'D');
        $this->fail('Expected a 422');
    } catch (ValidationException $e) {
        expect($e->errors())->toHaveKey('origin');
    }
});

it('refuses the same on the Chauffeur cash checkout and ignores a tampered fare or distance', function () {
    [$conductor, $trip, $stops] = fdFixture();
    $this->actingAs($conductor)->postJson('/api/conductor/shift/start')->assertSuccessful();
    $sell = fn (string $from, string $to, array $extra = []) => $this->actingAs($conductor)->postJson('/api/conductor/checkout', ['items' => [array_merge([
        'trip_id' => $trip->trip_id, 'seat_type' => 'seated', 'origin_stop_id' => $stops[$from]->stop_id, 'destination_stop_id' => $stops[$to]->stop_id,
    ], $extra)]]);

    $sell('D', 'B')->assertStatus(422)->assertJsonValidationErrors(['destination']);
    $sell('B', 'C')->assertStatus(422)->assertJsonValidationErrors(['destination']);
    expect(Ticket::count())->toBe(0);

    $ok = $sell('B', 'D', ['amount' => 1, 'distance_km' => 0.5, 'fare_rule_id' => 999, 'boarding_distance_m' => 1])->assertSuccessful();
    $ticket = Ticket::find($ok->json('data.tickets.0.ticket_id'));
    expect((float) $ticket->amount)->toBe(55.0);
    expect((float) $ticket->distance_km)->toBe(20.0);
    expect($ticket->fare_rule_id)->not->toBe(999);
    expect($ticket->boarding_distance_m)->toBe(10000);
});

// ---------------------------------------------------------------- Task 4: base distance, effective dates, snapshot

it('charges the base fare up to the base distance and per km beyond it; no base distance keeps today\'s formula', function () {
    $fares = app(FareCalculationService::class);

    $plain = new FareRule(['base_fare' => 15, 'fare_per_km' => 2]);
    expect($fares->priceWithRule($plain, 8.0))->toBe(31.0); // 15 + 8 * 2: unchanged for every existing rule

    $withBase = new FareRule(['base_fare' => 15, 'fare_per_km' => 2, 'base_distance_km' => 5]);
    expect($fares->priceWithRule($withBase, 3.0))->toBe(15.0);
    expect($fares->priceWithRule($withBase, 5.0))->toBe(15.0);
    expect($fares->priceWithRule($withBase, 8.0))->toBe(21.0); // 15 + (8 - 5) * 2
    expect($fares->priceWithRule($withBase, 8.333))->toBe(21.67); // rounded to centavos as before
});

it('prices a ticket with the base distance of the rule and stores the snapshot', function () {
    [$conductor, $trip, $stops, , $fare] = fdFixture('outbound', ['base_distance_km' => 12]);
    $this->actingAs($conductor)->postJson('/api/conductor/shift/start')->assertSuccessful();

    $short = $this->actingAs($conductor)->postJson('/api/conductor/checkout', ['items' => [[
        'trip_id' => $trip->trip_id, 'seat_type' => 'seated', 'origin_stop_id' => $stops['A']->stop_id, 'destination_stop_id' => $stops['B']->stop_id,
    ]]])->assertSuccessful();
    $long = $this->actingAs($conductor)->postJson('/api/conductor/checkout', ['items' => [[
        'trip_id' => $trip->trip_id, 'seat_type' => 'seated', 'origin_stop_id' => $stops['B']->stop_id, 'destination_stop_id' => $stops['E']->stop_id,
    ]]])->assertSuccessful();

    expect((float) $short->json('data.tickets.0.amount'))->toBe(15.0); // 10 km is inside the 12 km base
    $ticket = Ticket::find($long->json('data.tickets.0.ticket_id'));
    expect((float) $ticket->amount)->toBe(15 + (30 - 12) * 2.0);
    expect($ticket->fare_rule_id)->toBe($fare->fare_rule_id);
    expect([$ticket->boarding_distance_m, $ticket->alighting_distance_m])->toBe([10000, 40000]);
});

it('uses the rule in force on the trip date and ignores one that has not started or has ended', function () {
    [, $trip, $stops, $fleet] = fdFixture('outbound', ['effective_to' => '2026-09-30']); // the original rule ended last month
    $future = FareRule::create(['fleet_id' => $fleet->fleet_id, 'base_fare' => 99, 'fare_per_km' => 9, 'status' => 'active', 'seat_type' => 'seated', 'effective_from' => '2026-11-01']);
    $current = FareRule::create(['fleet_id' => $fleet->fleet_id, 'base_fare' => 20, 'fare_per_km' => 3, 'status' => 'active', 'seat_type' => 'seated', 'effective_from' => '2026-10-01']);

    $repo = app(FareRuleRepository::class);
    $routeId = (int) $trip->fleetRoute->route_id;
    expect($repo->findFleetOverride($fleet->fleet_id, 'seated', 'regular', $routeId, '2026-10-04')->fare_rule_id)->toBe($current->fare_rule_id);
    expect($repo->findFleetOverride($fleet->fleet_id, 'seated', 'regular', $routeId, '2026-11-02')->fare_rule_id)->toBe($future->fare_rule_id);

    // The trip's own date decides, not the day the ticket is sold.
    expect(fdPrice($trip, $stops, 'B', 'D')['amount'])->toBe(20 + 20 * 3.0);
    expect(fdPrice($trip, $stops, 'B', 'D')['fare_rule_id'])->toBe($current->fare_rule_id);
});

it('refuses a second fare rule that overlaps one in force for the same fleet and seat type', function () {
    [, , , $fleet] = fdFixture('outbound', ['effective_from' => '2026-10-01', 'effective_to' => '2026-12-31']);
    $service = app(FareRuleService::class);
    $payload = ['fleet_id' => $fleet->fleet_id, 'seat_type' => 'seated', 'base_fare' => 20, 'fare_per_km' => 3];

    expect(fn () => $service->createFareRule($payload + ['effective_from' => '2026-12-01']))->toThrow(ValidationException::class);
    expect(fn () => $service->createFareRule($payload))->toThrow(ValidationException::class); // open ended overlaps too

    // After the end date, or for another seat type, it is fine.
    expect($service->createFareRule($payload + ['effective_from' => '2027-01-01'])->fare_rule_id)->toBeInt();
    expect($service->createFareRule(array_merge($payload, ['seat_type' => 'standing']))->fare_rule_id)->toBeInt();
});

it('leaves tickets that were issued before the snapshot columns exist untouched', function () {
    [, $trip, $stops, , $fare] = fdFixture();
    $payment = \App\Models\Payment::create([
        'amount' => 55, 'payment_created' => now(), 'transaction_reference' => 'TXN-FD-'.Str::upper(Str::random(8)), 'payment_method' => 'online',
        'payment_channel' => 'gcash', 'status' => 'paid', 'payment_uuid' => (string) Str::uuid(), 'is_valid' => true, 'items_payload' => [],
    ]);
    $old = Ticket::create([
        'payment_id' => $payment->payment_id, 'fleet_route_id' => $trip->fleet_route_id, 'trip_id' => $trip->trip_id, 'ticket_uuid' => (string) Str::uuid(),
        'status' => 'valid', 'amount' => 55, 'distance_km' => 20, 'seat_type' => 'seated', 'fare_rule_id' => $fare->fare_rule_id,
        'origin_stop_id' => $stops['B']->stop_id, 'destination_stop_id' => $stops['D']->stop_id,
    ]);

    $fresh = $old->fresh();
    expect($fresh->boarding_distance_m)->toBeNull()->and($fresh->alighting_distance_m)->toBeNull();
    expect((float) $fresh->amount)->toBe(55.0);
});

// ---------------------------------------------------------------- Task 5: one definition of "occupied"

it('uses the same sold count for the sale block and the Chauffeur dashboard', function () {
    [$conductor, $trip, $stops, $fleet] = fdFixture();
    $fleet->update(['seated_capacity' => 2, 'standing_capacity' => 1, 'capacity' => 3]);
    $this->actingAs($conductor)->postJson('/api/conductor/shift/start')->assertSuccessful();
    $sell = fn (string $seat) => $this->actingAs($conductor)->postJson('/api/conductor/checkout', ['items' => [[
        'trip_id' => $trip->trip_id, 'seat_type' => $seat, 'origin_stop_id' => $stops['B']->stop_id, 'destination_stop_id' => $stops['D']->stop_id,
    ]]]);
    $summary = fn () => $this->actingAs($conductor)->getJson('/api/conductor/trips/current/occupancy')->assertSuccessful()->json('data.capacity_summary');

    $sell('seated')->assertSuccessful();
    $sell('seated')->assertSuccessful();
    $sell('seated')->assertStatus(422)->assertJsonValidationErrors(['capacity']); // the sale block says full for seated...

    $now = $summary();
    expect($now['definition'])->toBe('tickets_sold');
    expect($now['sold'])->toBe(['seated' => 2, 'standing' => 0, 'total' => 2]); // ...and the dashboard counts the same two
    expect($now['remaining'])->toBe(['seated' => 0, 'standing' => 1, 'total' => 1]);
    expect($now['is_full'])->toBeFalse(); // standing is still sellable, so the bus is not full

    $sell('standing')->assertSuccessful();
    expect($summary()['is_full'])->toBeTrue();
    $sell('standing')->assertStatus(422)->assertJsonValidationErrors(['capacity']);

    // Scanning a ticket onto the bus changes "onboard", never the sold count the block uses.
    expect($summary()['onboard']['total'])->toBe(0);
});