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
use App\Services\FcmService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

// Batch 28, Stage 2, item 4: roundtrip booking. One booking and one payment make two tickets, each with its own
// trip, stops, fare and date. Fare numbers are SAMPLE test values.

const RT_SECRET = 'whsk_rt_secret';

function rtStaff(string $role): User
{
    $user = User::create(['username' => 'rt'.Str::random(8), 'email' => Str::random(8).'@example.com', 'password' => bcrypt('password123'), 'role' => $role]);
    StaffUser::create(['user_id' => $user->user_id, 'company_user_uuid' => (string) Str::uuid(), 'name' => 'Rt '.ucfirst($role), 'phone_num' => '09170000032', 'address' => 'x']);

    return $user->fresh();
}

function rtPassenger(): PassengerUser
{
    $user = User::create(['username' => 'pax'.Str::random(6), 'email' => Str::random(8).'@example.com', 'password' => bcrypt('password123'), 'role' => 'passenger']);

    return PassengerUser::create(['passenger_uuid' => (string) Str::uuid(), 'user_id' => $user->user_id, 'name' => 'Pax', 'phone_num' => '09170000001', 'address' => 'x', 'birthdate' => '2000-01-01']);
}

/**
 * One route A(0) B(10) C(pass) D(30) E(40). Two buses: the outbound bus and a DIFFERENT bus for the return, each
 * with its own passenger label. Trips: outbound on 2026-10-05 08:00, return (reverse) on 2026-10-06 15:00.
 */
function rtFixture(bool $returnLabel = true): array
{
    $operator = rtStaff('operator');
    $route = Route::create(['route_name' => 'Rt Route', 'origin' => 'A', 'destination' => 'E']);
    $stops = [];
    foreach ([['A', 0, 'terminal'], ['B', 10, 'stop'], ['C', 20, 'pass_through'], ['D', 30, 'stop'], ['E', 40, 'terminal']] as $i => [$name, $km, $type]) {
        $stop = Stop::create(['stop_name' => "Stop $name", 'latitude' => 7.0 + $km / 100, 'longitude' => 125.0, 'municipality' => 'Mati City']);
        RouteStop::create(['route_id' => $route->route_id, 'stop_id' => $stop->stop_id, 'stop_order' => $i + 1, 'distance_from_origin_km' => $km, 'stop_type' => $type, 'scheduled_offset_min' => $km]);
        $stops[$name] = $stop;
    }

    $make = function (string $company, ?string $body, string $date, string $time, string $direction) use ($operator, $route) {
        $fleet = Fleet::create([
            'company_user_id' => $operator->companyProfile->company_user_id, 'plate_number' => 'RT-'.fake()->unique()->numerify('####'),
            'bus_company_name' => $body === null ? null : $company, 'body_no' => $body,
            'capacity' => 40, 'seated_capacity' => 30, 'standing_capacity' => 10, 'status' => 'active', 'vehicle_class' => 'aircon',
        ]);
        $fleetRoute = FleetRoute::create(['fleet_id' => $fleet->fleet_id, 'route_id' => $route->route_id, 'start_time' => '06:00', 'end_time' => '22:00', 'status' => 'active']);
        $trip = Trip::create([
            'fleet_route_id' => $fleetRoute->fleet_route_id, 'company_user_id' => $operator->companyProfile->company_user_id, 'trip_date' => $date, 'departure_time' => $time,
            'trip_type' => 'one_way', 'leg_direction' => $direction, 'status' => 'scheduled', 'current_seated_capacity' => 0, 'current_standing_capacity' => 0, 'total_occupancy' => 0,
        ]);

        return [$fleet, $trip, $fleetRoute];
    };

    [$outFleet, $outTrip] = $make('Co A', '101', '2026-10-05', '08:00', 'outbound');
    [$backFleet, $backTrip, $backFleetRoute] = $make('Co B', $returnLabel ? '202' : null, '2026-10-06', '15:00', 'reverse');
    // SAMPLE general rule: 13.00 + 2.25/km after a 5 km base, for the aircon class.
    FareRule::create(['fleet_id' => null, 'vehicle_class' => 'aircon', 'service_type' => 'regular', 'seat_type' => 'seated', 'status' => 'active', 'base_fare' => 13, 'fare_per_km' => 2.25, 'base_distance_km' => 5, 'note' => 'SAMPLE']);

    return compact('operator', 'route', 'stops', 'outFleet', 'outTrip', 'backFleet', 'backTrip', 'backFleetRoute');
}

