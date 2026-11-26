<?php

use App\Models\BaggageCharge;
use App\Models\Company;
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
use App\Services\CashRemittanceService;
use App\Services\FinancialReportService;
use App\Services\ShiftCashService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

// 2026-11-25 (user): baggage is declared online and paid by size in cash to the Chauffeur on board. Each payment is its
// own record, counted in the Chauffeur's expected cash, in the remittance and as its own line in Finance. Prices are
// SAMPLE values: the defaults (20 / 50 / 100) or what the Operator sets for the company.

function bobStaff(string $role, ?Company $company = null, bool $admin = false): User
{
    $user = User::create(['username' => 'bob'.Str::random(8), 'email' => Str::random(8).'@example.com', 'password' => bcrypt('password123'), 'role' => $role]);
    StaffUser::create([
        'user_id' => $user->user_id, 'company_user_uuid' => (string) Str::uuid(), 'name' => 'Bob '.ucfirst($role), 'phone_num' => '09170000025', 'address' => 'x',
        'company_id' => $company?->company_id, 'is_company_admin' => $admin,
    ]);

    return $user->fresh();
}

/** A boarding trip with a paired crew and an open Chauffeur shift; the bus belongs to a company. */
function bobFixture($test): array
{
    $company = Company::create(['company_name' => 'Bob Bus Co '.Str::random(4)]);
    $operator = bobStaff('operator', $company, true);
    $driver = bobStaff('driver', $company);
    $conductor = bobStaff('conductor', $company);

    $route = Route::create(['route_name' => 'Bob Route', 'origin' => 'A', 'destination' => 'C']);
    $stops = [];
    foreach ([['Stop A', 0, 'terminal'], ['Stop B', 5, 'stop'], ['Stop C', 10, 'terminal']] as $i => [$name, $km, $type]) {
        $stop = Stop::create(['stop_name' => $name, 'latitude' => 7.0 + $km / 100, 'longitude' => 125.0]);
        RouteStop::create(['route_id' => $route->route_id, 'stop_id' => $stop->stop_id, 'stop_order' => $i + 1, 'distance_from_origin_km' => $km, 'stop_type' => $type]);
        $stops[$name] = $stop;
    }
    $fleet = Fleet::create([
        'company_user_id' => $operator->companyProfile->company_user_id, 'company_id' => $company->company_id, 'plate_number' => 'BOB-'.fake()->unique()->numerify('###'),
        'capacity' => 40, 'seated_capacity' => 30, 'standing_capacity' => 10, 'status' => 'active',
    ]);
    FareRule::create(['fleet_id' => $fleet->fleet_id, 'base_fare' => 15, 'fare_per_km' => 2, 'status' => 'active', 'seat_type' => 'seated']);
    $fleetRoute = FleetRoute::create(['fleet_id' => $fleet->fleet_id, 'route_id' => $route->route_id, 'start_time' => '06:00', 'end_time' => '22:00', 'status' => 'active']);
    $trip = Trip::create([
        'fleet_route_id' => $fleetRoute->fleet_route_id, 'company_user_id' => $operator->companyProfile->company_user_id,
        'driver_id' => $driver->companyProfile->company_user_id, 'conductor_id' => $conductor->companyProfile->company_user_id,
        'driver_accepted_at' => now(), 'conductor_accepted_at' => now(),
        'trip_date' => now('Asia/Manila')->toDateString(), 'departure_time' => '08:00', 'trip_type' => 'one_way', 'leg_direction' => 'outbound',
        'status' => 'boarding', 'current_seated_capacity' => 0, 'current_standing_capacity' => 0, 'total_occupancy' => 0,
    ]);
    FleetDailyPin::create([
        'trip_id' => $trip->trip_id, 'driver_id' => $driver->companyProfile->company_user_id, 'conductor_id' => $conductor->companyProfile->company_user_id,
        'pin_code' => '123456', 'pin_date' => now('Asia/Manila')->toDateString(), 'driver_verified_at' => now(), 'conductor_verified_at' => now(), 'paired_at' => now(),
    ]);
    $test->actingAs($conductor)->postJson('/api/conductor/shift/start')->assertSuccessful();

    return compact('company', 'operator', 'conductor', 'trip', 'stops', 'fleet', 'fleetRoute');
}

