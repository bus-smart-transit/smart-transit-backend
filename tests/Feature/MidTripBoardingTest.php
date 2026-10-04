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
use App\Repositories\FleetLocationRepository;
use App\Repositories\RouteGeometryRepository;
use App\Repositories\TicketRepository;
use App\Services\TicketService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

uses(RefreshDatabase::class);

// Batch 27 Part 2, Task 6: a Chauffeur sells a cash ticket to someone boarding between stops.
// The server resolves the boarding point from the bus's own latest accepted position; the
// request carries only the destination. Weak GPS never produces a guessed fare.

function midStaff(string $role): User
{
    $user = User::create(['username' => 'mid'.Str::random(8), 'email' => Str::random(8).'@example.com', 'password' => bcrypt('password123'), 'role' => $role]);
    StaffUser::create(['user_id' => $user->user_id, 'company_user_uuid' => (string) Str::uuid(), 'name' => 'Mid '.ucfirst($role), 'phone_num' => '09170000028', 'address' => 'x']);

    return $user->fresh();
}

/**
 * Five-stop route, 10 km apart (stop C is pass-through). Fare 15 + 2.35 per km.
 * The trip is 'boarding' so the shift can start; tests then set it 'departed'.
 */
function midFixture(string $direction = 'outbound', string $service = 'regular', bool $dropoff = false): array
{
    $operator = midStaff('operator');
    $driver = midStaff('driver');
    $conductor = midStaff('conductor');

    $route = Route::create(['route_name' => 'Mid Route', 'origin' => 'A', 'destination' => 'E', 'allow_custom_dropoff' => $dropoff]);
    $defs = [
        ['Stop A', 7.00, 0, 'terminal'],
        ['Stop B', 7.09, 10, 'stop'],
        ['Stop C', 7.18, 20, 'pass_through'],
        ['Stop D', 7.27, 30, 'stop'],
        ['Stop E', 7.36, 40, 'terminal'],
    ];
    $stops = [];
    foreach ($defs as $i => [$name, $lat, $km, $type]) {
        $stop = Stop::create(['stop_name' => $name, 'latitude' => $lat, 'longitude' => 125.0, 'municipality' => 'Mati City']);
        RouteStop::create(['route_id' => $route->route_id, 'stop_id' => $stop->stop_id, 'stop_order' => $i + 1, 'distance_from_origin_km' => $km, 'stop_type' => $type, 'scheduled_offset_min' => $km]);
        $stops[$name] = $stop;
    }
    app(RouteGeometryRepository::class)->save($route->route_id, [
        'type' => 'LineString',
        'coordinates' => collect($defs)->map(fn ($d) => [125.0, $d[1]])->all(),
    ]);

    $fleet = Fleet::create([
        'company_user_id' => $operator->companyProfile->company_user_id,
        'plate_number' => 'MID-'.fake()->unique()->numerify('####'),
        'capacity' => 40, 'seated_capacity' => 30, 'standing_capacity' => 10, 'status' => 'active',
    ]);
    FareRule::create(['fleet_id' => $fleet->fleet_id, 'base_fare' => 15, 'fare_per_km' => 2.35, 'status' => 'active', 'seat_type' => 'seated']);
    $fleetRoute = FleetRoute::create(['fleet_id' => $fleet->fleet_id, 'route_id' => $route->route_id, 'start_time' => '06:00', 'end_time' => '22:00', 'status' => 'active']);

    $trip = Trip::create([
        'fleet_route_id' => $fleetRoute->fleet_route_id,
        'company_user_id' => $operator->companyProfile->company_user_id,
        'driver_id' => $driver->companyProfile->company_user_id,
        'conductor_id' => $conductor->companyProfile->company_user_id,
        'trip_date' => now('Asia/Manila')->toDateString(),
        'departure_time' => '08:00',
        'trip_type' => 'one_way',
        'leg_direction' => $direction,
        'service_type' => $service,
        'status' => 'boarding',
        'current_seated_capacity' => 0, 'current_standing_capacity' => 0, 'total_occupancy' => 0,
    ]);
    FleetDailyPin::create([
        'trip_id' => $trip->trip_id, 'driver_id' => $driver->companyProfile->company_user_id, 'conductor_id' => $conductor->companyProfile->company_user_id,
        'pin_code' => '123456', 'pin_date' => now('Asia/Manila')->toDateString(), 'driver_verified_at' => now(), 'conductor_verified_at' => now(), 'paired_at' => now(),
    ]);

    return [$conductor, $trip, $stops, $fleet, $route];
}

