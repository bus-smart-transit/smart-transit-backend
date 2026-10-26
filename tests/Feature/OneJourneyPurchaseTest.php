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
use App\Services\PaymentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

// One purchase is one journey (smarttransit_prompt.md §4.10, 2026-10-26). Roundtrip booking (Batch 28, item 4) is
// retired: a return is a separate purchase. Kept from the roundtrip tests: confirmation rolls back as a whole, a
// passenger cannot cancel or refund, and a trip moved to another bus updates its tickets. Fares are SAMPLE values.

const OJ_SECRET = 'whsk_oj_secret';

function ojStaff(string $role): User
{
    $user = User::create(['username' => 'oj'.Str::random(8), 'email' => Str::random(8).'@example.com', 'password' => bcrypt('password123'), 'role' => $role]);
    StaffUser::create(['user_id' => $user->user_id, 'company_user_uuid' => (string) Str::uuid(), 'name' => 'Oj '.ucfirst($role), 'phone_num' => '09170000032', 'address' => 'x']);

    return $user->fresh();
}

function ojPassenger(): PassengerUser
{
    $user = User::create(['username' => 'pax'.Str::random(6), 'email' => Str::random(8).'@example.com', 'password' => bcrypt('password123'), 'role' => 'passenger']);

    return PassengerUser::create(['passenger_uuid' => (string) Str::uuid(), 'user_id' => $user->user_id, 'name' => 'Pax', 'phone_num' => '09170000001', 'address' => 'x', 'birthdate' => '2000-01-01']);
}

/**
 * One route A(0) B(10) C(pass) D(30) E(40). Two buses, each with its own passenger label. Trips: outbound on
 * 2026-10-05 08:00 and the reverse one on 2026-10-06 15:00.
 */
function ojFixture(): array
{
    $operator = ojStaff('operator');
    $route = Route::create(['route_name' => 'Oj Route', 'origin' => 'A', 'destination' => 'E']);
    $stops = [];
    foreach ([['A', 0, 'terminal'], ['B', 10, 'stop'], ['C', 20, 'pass_through'], ['D', 30, 'stop'], ['E', 40, 'terminal']] as $i => [$name, $km, $type]) {
        $stop = Stop::create(['stop_name' => "Stop $name", 'latitude' => 7.0 + $km / 100, 'longitude' => 125.0, 'municipality' => 'Mati City']);
        RouteStop::create(['route_id' => $route->route_id, 'stop_id' => $stop->stop_id, 'stop_order' => $i + 1, 'distance_from_origin_km' => $km, 'stop_type' => $type, 'scheduled_offset_min' => $km]);
        $stops[$name] = $stop;
    }

    $make = function (string $company, string $body, string $date, string $time, string $direction) use ($operator, $route) {
        $fleet = Fleet::create([
            'company_user_id' => $operator->companyProfile->company_user_id, 'plate_number' => 'OJ-'.fake()->unique()->numerify('####'),
            'bus_company_name' => $company, 'body_no' => $body,
            'capacity' => 40, 'seated_capacity' => 30, 'standing_capacity' => 10, 'status' => 'active', 'vehicle_class' => 'aircon',
        ]);
        $fleetRoute = FleetRoute::create(['fleet_id' => $fleet->fleet_id, 'route_id' => $route->route_id, 'start_time' => '06:00', 'end_time' => '22:00', 'status' => 'active']);
        $trip = Trip::create([
            'fleet_route_id' => $fleetRoute->fleet_route_id, 'company_user_id' => $operator->companyProfile->company_user_id, 'trip_date' => $date, 'departure_time' => $time,
            'trip_type' => 'one_way', 'leg_direction' => $direction, 'status' => 'scheduled', 'current_seated_capacity' => 0, 'current_standing_capacity' => 0, 'total_occupancy' => 0,
        ]);

        return [$fleet, $trip, $fleetRoute];
    };

    [, $outTrip] = $make('Co A', '101', '2026-10-05', '08:00', 'outbound');
    [, $backTrip, $backFleetRoute] = $make('Co B', '202', '2026-10-06', '15:00', 'reverse');
    // SAMPLE general rule: 13.00 + 2.25/km after a 5 km base, for the aircon class.
    FareRule::create(['fleet_id' => null, 'vehicle_class' => 'aircon', 'service_type' => 'regular', 'seat_type' => 'seated', 'status' => 'active', 'base_fare' => 13, 'fare_per_km' => 2.25, 'base_distance_km' => 5, 'note' => 'SAMPLE']);

    return compact('operator', 'route', 'stops', 'outTrip', 'backTrip', 'backFleetRoute');
}