/** A paid online ticket on the trip that declared baggage (A -> C, PHP 35). */
function bobOnlineTicket(array $f, bool $declared = true): Ticket
{
    $payment = Payment::create(['amount' => 35, 'payment_created' => now(), 'transaction_reference' => 'TXN-BOB-'.Str::random(6), 'payment_method' => 'online', 'payment_channel' => 'gcash', 'status' => 'paid', 'payment_uuid' => (string) Str::uuid(), 'is_valid' => true, 'items_payload' => []]);

    return Ticket::create([
        'fleet_route_id' => $f['fleetRoute']->fleet_route_id, 'trip_id' => $f['trip']->trip_id, 'payment_id' => $payment->payment_id, 'ticket_uuid' => (string) Str::uuid(),
        'status' => 'valid', 'amount' => 35, 'distance_km' => 10, 'seat_type' => 'seated', 'has_baggage' => $declared,
        'origin_stop_id' => $f['stops']['Stop A']->stop_id, 'destination_stop_id' => $f['stops']['Stop C']->stop_id,
    ]);
}

function bobOpenShiftId(User $conductor): int
{
    return (int) app(ShiftCashService::class)->openShiftId((int) $conductor->companyProfile->company_user_id);
}

it('lets the Chauffeur record baggage paid by size, shows it in the passenger list and adds it to the expected cash', function () {
    $f = bobFixture($this);
    $ticket = bobOnlineTicket($f);

    $this->actingAs($f['conductor'])->getJson('/api/conductor/baggage-fees')->assertSuccessful()
        ->assertJsonPath('data.1.key', 'medium')->assertJsonPath('data.1.amount', 50);

    $this->actingAs($f['conductor'])->postJson("/api/conductor/tickets/{$ticket->ticket_id}/baggage", ['size' => 'large', 'stub_number' => ' 0042 '])
        ->assertCreated()->assertJsonPath('data.amount', 100)->assertJsonPath('data.size_label', 'Large')->assertJsonPath('data.stub_number', '0042');

    $row = collect($this->actingAs($f['conductor'])->getJson('/api/conductor/trips/current/passengers')->assertSuccessful()->json('data.passengers'))
        ->firstWhere('ticket_id', $ticket->ticket_id);
    expect($row['baggage'])->toBeTrue()->and($row['baggage_paid'])->toEqual(100)->and($row['origin_stop'])->toBe('Stop A');

    $cash = app(ShiftCashService::class)->summary(bobOpenShiftId($f['conductor']));
    expect($cash['baggage_cash'])->toEqual(100.0)->and($cash['baggage_count'])->toBe(1)->and($cash['expected_cash'])->toEqual(100.0);
});

it('records baggage for a passenger who did not declare it, and marks the ticket', function () {
    $f = bobFixture($this);
    $ticket = bobOnlineTicket($f, false);

    $this->actingAs($f['conductor'])->postJson("/api/conductor/tickets/{$ticket->ticket_id}/baggage", ['size' => 'small'])->assertCreated();

    expect($ticket->fresh()->has_baggage)->toBeTrue();
});

it('refuses an unknown size and a ticket that is not on the current trip', function () {
    $f = bobFixture($this);
    $ticket = bobOnlineTicket($f);
    $this->actingAs($f['conductor'])->postJson("/api/conductor/tickets/{$ticket->ticket_id}/baggage", ['size' => 'huge'])
        ->assertStatus(422)->assertJsonFragment(['Choose Small, Medium or Large.']);

    $otherTrip = $f['trip']->replicate();
    $otherTrip->trip_date = now('Asia/Manila')->addDay()->toDateString();
    $otherTrip->save();
    $elsewhere = bobOnlineTicket($f);
    $elsewhere->update(['trip_id' => $otherTrip->trip_id]);
    $this->actingAs($f['conductor'])->postJson("/api/conductor/tickets/{$elsewhere->ticket_id}/baggage", ['size' => 'small'])
        ->assertStatus(422)->assertJsonFragment(['This ticket is not on your current trip.']);

    expect(BaggageCharge::count())->toBe(0);
});

