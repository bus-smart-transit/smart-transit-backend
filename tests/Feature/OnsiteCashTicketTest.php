<?php

use App\Models\FareRule;
use App\Models\Fleet;
use App\Models\FleetDailyPin;
use App\Models\FleetRoute;
use App\Models\Payment;
use App\Models\Route;
use App\Models\RouteStop;
use App\Models\StaffUser;
use App\Models\Stop;
use App\Models\Ticket;
use App\Models\Trip;
use App\Models\User;
use App\Services\ShiftCashService;
use App\Services\TicketService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
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
        'name' => 'Cash '.ucfirst($role),
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
        'plate_number' => 'CASH-'.fake()->unique()->numerify('###'),
        'capacity' => $seated + $standing, 'seated_capacity' => $seated, 'standing_capacity' => $standing, 'status' => 'active',
    ]);
    FareRule::create(['fleet_id' => $fleet->fleet_id, 'base_fare' => 15, 'fare_per_km' => 2, 'status' => 'active', 'seat_type' => 'seated']);
    $fleetRoute = FleetRoute::create(['fleet_id' => $fleet->fleet_id, 'route_id' => $route->route_id, 'start_time' => '06:00', 'end_time' => '22:00', 'status' => 'active']);

    $trip = Trip::create([
        'fleet_route_id' => $fleetRoute->fleet_route_id,
        'company_user_id' => $operator->companyProfile->company_user_id,
        'driver_id' => $driver->companyProfile->company_user_id,
        'conductor_id' => $conductor->companyProfile->company_user_id,
        'driver_accepted_at' => now(), 'conductor_accepted_at' => now(), // Batch 29: the assignment is accepted
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
    $stored = DB::table('onsite_payment')->where('payment_id', $paymentId)->value('issued_at');
    expect(Carbon::parse($stored, 'UTC')->gte($before))->toBeTrue();
    expect(Carbon::parse($issuedAt)->equalTo(Carbon::parse($stored, 'UTC')))->toBeTrue();

    expect($response->json('data.issued_by.conductor_id'))->toBe($conductor->companyProfile->company_user_id);
    expect($response->json('data.issued_by.name'))->toBe('Cash Conductor');

    $this->assertDatabaseHas('audit_logs', ['action' => 'payment.cash_ticket_issued', 'subject_id' => $paymentId]);
});

it('replays an idempotent cash sale without issuing or auditing a second ticket', function () {
    [$conductor, $trip, $stops] = cashFixture();
    $this->actingAs($conductor)->postJson('/api/conductor/shift/start')->assertSuccessful();
    $headers = ['Idempotency-Key' => 'cash-retry-key-00000001'];
    $payload = ['items' => [[
        'trip_id' => $trip->trip_id,
        'seat_type' => 'seated',
        'origin_stop_id' => $stops['Stop A']->stop_id,
        'destination_stop_id' => $stops['Stop C']->stop_id,
    ]]];

    $first = $this->actingAs($conductor)->postJson('/api/conductor/checkout', $payload, $headers)->assertSuccessful();
    $replay = $this->actingAs($conductor)->postJson('/api/conductor/checkout', $payload, $headers)->assertSuccessful();

    expect($replay->json('data.payment.payment_id'))->toBe($first->json('data.payment.payment_id'));
    expect($replay->json('data.tickets.0.ticket_id'))->toBe($first->json('data.tickets.0.ticket_id'));
    expect(Payment::where('idempotency_key_hash', hash('sha256', $headers['Idempotency-Key']))->count())->toBe(1);
    expect(Ticket::where('trip_id', $trip->trip_id)->count())->toBe(1);
    expect(DB::table('audit_logs')->where('action', 'payment.cash_ticket_issued')->count())->toBe(1);

    $changedPayload = $payload;
    $changedPayload['items'][0]['destination_stop_id'] = $stops['Stop B']->stop_id;
    $this->actingAs($conductor)->postJson('/api/conductor/checkout', $changedPayload, $headers)->assertStatus(409);
    expect(Ticket::where('trip_id', $trip->trip_id)->count())->toBe(1);
});

it('blocks issuing once the bus is at capacity', function () {
    [$conductor, $trip, $stops] = cashFixture(seated: 1, standing: 0);
    $this->actingAs($conductor)->postJson('/api/conductor/shift/start')->assertSuccessful();

    cashSale($this, $conductor, $trip, $stops)->assertSuccessful();

    cashSale($this, $conductor, $trip, $stops)
        ->assertStatus(422)
        ->assertJsonValidationErrors(['capacity']);
    expect(Ticket::where('trip_id', $trip->trip_id)->count())->toBe(1);
});

it('refuses a cash sale without an open shift', function () {
    [$conductor, $trip, $stops] = cashFixture();

    cashSale($this, $conductor, $trip, $stops)->assertStatus(422);
    expect(Ticket::where('trip_id', $trip->trip_id)->count())->toBe(0);
});

