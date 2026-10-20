<?php

use App\Models\FareRule;
use App\Models\Fleet;
use App\Models\FleetRoute;
use App\Models\PassengerUser;
use App\Models\Payment;
use App\Models\RewardTransaction;
use App\Models\Route;
use App\Models\RouteStop;
use App\Models\StaffUser;
use App\Models\Stop;
use App\Models\Ticket;
use App\Models\Trip;
use App\Models\User;
use App\Repositories\ReportingRepository;
use App\Services\RewardService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

// Held seats and reward points (Priority 1, items 2-4):
//  - a payment that arrives after its hold was released is honoured only if the seat and the points can be taken again,
//    otherwise it is flagged for refund and no ticket is issued;
//  - reward points are taken once, atomically, and given back when the payment fails or the hold expires;
//  - a redeemed point is worth the checkout discount (PHP 1) in the financial report, not the earning rate (PHP 20);
//  - a correctly signed webhook that is far too old is refused.
// Fare numbers are SAMPLE test values: B -> D is 20 km, 13 + 15 x 2.25 = 46.75, 47 at the whole peso.

const HL_SECRET = 'whsk_hl_secret';

function hlStaff(string $role): User
{
    $user = User::create(['username' => 'hl'.Str::random(8), 'email' => Str::random(8).'@example.com', 'password' => bcrypt('password123'), 'role' => $role]);
    StaffUser::create(['user_id' => $user->user_id, 'company_user_uuid' => (string) Str::uuid(), 'name' => 'Hl '.ucfirst($role), 'phone_num' => '09170000051', 'address' => 'x']);

    return $user->fresh();
}

function hlPassenger(int $points = 0): PassengerUser
{
    $user = User::create(['username' => 'pax'.Str::random(6), 'email' => Str::random(8).'@example.com', 'password' => bcrypt('password123'), 'role' => 'passenger']);

    return PassengerUser::create(['passenger_uuid' => (string) Str::uuid(), 'user_id' => $user->user_id, 'name' => 'Pax', 'phone_num' => '09170000002', 'address' => 'x', 'birthdate' => '2000-01-01', 'reward_points' => $points]);
}

function hlFixture(int $seated = 30): array
{
    $operator = hlStaff('operator');
    $route = Route::create(['route_name' => 'Hl Route', 'origin' => 'A', 'destination' => 'E']);
    $stops = [];
    foreach ([['A', 0, 'terminal'], ['B', 10, 'stop'], ['C', 20, 'pass_through'], ['D', 30, 'stop'], ['E', 40, 'terminal']] as $i => [$name, $km, $type]) {
        $stop = Stop::create(['stop_name' => "Stop $name", 'latitude' => 7.0 + $km / 100, 'longitude' => 125.0, 'municipality' => 'Mati City']);
        RouteStop::create(['route_id' => $route->route_id, 'stop_id' => $stop->stop_id, 'stop_order' => $i + 1, 'distance_from_origin_km' => $km, 'stop_type' => $type, 'scheduled_offset_min' => $km]);
        $stops[$name] = $stop;
    }
    $fleet = Fleet::create([
        'company_user_id' => $operator->companyProfile->company_user_id, 'plate_number' => 'HL-'.fake()->unique()->numerify('####'),
        'bus_company_name' => 'Co H', 'body_no' => '8', 'capacity' => $seated, 'seated_capacity' => $seated, 'standing_capacity' => 0, 'status' => 'active', 'vehicle_class' => 'aircon',
    ]);
    $fleetRoute = FleetRoute::create(['fleet_id' => $fleet->fleet_id, 'route_id' => $route->route_id, 'start_time' => '06:00', 'end_time' => '22:00', 'status' => 'active']);
    $trip = Trip::create([
        'fleet_route_id' => $fleetRoute->fleet_route_id, 'company_user_id' => $operator->companyProfile->company_user_id, 'trip_date' => '2026-10-05', 'departure_time' => '08:00',
        'trip_type' => 'one_way', 'leg_direction' => 'outbound', 'status' => 'scheduled', 'current_seated_capacity' => 0, 'current_standing_capacity' => 0, 'total_occupancy' => 0,
    ]);
    FareRule::create(['fleet_id' => null, 'vehicle_class' => 'aircon', 'service_type' => 'regular', 'seat_type' => 'seated', 'status' => 'active', 'base_fare' => 13, 'fare_per_km' => 2.25, 'base_distance_km' => 5, 'note' => 'SAMPLE']);

    return compact('operator', 'route', 'stops', 'fleet', 'trip');
}

