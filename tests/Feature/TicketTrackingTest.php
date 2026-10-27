<?php

use App\Models\FareRule;
use App\Models\Fleet;
use App\Models\FleetRoute;
use App\Models\PassengerUser;
use App\Models\Payment;
use App\Models\Route;
use App\Models\RouteStop;
use App\Models\StaffUser;
use App\Models\Stop;
use App\Models\Ticket;
use App\Models\Trip;
use App\Models\User;
use App\Repositories\FleetLocationRepository;
use App\Repositories\RouteGeometryRepository;
use App\Services\TicketService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

// Self-contained fixture: a five-stop route with geometry, one fleet, one trip today at 08:00.
function trkFixture(string $status = 'scheduled'): array
{
    $user = User::create(['username' => 'op'.Str::random(6), 'email' => Str::random(8).'@example.com', 'password' => bcrypt('password123'), 'role' => 'operator']);
    StaffUser::create(['user_id' => $user->user_id, 'company_user_uuid' => (string) Str::uuid(), 'name' => 'Op', 'phone_num' => '0917', 'address' => 'x']);
    $operator = $user->fresh();

    $route = Route::create(['route_name' => 'Tracking Route', 'origin' => 'A', 'destination' => 'E']);
    $defs = [
        ['Stop A', 7.00, 125.0, 0, 0, 'terminal'],
        ['Stop B', 7.09, 125.0, 10, 15, 'stop'],
        ['Stop C', 7.18, 125.0, 20, 30, 'pass_through'],
        ['Stop D', 7.27, 125.0, 30, 45, 'stop'],
        ['Stop E', 7.36, 125.0, 40, 60, 'terminal'],
    ];
    $stops = [];
    foreach ($defs as $i => [$name, $lat, $lng, $km, $offset, $type]) {
        $stop = Stop::create(['stop_name' => $name, 'latitude' => $lat, 'longitude' => $lng, 'municipality' => 'Mati City']);
        RouteStop::create(['route_id' => $route->route_id, 'stop_id' => $stop->stop_id, 'stop_order' => $i + 1, 'distance_from_origin_km' => $km, 'stop_type' => $type, 'scheduled_offset_min' => $offset]);
        $stops[$name] = $stop;
    }
    app(RouteGeometryRepository::class)->save($route->route_id, [
        'type' => 'LineString',
        'coordinates' => collect($defs)->map(fn ($d) => [$d[2], $d[1]])->all(),
    ]);

    $fleet = Fleet::create([
        'company_user_id' => $operator->companyProfile->company_user_id,
        'plate_number' => 'TRK-'.fake()->unique()->numerify('####'),
        'capacity' => 40, 'seated_capacity' => 30, 'standing_capacity' => 10, 'status' => 'active',
    ]);
    $fare = FareRule::create(['fleet_id' => $fleet->fleet_id, 'base_fare' => 15, 'fare_per_km' => 2.35, 'status' => 'active', 'seat_type' => 'seated']);
    $fleetRoute = FleetRoute::create(['fleet_id' => $fleet->fleet_id, 'route_id' => $route->route_id, 'start_time' => '06:00', 'end_time' => '22:00', 'status' => 'active']);
    $trip = Trip::create([
        'fleet_route_id' => $fleetRoute->fleet_route_id,
        'company_user_id' => $operator->companyProfile->company_user_id,
        'trip_date' => Carbon::now('Asia/Manila')->toDateString(),
        'departure_time' => '08:00',
        'trip_type' => 'one_way',
        'leg_direction' => 'outbound',
        'status' => $status,
        'current_seated_capacity' => 0, 'current_standing_capacity' => 0, 'total_occupancy' => 0,
    ]);

    return [$trip, $stops, $fleet, $fare, $fleetRoute];
}

function trkPayment(?string $email = null): Payment
{
    return Payment::create([
        'amount' => 60, 'payment_created' => now(), 'transaction_reference' => 'TXN-TRK-'.strtoupper(Str::random(8)),
        'payment_method' => 'online', 'payment_channel' => 'gcash', 'status' => 'paid', 'payment_uuid' => (string) Str::uuid(),
        'is_valid' => true, 'items_payload' => [], 'guest_email' => $email,
    ]);
}

/** A ticket from Stop B to Stop D, issued the way checkout issues one (with a tracking token). */
function trkTicket($trip, $stops, $fare, ?Payment $payment = null, ?int $passengerId = null): Ticket
{
    return app(TicketService::class)->finalizeTicket([
        'fleet_route_id' => $trip->fleet_route_id, 'trip_id' => $trip->trip_id, 'fare_rule_id' => $fare->fare_rule_id,
        'distance_km' => 20, 'amount' => 60, 'seat_type' => 'seated',
        'origin_stop_id' => $stops['Stop B']->stop_id, 'destination_stop_id' => $stops['Stop D']->stop_id,
    ], $passengerId, ($payment ?? trkPayment())->payment_id);
}