it('records the Conductor shift a cash sale was made in, so expected cash starts from the opening cash declared at Start Shift', function () {
    [$conductor, $trip, $stops] = cashFixture();
    $shift = $this->actingAs($conductor)->postJson('/api/conductor/shift/start', ['opening_cash' => 120])->assertSuccessful()->json('data.shift');

    cashSale($this, $conductor, $trip, $stops)->assertSuccessful();

    $sale = DB::table('onsite_payment')->first();
    expect((int) $sale->conductor_shift_log_id)->toBe((int) $shift['shift_log_id']);
    $paid = (float) DB::table('payments')->where('payment_id', $sale->payment_id)->value('amount');
    expect($paid)->toBeGreaterThan(0.0);

    $summary = app(ShiftCashService::class)->summary((int) $shift['shift_log_id']);
    expect($summary['opening_cash'])->toEqual(120);
    expect($summary['cash_sales'])->toEqual($paid);
    expect($summary['expected_cash'])->toEqual(round(120 + $paid, 2));
});
it('quotes the exact fare before the sale, reserves nothing, and the sale then charges that same amount', function () {
    [$conductor, $trip, $stops] = cashFixture();
    $this->actingAs($conductor)->postJson('/api/conductor/shift/start')->assertSuccessful();
    $body = ['trip_id' => $trip->trip_id, 'seat_type' => 'seated', 'origin_stop_id' => $stops['Stop A']->stop_id, 'destination_stop_id' => $stops['Stop C']->stop_id];

    $quote = $this->actingAs($conductor)->postJson('/api/conductor/checkout/quote', $body + ['amount' => 1])->assertSuccessful();
    expect((float) $quote->json('data.amount'))->toBe(35.0)->and((float) $quote->json('data.distance_km'))->toBe(10.0);

    // A quote is not a sale: no seat held, no payment, no ticket.
    expect($trip->fresh()->total_occupancy)->toBe(0)->and(Ticket::count())->toBe(0)->and(Payment::count())->toBe(0);

    $sale = cashSale($this, $conductor, $trip, $stops)->assertSuccessful();
    expect((float) $sale->json('data.payment.amount'))->toBe((float) $quote->json('data.amount'));

    // A shorter ride quotes less: 15 base + 5 km * 2.00.
    $shorter = $this->actingAs($conductor)->postJson('/api/conductor/checkout/quote', array_merge($body, ['origin_stop_id' => $stops['Stop B']->stop_id]))->assertSuccessful();
    expect((float) $shorter->json('data.amount'))->toBe(25.0);
});

it('says why a fare cannot be quoted: a destination behind the boarding stop, or another trip', function () {
    [$conductor, $trip, $stops] = cashFixture();
    $this->actingAs($conductor)->postJson('/api/conductor/shift/start')->assertSuccessful();
    $body = ['trip_id' => $trip->trip_id, 'seat_type' => 'seated', 'origin_stop_id' => $stops['Stop C']->stop_id, 'destination_stop_id' => $stops['Stop A']->stop_id];

    $this->actingAs($conductor)->postJson('/api/conductor/checkout/quote', $body)
        ->assertStatus(422)
        ->assertJsonPath('message', 'The destination must be ahead of your boarding point for this trip\'s direction.');

    [, $otherTrip] = cashFixture();
    $this->actingAs($conductor)->postJson('/api/conductor/checkout/quote', array_merge($body, ['trip_id' => $otherTrip->trip_id, 'origin_stop_id' => $stops['Stop A']->stop_id, 'destination_stop_id' => $stops['Stop C']->stop_id]))
        ->assertStatus(422);
    $this->actingAs($conductor)->postJson('/api/conductor/checkout/quote', ['trip_id' => $trip->trip_id])->assertStatus(422);
});

it('only a paired Conductor can ask for a quote', function () {
    [, $trip, $stops] = cashFixture();
    $operator = cashStaff('operator');

    $this->actingAs($operator)->postJson('/api/conductor/checkout/quote', ['trip_id' => $trip->trip_id, 'seat_type' => 'seated', 'origin_stop_id' => $stops['Stop A']->stop_id, 'destination_stop_id' => $stops['Stop C']->stop_id])
        ->assertForbidden();
});

it('records a cash sale as boarded, counts the passenger on board, and lets the Conductor drop them off', function () {
    [$conductor, $trip, $stops] = cashFixture();
    $this->actingAs($conductor)->postJson('/api/conductor/shift/start')->assertSuccessful();

    $sale = cashSale($this, $conductor, $trip, $stops)->assertSuccessful();
    $ticket = Ticket::findOrFail($sale->json('data.tickets.0.ticket_id'));

    // Paid on the bus, so already aboard: boarded with a boarding time, not "valid" and waiting for a scan.
    expect($ticket->status)->toBe('boarded')->and($ticket->boarded_at)->not->toBeNull();

    $occupancy = $this->actingAs($conductor)->getJson('/api/conductor/trips/current/occupancy')->assertSuccessful();
    expect($occupancy->json('data.boarded.total'))->toBe(1);

    $passengers = $this->actingAs($conductor)->getJson('/api/conductor/trips/current/passengers')->assertSuccessful();
    expect(collect($passengers->json('data.passengers'))->pluck('ticket_id')->all())->toContain($ticket->ticket_id);
    expect(collect($passengers->json('data.passengers'))->firstWhere('ticket_id', $ticket->ticket_id)['is_boarded'])->toBeTrue();

    $this->actingAs($conductor)->postJson("/api/conductor/tickets/{$ticket->ticket_id}/alight")->assertSuccessful();
    expect($ticket->fresh()->status)->toBe('alighted');
});

it('still issues an online ticket as valid: only a cash sale on the bus is boarded', function () {
    [, $trip, $stops] = cashFixture();
    $payment = Payment::create(['amount' => 0, 'transaction_reference' => 'TXN-ONLINE-1', 'payment_method' => 'online', 'payment_channel' => 'gcash', 'status' => 'paid', 'is_valid' => true, 'payment_created' => now(), 'payment_uuid' => (string) Str::uuid()]);

    $ticket = app(TicketService::class)->issueTicket(['trip_id' => $trip->trip_id, 'seat_type' => 'seated', 'origin_stop_id' => $stops['Stop A']->stop_id, 'destination_stop_id' => $stops['Stop C']->stop_id], null, $payment->payment_id);

    expect($ticket->status)->toBe('valid')->and($ticket->boarded_at)->toBeNull();
});