function midFix(Fleet $fleet, Trip $trip, float $lat, float $lng = 125.0, ?float $accuracy = 8.0): void
{
    app(FleetLocationRepository::class)->upsertLocation([
        'fleet_id' => $fleet->fleet_id, 'trip_id' => $trip->trip_id, 'latitude' => $lat, 'longitude' => $lng, 'accuracy' => $accuracy,
    ]);
}

/** Starts the shift while boarding, then puts the bus on the road. */
function midOnTheRoad($test, User $conductor, Trip $trip): void
{
    $test->actingAs($conductor)->postJson('/api/conductor/shift/start')->assertSuccessful();
    $trip->update(['status' => 'departed']);
}

function midSell($test, User $conductor, Trip $trip, array $item)
{
    return $test->actingAs($conductor)->postJson('/api/conductor/checkout', ['items' => [array_merge(['trip_id' => $trip->trip_id, 'seat_type' => 'seated'], $item)]]);
}

beforeEach(fn () => Carbon::setTestNow(Carbon::parse('2026-10-04 09:00:00', 'Asia/Manila')));
afterEach(fn () => Carbon::setTestNow());

it('resolves the boarding point from the bus position and offers only the stops ahead', function () {
    [$conductor, $trip, $stops, $fleet] = midFixture();
    midOnTheRoad($this, $conductor, $trip);
    midFix($fleet, $trip, 7.135, 125.0003); // about 15 km along, a few metres off the line

    $data = $this->actingAs($conductor)->getJson('/api/conductor/trips/current/boarding-point')->assertSuccessful()->json('data');

    expect($data['state'])->toBe('good');
    expect($data['fallback'])->toBeNull();
    expect($data['boarding_point']['distance_from_origin_km'])->toEqualWithDelta(15.0, 0.1);
    expect($data['boarding_point']['longitude'])->toEqualWithDelta(125.0, 0.00001); // snapped onto the line
    // Pass-through Stop C is never offered; Stops A and B are behind the bus.
    expect(array_column($data['destinations'], 'stop_name'))->toBe(['Stop D', 'Stop E']);
    expect($data['destinations'][0]['distance_km'])->toEqualWithDelta(15.0, 0.1);
    $this->assertDatabaseHas('audit_logs', ['action' => 'boarding_point.resolved', 'subject_id' => $trip->trip_id]);
});

it('sells a ticket priced from the snapped boarding point and stores the snapshot', function () {
    [$conductor, $trip, $stops, $fleet] = midFixture();
    midOnTheRoad($this, $conductor, $trip);
    midFix($fleet, $trip, 7.135);

    $response = midSell($this, $conductor, $trip, ['boarding_mode' => 'gps', 'destination_stop_id' => $stops['Stop D']->stop_id])->assertSuccessful();

    $ticket = Ticket::find($response->json('data.tickets.0.ticket_id'));
    expect($ticket->origin_stop_id)->toBeNull();
    expect($ticket->destination_stop_id)->toBe($stops['Stop D']->stop_id);
    expect((float) $ticket->origin_lat)->toEqualWithDelta(7.135, 0.0001);
    expect((float) $ticket->distance_km)->toEqualWithDelta(15.0, 0.1);
    expect((float) $ticket->amount)->toEqualWithDelta(15 + 15 * 2.35, 0.51);
    expect($ticket->boarding_distance_m)->toBeBetween(14900, 15100);
    expect($ticket->alighting_distance_m)->toBe(30000);
    expect($ticket->fare_rule_id)->not->toBeNull();
});

