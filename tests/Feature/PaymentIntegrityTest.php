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
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

// Payment integrity (closes the three test gaps in THESIS_OBJECTIVES_AUDIT.md, Objective 2):
//  1. a paid-webhook redelivery issues no second ticket and awards no second reward;
//  2. reward points come from the paid amount on the server, once, and never for a guest;
//  3. the last seat cannot be sold twice (held at checkout, freed by a failed payment or an expired hold).
//
// What these tests CANNOT show: two requests at the very same instant. The suite runs on sqlite in memory, where
// "SELECT ... FOR UPDATE" is a no-op, so the row lock (TripRepository::findByIdForUpdate) is not exercised here; the
// tests prove the capacity rule and the hold/release bookkeeping that the lock protects, one request after another.
// Fare numbers are SAMPLE test values: B -> D is 20 km, 13 + 15 x 2.25 = 46.75, 47 at the whole peso.

const PI_SECRET = 'whsk_pi_secret';

function piStaff(string $role): User
{
    $user = User::create(['username' => 'pi'.Str::random(8), 'email' => Str::random(8).'@example.com', 'password' => bcrypt('password123'), 'role' => $role]);
    StaffUser::create(['user_id' => $user->user_id, 'company_user_uuid' => (string) Str::uuid(), 'name' => 'Pi '.ucfirst($role), 'phone_num' => '09170000041', 'address' => 'x']);

    return $user->fresh();
}

function piPassenger(): PassengerUser
{
    $user = User::create(['username' => 'pax'.Str::random(6), 'email' => Str::random(8).'@example.com', 'password' => bcrypt('password123'), 'role' => 'passenger']);

    return PassengerUser::create(['passenger_uuid' => (string) Str::uuid(), 'user_id' => $user->user_id, 'name' => 'Pax', 'phone_num' => '09170000002', 'address' => 'x', 'birthdate' => '2000-01-01', 'reward_points' => 0]);
}

/** One route A(0) B(10) C(pass) D(30) E(40) and one trip on 2026-10-05 08:00 with the given seated capacity. */
function piFixture(int $seated = 30): array
{
    $operator = piStaff('operator');
    $route = Route::create(['route_name' => 'Pi Route', 'origin' => 'A', 'destination' => 'E']);
    $stops = [];
    foreach ([['A', 0, 'terminal'], ['B', 10, 'stop'], ['C', 20, 'pass_through'], ['D', 30, 'stop'], ['E', 40, 'terminal']] as $i => [$name, $km, $type]) {
        $stop = Stop::create(['stop_name' => "Stop $name", 'latitude' => 7.0 + $km / 100, 'longitude' => 125.0, 'municipality' => 'Mati City']);
        RouteStop::create(['route_id' => $route->route_id, 'stop_id' => $stop->stop_id, 'stop_order' => $i + 1, 'distance_from_origin_km' => $km, 'stop_type' => $type, 'scheduled_offset_min' => $km]);
        $stops[$name] = $stop;
    }
    $fleet = Fleet::create([
        'company_user_id' => $operator->companyProfile->company_user_id, 'plate_number' => 'PI-'.fake()->unique()->numerify('####'),
        'bus_company_name' => 'Co P', 'body_no' => '7', 'capacity' => $seated, 'seated_capacity' => $seated, 'standing_capacity' => 0, 'status' => 'active', 'vehicle_class' => 'aircon',
    ]);
    $fleetRoute = FleetRoute::create(['fleet_id' => $fleet->fleet_id, 'route_id' => $route->route_id, 'start_time' => '06:00', 'end_time' => '22:00', 'status' => 'active']);
    $trip = Trip::create([
        'fleet_route_id' => $fleetRoute->fleet_route_id, 'company_user_id' => $operator->companyProfile->company_user_id, 'trip_date' => '2026-10-05', 'departure_time' => '08:00',
        'trip_type' => 'one_way', 'leg_direction' => 'outbound', 'status' => 'scheduled', 'current_seated_capacity' => 0, 'current_standing_capacity' => 0, 'total_occupancy' => 0,
    ]);
    FareRule::create(['fleet_id' => null, 'vehicle_class' => 'aircon', 'service_type' => 'regular', 'seat_type' => 'seated', 'status' => 'active', 'base_fare' => 13, 'fare_per_km' => 2.25, 'base_distance_km' => 5, 'note' => 'SAMPLE']);

    return compact('operator', 'route', 'stops', 'fleet', 'trip');
}

/** A one-way online checkout of one seated ticket B -> D; a passenger, or a guest when $passenger is null. */
function piCheckout($test, ?PassengerUser $passenger, array $f)
{
    $call = $passenger ? $test->actingAs($passenger->user) : $test;

    return $call->postJson('/api/checkout', [
        'booking_date' => '2026-10-05', 'payment_channel' => 'gcash', 'guest_email' => $passenger ? null : 'guest@example.com',
        'items' => [['trip_id' => $f['trip']->trip_id, 'seat_type' => 'seated', 'origin_stop_id' => $f['stops']['B']->stop_id, 'destination_stop_id' => $f['stops']['D']->stop_id]],
    ]);
}

