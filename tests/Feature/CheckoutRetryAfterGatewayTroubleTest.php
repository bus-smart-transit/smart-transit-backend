<?php

use App\Models\FareRule;
use App\Models\Fleet;
use App\Models\FleetRoute;
use App\Models\Payment;
use App\Models\Route;
use App\Models\RouteStop;
use App\Models\StaffUser;
use App\Models\Stop;
use App\Models\Trip;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

// §12 (2026-10-26): a retried checkout used to be refused for the whole 15-minute hold. Now, when the earlier attempt
// is over (PayMongo refused it, or did not answer and 2 minutes have passed), the retry is told to start fresh and the
// seats are free again. A checkout whose link reached the passenger keeps its hold.

function crTrip(): array
{
    $user = User::create(['username' => 'cr'.Str::random(8), 'email' => Str::random(8).'@example.com', 'password' => bcrypt('password123'), 'role' => 'operator']);
    StaffUser::create(['user_id' => $user->user_id, 'company_user_uuid' => (string) Str::uuid(), 'name' => 'Cr Operator', 'phone_num' => '09170000090', 'address' => 'x']);
    $operator = $user->fresh();
    $route = Route::create(['route_name' => 'Cr Route', 'origin' => 'A', 'destination' => 'C']);
    $stops = [];
    foreach ([['A', 0, 'terminal'], ['B', 10, 'stop'], ['C', 20, 'terminal']] as $i => [$name, $km, $type]) {
        $stop = Stop::create(['stop_name' => "Stop $name", 'latitude' => 7.0 + $km / 100, 'longitude' => 125.0, 'municipality' => 'Mati City']);
        RouteStop::create(['route_id' => $route->route_id, 'stop_id' => $stop->stop_id, 'stop_order' => $i + 1, 'distance_from_origin_km' => $km, 'stop_type' => $type, 'scheduled_offset_min' => $km]);
        $stops[$name] = $stop;
    }
    $fleet = Fleet::create(['company_user_id' => $operator->companyProfile->company_user_id, 'plate_number' => 'CR-'.fake()->unique()->numerify('####'), 'capacity' => 40, 'seated_capacity' => 30, 'standing_capacity' => 10, 'status' => 'active', 'vehicle_class' => 'aircon']);
    $fleetRoute = FleetRoute::create(['fleet_id' => $fleet->fleet_id, 'route_id' => $route->route_id, 'start_time' => '06:00', 'end_time' => '22:00', 'status' => 'active']);
    $trip = Trip::create(['fleet_route_id' => $fleetRoute->fleet_route_id, 'company_user_id' => $operator->companyProfile->company_user_id, 'trip_date' => '2026-10-05', 'departure_time' => '08:00', 'trip_type' => 'one_way', 'leg_direction' => 'outbound', 'status' => 'scheduled', 'current_seated_capacity' => 0, 'current_standing_capacity' => 0, 'total_occupancy' => 0]);
    FareRule::create(['fleet_id' => null, 'vehicle_class' => 'aircon', 'service_type' => 'regular', 'seat_type' => 'seated', 'status' => 'active', 'base_fare' => 13, 'fare_per_km' => 2.25, 'base_distance_km' => 5, 'note' => 'SAMPLE']);

    return [$trip, $stops];
}

function crCheckout($test, Trip $trip, array $stops, string $key)
{
    return $test->withHeaders(['Idempotency-Key' => $key])->postJson('/api/checkout', [
        'items' => [['trip_id' => $trip->trip_id, 'seat_type' => 'seated', 'origin_stop_id' => $stops['A']->stop_id, 'destination_stop_id' => $stops['C']->stop_id, 'booking_date' => '2026-10-05']],
        'payment_channel' => 'gcash', 'guest_email' => 'guest@example.com',
    ]);
}

beforeEach(function () {
    Carbon::setTestNow(Carbon::parse('2026-10-04 07:00:00', 'Asia/Manila'));
    Cache::flush();
    config(['services.paymongo.secret_key' => 'sk_test_dummy']);
});
afterEach(fn () => Carbon::setTestNow());

it('tells a retry of a refused checkout to start fresh at once, with the seats already free', function () {
    [$trip, $stops] = crTrip();
    $key = 'key-'.Str::random(24);
    Http::fake(['api.paymongo.com/*' => Http::response(['errors' => [['detail' => 'bad request']]], 400)]);

    expect(crCheckout($this, $trip, $stops, $key)->status())->toBeGreaterThanOrEqual(400);
    expect($trip->fresh()->total_occupancy)->toBe(0);

    crCheckout($this, $trip, $stops, $key)->assertStatus(409)
        ->assertJsonPath('errors.checkout.0', 'retry_with_new_key')
        ->assertJsonPath('message', 'Your last attempt did not go through, and its seats were released. Try again.');
});

it('holds an unanswered checkout for 2 minutes, then releases it and lets a fresh checkout start', function () {
    [$trip, $stops] = crTrip();
    $key = 'key-'.Str::random(24);
    // One stub that can come back up (Http::fake keeps the first stub it is given).
    $gatewayDown = true;
    Http::fake(['api.paymongo.com/*' => function () use (&$gatewayDown) {
        return $gatewayDown
            ? Http::response(['errors' => [['detail' => 'down']]], 503)
            : Http::response(['data' => ['id' => 'cs_'.Str::random(10), 'attributes' => ['checkout_url' => 'https://pay.example/x', 'payment_intent' => ['id' => 'pi_'.Str::random(8)]]]]);
    }]);

    crCheckout($this, $trip, $stops, $key)->assertStatus(503); // the outcome is unknown
    $payment = Payment::firstOrFail();
    expect($payment->idempotency_state)->toBe('unknown')->and($trip->fresh()->total_occupancy)->toBe(1);

    // Straight away: still held, and not yet a fresh start.
    crCheckout($this, $trip, $stops, $key)->assertStatus(409)->assertJsonPath('errors', null);
    expect($trip->fresh()->total_occupancy)->toBe(1);

    // After the 2-minute window: released, and the retry is told to start fresh.
    Carbon::setTestNow(now()->addMinutes(3));
    crCheckout($this, $trip, $stops, $key)->assertStatus(409)->assertJsonPath('errors.checkout.0', 'retry_with_new_key');
    expect($trip->fresh()->total_occupancy)->toBe(0)->and($payment->fresh()->status)->toBe('failed');

    // PayMongo is back: a fresh key now goes through.
    $gatewayDown = false;
    crCheckout($this, $trip, $stops, 'key-'.Str::random(24))->assertSuccessful();
});

it('keeps the hold of a checkout whose link reached the passenger, and replays it', function () {
    [$trip, $stops] = crTrip();
    $key = 'key-'.Str::random(24);
    Http::fake(['api.paymongo.com/*' => Http::response(['data' => ['id' => 'cs_delivered', 'attributes' => ['checkout_url' => 'https://pay.example/x', 'payment_intent' => ['id' => 'pi_x']]]])]);

    crCheckout($this, $trip, $stops, $key)->assertSuccessful();
    Carbon::setTestNow(now()->addMinutes(5));

    crCheckout($this, $trip, $stops, $key)->assertSuccessful()->assertJsonPath('data.checkout_url', 'https://pay.example/x');
    expect($trip->fresh()->total_occupancy)->toBe(1)->and(Payment::count())->toBe(1);
});