it('ignores coordinates and fares sent by the client', function () {
    [$conductor, $trip, $stops, $fleet] = midFixture();
    midOnTheRoad($this, $conductor, $trip);
    midFix($fleet, $trip, 7.135);

    $response = midSell($this, $conductor, $trip, [
        'boarding_mode' => 'gps', 'destination_stop_id' => $stops['Stop D']->stop_id,
        // A tampered client claims it boarded at Stop D's door, pays a tiny fare and names another origin.
        'origin_lat' => 7.269, 'origin_lng' => 125.0, 'latitude' => 7.269, 'longitude' => 125.0, 'amount' => 1, 'distance_km' => 0.1, 'origin_stop_id' => $stops['Stop D']->stop_id,
    ])->assertSuccessful();

    $ticket = Ticket::find($response->json('data.tickets.0.ticket_id'));
    expect($ticket->origin_stop_id)->toBeNull();
    expect((float) $ticket->origin_lat)->toEqualWithDelta(7.135, 0.0001);
    expect((float) $ticket->amount)->toBeGreaterThan(40);
});

it('falls back to the boarding stop picker when the position is poor, old, missing or off the route', function () {
    [$conductor, $trip, $stops, $fleet] = midFixture();
    midOnTheRoad($this, $conductor, $trip);
    $state = fn () => $this->actingAs($conductor)->getJson('/api/conductor/trips/current/boarding-point')->assertSuccessful()->json('data');
    $sellByGps = fn () => midSell($this, $conductor, $trip, ['boarding_mode' => 'gps', 'destination_stop_id' => $stops['Stop D']->stop_id]);

    // No fix at all.
    expect($state())->toMatchArray(['state' => 'none', 'fallback' => 'manual_boarding_stop', 'boarding_point' => null, 'destinations' => []]);
    $sellByGps()->assertStatus(422)->assertJsonValidationErrors(['boarding']);

    // Poor accuracy (the upload accepts it, the sale does not).
    midFix($fleet, $trip, 7.135, 125.0, 80);
    expect($state()['state'])->toBe('poor_accuracy');
    $sellByGps()->assertStatus(422)->assertJsonValidationErrors(['boarding']);

    // Off the route.
    midFix($fleet, $trip, 7.135, 125.02, 8);
    expect($state()['state'])->toBe('off_route');
    $sellByGps()->assertStatus(422)->assertJsonValidationErrors(['boarding']);

    // Old.
    midFix($fleet, $trip, 7.135, 125.0, 8);
    expect($state()['state'])->toBe('good');
    Carbon::setTestNow(Carbon::now()->addSeconds((int) config('booking.boarding_gps.max_age_seconds') + 30));
    expect($state()['state'])->toBe('stale');
    $sellByGps()->assertStatus(422)->assertJsonValidationErrors(['boarding']);

    expect(Ticket::where('trip_id', $trip->trip_id)->count())->toBe(0);
    expect($trip->fresh()->total_occupancy)->toBe(0);

    // The manual path still sells, priced from the stop the Chauffeur picked.
    $manual = midSell($this, $conductor, $trip, ['origin_stop_id' => $stops['Stop B']->stop_id, 'destination_stop_id' => $stops['Stop E']->stop_id])->assertSuccessful();
    expect((float) $manual->json('data.tickets.0.amount'))->toEqualWithDelta(15 + 30 * 2.35, 0.51);
});

it('measures along the reverse leg and offers the stops ahead in that direction', function () {
    [$conductor, $trip, $stops, $fleet] = midFixture('reverse');
    midOnTheRoad($this, $conductor, $trip);
    midFix($fleet, $trip, 7.135);

    $data = $this->actingAs($conductor)->getJson('/api/conductor/trips/current/boarding-point')->assertSuccessful()->json('data');
    expect($data['state'])->toBe('good');
    expect($data['leg_direction'])->toBe('reverse');
    // Heading from E towards A: B is 5 km ahead, A is 15 km ahead; D is behind.
    expect(array_column($data['destinations'], 'stop_name'))->toBe(['Stop B', 'Stop A']);
    expect($data['destinations'][0]['distance_km'])->toEqualWithDelta(5.0, 0.1);

    $ok = midSell($this, $conductor, $trip, ['boarding_mode' => 'gps', 'destination_stop_id' => $stops['Stop B']->stop_id])->assertSuccessful();
    expect((float) $ok->json('data.tickets.0.amount'))->toEqualWithDelta(15 + 5 * 2.35, 0.51);

    // A destination behind the bus for this direction is a 422.
    midSell($this, $conductor, $trip, ['boarding_mode' => 'gps', 'destination_stop_id' => $stops['Stop D']->stop_id])
        ->assertStatus(422)->assertJsonValidationErrors(['destination']);
    // Pass-through stops are never a destination.
    midSell($this, $conductor, $trip, ['boarding_mode' => 'gps', 'destination_stop_id' => $stops['Stop C']->stop_id])
        ->assertStatus(422)->assertJsonValidationErrors(['destination']);
});

