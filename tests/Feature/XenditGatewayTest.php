<?php

use App\Models\DiscountClaim;
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
use App\Services\DiscountClaimService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

// 2026-11-26 (user): PayMongo closed the test account, so Xendit is the online payment provider (PAYMENT_PROVIDER=xendit).
// The passenger pays on Xendit's page; only Xendit's webhook (with the dashboard's token) confirms it. SAMPLE values:
// B -> D is 20 km, 13 + 15 x 2.25 = 46.75, PHP 47 at the whole peso.

const XND_TOKEN = 'xnd-callback-token-sample';

function xndStaff(string $role): User
{
    $user = User::create(['username' => 'xnd'.Str::random(8), 'email' => Str::random(8).'@example.com', 'password' => bcrypt('password123'), 'role' => $role]);
    StaffUser::create(['user_id' => $user->user_id, 'company_user_uuid' => (string) Str::uuid(), 'name' => 'Xnd '.ucfirst($role), 'phone_num' => '09170000088', 'address' => 'x']);

    return $user->fresh();
}

function xndPassenger(): PassengerUser
{
    $user = User::create(['username' => 'pax'.Str::random(6), 'email' => Str::random(8).'@example.com', 'password' => bcrypt('password123'), 'role' => 'passenger']);

    return PassengerUser::create(['passenger_uuid' => (string) Str::uuid(), 'user_id' => $user->user_id, 'name' => 'Pax', 'phone_num' => '09170000089', 'address' => 'x', 'birthdate' => '2000-01-01', 'reward_points' => 0]);
}

function xndFixture(): array
{
    $operator = xndStaff('operator');
    $route = Route::create(['route_name' => 'Xnd Route', 'origin' => 'A', 'destination' => 'E']);
    $stops = [];
    foreach ([['A', 0, 'terminal'], ['B', 10, 'stop'], ['C', 20, 'stop'], ['D', 30, 'stop'], ['E', 40, 'terminal']] as $i => [$name, $km, $type]) {
        $stop = Stop::create(['stop_name' => "Stop $name", 'latitude' => 7.0 + $km / 100, 'longitude' => 125.0, 'municipality' => 'Sample City']);
        RouteStop::create(['route_id' => $route->route_id, 'stop_id' => $stop->stop_id, 'stop_order' => $i + 1, 'distance_from_origin_km' => $km, 'stop_type' => $type, 'scheduled_offset_min' => $km]);
        $stops[$name] = $stop;
    }
    $fleet = Fleet::create([
        'company_user_id' => $operator->companyProfile->company_user_id, 'plate_number' => 'XND-'.fake()->unique()->numerify('####'),
        'bus_company_name' => 'Co X', 'body_no' => '88', 'capacity' => 30, 'seated_capacity' => 30, 'standing_capacity' => 0, 'status' => 'active', 'vehicle_class' => 'aircon',
    ]);
    $fleetRoute = FleetRoute::create(['fleet_id' => $fleet->fleet_id, 'route_id' => $route->route_id, 'start_time' => '06:00', 'end_time' => '22:00', 'status' => 'active']);
    $trip = Trip::create([
        'fleet_route_id' => $fleetRoute->fleet_route_id, 'company_user_id' => $operator->companyProfile->company_user_id, 'trip_date' => '2026-10-05', 'departure_time' => '08:00',
        'trip_type' => 'one_way', 'leg_direction' => 'outbound', 'status' => 'scheduled', 'current_seated_capacity' => 0, 'current_standing_capacity' => 0, 'total_occupancy' => 0,
    ]);
    FareRule::create(['fleet_id' => null, 'vehicle_class' => 'aircon', 'service_type' => 'regular', 'seat_type' => 'seated', 'status' => 'active', 'base_fare' => 13, 'fare_per_km' => 2.25, 'base_distance_km' => 5, 'note' => 'SAMPLE']);

    return compact('stops', 'trip');
}

function xndCheckout($test, PassengerUser $passenger, array $f, array $extra = [], string $channel = 'gcash')
{
    $item = ['trip_id' => $f['trip']->trip_id, 'seat_type' => 'seated', 'origin_stop_id' => $f['stops']['B']->stop_id, 'destination_stop_id' => $f['stops']['D']->stop_id] + $extra;

    return $test->actingAs($passenger->user)->postJson('/api/checkout', ['booking_date' => '2026-10-05', 'payment_channel' => $channel, 'items' => [$item]]);
}

function xndWebhook($test, string $event, array $data, string $token = XND_TOKEN)
{
    return $test->postJson('/api/webhooks/xendit', ['event' => $event, 'business_id' => 'biz-sample', 'created' => now()->toIso8601String(), 'data' => $data], ['x-callback-token' => $token]);
}

beforeEach(function () {
    Carbon::setTestNow(Carbon::parse('2026-10-04 07:00:00', 'Asia/Manila'));
    Cache::flush();
    config([
        'services.payments.provider' => 'xendit',
        'services.xendit.secret_key' => 'xnd_development_sample',
        'services.xendit.webhook_token' => XND_TOKEN,
    ]);
    Http::fake([
        'api.xendit.co/sessions' => fn (HttpRequest $request) => Http::response([
            'payment_session_id' => 'ps-sample'.Str::random(6), 'status' => 'ACTIVE', 'reference_id' => $request['reference_id'],
            'payment_link_url' => 'https://checkout-staging.xendit.co/session/sample', 'amount' => $request['amount'],
        ], 201),
        'api.xendit.co/v3/refunds' => Http::response(['refund_id' => 'rfd-sample-1', 'status' => 'PENDING', 'amount' => 9, 'payment_id' => 'py-sample-1', 'reference_id' => 'RFD-X'], 200),
    ]);
});
afterEach(fn () => Carbon::setTestNow());

