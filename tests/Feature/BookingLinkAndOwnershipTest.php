<?php

use App\Models\Fleet;
use App\Models\FleetRoute;
use App\Models\PassengerUser;
use App\Models\Payment;
use App\Models\Route;
use App\Models\StaffUser;
use App\Models\Stop;
use App\Models\Ticket;
use App\Models\Trip;
use App\Models\User;
use App\Services\OccupancyService;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

uses(RefreshDatabase::class);

// ---------- stop codes and booking link validation (Batch 25, Issue 3) ----------

it('gives every stop a readable code, unique with a numeric suffix on collisions', function () {
    $a = Stop::create(['stop_name' => 'Bunawan Public Market', 'latitude' => 7.2, 'longitude' => 125.6]);
    $b = Stop::create(['stop_name' => 'Bunawan Public Market', 'latitude' => 7.3, 'longitude' => 125.6]);
    $c = Stop::create(['stop_name' => '  Ñandú & Co.  ', 'latitude' => 7.4, 'longitude' => 125.6]);
    $explicit = Stop::create(['stop_name' => 'Anything', 'stop_code' => 'my-code', 'latitude' => 7.5, 'longitude' => 125.6]);

    expect($a->stop_code)->toBe('bunawan-public-market');
    expect($b->stop_code)->toBe('bunawan-public-market-2');
    expect($c->stop_code)->toMatch('/^[a-z0-9]+(?:-[a-z0-9]+)*$/');
    expect($explicit->stop_code)->toBe('my-code');
});

function linkStops(): array
{
    return [
        Stop::create(['stop_name' => 'Alpha Terminal', 'latitude' => 7.0, 'longitude' => 125.6]),
        Stop::create(['stop_name' => 'Beta Market', 'latitude' => 7.1, 'longitude' => 125.6]),
    ];
}

it('accepts a valid booking link and returns the resolved stops and date', function () {
    [$a, $b] = linkStops();
    $today = Carbon::now('Asia/Manila')->toDateString();

    $this->getJson('/api/booking/link?from=alpha-terminal&to=BETA-market&date='.$today)
        ->assertSuccessful()
        ->assertJsonPath('data.from.stop_id', $a->stop_id)
        ->assertJsonPath('data.to.stop_code', 'beta-market')
        ->assertJsonPath('data.date', $today);

    // The date is optional and defaults to today in Manila.
    $this->getJson('/api/booking/link?from=alpha-terminal&to=beta-market')
        ->assertSuccessful()->assertJsonPath('data.date', $today);
});

it('rejects bad links with friendly messages instead of crashing', function () {
    linkStops();
    $today = Carbon::now('Asia/Manila')->toDateString();
    $get = fn (string $q) => $this->getJson('/api/booking/link?'.$q);

    $get('from=nope&to=beta-market')->assertStatus(422)->assertJsonValidationErrors(['from']);
    $get('from=alpha-terminal&to=%3Cscript%3E')->assertStatus(422)->assertJsonValidationErrors(['to']);
    $get('from=1&to=2')->assertStatus(422)->assertJsonValidationErrors(['from']); // numeric ids are not codes
    $get('to=beta-market')->assertStatus(422)->assertJsonValidationErrors(['from']);
    $get('from=alpha-terminal&to=alpha-terminal')->assertStatus(422)->assertJsonValidationErrors(['to']);
    $get('from=alpha-terminal&to=beta-market&date=2026-02-30')->assertStatus(422)->assertJsonValidationErrors(['date']);
    $get('from=alpha-terminal&to=beta-market&date=tomorrow')->assertStatus(422)->assertJsonValidationErrors(['date']);
    $get('from=alpha-terminal&to=beta-market&date=2000-01-01')->assertStatus(422)->assertJsonValidationErrors(['date']);
    $get('from=alpha-terminal&to=beta-market&date='.Carbon::now('Asia/Manila')->addDays(400)->toDateString())
        ->assertStatus(422)->assertJsonValidationErrors(['date']);
    expect($get('from=nope&to=beta-market')->json('message'))->toContain('could not find the stop');
    expect($today)->toMatch('/^\d{4}-\d{2}-\d{2}$/');
});

