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

// The last-seat race, one after the other, on every test run (SQLite): the second passenger is refused and leaves no
// payment and no held seat. LastSeatRaceTest runs the same race at the same moment on PostgreSQL.

it('refuses the second of two passengers for the last seat, without a payment or a held seat', function () {
    [$trip, $payload] = lsOneSeatTrip();
    $first = lsPassenger('First');
    $second = lsPassenger('Second');
    config(['services.paymongo.secret_key' => 'sk_test_race']);
    Http::fake(['api.paymongo.com/*' => fn () => Http::response(['data' => ['id' => 'cs_'.Str::random(12), 'attributes' => ['checkout_url' => 'https://pay.example/x', 'payment_intent' => ['id' => 'pi_'.Str::random(8)]]]])]);
    $service = app(PaymentService::class);

    $service->checkoutOnline($first->passenger_id, null, $payload, 'race-first-'.Str::random(20));

    try {
        $service->checkoutOnline($second->passenger_id, null, $payload, 'race-second-'.Str::random(20));
        $this->fail('The second passenger got a seat that was not there.');
    } catch (ValidationException $e) {
        expect(collect($e->errors())->flatten()->first())->toBe('This trip is full.');
    }

    expect((int) $trip->fresh()->current_seated_capacity)->toBe(1);
    expect(Payment::count())->toBe(1)->and(Ticket::count())->toBe(0);
});