function trkPassenger(): PassengerUser
{
    $user = User::create(['username' => 'pax'.Str::random(6), 'email' => Str::random(8).'@example.com', 'password' => bcrypt('password123'), 'role' => 'passenger']);

    return PassengerUser::create(['passenger_uuid' => (string) Str::uuid(), 'user_id' => $user->user_id, 'name' => 'Pax', 'phone_num' => '09170000001', 'address' => 'x', 'birthdate' => '2000-01-01']);
}

/** The plain token of a freshly issued ticket (what the owner is shown). */
function trkToken(Ticket $ticket): string
{
    return $ticket->tracking_token_enc;
}

beforeEach(function () {
    // Sunday 2026-10-04, 07:30 in Manila: the 08:00 trip opens for tracking at 07:00.
    Carbon::setTestNow(Carbon::parse('2026-10-04 07:30:00', 'Asia/Manila'));
});

afterEach(fn () => Carbon::setTestNow());

it('gives every new ticket an unguessable token, stores only a hash and ciphertext, and never serialises them', function () {
    [$trip, $stops, , $fare] = trkFixture();
    $ticket = trkTicket($trip, $stops, $fare);
    $token = trkToken($ticket);

    expect($token)->toMatch('/^[A-Za-z0-9_-]{43}$/'); // 256 bits of randomness
    $row = DB::table('tickets')->where('ticket_id', $ticket->ticket_id)->first();
    expect($row->tracking_token_hash)->toBe(hash('sha256', $token));
    expect($row->tracking_token_enc)->not->toContain($token); // encrypted at rest
    expect($ticket->fresh()->toArray())->not->toHaveKeys(['tracking_token_hash', 'tracking_token_enc', 'tracking_token']);
    expect(trkToken(trkTicket($trip, $stops, $fare)))->not->toBe($token);
});

it('shows the link to the owner only: a guest with the reference and email, or the signed-in passenger', function () {
    [$trip, $stops, , $fare] = trkFixture();
    $payment = trkPayment('guest@example.com');
    $guestTicket = trkTicket($trip, $stops, $fare, $payment);

    $this->getJson('/api/tickets/lookup?'.http_build_query(['transaction_reference' => $payment->transaction_reference, 'email' => 'guest@example.com']))
        ->assertSuccessful()->assertJsonPath('data.0.tracking_token', trkToken($guestTicket));
    // Without the email, a payment made with one stays closed.
    $this->getJson('/api/tickets/lookup?'.http_build_query(['transaction_reference' => $payment->transaction_reference]))->assertNotFound();

    $mine = trkPassenger();
    $other = trkPassenger();
    $myTicket = trkTicket($trip, $stops, $fare, null, $mine->passenger_id);
    trkTicket($trip, $stops, $fare, null, $other->passenger_id);

    $list = $this->actingAs($mine->user)->getJson('/api/passengers/tickets')->assertSuccessful()->json('data.data');
    expect($list)->toHaveCount(1);
    expect($list[0]['tracking_token'])->toBe(trkToken($myTicket));
});

it('gives an older ticket its link the first time its owner opens it, once, and none to old history', function () {
    [$trip, $stops, , $fare] = trkFixture();
    $payment = trkPayment('guest@example.com');
    $old = Ticket::create([
        'payment_id' => $payment->payment_id, 'fleet_route_id' => $trip->fleet_route_id, 'trip_id' => $trip->trip_id, 'ticket_uuid' => (string) Str::uuid(),
        'status' => 'valid', 'amount' => 60, 'distance_km' => 20, 'seat_type' => 'seated',
        'origin_stop_id' => $stops['Stop B']->stop_id, 'destination_stop_id' => $stops['Stop D']->stop_id,
    ]);
    expect($old->fresh()->tracking_token_hash)->toBeNull();
    $q = '/api/tickets/lookup?'.http_build_query(['transaction_reference' => $payment->transaction_reference, 'email' => 'guest@example.com']);

    $first = $this->getJson($q)->json('data.0.tracking_token');
    $second = $this->getJson($q)->json('data.0.tracking_token');
    expect($first)->toMatch('/^[A-Za-z0-9_-]{43}$/')->and($second)->toBe($first);
    $this->getJson('/api/track/'.$first)->assertSuccessful();

    // A trip from last week is history, not something to follow.
    $trip->update(['trip_date' => '2026-09-20']);
    $historic = trkPayment('guest@example.com');
    Ticket::create(['payment_id' => $historic->payment_id, 'fleet_route_id' => $trip->fleet_route_id, 'trip_id' => $trip->trip_id, 'ticket_uuid' => (string) Str::uuid(), 'status' => 'valid', 'amount' => 60, 'distance_km' => 20, 'seat_type' => 'seated']);
    $this->getJson('/api/tickets/lookup?'.http_build_query(['transaction_reference' => $historic->transaction_reference, 'email' => 'guest@example.com']))
        ->assertSuccessful()->assertJsonPath('data.0.tracking_token', null);
});

