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
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

// The checkout return page polls GET /api/payments/status. The webhook is the normal way a payment is confirmed, but when
// it never reaches the server the page used to wait forever. The status call now asks PayMongo about the session too.

function csFixture(): array
{
    $operatorUser = User::create(['username' => 'cs'.Str::random(8), 'email' => Str::random(8).'@example.com', 'password' => bcrypt('password123'), 'role' => 'operator']);
    $operator = StaffUser::create(['user_id' => $operatorUser->user_id, 'company_user_uuid' => (string) Str::uuid(), 'name' => 'Cs Operator', 'phone_num' => '09170000041', 'address' => 'x']);
    $route = Route::create(['route_name' => 'Cs Route', 'origin' => 'A', 'destination' => 'E']);
    $stops = [];
    foreach ([['A', 0, 'terminal'], ['B', 10, 'stop'], ['D', 30, 'stop'], ['E', 40, 'terminal']] as $i => [$name, $km, $type]) {
        $stop = Stop::create(['stop_name' => "Stop $name", 'latitude' => 7.0 + $km / 100, 'longitude' => 125.0, 'municipality' => 'Mati City']);
        RouteStop::create(['route_id' => $route->route_id, 'stop_id' => $stop->stop_id, 'stop_order' => $i + 1, 'distance_from_origin_km' => $km, 'stop_type' => $type, 'scheduled_offset_min' => $km]);
        $stops[$name] = $stop;
    }
    $fleet = Fleet::create([
        'company_user_id' => $operator->company_user_id, 'plate_number' => 'CS-'.fake()->unique()->numerify('####'),
        'bus_company_name' => 'Co C', 'body_no' => '8', 'capacity' => 30, 'seated_capacity' => 30, 'standing_capacity' => 0, 'status' => 'active', 'vehicle_class' => 'aircon',
    ]);
    $fleetRoute = FleetRoute::create(['fleet_id' => $fleet->fleet_id, 'route_id' => $route->route_id, 'start_time' => '06:00', 'end_time' => '22:00', 'status' => 'active']);
    $trip = Trip::create([
        'fleet_route_id' => $fleetRoute->fleet_route_id, 'company_user_id' => $operator->company_user_id, 'trip_date' => '2026-10-05', 'departure_time' => '08:00',
        'trip_type' => 'one_way', 'leg_direction' => 'outbound', 'status' => 'scheduled', 'current_seated_capacity' => 0, 'current_standing_capacity' => 0, 'total_occupancy' => 0,
    ]);
    FareRule::create(['fleet_id' => null, 'vehicle_class' => 'aircon', 'service_type' => 'regular', 'seat_type' => 'seated', 'status' => 'active', 'base_fare' => 13, 'fare_per_km' => 2.25, 'base_distance_km' => 5, 'note' => 'SAMPLE']);

    return compact('stops', 'trip');
}

/** PayMongo fake: creating a session always works; looking it up answers whatever $state says. */
function csProvider(): ArrayObject
{
    $state = new ArrayObject(['paid' => false, 'error' => false, 'lookups' => 0]);
    Http::fake(['api.paymongo.com/*' => function ($request) use ($state) {
        if ($request->method() === 'POST') {
            return Http::response(['data' => ['id' => 'cs_fixed_session', 'attributes' => ['checkout_url' => 'https://pay.example/x', 'payment_intent' => ['id' => 'pi_fixed']]]]);
        }
        $state['lookups'] = $state['lookups'] + 1;
        if ($state['error']) {
            return Http::response(['errors' => []], 500);
        }

        return Http::response(['data' => ['id' => 'cs_fixed_session', 'attributes' => [
            'payments' => $state['paid'] ? [['id' => 'pay_confirmed', 'attributes' => ['status' => 'paid']]] : [],
            'payment_intent' => ['id' => 'pi_fixed'],
        ]]]);
    }]);

    return $state;
}

function csPendingPayment($test): Payment
{
    $f = csFixture();
    $user = User::create(['username' => 'pax'.Str::random(6), 'email' => Str::random(8).'@example.com', 'password' => bcrypt('password123'), 'role' => 'passenger']);
    $pax = PassengerUser::create(['passenger_uuid' => (string) Str::uuid(), 'user_id' => $user->user_id, 'name' => 'Pax', 'phone_num' => '09170000002', 'address' => 'x', 'birthdate' => '2000-01-01', 'reward_points' => 0]);

    $id = $test->actingAs($pax->user)->postJson('/api/checkout', [
        'booking_date' => '2026-10-05', 'payment_channel' => 'gcash',
        'items' => [['trip_id' => $f['trip']->trip_id, 'seat_type' => 'seated', 'origin_stop_id' => $f['stops']['B']->stop_id, 'destination_stop_id' => $f['stops']['D']->stop_id]],
    ])->assertSuccessful()->json('data.payment.payment_id');

    return Payment::findOrFail($id);
}

beforeEach(function () {
    Carbon::setTestNow(Carbon::parse('2026-10-04 07:00:00', 'Asia/Manila'));
    Cache::flush();
    config(['services.paymongo.secret_key' => 'sk_test_dummy', 'services.paymongo.webhook_secret' => 'whsk_cs_secret']);
});
afterEach(fn () => Carbon::setTestNow());

it('confirms a paid checkout when the webhook never arrived, issuing one ticket however often the page polls', function () {
    $provider = csProvider();
    $payment = csPendingPayment($this);
    expect(Ticket::count())->toBe(0);

    $provider['paid'] = true;
    $this->getJson('/api/payments/status?ref='.$payment->transaction_reference)->assertSuccessful()->assertJsonPath('data.status', 'paid');
    Cache::flush(); // a later poll, after the short pause between provider checks
    $this->getJson('/api/payments/status?ref='.$payment->transaction_reference)->assertSuccessful()->assertJsonPath('data.status', 'paid');

    expect(Ticket::where('payment_id', $payment->payment_id)->count())->toBe(1);
    expect($payment->fresh()->gateway_payment_id)->toBe('pay_confirmed');
});

it('keeps saying pending while PayMongo does not report a paid payment', function () {
    $provider = csProvider();
    $payment = csPendingPayment($this);

    $this->getJson('/api/payments/status?ref='.$payment->transaction_reference)->assertSuccessful()->assertJsonPath('data.status', 'pending');

    expect(Ticket::count())->toBe(0)->and($payment->fresh()->status)->toBe('pending')->and($provider['lookups'])->toBe(1);
});

it('does not fail the status call or confirm anything when PayMongo cannot be reached', function () {
    $provider = csProvider();
    $payment = csPendingPayment($this);
    $provider['error'] = true;

    $this->getJson('/api/payments/status?ref='.$payment->transaction_reference)->assertSuccessful()->assertJsonPath('data.status', 'pending');

    expect(Ticket::count())->toBe(0)->and($payment->fresh()->status)->toBe('pending');
});

it('asks PayMongo at most once in a short window however fast the page polls', function () {
    $provider = csProvider();
    $payment = csPendingPayment($this);

    foreach (range(1, 5) as $poll) {
        $this->getJson('/api/payments/status?ref='.$payment->transaction_reference)->assertSuccessful();
    }

    expect($provider['lookups'])->toBe(1);
});