it('sells nothing on an express trip until the fare exists', function () {
    [$conductor, $trip, $stops, $fleet] = midFixture('outbound', 'express');
    midOnTheRoad($this, $conductor, $trip);
    midFix($fleet, $trip, 7.135);

    midSell($this, $conductor, $trip, ['boarding_mode' => 'gps', 'destination_stop_id' => $stops['Stop E']->stop_id])->assertStatus(422);
    expect(Ticket::where('trip_id', $trip->trip_id)->count())->toBe(0);
});

it('lets only the cash flow sell on a moving bus, and only with a paired Chauffeur on a started shift', function () {
    [$conductor, $trip, $stops, $fleet] = midFixture();
    midOnTheRoad($this, $conductor, $trip);
    midFix($fleet, $trip, 7.135);

    // The passenger flow (no onsite channel) still refuses a trip that has left.
    expect(fn () => app(TicketService::class)->reserveAndPrice([
        'trip_id' => $trip->trip_id, 'seat_type' => 'seated', 'origin_stop_id' => $stops['Stop B']->stop_id, 'destination_stop_id' => $stops['Stop E']->stop_id,
    ]))->toThrow(ValidationException::class);
    // A client cannot switch the channel or the mode on in a passenger checkout.
    expect(fn () => app(TicketService::class)->reserveAndPrice([
        'trip_id' => $trip->trip_id, 'seat_type' => 'seated', 'boarding_mode' => 'gps', 'destination_stop_id' => $stops['Stop E']->stop_id,
    ]))->toThrow(ValidationException::class);

    // A passenger cannot use the endpoint.
    $passenger = User::create(['username' => 'p'.Str::random(6), 'email' => Str::random(8).'@example.com', 'password' => bcrypt('password123'), 'role' => 'passenger']);
    $this->actingAs($passenger)->getJson('/api/conductor/trips/current/boarding-point')->assertForbidden();

    // A different Chauffeur, with no shift on this trip, gets nothing about it.
    $other = midStaff('conductor');
    $this->actingAs($other)->getJson('/api/conductor/trips/current/boarding-point')->assertNotFound();
});

it('refuses the endpoint without a token', function () {
    $this->getJson('/api/conductor/trips/current/boarding-point')->assertUnauthorized();
});

it('refuses the endpoint while the trip has not started boarding', function () {
    [$conductor, $trip] = midFixture();
    $this->actingAs($conductor)->postJson('/api/conductor/shift/start')->assertSuccessful();
    $trip->update(['status' => 'scheduled']);

    $this->actingAs($conductor)->getJson('/api/conductor/trips/current/boarding-point')->assertStatus(422);
});

it('sells a custom drop-off from the bus position and lists the requested stop', function () {
    [$conductor, $trip, $stops, $fleet] = midFixture(dropoff: true);
    midOnTheRoad($this, $conductor, $trip);
    midFix($fleet, $trip, 7.135);

    $data = $this->actingAs($conductor)->getJson('/api/conductor/trips/current/boarding-point')->assertSuccessful()->json('data');
    expect($data['custom_dropoff'])->toBe(['available' => true, 'reason' => null]);

    $response = midSell($this, $conductor, $trip, [
        'boarding_mode' => 'gps', 'destination_lat' => 7.22, 'destination_lng' => 125.0003, 'destination_label' => 'Near the chapel',
    ])->assertSuccessful();

    $ticket = Ticket::find($response->json('data.tickets.0.ticket_id'));
    expect($ticket->origin_stop_id)->toBeNull()->and($ticket->destination_stop_id)->toBeNull();
    expect($ticket->destination_label)->toBe('Near the chapel');
    expect(abs((float) $ticket->destination_lng - 125.0))->toBeLessThan(0.00001); // snapped onto the line
    expect((float) $ticket->distance_km)->toEqualWithDelta(9.45, 0.4); // 7.135 -> 7.22
    // The Driver's route details and the manifest are built from the ticket's drop-off point.
    expect(app(TicketRepository::class)->getRequestedStopsForTrip($trip->trip_id))->toHaveCount(1);

    // Behind the bus is refused by the same rules as everywhere else.
    midSell($this, $conductor, $trip, ['boarding_mode' => 'gps', 'destination_lat' => 7.05, 'destination_lng' => 125.0])
        ->assertStatus(422)->assertJsonValidationErrors(['dropoff']);
});


