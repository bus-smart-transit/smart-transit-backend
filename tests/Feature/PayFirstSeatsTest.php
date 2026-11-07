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
use App\Services\UnfulfilledPaymentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

uses(RefreshDatabase::class);

// Batch 49 item 1 (user, 2026-11-07): seats are taken only when the payment is confirmed, all or nothing. An unpaid
// checkout blocks no seat; a payment confirmed after the bus filled up makes no ticket, gives the reward points back
// and is refunded through PayMongo automatically. Fare numbers are SAMPLE test values (B -> D is 20 km).

const PF_SECRET = 'whsk_pf_secret';

function pfFixture(int $seated = 30): array
{
    $user = User::create(['username' => 'pf'.Str::random(8), 'email' => Str::random(8).'@example.com', 'password' => bcrypt('password123'), 'role' => 'operator']);
    StaffUser::create(['user_id' => $user->user_id, 'company_user_uuid' => (string) Str::uuid(), 'name' => 'Pf Operator', 'phone_num' => '09170000061', 'address' => 'x']);
    $operator = $user->fresh();
    $route = Route::create(['route_name' => 'Pf Route', 'origin' => 'A', 'destination' => 'E']);
    $stops = [];
    foreach ([['A', 0, 'terminal'], ['B', 10, 'stop'], ['D', 30, 'stop'], ['E', 40, 'terminal']] as $i => [$name, $km, $type]) {
        $stop = Stop::create(['stop_name' => "Stop $name", 'latitude' => 7.0 + $km / 100, 'longitude' => 125.0, 'municipality' => 'Mati City']);
        RouteStop::create(['route_id' => $route->route_id, 'stop_id' => $stop->stop_id, 'stop_order' => $i + 1, 'distance_from_origin_km' => $km, 'stop_type' => $type, 'scheduled_offset_min' => $km]);
        $stops[$name] = $stop;
    }
    $fleet = Fleet::create([
        'company_user_id' => $operator->companyProfile->company_user_id, 'plate_number' => 'PF-'.fake()->unique()->numerify('####'),
        'bus_company_name' => 'Co P', 'body_no' => '9', 'capacity' => $seated, 'seated_capacity' => $seated, 'standing_capacity' => 0, 'status' => 'active', 'vehicle_class' => 'aircon',
    ]);
    $fleetRoute = FleetRoute::create(['fleet_id' => $fleet->fleet_id, 'route_id' => $route->route_id, 'start_time' => '06:00', 'end_time' => '22:00', 'status' => 'active']);
    $trip = Trip::create([
        'fleet_route_id' => $fleetRoute->fleet_route_id, 'company_user_id' => $operator->companyProfile->company_user_id, 'trip_date' => '2026-10-05', 'departure_time' => '08:00',
        'trip_type' => 'one_way', 'leg_direction' => 'outbound', 'status' => 'scheduled', 'current_seated_capacity' => 0, 'current_standing_capacity' => 0, 'total_occupancy' => 0,
    ]);
    FareRule::create(['fleet_id' => null, 'vehicle_class' => 'aircon', 'service_type' => 'regular', 'seat_type' => 'seated', 'status' => 'active', 'base_fare' => 13, 'fare_per_km' => 2.25, 'base_distance_km' => 5, 'note' => 'SAMPLE']);

    return compact('operator', 'stops', 'trip');
}

function pfPassenger(int $points = 0): PassengerUser
{
    $user = User::create(['username' => 'pax'.Str::random(6), 'email' => Str::random(8).'@example.com', 'password' => bcrypt('password123'), 'role' => 'passenger']);

    return PassengerUser::create(['passenger_uuid' => (string) Str::uuid(), 'user_id' => $user->user_id, 'name' => 'Pax', 'phone_num' => '09170000002', 'address' => 'x', 'birthdate' => '2000-01-01', 'reward_points' => $points]);
}

function pfCheckout($test, PassengerUser $passenger, array $f, int $qty, int $points = 0)
{
    $item = ['trip_id' => $f['trip']->trip_id, 'seat_type' => 'seated', 'origin_stop_id' => $f['stops']['B']->stop_id, 'destination_stop_id' => $f['stops']['D']->stop_id];

    return $test->actingAs($passenger->user)->postJson('/api/passengers/checkout', array_filter([
        'booking_date' => '2026-10-05', 'payment_channel' => 'gcash', 'reward_points_to_use' => $points ?: null, 'items' => array_fill(0, $qty, $item),
    ], fn ($v) => $v !== null));
}