function piWebhook($test, string $type, array $resource)
{
    $payload = json_encode(['data' => ['id' => 'evt_'.Str::random(8), 'type' => 'event', 'attributes' => ['type' => $type, 'livemode' => false, 'data' => $resource, 'created_at' => time(), 'updated_at' => time()]]]);
    $ts = time();
    $sig = hash_hmac('sha256', $ts.'.'.$payload, PI_SECRET);

    return $test->call('POST', '/api/webhooks/paymongo', [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_PAYMONGO_SIGNATURE' => "t={$ts},te={$sig},li="], $payload);
}

function piPaid($test, Payment $payment)
{
    return piWebhook($test, 'checkout_session.payment.paid', ['id' => $payment->gateway_reference, 'type' => 'checkout_session', 'attributes' => []]);
}

function piFailed($test, Payment $payment)
{
    return piWebhook($test, 'payment.failed', ['id' => 'pay_'.Str::random(6), 'type' => 'payment', 'attributes' => ['metadata' => ['payment_id' => (string) $payment->payment_id]]]);
}

beforeEach(function () {
    Carbon::setTestNow(Carbon::parse('2026-10-04 07:00:00', 'Asia/Manila'));
    Cache::flush();
    config(['services.paymongo.secret_key' => 'sk_test_dummy', 'services.paymongo.webhook_secret' => PI_SECRET]);
    // One fake for the whole test, a new gateway session id on every request (a second Http::fake() in the same test is
    // ignored, and two payments must not share a gateway reference).
    Http::fake(['api.paymongo.com/*' => fn () => Http::response(['data' => ['id' => 'cs_'.Str::random(12), 'attributes' => ['checkout_url' => 'https://pay.example/x', 'payment_intent' => ['id' => 'pi_'.Str::random(8)]]]])]);
});
afterEach(fn () => Carbon::setTestNow());

// ----------------------------------------------------------------- 1. webhook redelivery

it('issues one ticket and takes one seat however many times the paid webhook is delivered', function () {
    $f = piFixture();
    $pax = piPassenger();
    $payment = Payment::findOrFail(piCheckout($this, $pax, $f)->assertSuccessful()->json('data.payment.payment_id'));

    // Held at checkout; no ticket before payment.
    expect($f['trip']->fresh()->total_occupancy)->toBe(1)->and(Ticket::count())->toBe(0);

    foreach (range(1, 4) as $delivery) {
        piPaid($this, $payment)->assertOk();
    }

    expect(Ticket::where('payment_id', $payment->payment_id)->count())->toBe(1);
    expect($payment->fresh()->status)->toBe('paid');
    expect($f['trip']->fresh()->total_occupancy)->toBe(1);        // the seat was taken once, not once per delivery
    expect((float) Ticket::firstOrFail()->amount)->toBe(47.0);
});

it('keeps one ticket when the paid event arrives twice at once, and ignores a failure that arrives after it', function () {
    $f = piFixture();
    $payment = Payment::findOrFail(piCheckout($this, piPassenger(), $f)->assertSuccessful()->json('data.payment.payment_id'));

    piPaid($this, $payment)->assertOk();
    piFailed($this, $payment)->assertOk();    // out of order: must not undo a confirmed payment or free its seat
    piPaid($this, $payment)->assertOk();

    expect($payment->fresh()->status)->toBe('paid');
    expect(Ticket::where('payment_id', $payment->payment_id)->count())->toBe(1);
    expect($f['trip']->fresh()->total_occupancy)->toBe(1);
});

// ----------------------------------------------------------------- 2. reward accrual

it('awards reward points from the amount the server priced, once, and records one earned transaction', function () {
    $f = piFixture();
    $pax = piPassenger();
    $payment = Payment::findOrFail(piCheckout($this, $pax, $f)->assertSuccessful()->json('data.payment.payment_id'));

    expect((int) $pax->fresh()->reward_points)->toBe(0);          // nothing is earned before the payment is confirmed
    expect(DB::table('reward_transactions')->count())->toBe(0);

    piPaid($this, $payment)->assertOk();
    piPaid($this, $payment)->assertOk();                          // redelivery

    // 47 paid, 1 point per 20 pesos, rounded down: 2 points.
    expect((int) $pax->fresh()->reward_points)->toBe(2);
    $rows = DB::table('reward_transactions')->where('passenger_id', $pax->passenger_id)->get();
    expect($rows)->toHaveCount(1);
    expect([$rows[0]->type, (int) $rows[0]->points, (int) $rows[0]->payment_id])->toBe(['earned', 2, $payment->payment_id]);
});

it('awards nothing to a guest and nothing for a payment that failed', function () {
    $f = piFixture();
    $guestPayment = Payment::findOrFail(piCheckout($this, null, $f)->assertSuccessful()->json('data.payment.payment_id'));
    piPaid($this, $guestPayment)->assertOk();
    expect(Ticket::where('payment_id', $guestPayment->payment_id)->count())->toBe(1);
    expect(DB::table('reward_transactions')->count())->toBe(0);

    $pax = piPassenger();
    $failedPayment = Payment::findOrFail(piCheckout($this, $pax, $f)->assertSuccessful()->json('data.payment.payment_id'));
    piFailed($this, $failedPayment)->assertOk();
    expect($failedPayment->fresh()->status)->toBe('failed');
    expect((int) $pax->fresh()->reward_points)->toBe(0)->and(DB::table('reward_transactions')->count())->toBe(0);
});

it('never takes a point value from the client: extra fields in the checkout change nothing', function () {
    $f = piFixture();
    $pax = piPassenger();

    $id = $this->actingAs($pax->user)->postJson('/api/checkout', [
        'booking_date' => '2026-10-05', 'payment_channel' => 'gcash', 'points' => 9999, 'reward_points' => 9999, 'amount' => 100000,
        'items' => [['trip_id' => $f['trip']->trip_id, 'seat_type' => 'seated', 'origin_stop_id' => $f['stops']['B']->stop_id, 'destination_stop_id' => $f['stops']['D']->stop_id, 'amount' => 1, 'points' => 9999]],
    ])->assertSuccessful()->json('data.payment.payment_id');
    $payment = Payment::findOrFail($id);
    piPaid($this, $payment)->assertOk();

    expect((float) $payment->fresh()->amount)->toBe(47.0);
    expect((int) $pax->fresh()->reward_points)->toBe(2);
});

// ----------------------------------------------------------------- 3. the last seat

it('sells the last seat once: a second buyer is refused while it is held, and after it is paid', function () {
    $f = piFixture(seated: 1);

    $first = Payment::findOrFail(piCheckout($this, piPassenger(), $f)->assertSuccessful()->json('data.payment.payment_id'));
    expect($f['trip']->fresh()->current_seated_capacity)->toBe(1);

    // The seat is held for the first buyer: the second is refused and leaves nothing behind.
    piCheckout($this, piPassenger(), $f)->assertStatus(422);
    expect(Payment::count())->toBe(1)->and($f['trip']->fresh()->current_seated_capacity)->toBe(1);

    piPaid($this, $first)->assertOk();
    piCheckout($this, piPassenger(), $f)->assertStatus(422);       // still refused once it is sold
    piCheckout($this, null, $f)->assertStatus(422);                // a guest too

    expect(Ticket::count())->toBe(1)->and(Payment::count())->toBe(1);
    expect($f['trip']->fresh()->current_seated_capacity)->toBe(1)->and($f['trip']->fresh()->total_occupancy)->toBe(1);
});

it('lets the next buyer have the seat when the first payment fails', function () {
    $f = piFixture(seated: 1);

    $first = Payment::findOrFail(piCheckout($this, piPassenger(), $f)->assertSuccessful()->json('data.payment.payment_id'));
    piCheckout($this, piPassenger(), $f)->assertStatus(422);

    piFailed($this, $first)->assertOk();
    piFailed($this, $first)->assertOk();                           // redelivery frees the seat once, not twice
    expect($f['trip']->fresh()->current_seated_capacity)->toBe(0)->and($f['trip']->fresh()->total_occupancy)->toBe(0);

    $second = Payment::findOrFail(piCheckout($this, piPassenger(), $f)->assertSuccessful()->json('data.payment.payment_id'));
    piPaid($this, $second)->assertOk();
    expect(Ticket::count())->toBe(1)->and(Ticket::firstOrFail()->payment_id)->toBe($second->payment_id);
    expect($f['trip']->fresh()->current_seated_capacity)->toBe(1);
});

it('frees a held seat when the hold expires, and never oversells a bus with a few seats', function () {
    $f = piFixture(seated: 3);

    // Five buyers, three seats: exactly three succeed.
    $results = collect(range(1, 5))->map(fn () => piCheckout($this, piPassenger(), $f)->getStatusCode());
    expect($results->filter(fn ($s) => $s === 200 || $s === 201)->count())->toBe(3);
    expect($results->filter(fn ($s) => $s === 422)->count())->toBe(2);
    expect($f['trip']->fresh()->current_seated_capacity)->toBe(3)->and(Payment::count())->toBe(3);

    // None of them pays; after the hold window the command gives the seats back.
    Carbon::setTestNow(Carbon::parse('2026-10-04 07:20:00', 'Asia/Manila'));
    Artisan::call('payments:release-expired-holds');
    expect($f['trip']->fresh()->current_seated_capacity)->toBe(0)->and($f['trip']->fresh()->total_occupancy)->toBe(0);

    piCheckout($this, piPassenger(), $f)->assertSuccessful();
    expect($f['trip']->fresh()->current_seated_capacity)->toBe(1);
});