it('lets the Chauffeur take back a payment in the same shift, and no one else', function () {
    $f = bobFixture($this);
    $ticket = bobOnlineTicket($f);
    $id = $this->actingAs($f['conductor'])->postJson("/api/conductor/tickets/{$ticket->ticket_id}/baggage", ['size' => 'medium'])->json('data.baggage_charge_id');

    app('auth')->forgetGuards();
    $other = bobStaff('conductor', $f['company']);
    $this->actingAs($other)->patchJson("/api/conductor/baggage/{$id}/void")->assertClientError();
    expect(BaggageCharge::find($id)->voided_at)->toBeNull();

    app('auth')->forgetGuards();
    $this->actingAs($f['conductor'])->patchJson("/api/conductor/baggage/{$id}/void")->assertSuccessful()->assertJsonPath('data.voided', true);

    $cash = app(ShiftCashService::class)->summary(bobOpenShiftId($f['conductor']));
    expect($cash['baggage_cash'])->toEqual(0.0)->and($cash['expected_cash'])->toEqual(0.0);
});

it('quotes and records baggage with a cash sale as its own payment, at the Operator\'s price', function () {
    $f = bobFixture($this);
    $f['company']->update(['baggage_fee_medium' => 60]);
    $item = ['trip_id' => $f['trip']->trip_id, 'seat_type' => 'seated', 'origin_stop_id' => $f['stops']['Stop A']->stop_id, 'destination_stop_id' => $f['stops']['Stop C']->stop_id];

    $this->actingAs($f['conductor'])->postJson('/api/conductor/checkout/quote', $item + ['baggage_size' => 'medium'])
        ->assertSuccessful()->assertJsonPath('data.amount', 35)->assertJsonPath('data.baggage_fee', 60);

    $this->actingAs($f['conductor'])->postJson('/api/conductor/checkout', ['items' => [$item + ['baggage_size' => 'medium', 'baggage_stub' => 'S-9'], $item]])->assertSuccessful();

    expect(BaggageCharge::count())->toBe(1)
        ->and((float) BaggageCharge::first()->amount)->toBe(60.0);
    $cash = app(ShiftCashService::class)->summary(bobOpenShiftId($f['conductor']));
    // Two fares of PHP 35 and one baggage of PHP 60.
    expect($cash['cash_sales'])->toEqual(70.0)->and($cash['baggage_cash'])->toEqual(60.0)->and($cash['expected_cash'])->toEqual(130.0);
});

it('shows baggage cash in the remittance and as its own line in Finance', function () {
    $f = bobFixture($this);
    $ticket = bobOnlineTicket($f);
    $this->actingAs($f['conductor'])->postJson("/api/conductor/tickets/{$ticket->ticket_id}/baggage", ['size' => 'small'])->assertCreated();
    $f['trip']->update(['status' => 'completed']);
    $this->actingAs($f['conductor'])->postJson('/api/conductor/shift/end', ['closing_cash' => 20])->assertSuccessful();

    $operatorId = (int) $f['operator']->companyProfile->company_user_id;
    $row = app(CashRemittanceService::class)->paginate($operatorId, [])['data'][0];
    expect($row['baggage_cash'])->toEqual(20.0)->and($row['expected_cash'])->toEqual(20.0);

    $finance = app(FinancialReportService::class);
    $report = $finance->report($operatorId, $finance->normalize([]));
    expect($report['kpis']['baggage'])->toBe(['collected' => 20.0, 'count' => 1])
        ->and($report['kpis']['gross'])->toEqual(35.0);
    expect($finance->report($operatorId, $finance->normalize(['payment_method' => 'online']))['kpis']['baggage'])->toBe(['collected' => 0.0, 'count' => 0]);
});