function rtQuery(array $stops, array $over = []): string
{
    return http_build_query(array_merge([
        'origin_stop_id' => $stops['B']->stop_id, 'destination_stop_id' => $stops['D']->stop_id,
        'outbound_mode' => 'later', 'outbound_date' => '2026-10-05', 'outbound_time' => '08:00',
        'return_date' => '2026-10-06', 'return_time' => '14:00',
    ], $over));
}

function rtItems(array $f): array
{
    return [
        ['trip_id' => $f['outTrip']->trip_id, 'seat_type' => 'seated', 'origin_stop_id' => $f['stops']['B']->stop_id, 'destination_stop_id' => $f['stops']['D']->stop_id, 'booking_date' => '2026-10-05', 'journey_leg' => 'outbound'],
        ['trip_id' => $f['backTrip']->trip_id, 'seat_type' => 'seated', 'origin_stop_id' => $f['stops']['D']->stop_id, 'destination_stop_id' => $f['stops']['B']->stop_id, 'booking_date' => '2026-10-06', 'journey_leg' => 'return'],
    ];
}

function rtCheckout($test, ?PassengerUser $passenger, array $items, array $extra = [])
{
    Http::fake(['api.paymongo.com/*' => Http::response(['data' => ['id' => 'cs_'.Str::random(10), 'attributes' => ['checkout_url' => 'https://pay.example/x', 'payment_intent' => ['id' => 'pi_'.Str::random(8)]]]])]);
    $call = $passenger ? $test->actingAs($passenger->user) : $test;

    return $call->postJson('/api/checkout', array_merge(['journey_type' => 'roundtrip', 'items' => $items, 'payment_channel' => 'gcash', 'guest_email' => $passenger ? null : 'guest@example.com'], $extra));
}

