<?php

use App\Models\Payment;
use App\Models\Ticket;
use App\Services\PaymentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

require_once __DIR__.'/../Support/last_seat_fixture.php';

uses(RefreshDatabase::class);

// The last-seat race, one after the other, on every test run (SQLite). Batch 49 item 1: seats are taken only when the payment is confirmed: both
// passengers may check out; the first payment confirmed gets the seat and the second is refunded (no ticket, no seat).
// LastSeatRaceTest runs the confirmations at the same moment on PostgreSQL.

it('gives the last seat to the first payment confirmed and refunds the second, without a ticket or a seat', function () {
    [$trip, $payload] = lsOneSeatTrip();
    $first = lsPassenger('First');
    $second = lsPassenger('Second');
    config(['services.paymongo.secret_key' => 'sk_test_race']);
    Http::fake(['api.paymongo.com/*' => fn () => Http::response(['data' => ['id' => 'cs_'.Str::random(12), 'attributes' => ['checkout_url' => 'https://pay.example/x', 'payment_intent' => ['id' => 'pi_'.Str::random(8)]]]])]);
    $service = app(PaymentService::class);

    $firstPayment = $service->checkoutOnline($first->passenger_id, null, $payload, 'race-first-'.Str::random(20))['payment'];
    $secondPayment = $service->checkoutOnline($second->passenger_id, null, $payload, 'race-second-'.Str::random(20))['payment'];
    expect((int) $trip->fresh()->current_seated_capacity)->toBe(0)->and(Payment::count())->toBe(2);

    $service->finalizeOnlinePayment($firstPayment->fresh());
    $service->finalizeOnlinePayment($secondPayment->fresh());

    expect((int) $trip->fresh()->current_seated_capacity)->toBe(1); // never 2: not oversold
    expect($firstPayment->fresh()->status)->toBe('paid')->and($secondPayment->fresh()->status)->toBe('needs_refund');
    expect(Ticket::count())->toBe(1)->and(Ticket::firstOrFail()->payment_id)->toBe($firstPayment->payment_id);

    // A third passenger is now refused at checkout: the bus is full.
    try {
        $service->checkoutOnline(lsPassenger('Third')->passenger_id, null, $payload, 'race-third-'.Str::random(20));
        $this->fail('The third passenger could check out for a seat that was not there.');
    } catch (ValidationException $e) {
        expect(collect($e->errors())->flatten()->first())->toBe('This trip is full.');
    }
});