it('judges a past date on the Manila calendar, not UTC', function () {
    linkStops();
    // 00:30 on 1 Oct in Manila is still 30 Sep in UTC.
    $this->travelTo(Carbon::parse('2026-10-01 00:30:00', 'Asia/Manila'));

    $this->getJson('/api/booking/link?from=alpha-terminal&to=beta-market&date=2026-10-01')->assertSuccessful();
    $this->getJson('/api/booking/link?from=alpha-terminal&to=beta-market&date=2026-09-30')
        ->assertStatus(422)->assertJsonValidationErrors(['date']);
});

it('lets an admin edit a stop code, rejecting duplicates and unsafe formats', function () {
    [$a, $b] = linkStops();
    $admin = User::create(['username' => 'adm', 'email' => 'adm@example.com', 'password' => bcrypt('x'), 'role' => 'admin']);

    $put = fn (Stop $stop, array $body) => $this->actingAs($admin)->putJson("/api/admin/stops/{$stop->stop_id}", $body);

    $put($a, ['stop_code' => 'alpha'])->assertSuccessful();
    expect($a->fresh()->stop_code)->toBe('alpha');
    $put($b, ['stop_code' => 'alpha'])->assertStatus(422)->assertJsonValidationErrors(['stop_code']);
    $put($b, ['stop_code' => 'Has Spaces!'])->assertStatus(422)->assertJsonValidationErrors(['stop_code']);
    $put($b, ['stop_code' => '../etc'])->assertStatus(422)->assertJsonValidationErrors(['stop_code']);
    // Keeping its own code is fine, and a blank one leaves it unchanged.
    $put($a, ['stop_code' => 'alpha'])->assertSuccessful();
    $put($a, ['stop_code' => '', 'stop_name' => 'Alpha Renamed'])->assertSuccessful();
    expect($a->fresh()->stop_code)->toBe('alpha');
});

// ---------- ownership of ticket and payment resources ----------

function ownerFixture(): array
{
    $operatorUser = User::create(['username' => 'op_'.uniqid(), 'email' => 'op+'.uniqid().'@t.test', 'password' => bcrypt('x'), 'role' => 'operator']);
    $operator = StaffUser::create(['company_user_uuid' => (string) Str::uuid(), 'user_id' => $operatorUser->user_id, 'name' => 'Op', 'phone_num' => '0917', 'address' => 'x']);
    $route = Route::create(['route_name' => 'Own Route '.uniqid(), 'origin' => 'A', 'destination' => 'B']);
    $fleet = Fleet::create(['company_user_id' => $operator->company_user_id, 'plate_number' => 'OW-'.rand(100, 999), 'capacity' => 40, 'seated_capacity' => 30, 'standing_capacity' => 10, 'status' => 'active']);
    $fleetRoute = FleetRoute::create(['fleet_id' => $fleet->fleet_id, 'route_id' => $route->route_id, 'start_time' => '06:00', 'end_time' => '22:00', 'status' => 'active']);
    $makeTrip = fn () => Trip::create([
        'fleet_route_id' => $fleetRoute->fleet_route_id, 'company_user_id' => $operator->company_user_id,
        'trip_date' => now()->toDateString(), 'status' => 'scheduled',
        'current_seated_capacity' => 0, 'current_standing_capacity' => 0, 'total_occupancy' => 0,
    ]);

    return [$fleetRoute, $makeTrip];
}

function ownerPassenger(): array
{
    $user = User::create(['username' => 'p_'.uniqid(), 'email' => 'p+'.uniqid().'@t.test', 'password' => bcrypt('x'), 'role' => 'passenger']);
    $profile = PassengerUser::create(['user_id' => $user->user_id, 'passenger_uuid' => (string) Str::uuid(), 'name' => 'P', 'phone_num' => '0917', 'address' => 'x', 'birthdate' => '2000-01-01', 'reward_points' => 0]);

    return [$user, $profile];
}