it('lets the Operator set the company\'s prices with the extra check, and shows the passenger what was paid', function () {
    $f = bobFixture($this);
    app('auth')->forgetGuards();
    $this->actingAs($f['operator'])->putJson('/api/operator/baggage-fees', ['small' => 25, 'medium' => 55, 'large' => 120])->assertStatus(403);

    $raw = bin2hex(random_bytes(32));
    DB::table('step_up_tokens')->insert(['guard' => 'sanctum', 'user_id' => $f['operator']->user_id, 'token_hash' => hash('sha256', $raw), 'expires_at' => now()->addMinutes(15), 'created_at' => now()]);
    $this->actingAs($f['operator'], 'sanctum')->withHeader('X-Step-Up-Token', $raw)
        ->putJson('/api/operator/baggage-fees', ['small' => 25, 'medium' => 55, 'large' => 120])
        ->assertSuccessful()->assertJsonPath('data.0.amount', 25)->assertJsonPath('data.2.amount', 120);

    $this->actingAs($f['operator'])->putJson('/api/operator/baggage-fees', ['small' => -1, 'medium' => 55, 'large' => 120])->assertStatus(422);
    $this->actingAs($f['operator'])->getJson('/api/operator/baggage-fees')->assertSuccessful()->assertJsonPath('data.1.amount', 55)->assertJsonPath('data.1.is_default', false);
});

// 2026-11-26 (user): the scan pop-up shows the destination, priority seat and baggage of the passenger just boarded,
// for one ticket and for a group ticket.
it('gives the scan pop-up the priority seat, the pieces of baggage and what was paid', function () {
    $f = bobFixture($this);
    $ticket = bobOnlineTicket($f);
    $ticket->update(['priority_type' => 'senior', 'baggage_count' => 2]);
    $this->actingAs($f['conductor'])->postJson("/api/conductor/tickets/{$ticket->ticket_id}/baggage", ['size' => 'small'])->assertCreated();

    $this->actingAs($f['conductor'])->postJson('/api/conductor/tickets/scan', ['ticket_uuid' => $ticket->ticket_uuid])
        ->assertSuccessful()
        ->assertJsonPath('data.destination', 'Stop C')
        ->assertJsonPath('data.priority_label', 'Senior citizen')
        ->assertJsonPath('data.has_baggage', true)
        ->assertJsonPath('data.baggage_count', 2)
        ->assertJsonPath('data.baggage_paid', 20)
        ->assertJsonPath('data.baggage_charges.0.size_label', 'Small');
});

it('lists each boarded passenger of a group ticket with their priority seat and baggage', function () {
    $f = bobFixture($this);
    $first = bobOnlineTicket($f);
    $second = bobOnlineTicket($f, false);
    $second->update(['payment_id' => $first->payment_id, 'priority_type' => 'pwd']);
    $first->update(['baggage_count' => 3]);
    $reference = Payment::find($first->payment_id)->transaction_reference;

    $rows = collect($this->actingAs($f['conductor'])->postJson('/api/conductor/tickets/scan-group', ['transaction_reference' => $reference])
        ->assertSuccessful()->assertJsonPath('data.boarded_count', 2)->json('data.tickets'))->keyBy('ticket_id');

    expect($rows[$first->ticket_id]['baggage_count'])->toBe(3)
        ->and($rows[$first->ticket_id]['origin'])->toBe('Stop A')
        ->and($rows[$second->ticket_id]['priority_label'])->toBe('Person with disability')
        ->and($rows[$second->ticket_id]['has_baggage'])->toBeFalse();
});

it('raises the pieces on the ticket to what was paid for on board', function () {
    $f = bobFixture($this);
    $ticket = bobOnlineTicket($f);
    foreach (['small', 'medium'] as $size) {
        $this->actingAs($f['conductor'])->postJson("/api/conductor/tickets/{$ticket->ticket_id}/baggage", ['size' => $size])->assertCreated();
    }

    expect($ticket->fresh()->baggage_count)->toBe(2);
});