// Task 7: custom drop-off in the Chauffeur's cash flow (stop boarding + pin), and the geocoder gate.

it('sells a cash ticket from a boarding stop to a custom drop-off pin, priced and snapped on the server', function () {
    [$conductor, $trip, $stops, $fleet] = midFixture(dropoff: true);
    $this->actingAs($conductor)->postJson('/api/conductor/shift/start')->assertSuccessful();

    $response = midSell($this, $conductor, $trip, [
        'origin_stop_id' => $stops['Stop B']->stop_id, 'destination_lat' => 7.22, 'destination_lng' => 125.0003,
        'destination_label' => 'Near the chapel', 'amount' => 1,
    ])->assertSuccessful();

    $ticket = Ticket::find($response->json('data.tickets.0.ticket_id'));
    expect($ticket->origin_stop_id)->toBe($stops['Stop B']->stop_id);
    expect(abs((float) $ticket->destination_lng - 125.0))->toBeLessThan(0.00001);
    expect((float) $ticket->distance_km)->toEqualWithDelta(14.45, 0.4); // Stop B 7.09 -> 7.22
    expect((float) $ticket->amount)->toBeGreaterThan(40); // not the tampered 1
    expect($ticket->boarding_distance_m)->toBe(10000);
    expect(app(TicketRepository::class)->getRequestedStopsForTrip($trip->trip_id))->toHaveCount(1);

    // A pin on a route that does not offer it is refused.
    [$c2, $t2, $s2] = midFixture(dropoff: false);
    $this->actingAs($c2)->postJson('/api/conductor/shift/start')->assertSuccessful();
    midSell($this, $c2, $t2, ['origin_stop_id' => $s2['Stop B']->stop_id, 'destination_lat' => 7.22, 'destination_lng' => 125.0003])
        ->assertStatus(422)->assertJsonValidationErrors(['dropoff']);
});

it('refuses a custom drop-off when the route has no generated path', function () {
    [, $trip, $stops, , $route] = midFixture(dropoff: true);
    \Illuminate\Support\Facades\DB::table('routes')->where('route_id', $route->route_id)->update(['route_geometry' => null]);

    expect(fn () => app(\App\Services\CustomDropoffService::class)->evaluate($route->route_id, 'outbound', $stops['Stop B']->stop_id, 7.22, 125.0))
        ->toThrow(ValidationException::class, 'no generated path');
});

it('refuses a custom drop-off whose boarding stop is not on the trip\'s route', function () {
    [, , , , $route] = midFixture(dropoff: true);
    $foreign = Stop::create(['stop_name' => 'Elsewhere', 'latitude' => 7.5, 'longitude' => 125.2, 'municipality' => 'Mati City']);

    expect(fn () => app(\App\Services\CustomDropoffService::class)->evaluate($route->route_id, 'outbound', $foreign->stop_id, 7.22, 125.0))
        ->toThrow(ValidationException::class, 'boarding stop is not on this trip');
});