it('opens a Xendit payment page for GCash with the fares and the fee, and keeps the session id', function () {
    $f = xndFixture();
    $response = xndCheckout($this, xndPassenger(), $f)->assertSuccessful()
        ->assertJsonPath('data.checkout_url', 'https://checkout-staging.xendit.co/session/sample');
    $payment = Payment::findOrFail($response->json('data.payment.payment_id'));

    expect($payment->gateway_reference)->toStartWith('ps-sample')->and($payment->status)->toBe('pending');
    Http::assertSent(fn (HttpRequest $request) => $request->url() === 'https://api.xendit.co/sessions'
        && $request['reference_id'] === $payment->transaction_reference
        && $request['allowed_payment_channels'] === ['GCASH']
        && $request['currency'] === 'PHP' && $request['mode'] === 'PAYMENT_LINK'
        && abs($request['amount'] - round(47 + (float) $payment->fee_amount, 2)) < 0.001);
});

it('asks for Maya when the passenger chose Maya', function () {
    xndCheckout($this, xndPassenger(), xndFixture(), [], 'maya')->assertSuccessful();
    Http::assertSent(fn (HttpRequest $request) => str_ends_with($request->url(), '/sessions') && $request['allowed_payment_channels'] === ['PAYMAYA']);
});

it('refuses a webhook without the right token and changes nothing', function () {
    $f = xndFixture();
    $payment = Payment::findOrFail(xndCheckout($this, xndPassenger(), $f)->json('data.payment.payment_id'));

    xndWebhook($this, 'payment_session.completed', ['payment_session_id' => $payment->gateway_reference, 'reference_id' => $payment->transaction_reference, 'payment_id' => 'py-1'], 'wrong')->assertStatus(401);
    expect($payment->fresh()->status)->toBe('pending')->and(Ticket::where('payment_id', $payment->payment_id)->count())->toBe(0);
});

it('issues the ticket once when Xendit says the payment session completed', function () {
    $f = xndFixture();
    $payment = Payment::findOrFail(xndCheckout($this, xndPassenger(), $f, ['baggage' => true, 'baggage_count' => 2])->json('data.payment.payment_id'));
    $data = ['payment_session_id' => $payment->gateway_reference, 'reference_id' => $payment->transaction_reference, 'payment_id' => 'py-sample-1', 'status' => 'COMPLETED'];

    xndWebhook($this, 'payment_session.completed', $data)->assertOk();
    xndWebhook($this, 'payment_session.completed', $data)->assertOk(); // a repeated delivery changes nothing

    $payment = $payment->fresh();
    $tickets = Ticket::where('payment_id', $payment->payment_id)->get();
    expect($payment->status)->toBe('paid')->and($payment->gateway_payment_id)->toBe('py-sample-1')
        ->and($tickets)->toHaveCount(1)->and($tickets[0]->baggage_count)->toBe(2);
});

it('fails the payment when its page expires unpaid', function () {
    $f = xndFixture();
    $payment = Payment::findOrFail(xndCheckout($this, xndPassenger(), $f)->json('data.payment.payment_id'));

    xndWebhook($this, 'payment_session.expired', ['payment_session_id' => $payment->gateway_reference, 'reference_id' => $payment->transaction_reference, 'status' => 'EXPIRED'])->assertOk();

    expect($payment->fresh()->status)->not->toBe('pending')->and(Ticket::where('payment_id', $payment->payment_id)->count())->toBe(0);
});

it('refunds an approved discount through Xendit, settled by the refund webhook', function () {
    $f = xndFixture();
    $payment = Payment::findOrFail(xndCheckout($this, xndPassenger(), $f, ['discount_claim_key' => 'student'])->json('data.payment.payment_id'));
    xndWebhook($this, 'payment_session.completed', ['payment_session_id' => $payment->gateway_reference, 'reference_id' => $payment->transaction_reference, 'payment_id' => 'py-sample-1'])->assertOk();
    $ticket = Ticket::where('payment_id', $payment->payment_id)->firstOrFail();

    $f['trip']->update(['status' => 'boarding']);
    app(DiscountClaimService::class)->review($ticket->fresh(), true, $f['trip']->fresh(), xndStaff('conductor'));

    Http::assertSent(fn (HttpRequest $request) => str_ends_with($request->url(), '/v3/refunds')
        && $request['payment_id'] === 'py-sample-1' && abs($request['amount'] - 9) < 0.001 && $request->hasHeader('api-version', '2024-11-11'));
    $claim = DiscountClaim::where('ticket_id', $ticket->ticket_id)->firstOrFail();
    expect($claim->gateway_refund_id)->toBe('rfd-sample-1');

    xndWebhook($this, 'refund.succeeded', ['refund_id' => 'rfd-sample-1', 'payment_id' => 'py-sample-1', 'status' => 'SUCCEEDED', 'amount' => 9, 'currency' => 'PHP'])->assertOk();
    expect($claim->fresh()->gateway_status)->toBe('succeeded');
});