function hlCheckout($test, PassengerUser $passenger, array $f, int $points = 0)
{
    return $test->actingAs($passenger->user)->postJson('/api/checkout', array_filter([
        'booking_date' => '2026-10-05', 'payment_channel' => 'gcash',
        'reward_points_to_use' => $points ?: null,
        'items' => [['trip_id' => $f['trip']->trip_id, 'seat_type' => 'seated', 'origin_stop_id' => $f['stops']['B']->stop_id, 'destination_stop_id' => $f['stops']['D']->stop_id]],
    ], fn ($v) => $v !== null));
}

function hlWebhook($test, string $type, array $resource, ?int $timestamp = null)
{
    $payload = json_encode(['data' => ['id' => 'evt_'.Str::random(8), 'type' => 'event', 'attributes' => ['type' => $type, 'livemode' => false, 'data' => $resource, 'created_at' => time(), 'updated_at' => time()]]]);
    $ts = $timestamp ?? time();
    $sig = hash_hmac('sha256', $ts.'.'.$payload, HL_SECRET);

    return $test->call('POST', '/api/webhooks/paymongo', [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_PAYMONGO_SIGNATURE' => "t={$ts},te={$sig},li="], $payload);
}

function hlPaid($test, Payment $payment, ?int $timestamp = null)
{
    return hlWebhook($test, 'checkout_session.payment.paid', ['id' => $payment->gateway_reference, 'type' => 'checkout_session', 'attributes' => []], $timestamp);
}

function hlFailed($test, Payment $payment)
{
    return hlWebhook($test, 'payment.failed', ['id' => 'pay_'.Str::random(6), 'type' => 'payment', 'attributes' => ['metadata' => ['payment_id' => (string) $payment->payment_id]]]);
}

/** A checkout whose hold then runs out: the scheduled job releases the seat and gives the points back. */
function hlExpire(Payment $payment): void
{
    Carbon::setTestNow(Carbon::parse('2026-10-04 07:20:00', 'Asia/Manila'));
    Artisan::call('payments:release-expired-holds');
    expect($payment->fresh()->status)->toBe('expired');
}

beforeEach(function () {
    Carbon::setTestNow(Carbon::parse('2026-10-04 07:00:00', 'Asia/Manila'));
    Cache::flush();
    config(['services.paymongo.secret_key' => 'sk_test_dummy', 'services.paymongo.webhook_secret' => HL_SECRET]);
    Http::fake(['api.paymongo.com/*' => fn () => Http::response(['data' => ['id' => 'cs_'.Str::random(12), 'attributes' => ['checkout_url' => 'https://pay.example/x', 'payment_intent' => ['id' => 'pi_'.Str::random(8)]]]])]);
});
afterEach(fn () => Carbon::setTestNow());

it('replays an online checkout key without duplicating its payment, hold, or reward redemption', function () {
    $fixture = hlFixture();
    $passenger = hlPassenger(20);
    $key = 'online-retry-key-00000001';
    $payload = [
        'booking_date' => '2026-10-05',
        'payment_channel' => 'gcash',
        'reward_points_to_use' => 5,
        'items' => [[
            'trip_id' => $fixture['trip']->trip_id,
            'seat_type' => 'seated',
            'origin_stop_id' => $fixture['stops']['B']->stop_id,
            'destination_stop_id' => $fixture['stops']['D']->stop_id,
        ]],
    ];

    $first = $this->actingAs($passenger->user)->withHeader('Idempotency-Key', $key)->postJson('/api/passengers/checkout', $payload)->assertSuccessful();
    $replay = $this->actingAs($passenger->user)->withHeader('Idempotency-Key', $key)->postJson('/api/passengers/checkout', $payload)->assertSuccessful();

    expect($replay->json('data.payment.payment_id'))->toBe($first->json('data.payment.payment_id'));
    expect($replay->json('data.checkout_url'))->toBe($first->json('data.checkout_url'));
    expect(Payment::where('idempotency_key_hash', hash('sha256', $key))->count())->toBe(1);
    expect((int) $fixture['trip']->fresh()->current_seated_capacity)->toBe(1);
    expect((int) $passenger->fresh()->reward_points)->toBe(15);
    expect(RewardTransaction::where('passenger_id', $passenger->passenger_id)->where('type', 'redeemed')->count())->toBe(1);
    Http::assertSentCount(1);

    $changedPayload = $payload;
    $changedPayload['payment_channel'] = 'maya';
    $this->actingAs($passenger->user)->withHeader('Idempotency-Key', $key)->postJson('/api/passengers/checkout', $changedPayload)->assertStatus(409);
    expect(Payment::where('idempotency_key_hash', hash('sha256', $key))->count())->toBe(1);
});

it('enforces the database unique index for an actor-scoped checkout key', function () {
    $attributes = [
        'amount' => 1,
        'payment_created' => now(),
        'transaction_reference' => 'IDEMPOTENCY-'.Str::random(8),
        'payment_method' => 'online',
        'payment_channel' => 'gcash',
        'status' => 'pending',
        'payment_uuid' => (string) Str::uuid(),
        'is_valid' => true,
        'idempotency_scope_hash' => hash('sha256', 'passenger:42'),
        'idempotency_key_hash' => hash('sha256', 'duplicate-key'),
        'idempotency_request_hash' => hash('sha256', 'payload'),
        'idempotency_state' => 'processing',
    ];
    Payment::create($attributes);

    expect(fn () => Payment::create(array_replace($attributes, [
        'transaction_reference' => 'IDEMPOTENCY-'.Str::random(8),
        'payment_uuid' => (string) Str::uuid(),
    ])))->toThrow(\Illuminate\Database\QueryException::class);
});

it('keeps the hold and reward redemption when PayMongo session creation has an unknown outcome', function () {
    $fixture = hlFixture();
    $passenger = hlPassenger(20);
    $gatewayCalls = 0;
    Http::fake(['api.paymongo.com/*' => function () use (&$gatewayCalls) {
        $gatewayCalls++;
        throw new ConnectionException('simulated timeout');
    }]);
    $key = 'online-unknown-key-0000001';
    $payload = [
        'booking_date' => '2026-10-05',
        'payment_channel' => 'gcash',
        'reward_points_to_use' => 5,
        'items' => [[
            'trip_id' => $fixture['trip']->trip_id,
            'seat_type' => 'seated',
            'origin_stop_id' => $fixture['stops']['B']->stop_id,
            'destination_stop_id' => $fixture['stops']['D']->stop_id,
        ]],
    ];

    $response = $this->actingAs($passenger->user)->withHeader('Idempotency-Key', $key)
        ->postJson('/api/passengers/checkout', $payload)
        ->assertStatus(503);
    $reference = $response->json('errors.transaction_reference.0');
    expect($response->json('message'))->toContain($reference);
    $payment = Payment::where('transaction_reference', $reference)->firstOrFail();

    expect($payment->status)->toBe('pending');
    expect($payment->idempotency_state)->toBe('unknown');
    expect((int) $fixture['trip']->fresh()->current_seated_capacity)->toBe(1);
    expect((int) $passenger->fresh()->reward_points)->toBe(15);
    $payment->forceFill(['hold_expires_at' => now()->subMinute()])->save();
    Artisan::call('payments:release-expired-holds');
    expect($payment->fresh()->status)->toBe('pending');
    expect((int) $fixture['trip']->fresh()->current_seated_capacity)->toBe(1);
    expect((int) $passenger->fresh()->reward_points)->toBe(15);
    $this->getJson('/api/payments/status?ref='.urlencode($reference))
        ->assertSuccessful()
        ->assertJsonPath('data.status', 'pending')
        ->assertJsonPath('data.outcome_unknown', true);

    $this->actingAs($passenger->user)->withHeader('Idempotency-Key', $key)
        ->postJson('/api/passengers/checkout', $payload)
        ->assertStatus(409);
    expect($gatewayCalls)->toBe(1);
});

it('serves bounded passenger history pages for tickets, rewards, and payments', function () {
    $fixture = hlFixture();
    $passenger = hlPassenger();

    foreach ([1, 2] as $sequence) {
        $payment = Payment::create([
            'amount' => 20 * $sequence,
            'payment_created' => now()->addMinutes($sequence),
            'transaction_reference' => 'HISTORY-'.$sequence.'-'.Str::random(6),
            'payment_method' => 'online',
            'payment_channel' => 'gcash',
            'status' => 'paid',
            'payment_uuid' => (string) Str::uuid(),
            'is_valid' => true,
            'items_payload' => [[
                'passenger_id' => $passenger->passenger_id,
                'trip_id' => $fixture['trip']->trip_id,
                'seat_type' => 'seated',
                'amount' => 20 * $sequence,
                'origin_stop_id' => $fixture['stops']['B']->stop_id,
                'destination_stop_id' => $fixture['stops']['D']->stop_id,
            ]],
        ]);

        Ticket::create([
            'fleet_route_id' => $fixture['trip']->fleet_route_id,
            'trip_id' => $fixture['trip']->trip_id,
            'payment_id' => $payment->payment_id,
            'passenger_id' => $passenger->passenger_id,
            'ticket_uuid' => (string) Str::uuid(),
            'status' => 'valid',
            'seat_type' => 'seated',
            'amount' => 20 * $sequence,
            'origin_stop_id' => $fixture['stops']['B']->stop_id,
            'destination_stop_id' => $fixture['stops']['D']->stop_id,
        ]);
        RewardTransaction::create([
            'passenger_id' => $passenger->passenger_id,
            'payment_id' => $payment->payment_id,
            'points' => $sequence,
            'type' => 'earned',
            'description' => 'History page test',
        ]);
    }

    $summary = $this->actingAs($passenger->user)->getJson('/api/passengers/dashboard-summary')->assertSuccessful()->json('data');
    expect($summary['tickets'])->toHaveCount(2);
    expect($summary['rewards'])->toHaveCount(2);

    expect($summary['payments'])->toHaveCount(2);
    expect($summary['meta']['history_pagination']['tickets']['total'])->toBe(2);
    expect($summary['dashboard_facts']['upcoming_count'])->toBe(2);
    expect($summary['dashboard_facts']['nearby_routes_count'])->toBe(1);
    expect($summary['dashboard_facts']['featured_ticket']['passenger_id'])->toBe($passenger->passenger_id);

    foreach ([
        '/api/passengers/tickets',
        '/api/passengers/rewards/history',
        '/api/passengers/payments',
    ] as $endpoint) {
        $page = $this->actingAs($passenger->user)->getJson($endpoint.'?page=2&per_page=1')->assertSuccessful()->json('data');
        expect($page['data'])->toHaveCount(1);
        expect($page)->toMatchArray(['current_page' => 2, 'last_page' => 2, 'per_page' => 1, 'total' => 2]);
    }
});

it('recovers an unknown checkout from a signed paid-session webhook by payment metadata', function () {
    $fixture = hlFixture();
    $passenger = hlPassenger(20);
    Http::fake(['api.paymongo.com/*' => fn () => throw new ConnectionException('simulated timeout')]);
    $key = 'online-webhook-recovery-001';
    $payload = [
        'booking_date' => '2026-10-05',
        'payment_channel' => 'gcash',
        'reward_points_to_use' => 5,
        'items' => [[
            'trip_id' => $fixture['trip']->trip_id,
            'seat_type' => 'seated',
            'origin_stop_id' => $fixture['stops']['B']->stop_id,
            'destination_stop_id' => $fixture['stops']['D']->stop_id,
        ]],
    ];

    $checkout = $this->actingAs($passenger->user)->withHeader('Idempotency-Key', $key)
        ->postJson('/api/passengers/checkout', $payload)->assertStatus(503);
    $reference = $checkout->json('errors.transaction_reference.0');
    $payment = Payment::where('transaction_reference', $reference)->firstOrFail();

    hlWebhook($this, 'checkout_session.payment.paid', [
        'id' => 'cs_recovered_123',
        'type' => 'checkout_session',
        'attributes' => [
            'metadata' => ['payment_id' => (string) $payment->payment_id],
            'payment_intent' => ['id' => 'pi_recovered_123'],
            'payments' => [['id' => 'pay_recovered_123']],
        ],
    ])->assertOk();

    expect($payment->fresh()->status)->toBe('paid');
    expect($payment->fresh()->idempotency_state)->toBe('settled');
    expect($payment->fresh()->gateway_reference)->toBe('cs_recovered_123');
    expect($payment->fresh()->payment_intent_id)->toBe('pi_recovered_123');
    expect(Ticket::where('payment_id', $payment->payment_id)->count())->toBe(1);
    expect((int) $passenger->fresh()->reward_points)->toBe(17);

    $this->actingAs($passenger->user)->withHeader('Idempotency-Key', $key)
        ->postJson('/api/passengers/checkout', $payload)
        ->assertStatus(409)
        ->assertJsonFragment(['This checkout has been settled. Check its payment status before starting another checkout.']);
});

// ------------------------------------------------------------ reward points: taken once, given back

it('takes the points once at checkout and takes PHP 1 off per point', function () {
    $f = hlFixture();
    $pax = hlPassenger(30);

    $payment = Payment::findOrFail(hlCheckout($this, $pax, $f, 10)->assertSuccessful()->json('data.payment.payment_id'));

    expect((float) $payment->amount)->toBe(37.0);                       // 47 - 10 points x PHP 1
    expect((int) $pax->fresh()->reward_points)->toBe(20);
    expect(RewardTransaction::where('payment_id', $payment->payment_id)->pluck('points')->all())->toBe([-10]);
});

it('never lets two checkouts spend the same points: the second spend is refused and the balance cannot go below zero', function () {
    $pax = hlPassenger(10);
    $rewards = app(RewardService::class);

    expect($rewards->redeemPoints($pax->passenger_id, 10))->toBeTrue();
    expect($rewards->redeemPoints($pax->passenger_id, 10))->toBeFalse();   // the check and the spend are one statement

    expect((int) $pax->fresh()->reward_points)->toBe(0);
    expect(RewardTransaction::where('passenger_id', $pax->passenger_id)->count())->toBe(1);
});

it('gives the points back when the payment fails, once, however many times the failure arrives', function () {
    $f = hlFixture();
    $pax = hlPassenger(30);
    $payment = Payment::findOrFail(hlCheckout($this, $pax, $f, 10)->assertSuccessful()->json('data.payment.payment_id'));

    hlFailed($this, $payment)->assertOk();
    hlFailed($this, $payment)->assertOk();

    expect((int) $pax->fresh()->reward_points)->toBe(30);
    expect((int) RewardTransaction::where('payment_id', $payment->payment_id)->sum('points'))->toBe(0);
    expect($f['trip']->fresh()->total_occupancy)->toBe(0);
});

it('gives the points back when the hold expires', function () {
    $f = hlFixture();
    $pax = hlPassenger(30);
    $payment = Payment::findOrFail(hlCheckout($this, $pax, $f, 10)->assertSuccessful()->json('data.payment.payment_id'));
    expect((int) $pax->fresh()->reward_points)->toBe(20);

    hlExpire($payment);
    Artisan::call('payments:release-expired-holds'); // a second run changes nothing

    expect((int) $pax->fresh()->reward_points)->toBe(30);
    expect((int) RewardTransaction::where('payment_id', $payment->payment_id)->sum('points'))->toBe(0);
    expect($f['trip']->fresh()->total_occupancy)->toBe(0);
});

it('keeps the points of a payment that was paid, and does not expire it', function () {
    $f = hlFixture();
    $pax = hlPassenger(30);
    $payment = Payment::findOrFail(hlCheckout($this, $pax, $f, 10)->assertSuccessful()->json('data.payment.payment_id'));
    hlPaid($this, $payment)->assertOk();

    Carbon::setTestNow(Carbon::parse('2026-10-04 07:20:00', 'Asia/Manila'));
    Artisan::call('payments:release-expired-holds');

    expect($payment->fresh()->status)->toBe('paid');
    // 30 - 10 spent + 1 earned on the PHP 37 actually paid (1 point per PHP 20).
    expect((int) $pax->fresh()->reward_points)->toBe(21);
});

// ------------------------------------------------------------ the report values a point at the discount

it('values a redeemed point at PHP 1 in the payment-channel report, and does not count points that were given back', function () {
    $f = hlFixture();
    $pax = hlPassenger(60);
    $paid = Payment::findOrFail(hlCheckout($this, $pax, $f, 10)->assertSuccessful()->json('data.payment.payment_id'));
    hlPaid($this, $paid)->assertOk();
    $failed = Payment::findOrFail(hlCheckout($this, $pax, $f, 20)->assertSuccessful()->json('data.payment.payment_id'));
    hlFailed($this, $failed)->assertOk();   // 20 points taken, then given back: not a redemption

    $report = app(ReportingRepository::class)->getPaymentChannelBreakdown($f['fleet']->fleet_id, '2026-10-01', '2026-10-31');

    expect($report['reward_redemptions']['redemption_count'])->toBe(1);
    expect($report['reward_redemptions']['total_points_redeemed'])->toBe(10);
    expect($report['reward_redemptions']['total_value_php'])->toBe(10.0);
});

// ------------------------------------------------------------ a payment that arrives after its hold was released

it('honours a late payment when the seat is still free: one ticket, one seat, the points taken again', function () {
    $f = hlFixture();
    $pax = hlPassenger(30);
    $payment = Payment::findOrFail(hlCheckout($this, $pax, $f, 10)->assertSuccessful()->json('data.payment.payment_id'));
    hlExpire($payment);
    expect((int) $pax->fresh()->reward_points)->toBe(30)->and($f['trip']->fresh()->total_occupancy)->toBe(0);

    hlPaid($this, $payment)->assertOk();
    hlPaid($this, $payment)->assertOk();   // redelivery

    expect($payment->fresh()->status)->toBe('paid');
    expect(Ticket::where('payment_id', $payment->payment_id)->count())->toBe(1);
    expect($f['trip']->fresh()->total_occupancy)->toBe(1);
    // 30 - 10 taken again + 1 earned on the PHP 37 paid.
    expect((int) $pax->fresh()->reward_points)->toBe(21);
});

it('honours a payment that succeeds after an earlier attempt in the same checkout failed, when the seat is still free', function () {
    $f = hlFixture();
    $payment = Payment::findOrFail(hlCheckout($this, hlPassenger(), $f)->assertSuccessful()->json('data.payment.payment_id'));
    hlFailed($this, $payment)->assertOk();
    expect($f['trip']->fresh()->total_occupancy)->toBe(0);

    hlPaid($this, $payment)->assertOk();

    expect($payment->fresh()->status)->toBe('paid');
    expect(Ticket::where('payment_id', $payment->payment_id)->count())->toBe(1);
    expect($f['trip']->fresh()->total_occupancy)->toBe(1);
});

it('does not issue a ticket for a late payment when the seat was taken meanwhile: it is flagged for refund', function () {
    $f = hlFixture(1);    // one seat
    $first = Payment::findOrFail(hlCheckout($this, hlPassenger(), $f)->assertSuccessful()->json('data.payment.payment_id'));
    hlExpire($first);
    // Somebody else buys the seat that was released.
    $second = Payment::findOrFail(hlCheckout($this, hlPassenger(), $f)->assertSuccessful()->json('data.payment.payment_id'));
    hlPaid($this, $second)->assertOk();
    expect($f['trip']->fresh()->total_occupancy)->toBe(1);

    hlPaid($this, $first)->assertOk();

    expect($first->fresh()->status)->toBe('needs_refund');
    expect(Ticket::where('payment_id', $first->payment_id)->count())->toBe(0);
    expect($f['trip']->fresh()->total_occupancy)->toBe(1);          // still only the second buyer
    expect(Ticket::where('payment_id', $second->payment_id)->count())->toBe(1);

    hlPaid($this, $first)->assertOk();                               // a redelivery cannot change that
    expect(Ticket::where('payment_id', $first->payment_id)->count())->toBe(0);
});

it('does not issue a ticket for a late payment when the trip is no longer taking bookings', function () {
    $f = hlFixture();
    $payment = Payment::findOrFail(hlCheckout($this, hlPassenger(), $f)->assertSuccessful()->json('data.payment.payment_id'));
    hlExpire($payment);
    $f['trip']->update(['status' => 'cancelled']);

    hlPaid($this, $payment)->assertOk();

    expect($payment->fresh()->status)->toBe('needs_refund');
    expect(Ticket::where('payment_id', $payment->payment_id)->count())->toBe(0);
    expect($f['trip']->fresh()->total_occupancy)->toBe(0);
});

it('does not issue a ticket for a late payment when the reward points it used are gone', function () {
    $f = hlFixture();
    $pax = hlPassenger(30);
    $payment = Payment::findOrFail(hlCheckout($this, $pax, $f, 10)->assertSuccessful()->json('data.payment.payment_id'));
    hlExpire($payment);
    $pax->update(['reward_points' => 0]);   // the returned points were spent elsewhere

    hlPaid($this, $payment)->assertOk();

    expect($payment->fresh()->status)->toBe('needs_refund');
    expect(Ticket::where('payment_id', $payment->payment_id)->count())->toBe(0);
    expect((int) $pax->fresh()->reward_points)->toBe(0);
    expect($f['trip']->fresh()->total_occupancy)->toBe(0);            // the seat was not left held either
});

// ------------------------------------------------------------ webhook replay

it('refuses a correctly signed webhook that is far too old, and accepts a recent one', function () {
    $f = hlFixture();
    $payment = Payment::findOrFail(hlCheckout($this, hlPassenger(), $f)->assertSuccessful()->json('data.payment.payment_id'));

    hlPaid($this, $payment, time() - 2 * 86400)->assertUnauthorized();
    expect($payment->fresh()->status)->toBe('pending');

    hlPaid($this, $payment, time() - 3600)->assertOk();               // within the window: PayMongo retries still work
    expect($payment->fresh()->status)->toBe('paid');
});

it('lets the replay window be changed, and switched off with 0', function () {
    $f = hlFixture();
    $payment = Payment::findOrFail(hlCheckout($this, hlPassenger(), $f)->assertSuccessful()->json('data.payment.payment_id'));

    config(['services.paymongo.webhook_tolerance_s' => 60]);
    hlPaid($this, $payment, time() - 3600)->assertUnauthorized();

    config(['services.paymongo.webhook_tolerance_s' => 0]);
    hlPaid($this, $payment, time() - 30 * 86400)->assertOk();
});