it('limits address search to the configured country and spaces geocoder calls across everyone', function () {
    Carbon::setTestNow(); // lock waits use the real clock
    \Illuminate\Support\Facades\Http::fake(['nominatim.openstreetmap.org/*' => \Illuminate\Support\Facades\Http::response([])]);
    config(['timeline.address_search.min_interval_ms' => 400, 'timeline.address_search.max_wait_ms' => 3000]);

    $started = microtime(true);
    foreach (['first place', 'second place', 'third place'] as $q) {
        test()->getJson('/api/booking/address-search?q='.urlencode($q))->assertSuccessful();
    }
    $elapsed = microtime(true) - $started;

    // Three different users' misses cannot be closer than 0.4 s apart: at least two gaps.
    expect($elapsed)->toBeGreaterThanOrEqual(0.75);
    \Illuminate\Support\Facades\Http::assertSent(fn ($request) => str_contains($request->url(), 'countrycodes=ph'));
});

it('reports the address search as unavailable when the geocoder queue is too long to wait for', function () {
    Carbon::setTestNow(); // lock waits use the real clock
    \Illuminate\Support\Facades\Http::fake(['nominatim.openstreetmap.org/*' => \Illuminate\Support\Facades\Http::response([])]);
    config(['timeline.address_search.min_interval_ms' => 5000, 'timeline.address_search.max_wait_ms' => 1000]);

    // The first caller takes the turn; the lock-wait is bounded, so a flooded gate answers 503 instead of hanging.
    $lock = \Illuminate\Support\Facades\Cache::lock('nominatim:gate', 10);
    expect($lock->get())->toBeTrue();
    test()->getJson('/api/booking/address-search?q='.urlencode('blocked query'))->assertStatus(503);
    $lock->release();
    \Illuminate\Support\Facades\Http::assertNothingSent();
});


// Batch 28 Stage 2: the cash flow follows the fare key, and still refuses finished trips.

it('prices a bus-position sale from the class rule and ignores a tampered fare', function () {
    [$conductor, $trip, $stops, $fleet] = midFixture();
    FareRule::where('fleet_id', $fleet->fleet_id)->delete(); // no override: the fleet's class decides
    $fleet->update(['vehicle_class' => 'aircon']);
    FareRule::create(['fleet_id' => null, 'vehicle_class' => 'aircon', 'service_type' => 'regular', 'seat_type' => 'seated', 'status' => 'active', 'base_fare' => 13, 'fare_per_km' => 2.25, 'base_distance_km' => 5, 'note' => 'SAMPLE']);
    midOnTheRoad($this, $conductor, $trip);
    midFix($fleet, $trip, 7.135);

    $response = midSell($this, $conductor, $trip, ['boarding_mode' => 'gps', 'destination_stop_id' => $stops['Stop D']->stop_id, 'amount' => 1, 'fare_rule_id' => 999])->assertSuccessful();
    $ticket = Ticket::find($response->json('data.tickets.0.ticket_id'));
    expect((float) $ticket->amount)->toEqualWithDelta(13 + (15 - 5) * 2.25, 0.51); // 15 km from the bus position, 5 km base
    expect($ticket->rounding_mode)->toBe('nearest')->and($ticket->fare_rule_id)->not->toBe(999);

    // The same bus without a class and without an override: nothing is sold.
    $fleet->update(['vehicle_class' => null]);
    midSell($this, $conductor, $trip, ['boarding_mode' => 'gps', 'destination_stop_id' => $stops['Stop E']->stop_id])->assertStatus(422)->assertJsonValidationErrors(['trip']);
});

it('refuses a cash sale on a completed or cancelled trip', function () {
    foreach (['completed', 'cancelled'] as $status) {
        [$conductor, $trip, $stops] = midFixture();
        $this->actingAs($conductor)->postJson('/api/conductor/shift/start')->assertSuccessful();
        $trip->update(['status' => $status]);

        $response = midSell($this, $conductor, $trip, ['origin_stop_id' => $stops['Stop A']->stop_id, 'destination_stop_id' => $stops['Stop B']->stop_id]);
        expect($response->status())->toBeIn([404, 422]);
        expect(Ticket::where('trip_id', $trip->trip_id)->count())->toBe(0);

        // And the service itself, whoever calls it, refuses a finished trip even on the cash channel.
        expect(fn () => app(TicketService::class)->reserveAndPrice([
            'trip_id' => $trip->trip_id, 'seat_type' => 'seated', 'channel' => 'onsite',
            'origin_stop_id' => $stops['Stop A']->stop_id, 'destination_stop_id' => $stops['Stop B']->stop_id,
        ]))->toThrow(ValidationException::class, 'not accepting tickets');
    }
});


