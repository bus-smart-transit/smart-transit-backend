<?php

use App\Services\PayMongoService;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;

it('records safe API request aggregates without query values or response content', function () {
    Log::spy();
    Route::middleware('api')->get('api/_metrics-probe/{probeId}', fn () => response()->json(['secret' => 'not logged']))
        ->name('metrics.probe');

    $this->getJson('/api/_metrics-probe/123?access_token=do-not-log')
        ->assertSuccessful()
        ->assertJsonPath('secret', 'not logged');

    Log::shouldHaveReceived('info')->with('api.request', Mockery::on(function (array $context): bool {
        expect($context)->toHaveKeys(['method', 'route', 'status', 'duration_ms', 'response_bytes', 'response_rows', 'db_query_count', 'db_time_ms']);
        expect($context['method'])->toBe('GET');
        expect($context['route'])->toBe('api/_metrics-probe/{probeId}');
        expect($context['status'])->toBe(200);
        expect($context['response_bytes'])->toBeGreaterThan(0);
        expect($context['response_rows'])->toBeNull();
        expect(json_encode($context))->not->toContain('do-not-log')->not->toContain('not logged');

        return true;
    }))->once();
});

it('can disable API request instrumentation through configuration', function () {
    Log::spy();
    config(['api_instrumentation.enabled' => false]);
    Route::middleware('api')->get('api/_metrics-disabled', fn () => response()->json(['ok' => true]));

    $this->getJson('/api/_metrics-disabled')->assertOk();

    Log::shouldNotHaveReceived('info');
});

it('records provider latency and outcome without logging the hosted checkout URL', function () {
    Log::spy();
    config(['services.paymongo.secret_key' => 'sk_test_metrics']);
    Http::fake(['api.paymongo.com/*' => Http::response([
        'data' => [
            'id' => 'cs_metrics',
            'attributes' => [
                'checkout_url' => 'https://checkout.example/private-session',
                'payment_intent' => ['id' => 'pi_metrics'],
            ],
        ],
    ], 201)]);

    app(PayMongoService::class)->createCheckoutSession(
        (object) ['payment_channel' => 'gcash', 'transaction_reference' => 'METRICS-REF', 'payment_id' => 1],
        [['name' => 'Ticket', 'amount' => 1000, 'currency' => 'PHP', 'quantity' => 1]],
        'https://app.example/success',
        'https://app.example/cancel',
    );

    Log::shouldHaveReceived('info')->with('payment.provider_request', Mockery::on(function (array $context): bool {
        expect($context)->toHaveKeys(['provider', 'operation', 'outcome', 'status', 'duration_ms']);
        expect($context)->toMatchArray(['provider' => 'paymongo', 'operation' => 'checkout_session.create', 'outcome' => 'success', 'status' => 201]);
        expect(json_encode($context))->not->toContain('private-session');

        return true;
    }))->once();
});