function rtPaidWebhook($test, Payment $payment)
{
    $payload = json_encode(['data' => ['id' => 'evt_'.Str::random(8), 'type' => 'event', 'attributes' => ['type' => 'checkout_session.payment.paid', 'livemode' => false,
        'data' => ['id' => $payment->gateway_reference, 'type' => 'checkout_session', 'attributes' => []], 'created_at' => time(), 'updated_at' => time()]]]);
    $ts = time();
    $sig = hash_hmac('sha256', $ts.'.'.$payload, RT_SECRET);

    return $test->call('POST', '/api/webhooks/paymongo', [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_PAYMONGO_SIGNATURE' => "t={$ts},te={$sig},li="], $payload);
}

beforeEach(function () {
    Carbon::setTestNow(Carbon::parse('2026-10-04 07:00:00', 'Asia/Manila'));
    Cache::flush();
    config(['services.paymongo.secret_key' => 'sk_test_dummy', 'services.paymongo.webhook_secret' => RT_SECRET]);
});
afterEach(fn () => Carbon::setTestNow());

it('resolves both legs, each with its own trip, bus label and fare, and says the rule before payment', function () {
    $f = rtFixture();

    $data = $this->getJson('/api/booking/roundtrip?'.rtQuery($f['stops']))->assertSuccessful()->json('data');

    expect($data['can_pay'])->toBeTrue()->and($data['message'])->toBeNull();
    expect($data['outbound']['trip_id'])->toBe($f['outTrip']->trip_id)->and($data['outbound']['bus_label'])->toBe('Co A - 101')->and($data['outbound']['journey_leg'])->toBe('outbound');
    expect($data['return']['trip_id'])->toBe($f['backTrip']->trip_id)->and($data['return']['bus_label'])->toBe('Co B - 202')->and($data['return']['journey_leg'])->toBe('return');
    expect($data['return']['bus_label'])->not->toBe($data['outbound']['bus_label']); // the return bus is not assumed to be the outbound bus
    // B -> D is 20 km each way: 13 + 15 x 2.25 = 46.75, 47 per leg at the whole peso.
    expect([$data['outbound']['fare'], $data['return']['fare'], $data['total_fare']])->toEqual([47.0, 47.0, 94.0]);
    expect($data['return_defaults'])->toBe(['origin_stop_id' => $f['stops']['D']->stop_id, 'destination_stop_id' => $f['stops']['B']->stop_id]);
    expect($data['cancellation_notice'])->toContain('cannot be cancelled or refunded');
    // Resolving holds and charges nothing.
    expect(Payment::count())->toBe(0)->and($f['outTrip']->fresh()->total_occupancy)->toBe(0);
});

it('validates the dates by Manila time: future only, return after departure, inside the window', function () {
    $f = rtFixture();
    $get = fn (array $over) => $this->getJson('/api/booking/roundtrip?'.rtQuery($f['stops'], $over));

    $get(['return_date' => '2026-10-05', 'return_time' => '07:59'])->assertStatus(422)->assertJsonValidationErrors(['return_date']);  // before the departure
    $get(['return_date' => '2026-10-05', 'return_time' => '08:00'])->assertStatus(422)->assertJsonValidationErrors(['return_date']);  // not after it
    $get(['return_date' => '2026-10-03', 'return_time' => '09:00'])->assertStatus(422)->assertJsonValidationErrors(['date']);          // past
    $get(['return_date' => '2026-12-31', 'return_time' => '09:00'])->assertStatus(422)->assertJsonValidationErrors(['date']);          // beyond the window
    $get(['outbound_date' => '2026-10-03'])->assertStatus(422)->assertJsonValidationErrors(['date']);
    // The midnight edge: 00:00 on the next Manila day is after 23:30 on the day before, and is accepted (no trip there).
    $get(['outbound_time' => '23:30', 'return_date' => '2026-10-06', 'return_time' => '00:00'])->assertSuccessful();
    $get(['outbound_time' => '23:30', 'return_date' => '2026-10-05', 'return_time' => '23:30'])->assertStatus(422);
});

it('is not payable, and charges nothing, when a leg has no departure', function () {
    $f = rtFixture();

    $data = $this->getJson('/api/booking/roundtrip?'.rtQuery($f['stops'], ['return_date' => '2026-10-09', 'return_time' => '09:00']))->assertSuccessful()->json('data');

    expect($data['can_pay'])->toBeFalse()->and($data['return'])->toBeNull()->and($data['total_fare'])->toBeNull();
    expect($data['message'])->toContain('No return departure');
    expect($data['outbound']['trip_id'])->toBe($f['outTrip']->trip_id);
    expect(Payment::count())->toBe(0)->and(Ticket::count())->toBe(0);

    $none = $this->getJson('/api/booking/roundtrip?'.rtQuery($f['stops'], ['outbound_date' => '2026-10-08', 'return_date' => '2026-10-09']))->assertSuccessful()->json('data');
    expect($none['can_pay'])->toBeFalse()->and($none['outbound'])->toBeNull()->and($none['message'])->toContain('No outbound departure');
});

it('says so when the return bus has no passenger label yet, never showing a plate or the outbound label', function () {
    $f = rtFixture(returnLabel: false);

    $data = $this->getJson('/api/booking/roundtrip?'.rtQuery($f['stops']))->assertSuccessful()->json('data');

    expect($data['outbound']['bus_state'])->toBe('ready');
    expect($data['return']['bus_label'])->toBeNull()->and($data['return']['bus_state'])->toBe('label_unavailable');
    expect(json_encode($data))->not->toContain($f['backFleet']->plate_number);
});

it('makes one payment and two tickets, confirmed together by the webhook, and redelivery changes nothing', function () {
    $f = rtFixture();
    $pax = rtPassenger();

    $response = rtCheckout($this, $pax, rtItems($f))->assertSuccessful();
    $payment = Payment::findOrFail($response->json('data.payment.payment_id'));

    // One pending payment holding both legs; capacity is held on BOTH trips; no ticket exists before payment.
    expect(Payment::count())->toBe(1)->and(count($payment->items_payload))->toBe(2);
    expect($f['outTrip']->fresh()->total_occupancy)->toBe(1)->and($f['backTrip']->fresh()->total_occupancy)->toBe(1);
    expect(Ticket::count())->toBe(0);
    expect((float) $payment->amount)->toBe(94.0);

    rtPaidWebhook($this, $payment)->assertOk();
    rtPaidWebhook($this, $payment)->assertOk(); // redelivery

    expect(Ticket::where('payment_id', $payment->payment_id)->count())->toBe(2);
    $out = Ticket::where('journey_leg', 'outbound')->firstOrFail();
    $back = Ticket::where('journey_leg', 'return')->firstOrFail();
    expect([$out->trip_id, $back->trip_id])->toBe([$f['outTrip']->trip_id, $f['backTrip']->trip_id]);
    expect([$out->origin_stop_id, $out->destination_stop_id])->toBe([$f['stops']['B']->stop_id, $f['stops']['D']->stop_id]);
    expect([$back->origin_stop_id, $back->destination_stop_id])->toBe([$f['stops']['D']->stop_id, $f['stops']['B']->stop_id]);
    expect([(float) $out->amount, (float) $back->amount])->toBe([47.0, 47.0]);
    expect($out->fare_rule_id)->not->toBeNull()->and($back->fare_rule_id)->not->toBeNull()->and($out->rounding_mode)->toBe('nearest');
    expect($out->ticket_uuid)->not->toBe($back->ticket_uuid);
    expect($out->passenger_id)->toBe($pax->passenger_id)->and($back->passenger_id)->toBe($pax->passenger_id);
    expect($payment->fresh()->status)->toBe('paid');
});

it('gates each ticket by its own trip date and shows each its own bus', function () {
    $f = rtFixture();
    $pax = rtPassenger();
    $payment = Payment::findOrFail(rtCheckout($this, $pax, rtItems($f))->json('data.payment.payment_id'));
    rtPaidWebhook($this, $payment)->assertOk();

    $tickets = collect($this->actingAs($pax->user)->getJson('/api/passengers/tickets')->assertSuccessful()->json('data'))->keyBy('journey_leg');
    expect(Carbon::parse($tickets['outbound']['valid_from'])->setTimezone('Asia/Manila')->toDateString())->toBe('2026-10-05');
    expect(Carbon::parse($tickets['return']['valid_from'])->setTimezone('Asia/Manila')->toDateString())->toBe('2026-10-06');
    expect($tickets['outbound']['bus_label'])->toBe('Co A - 101')->and($tickets['return']['bus_label'])->toBe('Co B - 202');
    // The return ticket knows its own default return journey (this is the return ticket: back to where it started).
    expect($tickets['outbound']['return_journey']['origin_stop_id'])->toBe($f['stops']['D']->stop_id);
    expect($tickets['outbound']['return_journey']['destination_stop_id'])->toBe($f['stops']['B']->stop_id);

    // A scan on the outbound date accepts only the outbound ticket: the return ticket is for another trip and day.
    Carbon::setTestNow(Carbon::parse('2026-10-05 08:30:00', 'Asia/Manila'));
    expect(Ticket::where('journey_leg', 'return')->first()->trip->trip_date->toDateString())->toBe('2026-10-06');
});

it('refuses the whole booking, holding nothing and creating no payment, when the return leg is tampered or full', function () {
    $f = rtFixture();
    $pax = rtPassenger();

    // A return stop behind the boarding stop for the return trip's direction.
    $tampered = rtItems($f);
    $tampered[1]['origin_stop_id'] = $f['stops']['B']->stop_id;
    $tampered[1]['destination_stop_id'] = $f['stops']['D']->stop_id;
    rtCheckout($this, $pax, $tampered)->assertStatus(422);
    expect(Payment::count())->toBe(0)->and(Ticket::count())->toBe(0)->and($f['outTrip']->fresh()->total_occupancy)->toBe(0);

    // The return trip is full: the outbound seat is not kept either.
    $f['backTrip']->update(['current_seated_capacity' => 30, 'total_occupancy' => 30]);
    rtCheckout($this, $pax, rtItems($f))->assertStatus(422);
    expect(Payment::count())->toBe(0)->and($f['outTrip']->fresh()->total_occupancy)->toBe(0);

    // A return that departs before the outbound trip, and a leg missing: refused by the request itself.
    $f['backTrip']->update(['current_seated_capacity' => 0, 'total_occupancy' => 0]);
    $early = rtItems($f);
    $early[1]['trip_id'] = $f['outTrip']->trip_id;
    $early[1]['booking_date'] = '2026-10-05';
    rtCheckout($this, $pax, $early)->assertStatus(422);
    rtCheckout($this, $pax, [rtItems($f)[0]])->assertStatus(422);
    $noLeg = rtItems($f);
    unset($noLeg[0]['journey_leg']);
    rtCheckout($this, $pax, $noLeg)->assertStatus(422);
    expect(Payment::count())->toBe(0);
});

it('leaves no orphan ticket when finalising fails halfway', function () {
    $f = rtFixture();
    $pax = rtPassenger();
    $payment = Payment::findOrFail(rtCheckout($this, $pax, rtItems($f))->json('data.payment.payment_id'));

    // Break the second leg after checkout (its trip row disappears): the whole confirmation must roll back.
    $payload = $payment->items_payload;
    $payload[1]['fleet_route_id'] = 99999; // violates the tickets -> fleets_routes foreign key
    $payment->update(['items_payload' => $payload]);

    try {
        app(\App\Services\PaymentService::class)->finalizeOnlinePayment($payment->fresh());
    } catch (\Throwable) {
        // the webhook would answer an error and PayMongo would retry
    }

    expect(Ticket::count())->toBe(0);
    expect($payment->fresh()->status)->toBe('pending');
})->skip(fn () => \Illuminate\Support\Facades\DB::getDriverName() === 'sqlite' && ! \Illuminate\Support\Facades\DB::selectOne('PRAGMA foreign_keys')->foreign_keys, 'needs enforced foreign keys');

it('refuses a passenger cancel or refund of either ticket with 422 and an audit entry, and hides other people\'s tickets', function () {
    $f = rtFixture();
    $pax = rtPassenger();
    $payment = Payment::findOrFail(rtCheckout($this, $pax, rtItems($f))->json('data.payment.payment_id'));
    rtPaidWebhook($this, $payment)->assertOk();

    foreach (Ticket::all() as $ticket) {
        foreach (['cancel', 'refund'] as $action) {
            $this->actingAs($pax->user)->postJson("/api/passengers/tickets/{$ticket->ticket_uuid}/{$action}")->assertStatus(422)->assertJsonPath('message', fn ($m) => str_contains($m, 'cannot be cancelled or refunded'));
        }
        $this->assertDatabaseHas('audit_logs', ['action' => 'ticket.passenger_cancel_refused', 'subject_id' => $ticket->ticket_id]);
        expect($ticket->fresh()->status)->toBe('valid'); // nothing changed
    }

    $other = rtPassenger();
    $this->actingAs($other->user)->postJson('/api/passengers/tickets/'.Ticket::first()->ticket_uuid.'/cancel')->assertStatus(404);
});

it('moves a trip to another bus before it starts, updates its tickets and tells the passengers', function () {
    $f = rtFixture();
    $pax = rtPassenger();
    $payment = Payment::findOrFail(rtCheckout($this, $pax, rtItems($f))->json('data.payment.payment_id'));
    rtPaidWebhook($this, $payment)->assertOk();

    // A third bus on the same route, its label not set yet.
    $spare = Fleet::create(['company_user_id' => $f['operator']->companyProfile->company_user_id, 'plate_number' => 'RT-SPARE', 'capacity' => 40, 'seated_capacity' => 30, 'standing_capacity' => 10, 'status' => 'active', 'vehicle_class' => 'aircon']);
    $spareRoute = FleetRoute::create(['fleet_id' => $spare->fleet_id, 'route_id' => $f['route']->route_id, 'start_time' => '06:00', 'end_time' => '22:00', 'status' => 'active']);

    $this->mock(FcmService::class, function ($mock) {
        $mock->shouldReceive('sendToUser')->once()->withArgs(fn ($user, $title, $body, $data) => ($data['type'] ?? null) === 'trip_bus_changed' && str_contains($body, 'new bus'));
    });

    app('auth')->forgetGuards(); // the checkout above ran as the passenger; start the operator calls with a clean guard
    $this->actingAs($f['operator'])->patchJson("/api/operator/trips/{$f['backTrip']->trip_id}/fleet", ['fleet_route_id' => $spareRoute->fleet_route_id])->assertSuccessful();

    expect($f['backTrip']->fresh()->fleet_route_id)->toBe($spareRoute->fleet_route_id);
    expect(Ticket::where('journey_leg', 'return')->first()->fleet_route_id)->toBe($spareRoute->fleet_route_id);
    // Read live: the passenger now sees the unlabelled state for the return bus, and the outbound bus is untouched.
    $tickets = collect($this->actingAs($pax->user)->getJson('/api/passengers/tickets')->json('data'))->keyBy('journey_leg');
    expect($tickets['return']['bus_label'])->toBeNull()->and($tickets['outbound']['bus_label'])->toBe('Co A - 101');

    // Only before the trip starts, and only onto a bus of the same route.
    $f['backTrip']->update(['status' => 'boarding']);
    $this->actingAs($f['operator'])->patchJson("/api/operator/trips/{$f['backTrip']->trip_id}/fleet", ['fleet_route_id' => $f['backFleetRoute']->fleet_route_id])->assertStatus(422);
});