function ownerTicket($fleetRoute, $trip, ?int $passengerId, array $paymentExtra = [], string $status = 'valid'): Ticket
{
    $payment = Payment::create(array_merge([
        'amount' => 20, 'payment_created' => now(), 'transaction_reference' => 'TXN-'.strtoupper(bin2hex(random_bytes(8))),
        'payment_method' => 'online', 'payment_channel' => 'gcash', 'status' => 'paid', 'payment_uuid' => (string) Str::uuid(),
        'is_valid' => true, 'items_payload' => [],
    ], $paymentExtra));

    return Ticket::create([
        'fleet_route_id' => $fleetRoute->fleet_route_id, 'trip_id' => $trip->trip_id, 'payment_id' => $payment->payment_id,
        'passenger_id' => $passengerId, 'ticket_uuid' => (string) Str::uuid(), 'status' => $status, 'seat_type' => 'seated', 'amount' => 20,
    ]);
}

it('only the owning passenger can fetch a ticket QR; guest tickets are not anyone\'s account ticket', function () {
    [$fleetRoute, $makeTrip] = ownerFixture();
    $trip = $makeTrip();
    [$ownerUser, $owner] = ownerPassenger();
    [$otherUser] = ownerPassenger();

    $mine = ownerTicket($fleetRoute, $trip, $owner->passenger_id);
    $guest = ownerTicket($fleetRoute, $trip, null, ['guest_email' => 'g@example.com']);

    $this->actingAs($ownerUser)->getJson("/api/passengers/tickets/{$mine->ticket_uuid}/qr")->assertSuccessful();
    $this->actingAs($ownerUser)->getJson("/api/passengers/tickets/{$guest->ticket_uuid}/qr")->assertForbidden();
});

it('refuses another passenger\'s ticket QR', function () {
    [$fleetRoute, $makeTrip] = ownerFixture();
    [, $owner] = ownerPassenger();
    [$otherUser] = ownerPassenger();
    $ticket = ownerTicket($fleetRoute, $makeTrip(), $owner->passenger_id);

    $this->actingAs($otherUser)->getJson("/api/passengers/tickets/{$ticket->ticket_uuid}/qr")->assertForbidden();
});

it('a guest lookup needs the email used at checkout; a reference alone never opens an email payment', function () {
    [$fleetRoute, $makeTrip] = ownerFixture();
    $trip = $makeTrip();
    $withEmail = ownerTicket($fleetRoute, $trip, null, ['guest_email' => 'buyer@example.com']);
    $ref = $withEmail->payment->transaction_reference;

    $this->getJson('/api/tickets/lookup?transaction_reference='.$ref)->assertNotFound();
    $this->getJson('/api/tickets/lookup?transaction_reference='.$ref.'&email=someone@else.com')->assertNotFound();
    $this->getJson('/api/tickets/lookup?transaction_reference='.$ref.'&email=buyer@example.com')
        ->assertSuccessful()->assertJsonCount(1, 'data');

    // A guest who gave no email can still recover by the (unguessable) reference.
    $noEmail = ownerTicket($fleetRoute, $trip, null);
    $this->getJson('/api/tickets/lookup?transaction_reference='.$noEmail->payment->transaction_reference)
        ->assertSuccessful()->assertJsonCount(1, 'data');
});

it('alighting is limited to tickets of the conductor\'s own trip', function () {
    [$fleetRoute, $makeTrip] = ownerFixture();
    $tripA = $makeTrip();
    $tripB = $makeTrip();
    $ticket = ownerTicket($fleetRoute, $tripB, null, [], 'boarded');
    $service = app(OccupancyService::class);

    // A ticket of another trip looks like "not found", exactly like a missing id.
    expect(fn () => $service->recordAlighting($ticket->ticket_id, $tripA->trip_id))->toThrow(ModelNotFoundException::class);
    expect($ticket->fresh()->status)->toBe('boarded');

    $service->recordAlighting($ticket->ticket_id, $tripB->trip_id);
    expect($ticket->fresh()->status)->toBe('alighted');

    // Only boarded passengers can alight.
    $valid = ownerTicket($fleetRoute, $tripB, null);
    expect(fn () => $service->recordAlighting($valid->ticket_id, $tripB->trip_id))->toThrow(ValidationException::class);
});
