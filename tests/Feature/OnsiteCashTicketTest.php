<?php

use App\Models\Fleet;
use App\Models\FleetDailyPin;
use App\Models\FleetRoute;
use App\Models\FareRule;
use App\Models\Route;
use App\Models\RouteStop;
use App\Models\StaffUser;
use App\Models\Stop;
use App\Models\Trip;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

// Batch 27, Task 2: the Chauffeur's Manual Cash Ticket.
// Root cause fixed here: GET conductor/trips/current did not include the route's
// stops, so the origin/destination lists were empty and nothing could be sold.
// The fare is always computed on the server; the issuer and the server time are
// recorded; a full bus refuses the sale.

function cashStaff(string $role): User
{
    $user = User::create([
        'username' => fake()->unique()->userName(),
        'email' => fake()->unique()->safeEmail(),
        'password' => bcrypt('password123'),
        'role' => $role,
    ]);
    StaffUser::create([
        'user_id' => $user->user_id,
        'company_user_uuid' => (string) Str::uuid(),
        'name' => 'Cash ' . ucfirst($role),
        'phone_num' => '09170000027',
        'address' => 'Test Address',
    ]);

    return $user->fresh();
}

/** Three stops 0, 5 and 10 km apart; fare rule 15 + 2.00 per km. */
function cashFixture(int $seated = 30, int $standing = 10): array
{
    $operator = cashStaff('operator');
    $driver = cashStaff('driver');
    $conductor = cashStaff('conductor');

    $route = Route::create(['route_name' => 'Cash Route', 'origin' => 'A', 'destination' => 'C']);
    $stops = [];
    foreach ([['Stop A', 0, 'terminal'], ['Stop B', 5, 'stop'], ['Stop C', 10, 'terminal']] as $i => [$name, $km, $type]) {
        $stop = Stop::create(['stop_name' => $name, 'latitude' => 7.0 + $km / 100, 'longitude' => 125.0]);
        RouteStop::create([
            'route_id' => $route->route_id, 'stop_id' => $stop->stop_id, 'stop_order' => $i + 1,
            'distance_from_origin_km' => $km, 'stop_type' => $type,
        ]);
        $stops[$name] = $stop;
    }

    $fleet = Fleet::create([
        'company_user_id' => $operator->companyProfile->company_user_id,
        'plate_number' => 'CASH-' . fake()->unique()->numerify('###'),
        'capacity' => $seated + $standing, 'seated_capacity' => $seated, 'standing_capacity' => $standing, 'status' => 'active',
    ]);
    FareRule::create(['fleet_id' => $fleet->fleet_id, 'base_fare' => 15, 'fare_per_km' => 2, 'status' => 'active', 'seat_type' => 'seated']);
    $fleetRoute = FleetRoute::create(['fleet_id' => $fleet->fleet_id, 'route_id' => $route->route_id, 'start_time' => '06:00', 'end_time' => '22:00', 'status' => 'active']);

    $trip = Trip::create([
        'fleet_route_id' => $fleetRoute->fleet_route_id,
        'company_user_id' => $operator->companyProfile->company_user_id,
        'driver_id' => $driver->companyProfile->company_user_id,
        'conductor_id' => $conductor->companyProfile->company_user_id,
        'trip_date' => now('Asia/Manila')->toDateString(),
        'departure_time' => '08:00',
        'trip_type' => 'one_way',
        'leg_direction' => 'outbound',
        'status' => 'boarding',
        'current_seated_capacity' => 0, 'current_standing_capacity' => 0, 'total_occupancy' => 0,
    ]);

    FleetDailyPin::create([
        'trip_id' => $trip->trip_id,
        'driver_id' => $driver->companyProfile->company_user_id,
        'conductor_id' => $conductor->companyProfile->company_user_id,
        'pin_code' => '123456',
        'pin_date' => now('Asia/Manila')->toDateString(),
        'driver_verified_at' => now(),
        'conductor_verified_at' => now(),
        'paired_at' => now(),
    ]);

    return [$conductor, $trip, $stops, $fleet];
}