// Batch 28 Stage 2: every reader of tickets.origin_stop_id copes with a boarding point that is a snapped position.

it('shows a boarding between stops everywhere a ticket is read, and counts it at the nearest preceding stop', function () {
    [$conductor, $trip, $stops, $fleet] = midFixture();
    midOnTheRoad($this, $conductor, $trip);
    midFix($fleet, $trip, 7.135); // about 15 km along: after Stop B (10 km), before Stop D (30 km)
    $sold = midSell($this, $conductor, $trip, ['boarding_mode' => 'gps', 'destination_stop_id' => $stops['Stop D']->stop_id])->assertSuccessful();
    $ticket = Ticket::find($sold->json('data.tickets.0.ticket_id'));
    $ticket->update(['status' => 'boarded', 'boarded_at' => now()]);
    expect($ticket->origin_stop_id)->toBeNull();
    $label = \App\Support\TicketBoarding::BETWEEN_STOPS;

    // Manifest (Chauffeur's passenger list): a label and a flag, never a blank or a stop id of 0.
    $row = collect($this->actingAs($conductor)->getJson('/api/conductor/trips/current/passengers')->assertSuccessful()->json('data.passengers') ?? $this->actingAs($conductor)->getJson('/api/conductor/trips/current/passengers')->json('data'))->first();
    expect($row['origin_stop'])->toBe($label)->and($row['origin_between_stops'])->toBeTrue();

    // Passengers on the bus by stop: the boarding counts at the nearest preceding stop (Stop B), so the running total adds up.
    $byStop = collect($this->actingAs($conductor)->getJson('/api/conductor/trips/current/occupancy/by-stop')->assertSuccessful()->json('data.stops'))->keyBy('stop_name');
    expect($byStop['Stop B']['boarding_count'])->toBe(1)->and($byStop['Stop A']['boarding_count'])->toBe(0);
    expect($byStop['Stop C']['passengers_on_bus_after'])->toBe(1)->and($byStop['Stop D']['alighting_count'])->toBe(1); // its drop-off stop, counted as the other tickets are

    // Saved QR image and the ticket's own payload.
    $qr = app(\App\Services\QRCodeService::class)->generateQRData($ticket->fresh()->load(['payment', 'trip.fleetRoute.fleet', 'passenger']));
    expect($qr['origin'])->toBe($label)->and($qr['destination'])->toBe('Stop D');

    // Public tracking summary of the ticket: the boarding row says it, with no ETA invented for a point that is not a stop.
    $view = app(\App\Services\TicketTrackingService::class)->viewFor($ticket->fresh());
    expect($view['boarding']['name'] ?? null)->toBe($label);
});

it('defaults a return trip to the nearest preceding stop when the outbound boarding was a snapped point', function () {
    [$conductor, $trip, $stops, $fleet] = midFixture();
    midOnTheRoad($this, $conductor, $trip);
    midFix($fleet, $trip, 7.135);
    $ticket = Ticket::find(midSell($this, $conductor, $trip, ['boarding_mode' => 'gps', 'destination_stop_id' => $stops['Stop D']->stop_id])->json('data.tickets.0.ticket_id'));

    // The same stop-array helper the ticket payload uses: Stop B is the last stop passed before 15 km on an outbound leg.
    $routeStops = app(\App\Repositories\RouteStopRepository::class)->getOrderedStops((int) $trip->fleetRoute->route_id);
    expect(\App\Support\TicketBoarding::precedingStopId($routeStops, $ticket->boarding_distance_m, 'outbound'))->toBe($stops['Stop B']->stop_id);
    // On a reverse leg the stop passed before the same point is the one further along the route (Stop D).
    expect(\App\Support\TicketBoarding::precedingStopId($routeStops, $ticket->boarding_distance_m, 'reverse'))->toBe($stops['Stop D']->stop_id);
    expect(\App\Support\TicketBoarding::precedingStopId($routeStops, null, 'outbound'))->toBeNull();
});