function pfWebhook($test, string $type, array $resource)
{
    $payload = json_encode(['data' => ['id' => 'evt_'.Str::random(8), 'type' => 'event', 'attributes' => ['type' => $type, 'livemode' => false, 'data' => $resource, 'created_at' => time(), 'updated_at' => time()]]]);
    $ts = time();
    $sig = hash_hmac('sha256', $ts.'.'.$payload, PF_SECRET);

    return $test->call('POST', '/api/webhooks/paymongo', [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_PAYMONGO_SIGNATURE' => "t={$ts},te={$sig},li="], $payload);
}

function pfPaid($test, Payment $payment)
{
    return pfWebhook($test, 'checkout_session.payment.paid', ['id' => $payment->gateway_reference, 'type' => 'checkout_session', 'attributes' => ['payments' => [['id' => 'pay_'.$payment->payment_id]]]]);
}

function pfRefundEvent($test, string $refundId, string $status)
{
    return pfWebhook($test, 'payment.refund.updated', ['id' => $refundId, 'type' => 'refund', 'attributes' => ['status' => $status, 'amount' => 0]]);
}

function pfSold(array $f): int
{
    return (int) $f['trip']->fresh()->current_seated_capacity;
}

/** @return list<array<string, mixed>> the refund requests sent to PayMongo */
function pfRefundsSent(): array
{
    return Http::recorded(fn ($request) => str_contains($request->url(), '/v1/refunds'))->map(fn ($pair) => $pair[0]->data()['data']['attributes'])->values()->all();
}

beforeEach(function () {
    Carbon::setTestNow(Carbon::parse('2026-10-04 07:00:00', 'Asia/Manila'));
    Cache::flush();
    config(['services.paymongo.secret_key' => 'sk_test_dummy', 'services.paymongo.webhook_secret' => PF_SECRET]);
    Http::fake([
        // A test that needs PayMongo to refuse the refund sets pf.refund_refused (fakes stack, so one stub decides).
        'api.paymongo.com/v1/refunds' => fn () => config('pf.refund_refused')
            ? Http::response(['errors' => [['detail' => 'Refunds are not available.']]], 400)
            : Http::response(['data' => ['id' => 'ref_pf_1', 'type' => 'refund', 'attributes' => ['status' => 'pending']]]),
        'api.paymongo.com/*' => fn () => Http::response(['data' => ['id' => 'cs_'.Str::random(12), 'attributes' => ['checkout_url' => 'https://pay.example/x', 'payment_intent' => ['id' => 'pi_'.Str::random(8)]]]]),
    ]);
});
afterEach(fn () => Carbon::setTestNow());

it('takes no seat at checkout and gives nothing back when the checkout expires', function () {
    $f = pfFixture();
    pfCheckout($this, pfPassenger(), $f, 16)->assertSuccessful();

    expect(pfSold($f))->toBe(0)
        ->and(Payment::first()->items_payload[0]['seat_held'])->toBeFalse();

    Carbon::setTestNow(Carbon::parse('2026-10-04 07:20:00', 'Asia/Manila'));
    Artisan::call('payments:release-expired-holds');

    expect(Payment::first()->status)->toBe('expired')
        ->and(pfSold($f))->toBe(0);
});

it('takes the seats when the payment is confirmed', function () {
    $f = pfFixture();
    pfCheckout($this, pfPassenger(), $f, 3)->assertSuccessful();
    pfPaid($this, Payment::first())->assertOk();

    expect(Payment::first()->status)->toBe('paid')
        ->and(Ticket::count())->toBe(3)
        ->and(pfSold($f))->toBe(3)
        ->and(pfRefundsSent())->toBe([]);
});

it('refuses a checkout for more seats than are free right now', function () {
    $f = pfFixture(5);
    pfCheckout($this, pfPassenger(), $f, 6)
        ->assertStatus(422)
        ->assertJsonFragment(['Only 5 seats are left on this bus. Choose fewer tickets.']);
});

it('refuses more tickets in one purchase than the limit', function () {
    config(['booking.max_seats_per_purchase' => 4]);
    $f = pfFixture();
    pfCheckout($this, pfPassenger(), $f, 5)
        ->assertStatus(422)
        ->assertJsonFragment(['You can buy up to 4 tickets in one purchase.']);

    expect(Payment::count())->toBe(0);
});

it('lets two buyers check out for the last seats; the first to pay gets them and the second is refunded automatically', function () {
    $f = pfFixture(2);
    $second = pfPassenger(10);
    pfCheckout($this, pfPassenger(), $f, 2)->assertSuccessful();
    app('auth')->forgetGuards();
    pfCheckout($this, $second, $f, 2, points: 5)->assertSuccessful();
    [$firstPayment, $secondPayment] = Payment::orderBy('payment_id')->get()->all();
    expect(pfSold($f))->toBe(0)
        ->and($second->fresh()->reward_points)->toEqual(5);

    pfPaid($this, $firstPayment)->assertOk();
    pfPaid($this, $secondPayment)->assertOk();

    expect($firstPayment->fresh()->status)->toBe('paid')
        ->and(Ticket::where('payment_id', $firstPayment->payment_id)->count())->toBe(2)
        ->and($secondPayment->fresh()->status)->toBe('needs_refund')
        ->and(Ticket::where('payment_id', $secondPayment->payment_id)->count())->toBe(0)
        ->and(pfSold($f))->toBe(2)
        ->and($second->fresh()->reward_points)->toEqual(10); // the 5 points came back

    // The whole amount the second buyer paid went back through PayMongo, once.
    $sent = pfRefundsSent();
    $paid = $secondPayment->fresh();
    expect($sent)->toHaveCount(1)
        ->and($sent[0]['payment_id'])->toBe('pay_'.$paid->payment_id)
        ->and($sent[0]['amount'])->toBe(max(100, (int) round((float) $paid->amount * 100)) + (int) round((float) $paid->fee_amount * 100))
        ->and($paid->gateway_refund_id)->toBe('ref_pf_1')
        ->and(DB::table('audit_logs')->where('action', 'payment.refund_submitted')->count())->toBe(1);

    // A redelivered paid event neither issues tickets nor refunds again.
    pfPaid($this, $secondPayment)->assertOk();
    expect(Ticket::where('payment_id', $secondPayment->payment_id)->count())->toBe(0)
        ->and(pfRefundsSent())->toHaveCount(1);
});

it('marks the payment refunded when PayMongo confirms the automatic refund, once', function () {
    $f = pfFixture(1);
    pfCheckout($this, pfPassenger(), $f, 1)->assertSuccessful();
    app('auth')->forgetGuards();
    pfCheckout($this, pfPassenger(), $f, 1)->assertSuccessful();
    [$first, $second] = Payment::orderBy('payment_id')->get()->all();
    pfPaid($this, $first);
    pfPaid($this, $second);

    pfRefundEvent($this, 'ref_pf_1', 'succeeded')->assertOk();
    pfRefundEvent($this, 'ref_pf_1', 'succeeded')->assertOk();

    $refunded = $second->fresh();
    expect($refunded->status)->toBe('refunded')
        ->and($refunded->refunded_at)->not->toBeNull()
        ->and($refunded->refunded_by_user_id)->toBeNull()
        ->and(DB::table('audit_logs')->where('action', 'payment.refund_completed')->count())->toBe(1);

    $row = app(UnfulfilledPaymentService::class)->paginate(null, ['status' => 'refunded'])['data'][0];
    expect($row['refunded_automatically'])->toBeTrue();
});

it('hands the payment back to the Operator when PayMongo reports the automatic refund failed', function () {
    $f = pfFixture(1);
    pfCheckout($this, pfPassenger(), $f, 1)->assertSuccessful();
    app('auth')->forgetGuards();
    pfCheckout($this, pfPassenger(), $f, 1)->assertSuccessful();
    [$first, $second] = Payment::orderBy('payment_id')->get()->all();
    pfPaid($this, $first);
    pfPaid($this, $second);

    // While PayMongo has it, nobody can record a refund by hand.
    $service = app(UnfulfilledPaymentService::class);
    $companyUserId = (int) $f['operator']->companyProfile->company_user_id;
    expect(fn () => $service->recordRefund((int) $second->payment_id, $companyUserId, $f['operator'], 'rf_hand'))
        ->toThrow(ValidationException::class);
    expect($service->paginate($companyUserId, [])['data'][0]['automatic_refund_pending'])->toBeTrue();

    pfRefundEvent($this, 'ref_pf_1', 'failed')->assertOk();

    $waiting = $second->fresh();
    expect($waiting->status)->toBe('needs_refund')
        ->and($waiting->gateway_refund_id)->toBeNull()
        ->and($waiting->refund_note)->toBe('Automatic refund failed at PayMongo. Refund it by hand.');

    $service->recordRefund((int) $second->payment_id, $companyUserId, $f['operator'], 'rf_hand');
    expect($second->fresh()->status)->toBe('refunded');
});

it('leaves the payment for a refund by hand when the automatic refund cannot be sent', function () {
    config(['pf.refund_refused' => true]);
    $f = pfFixture(1);
    pfCheckout($this, pfPassenger(), $f, 1)->assertSuccessful();
    app('auth')->forgetGuards();
    pfCheckout($this, pfPassenger(), $f, 1)->assertSuccessful();
    [$first, $second] = Payment::orderBy('payment_id')->get()->all();
    pfPaid($this, $first);
    pfPaid($this, $second);

    $waiting = $second->fresh();
    expect($waiting->status)->toBe('needs_refund')
        ->and($waiting->gateway_refund_id)->toBeNull()
        ->and($waiting->refund_note)->toBe('Automatic refund failed. Refund it by hand.')
        ->and(DB::table('audit_logs')->where('action', 'payment.refund_failed')->count())->toBe(1);
});

it('still gives back the seats of a checkout made before the change, which held them', function () {
    $f = pfFixture();
    pfCheckout($this, pfPassenger(), $f, 2)->assertSuccessful();
    // An older checkout: its seats were taken at checkout and its items carry no seat_held flag.
    $payment = Payment::first();
    $payment->items_payload = collect($payment->items_payload)->map(fn ($item) => collect($item)->except('seat_held')->all())->all();
    $payment->save();
    $f['trip']->update(['current_seated_capacity' => 2, 'total_occupancy' => 2]);

    Carbon::setTestNow(Carbon::parse('2026-10-04 07:20:00', 'Asia/Manila'));
    Artisan::call('payments:release-expired-holds');

    expect(Payment::first()->status)->toBe('expired')
        ->and(pfSold($f))->toBe(0);
});

it('shows the purchase limit in the booking window', function () {
    $this->getJson('/api/booking/window')->assertOk()->assertJsonPath('data.max_seats_per_purchase', 20);
});