/** $count tickets for ONE journey: B to D on the outbound trip, or D to B on the return trip. */
function ojItems(array $f, int $count = 1, string $trip = 'outTrip'): array
{
    [$from, $to, $date] = $trip === 'outTrip' ? ['B', 'D', '2026-10-05'] : ['D', 'B', '2026-10-06'];

    return array_fill(0, $count, ['trip_id' => $f[$trip]->trip_id, 'seat_type' => 'seated', 'origin_stop_id' => $f['stops'][$from]->stop_id, 'destination_stop_id' => $f['stops'][$to]->stop_id, 'booking_date' => $date]);
}

function ojCheckout($test, ?PassengerUser $passenger, array $items, array $extra = [])
{
    // A new session id on every call: Http::fake keeps the first stub it is given, so a fixed id would repeat on a
    // second checkout in the same test.
    Http::fake(['api.paymongo.com/*' => fn () => Http::response(['data' => ['id' => 'cs_'.Str::random(10), 'attributes' => ['checkout_url' => 'https://pay.example/x', 'payment_intent' => ['id' => 'pi_'.Str::random(8)]]]])]);
    $call = $passenger ? $test->actingAs($passenger->user) : $test;

    return $call->postJson('/api/checkout', array_merge(['items' => $items, 'payment_channel' => 'gcash', 'guest_email' => $passenger ? null : 'guest@example.com'], $extra));
}