it('answers with the bus status and the ETA at the ticket\'s own stops, and nothing about the bus or crew', function () {
    [$trip, $stops, $fleet, $fare] = trkFixture();
    $token = trkToken(trkTicket($trip, $stops, $fare));

    $response = $this->getJson('/api/track/'.$token)->assertSuccessful();
    $data = $response->json('data');

    expect($data['state'])->toBe('waiting');
    expect($data['trip'])->toMatchArray(['trip_date' => '2026-10-04', 'departure_time' => '08:00', 'status' => 'scheduled', 'route_name' => 'Tracking Route']);
    expect($data['boarding'])->toMatchArray(['name' => 'Stop B', 'eta_time' => '08:15', 'eta_source' => 'scheduled']);
    expect($data['alighting'])->toMatchArray(['name' => 'Stop D', 'eta_time' => '08:45']);
    expect($data['route'])->toBe(['route_id' => $trip->fleetRoute->route_id, 'direction' => 'outbound']);
    expect($data['bus'])->toBeNull();
    expect($data['refresh']['poll_seconds'])->toBe((int) config('tracking.poll_seconds'));

    $body = $response->getContent();
    foreach ([$fleet->plate_number, 'plate_number', 'fleet_id', 'driver', 'conductor', 'passenger', 'ticket_uuid', 'amount'] as $private) {
        expect($body)->not->toContain($private);
    }
    expect($response->headers->get('Cache-Control'))->toContain('no-store');
    expect($response->headers->get('Referrer-Policy'))->toBe('no-referrer');
});

it('shows the live position of this trip\'s bus on the route, then marks it stale, and ignores another trip\'s fix', function () {
    [$trip, $stops, $fleet, $fare, $fleetRoute] = trkFixture('departed');
    $token = trkToken(trkTicket($trip, $stops, $fare));

    $this->getJson('/api/track/'.$token)->assertJsonPath('data.state', 'no_signal')->assertJsonPath('data.bus', null);

    // A fix reported for a different trip must not be shown.
    $otherTrip = Trip::create(['fleet_route_id' => $fleetRoute->fleet_route_id, 'company_user_id' => $trip->company_user_id, 'trip_date' => '2026-10-04', 'departure_time' => '12:00', 'trip_type' => 'one_way', 'leg_direction' => 'outbound', 'status' => 'scheduled', 'current_seated_capacity' => 0, 'current_standing_capacity' => 0, 'total_occupancy' => 0]);
    app(FleetLocationRepository::class)->upsertLocation(['fleet_id' => $fleet->fleet_id, 'trip_id' => $otherTrip->trip_id, 'latitude' => 7.05, 'longitude' => 125.0]);
    $this->getJson('/api/track/'.$token)->assertJsonPath('data.state', 'no_signal');

    // A fresh fix a little off the line is projected onto it.
    app(FleetLocationRepository::class)->upsertLocation(['fleet_id' => $fleet->fleet_id, 'trip_id' => $trip->trip_id, 'latitude' => 7.10, 'longitude' => 125.0004, 'heading' => 0]);
    $live = $this->getJson('/api/track/'.$token)->assertSuccessful()->json('data');
    expect($live['state'])->toBe('tracking');
    expect($live['bus']['on_route'])->toBeTrue();
    expect($live['bus']['longitude'])->toEqualWithDelta(125.0, 0.0001); // on the line
    expect($live['bus']['latitude'])->toEqualWithDelta(7.10, 0.001);
    expect($live['bus']['age_seconds'])->toBeLessThan(5);

    // Five minutes later with no new fix: the old position is labelled, not presented as live.
    Carbon::setTestNow(Carbon::now()->addMinutes(5));
    $stale = $this->getJson('/api/track/'.$token)->json('data');
    expect($stale['state'])->toBe('stale');
    expect($stale['bus']['age_seconds'])->toBeGreaterThanOrEqual(300);
});

