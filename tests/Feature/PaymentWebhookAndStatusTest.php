<?php

use App\Models\Payment;
use App\Services\PayMongoService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

const WEBHOOK_SECRET = 'whsk_test_secret';

beforeEach(function () {
    config([
        'services.paymongo.secret_key' => 'sk_test_dummy',
        'services.paymongo.webhook_secret' => WEBHOOK_SECRET,
    ]);
});

function makeOnlinePayment(array $overrides = []): Payment
{
    return Payment::create(array_merge([
        'amount' => 80,
        'payment_created' => now(),
        'transaction_reference' => 'TXN-'.strtoupper(Str::random(10)),
        'payment_method' => 'online',
        'payment_channel' => 'gcash',
        'status' => 'pending',
        'payment_uuid' => (string) Str::uuid(),
        'gateway_reference' => 'cs_'.Str::random(12),
        'items_payload' => [],
        'hold_expires_at' => now()->addMinutes(15),
    ], $overrides));
}

function signedWebhook(string $type, array $resource, ?string $secret = WEBHOOK_SECRET): array
{
    $payload = json_encode(['data' => ['id' => 'evt_'.Str::random(8), 'type' => 'event', 'attributes' => [
        'type' => $type,
        'livemode' => false,
        'data' => $resource,
        'created_at' => time(),
        'updated_at' => time(),
    ]]]);
    $timestamp = time();
    $signature = hash_hmac('sha256', $timestamp.'.'.$payload, (string) $secret);

    return [$payload, "t={$timestamp},te={$signature},li="];
}

function postWebhook($test, string $payload, ?string $header)
{
    $server = ['CONTENT_TYPE' => 'application/json'];
    if ($header !== null) {
        $server['HTTP_PAYMONGO_SIGNATURE'] = $header;
    }

    return $test->call('POST', '/api/webhooks/paymongo', [], [], [], $server, $payload);
}

// ---------------------------------------------------------------------------
// Webhook signature verification
// ---------------------------------------------------------------------------

test('a webhook without a signature is rejected', function () {
    [$payload] = signedWebhook('checkout_session.payment.paid', ['id' => 'cs_x', 'type' => 'checkout_session', 'attributes' => []]);

    postWebhook($this, $payload, null)->assertStatus(401);
});

test('a webhook signed with the wrong secret is rejected and changes nothing', function () {
    $payment = makeOnlinePayment();
    [$payload, $header] = signedWebhook('checkout_session.payment.paid', [
        'id' => $payment->gateway_reference, 'type' => 'checkout_session', 'attributes' => [],
    ], 'attacker-secret');

    postWebhook($this, $payload, $header)->assertStatus(401);

    expect($payment->fresh()->status)->toBe('pending');
});

test('a malformed signature header is rejected with 401, not a server error', function () {
    [$payload] = signedWebhook('checkout_session.payment.paid', ['id' => 'cs_x', 'type' => 'checkout_session', 'attributes' => []]);

    postWebhook($this, $payload, 'garbage')->assertStatus(401);
});

test('an unset webhook secret rejects everything instead of accepting an empty-key signature', function () {
    config(['services.paymongo.webhook_secret' => '']);
    $payment = makeOnlinePayment();
    [$payload, $header] = signedWebhook('checkout_session.payment.paid', [
        'id' => $payment->gateway_reference, 'type' => 'checkout_session', 'attributes' => [],
    ], '');

    postWebhook($this, $payload, $header)->assertStatus(401);

    expect($payment->fresh()->status)->toBe('pending');
});

// ---------------------------------------------------------------------------
// Webhook is the source of truth; handling is idempotent
// ---------------------------------------------------------------------------

test('a valid paid webhook confirms the payment and redelivery is idempotent', function () {
    $payment = makeOnlinePayment();
    [$payload, $header] = signedWebhook('checkout_session.payment.paid', [
        'id' => $payment->gateway_reference, 'type' => 'checkout_session', 'attributes' => [],
    ]);

    postWebhook($this, $payload, $header)->assertOk();
    expect($payment->fresh()->status)->toBe('paid');

    postWebhook($this, $payload, $header)->assertOk();
    expect($payment->fresh()->status)->toBe('paid');
});