function ojPaidWebhook($test, Payment $payment)
{
    $payload = json_encode(['data' => ['id' => 'evt_'.Str::random(8), 'type' => 'event', 'attributes' => ['type' => 'checkout_session.payment.paid', 'livemode' => false,
        'data' => ['id' => $payment->gateway_reference, 'type' => 'checkout_session', 'attributes' => []], 'created_at' => time(), 'updated_at' => time()]]]);
    $ts = time();
    $sig = hash_hmac('sha256', $ts.'.'.$payload, OJ_SECRET);

    return $test->call('POST', '/api/webhooks/paymongo', [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_PAYMONGO_SIGNATURE' => "t={$ts},te={$sig},li="], $payload);
}

beforeEach(function () {
    Carbon::setTestNow(Carbon::parse('2026-10-04 07:00:00', 'Asia/Manila'));
    Cache::flush();
    config(['services.paymongo.secret_key' => 'sk_test_dummy', 'services.paymongo.webhook_secret' => OJ_SECRET]);
});
afterEach(fn () => Carbon::setTestNow());

it('refuses a roundtrip booking: the checkout says to buy the return separately, and the roundtrip lookup is gone', function () {
    $f = ojFixture();
    $pax = ojPassenger();
    $message = 'Roundtrip booking is no longer offered. Book the return journey as a separate ticket.';

    ojCheckout($this, $pax, ojItems($f), ['journey_type' => 'roundtrip'])->assertStatus(422)->assertJsonFragment([$message]);
    $withLeg = ojItems($f);
    $withLeg[0]['journey_leg'] = 'outbound';
    ojCheckout($this, $pax, $withLeg)->assertStatus(422)->assertJsonFragment([$message]);
    $this->getJson('/api/booking/roundtrip?origin_stop_id=1')->assertNotFound();
    expect(Payment::count())->toBe(0);
});

it('refuses a purchase that mixes journeys, and accepts several tickets for one journey', function () {
    $f = ojFixture();
    $pax = ojPassenger();
    $message = 'All tickets in one purchase must be for the same trip, boarding stop and destination. Buy another journey separately.';

    ojCheckout($this, $pax, [ojItems($f)[0], ojItems($f, 1, 'backTrip')[0]])->assertStatus(422)->assertJsonFragment([$message]);
    $otherStop = ojItems($f, 2);
    $otherStop[1]['destination_stop_id'] = $f['stops']['E']->stop_id;
    ojCheckout($this, $pax, $otherStop)->assertStatus(422)->assertJsonFragment([$message]);
    expect(Payment::count())->toBe(0);

    $payment = Payment::findOrFail(ojCheckout($this, $pax, ojItems($f, 2))->assertSuccessful()->json('data.payment.payment_id'));
    ojPaidWebhook($this, $payment)->assertOk();
    expect(Ticket::where('payment_id', $payment->payment_id)->count())->toBe(2);
});

it('leaves no orphan ticket when finalising fails halfway', function () {
    $f = ojFixture();
    $pax = ojPassenger();
    $payment = Payment::findOrFail(ojCheckout($this, $pax, ojItems($f, 2))->json('data.payment.payment_id'));

    // Break the second ticket after checkout: the whole confirmation must roll back.
    $payload = $payment->items_payload;
    $payload[1]['fleet_route_id'] = 99999; // violates the tickets -> fleets_routes foreign key
    $payment->update(['items_payload' => $payload]);

    try {
        app(PaymentService::class)->finalizeOnlinePayment($payment->fresh());
    } catch (Throwable) {
        // the webhook would answer an error and PayMongo would retry
    }

    expect(Ticket::count())->toBe(0);
    expect($payment->fresh()->status)->toBe('pending');
})->skip(fn () => DB::getDriverName() === 'sqlite' && ! DB::selectOne('PRAGMA foreign_keys')->foreign_keys, 'needs enforced foreign keys');

it('refuses a passenger cancel or refund of any ticket with 422 and an audit entry, and hides other people\'s tickets', function () {
    $f = ojFixture();
    $pax = ojPassenger();
    $payment = Payment::findOrFail(ojCheckout($this, $pax, ojItems($f, 2))->json('data.payment.payment_id'));
    ojPaidWebhook($this, $payment)->assertOk();

    foreach (Ticket::all() as $ticket) {
        foreach (['cancel', 'refund'] as $action) {
            $this->actingAs($pax->user)->postJson("/api/passengers/tickets/{$ticket->ticket_uuid}/{$action}")->assertStatus(422)->assertJsonPath('message', fn ($m) => str_contains($m, 'cannot be cancelled or refunded'));
        }
        $this->assertDatabaseHas('audit_logs', ['action' => 'ticket.passenger_cancel_refused', 'subject_id' => $ticket->ticket_id]);
        expect($ticket->fresh()->status)->toBe('valid'); // nothing changed
    }

    $other = ojPassenger();
    $this->actingAs($other->user)->postJson('/api/passengers/tickets/'.Ticket::first()->ticket_uuid.'/cancel')->assertStatus(404);
});

it('moves a trip to another bus before it starts, updates its tickets and tells the passengers', function () {
    $f = ojFixture();
    $pax = ojPassenger();
    // Two separate purchases: the outbound journey and the return journey.
    foreach (['outTrip', 'backTrip'] as $trip) {
        app('auth')->forgetGuards();
        $payment = Payment::findOrFail(ojCheckout($this, $pax, ojItems($f, 1, $trip))->assertSuccessful()->json('data.payment.payment_id'));
        ojPaidWebhook($this, $payment)->assertOk();
    }

    // A third bus on the same route, its label not set yet.
    $spare = Fleet::create(['company_user_id' => $f['operator']->companyProfile->company_user_id, 'plate_number' => 'OJ-SPARE', 'capacity' => 40, 'seated_capacity' => 30, 'standing_capacity' => 10, 'status' => 'active', 'vehicle_class' => 'aircon']);
    $spareRoute = FleetRoute::create(['fleet_id' => $spare->fleet_id, 'route_id' => $f['route']->route_id, 'start_time' => '06:00', 'end_time' => '22:00', 'status' => 'active']);

    $this->mock(FcmService::class, function ($mock) {
        $mock->shouldReceive('sendToUser')->once()->withArgs(fn ($user, $title, $body, $data) => ($data['type'] ?? null) === 'trip_bus_changed' && str_contains($body, 'new bus'));
    });

    app('auth')->forgetGuards(); // the checkouts above ran as the passenger; start the operator calls with a clean guard
    $this->actingAs($f['operator'])->patchJson("/api/operator/trips/{$f['backTrip']->trip_id}/fleet", ['fleet_route_id' => $spareRoute->fleet_route_id])->assertSuccessful();

    expect($f['backTrip']->fresh()->fleet_route_id)->toBe($spareRoute->fleet_route_id);
    expect(Ticket::where('trip_id', $f['backTrip']->trip_id)->first()->fleet_route_id)->toBe($spareRoute->fleet_route_id);
    // Read live: the passenger now sees the unlabelled state for the moved bus, and the other bus is untouched.
    $tickets = collect($this->actingAs($pax->user)->getJson('/api/passengers/tickets')->json('data.data'))->keyBy('trip_id');
    expect($tickets[$f['backTrip']->trip_id]['bus_label'])->toBeNull()->and($tickets[$f['outTrip']->trip_id]['bus_label'])->toBe('Co A - 101');

    // Only before the trip starts, and only onto a bus of the same route.
    $f['backTrip']->update(['status' => 'boarding']);
    $this->actingAs($f['operator'])->patchJson("/api/operator/trips/{$f['backTrip']->trip_id}/fleet", ['fleet_route_id' => $f['backFleetRoute']->fleet_route_id])->assertStatus(422);
});