function cashSale($test, User $conductor, Trip $trip, array $stops, array $extra = [], string $seat = 'seated')
{
    return $test->actingAs($conductor)->postJson('/api/conductor/checkout', ['items' => [array_merge([
        'trip_id' => $trip->trip_id,
        'seat_type' => $seat,
        'origin_stop_id' => $stops['Stop A']->stop_id,
        'destination_stop_id' => $stops['Stop C']->stop_id,
    ], $extra)]]);
}

it('gives the Chauffeur the route stops with the current trip, so the cash ticket form can be filled', function () {
    [$conductor, $trip] = cashFixture();
    $this->actingAs($conductor)->postJson('/api/conductor/shift/start')->assertSuccessful();

    $routeStops = $this->actingAs($conductor)->getJson('/api/conductor/trips/current')
        ->assertSuccessful()
        ->json('data.fleet_route.route.route_stops');

    expect($routeStops)->toHaveCount(3);
    expect(array_column(array_column($routeStops, 'stop'), 'stop_name'))->toBe(['Stop A', 'Stop B', 'Stop C']);
    expect(array_column($routeStops, 'stop_order'))->toBe([1, 2, 3]);
});

it('prices a cash ticket on the server and ignores any fare the client sends', function () {
    [$conductor, $trip, $stops] = cashFixture();
    $this->actingAs($conductor)->postJson('/api/conductor/shift/start')->assertSuccessful();

    $response = cashSale($this, $conductor, $trip, $stops, ['amount' => 1, 'fare' => 1, 'gross_amount' => 1])->assertSuccessful();

    // 15 base + 10 km * 2.00
    expect((float) $response->json('data.tickets.0.amount'))->toBe(35.0);
    expect((float) $response->json('data.payment.amount'))->toBe(35.0);
});

it('records who issued the cash ticket, that it is cash, and the server time in UTC', function () {
    [$conductor, $trip, $stops] = cashFixture();
    $this->actingAs($conductor)->postJson('/api/conductor/shift/start')->assertSuccessful();
    $before = Carbon::now('UTC')->subSeconds(5);

    $response = cashSale($this, $conductor, $trip, $stops)->assertSuccessful();
    $paymentId = $response->json('data.payment.payment_id');

    $this->assertDatabaseHas('payments', ['payment_id' => $paymentId, 'payment_method' => 'cash', 'payment_channel' => 'onsite', 'status' => 'paid']);
    $this->assertDatabaseHas('onsite_payment', ['payment_id' => $paymentId, 'conductor_id' => $conductor->companyProfile->company_user_id]);

    // Stored value is UTC; the response carries it as ISO 8601 with Z.
    $issuedAt = $response->json('data.issued_at');
    expect($issuedAt)->toEndWith('Z');
    $stored = \Illuminate\Support\Facades\DB::table('onsite_payment')->where('payment_id', $paymentId)->value('issued_at');
    expect(Carbon::parse($stored, 'UTC')->gte($before))->toBeTrue();
    expect(Carbon::parse($issuedAt)->equalTo(Carbon::parse($stored, 'UTC')))->toBeTrue();

    expect($response->json('data.issued_by.conductor_id'))->toBe($conductor->companyProfile->company_user_id);
    expect($response->json('data.issued_by.name'))->toBe('Cash Conductor');

    $this->assertDatabaseHas('audit_logs', ['action' => 'payment.cash_ticket_issued', 'subject_id' => $paymentId]);
});

it('blocks issuing once the bus is at capacity', function () {
    [$conductor, $trip, $stops] = cashFixture(seated: 1, standing: 0);
    $this->actingAs($conductor)->postJson('/api/conductor/shift/start')->assertSuccessful();

    cashSale($this, $conductor, $trip, $stops)->assertSuccessful();

    cashSale($this, $conductor, $trip, $stops)
        ->assertStatus(422)
        ->assertJsonValidationErrors(['capacity']);
    expect(\App\Models\Ticket::where('trip_id', $trip->trip_id)->count())->toBe(1);
});

it('refuses a cash sale without an open shift', function () {
    [$conductor, $trip, $stops] = cashFixture();

    cashSale($this, $conductor, $trip, $stops)->assertStatus(422);
    expect(\App\Models\Ticket::where('trip_id', $trip->trip_id)->count())->toBe(0);
});