it('works only for its own trip\'s window: not yet, live, arrived for a grace period, then ended', function () {
    [$trip, $stops, , $fare] = trkFixture();
    $token = trkToken(trkTicket($trip, $stops, $fare));
    $state = fn () => $this->getJson('/api/track/'.$token)->assertSuccessful()->json('data');

    Carbon::setTestNow(Carbon::parse('2026-10-04 06:30:00', 'Asia/Manila')); // 90 min before: too early
    $early = $state();
    expect($early['state'])->toBe('not_yet')->and($early['bus'])->toBeNull();
    expect(Carbon::parse($early['opens_at'])->format('H:i'))->toBe('07:00');

    Carbon::setTestNow(Carbon::parse('2026-10-04 07:05:00', 'Asia/Manila'));
    expect($state()['state'])->toBe('waiting');

    $trip->forceFill(['status' => 'completed', 'completed_at' => Carbon::parse('2026-10-04 09:10:00', 'Asia/Manila')])->save();
    Carbon::setTestNow(Carbon::parse('2026-10-04 09:30:00', 'Asia/Manila')); // inside the 30 min grace
    expect($state()['state'])->toBe('arrived');
    Carbon::setTestNow(Carbon::parse('2026-10-04 09:45:00', 'Asia/Manila'));
    expect($state()['state'])->toBe('ended');

    // A trip that never gets closed stops being trackable after the longest plausible run.
    $trip->forceFill(['status' => 'departed', 'completed_at' => null])->save();
    Carbon::setTestNow(Carbon::parse('2026-10-04 20:45:00', 'Asia/Manila'));
    expect($state()['state'])->toBe('ended');
});

it('says a cancelled trip is cancelled, and refuses an expired or cancelled ticket', function () {
    [$trip, $stops, , $fare] = trkFixture();
    $ticket = trkTicket($trip, $stops, $fare);
    $token = trkToken($ticket);

    $trip->update(['status' => 'cancelled']);
    $this->getJson('/api/track/'.$token)->assertJsonPath('data.state', 'cancelled');

    $trip->update(['status' => 'scheduled']);
    $ticket->update(['status' => 'expired']);
    $this->getJson('/api/track/'.$token)->assertJsonPath('data.state', 'unavailable')->assertJsonPath('data.bus', null);
});

it('follows a custom drop-off ticket to its pin with an ETA', function () {
    [$trip, $stops, , $fare, $fleetRoute] = trkFixture();
    $fleetRoute->route->update(['allow_custom_dropoff' => true]); // a pin ticket only exists where the route allows it
    $ticket = trkTicket($trip, $stops, $fare);
    $ticket->update(['destination_stop_id' => null, 'destination_lat' => 7.2, 'destination_lng' => 125.0, 'destination_label' => 'Near the chapel']);

    $data = $this->getJson('/api/track/'.trkToken($ticket))->assertSuccessful()->json('data');

    expect($data['alighting']['name'])->toBe('Near the chapel');
    expect($data['alighting']['custom'])->toBeTrue();
    expect($data['alighting']['eta_time'])->not->toBeNull();
});

it('answers 404 for any unknown or malformed link, the same way, and slows a client that keeps guessing', function () {
    config(['tracking.miss_limit' => 5]);
    [$trip, $stops, , $fare] = trkFixture();
    $token = trkToken(trkTicket($trip, $stops, $fare));
    $wrong = substr($token, 0, 42).($token[42] === 'a' ? 'b' : 'a');

    $this->getJson('/api/track/'.$wrong)->assertNotFound()->assertJsonPath('message', 'Tracking link not found.');
    $this->getJson('/api/track/short')->assertNotFound(); // not even a route
    $this->getJson('/api/track/'.str_repeat('A', 43))->assertNotFound()->assertJsonPath('message', 'Tracking link not found.');
    $this->getJson('/api/track/1')->assertNotFound();

    foreach (range(1, 4) as $i) {
        $this->getJson('/api/track/'.Str::random(43))->assertStatus($i <= 3 ? 404 : 429);
    }
    $this->getJson('/api/track/'.$token)->assertStatus(429); // even a good link waits while the client is being slowed
});

it('rate limits the public routes: the track link and the live bus positions', function () {
    $middleware = fn (string $uri) => collect(app('router')->getRoutes()->getRoutes())->first(fn ($route) => $route->uri() === $uri)->gatherMiddleware();

    // 2026-10-27: same limits, each with its own counter (the third value).
    expect($middleware('api/track/{token}'))->toContain('throttle:60,1,track');
    expect($middleware('api/fleet/locations'))->toContain('throttle:90,1,fleet-locations');
    expect($middleware('api/fleet/nearest'))->toContain('throttle:90,1,fleet-nearest');
});