test('a valid failed webhook marks the payment failed once', function () {
    $payment = makeOnlinePayment();
    [$payload, $header] = signedWebhook('payment.failed', [
        'id' => 'pay_1', 'type' => 'payment',
        'attributes' => ['metadata' => ['payment_id' => (string) $payment->payment_id]],
    ]);

    postWebhook($this, $payload, $header)->assertOk();
    postWebhook($this, $payload, $header)->assertOk();

    expect($payment->fresh()->status)->toBe('failed');
});

test('a late failure event cannot undo a confirmed payment', function () {
    $payment = makeOnlinePayment();
    [$paid, $paidHeader] = signedWebhook('checkout_session.payment.paid', [
        'id' => $payment->gateway_reference, 'type' => 'checkout_session', 'attributes' => [],
    ]);
    [$failed, $failedHeader] = signedWebhook('payment.failed', [
        'id' => 'pay_2', 'type' => 'payment',
        'attributes' => ['metadata' => ['payment_id' => (string) $payment->payment_id]],
    ]);

    postWebhook($this, $paid, $paidHeader)->assertOk();
    postWebhook($this, $failed, $failedHeader)->assertOk();

    expect($payment->fresh()->status)->toBe('paid');
});

// ---------------------------------------------------------------------------
// Return-page status endpoint
// ---------------------------------------------------------------------------

test('status endpoint reports pending, paid, failed and expired from recorded state', function () {
    $pending = makeOnlinePayment();
    $paid = makeOnlinePayment(['status' => 'paid']);
    $failed = makeOnlinePayment(['status' => 'failed']);
    $expired = makeOnlinePayment(['hold_expires_at' => now()->subMinute()]);

    foreach ([[$pending, 'pending'], [$paid, 'paid'], [$failed, 'failed'], [$expired, 'expired']] as [$payment, $expected]) {
        $this->getJson('/api/payments/status?ref='.$payment->transaction_reference)
            ->assertOk()
            ->assertJsonPath('data.status', $expected);
    }
});

test('status endpoint exposes nothing beyond state and 404s unknown or missing refs', function () {
    $payment = makeOnlinePayment(['guest_email' => 'guest@example.com']);

    $response = $this->getJson('/api/payments/status?ref='.$payment->transaction_reference)->assertOk();
    expect(array_keys($response->json('data')))->toEqualCanonicalizing(['transaction_reference', 'status', 'hold_expires_at']);

    $this->getJson('/api/payments/status?ref=TXN-DOES-NOT-EXIST')->assertStatus(404);
    $this->getJson('/api/payments/status')->assertStatus(422);
});

// ---------------------------------------------------------------------------
// C4: no customer-information requirement, GCash/Maya only
// ---------------------------------------------------------------------------

test('checkout session sends only the chosen wallet and no billing details beyond an optional email', function () {
    Http::fake(['api.paymongo.com/*' => Http::response(['data' => ['id' => 'cs_1', 'attributes' => ['checkout_url' => 'https://pay.test']]])]);
    $service = new PayMongoService();
    $items = [['name' => 'Ticket', 'amount' => 8000, 'currency' => 'PHP', 'quantity' => 1]];

    $maya = makeOnlinePayment(['payment_channel' => 'maya']);
    $service->createCheckoutSession($maya, $items, 'https://app.test/checkout/success', 'https://app.test/checkout/cancel');

    Http::assertSent(function ($request) {
        $attrs = $request['data']['attributes'];

        return $attrs['payment_method_types'] === ['paymaya'] && ! array_key_exists('billing', $attrs);
    });

    $gcash = makeOnlinePayment(['payment_channel' => 'gcash']);
    $service->createCheckoutSession($gcash, $items, 'https://app.test/checkout/success', 'https://app.test/checkout/cancel', 'me@example.com');

    Http::assertSent(function ($request) {
        $attrs = $request['data']['attributes'];

        return $attrs['payment_method_types'] === ['gcash'] && $attrs['billing'] === ['email' => 'me@example.com'];
    });
});

test('card is no longer an accepted online payment channel', function () {
    $this->postJson('/api/checkout', [
        'payment_channel' => 'card',
        'items' => [['trip_id' => 1, 'seat_type' => 'seated', 'origin_stop_id' => 1, 'destination_stop_id' => 2]],
        'guest_email' => 'guest@example.com',
    ])->assertStatus(422)->assertJsonValidationErrors(['payment_channel']);
});
