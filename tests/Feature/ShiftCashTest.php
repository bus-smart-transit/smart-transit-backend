<?php

use App\Models\Fleet;
use App\Models\FleetRoute;
use App\Models\Route;
use App\Models\StaffUser;
use App\Models\Trip;
use App\Models\User;
use App\Services\ShiftCashService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

// Batch 29, cash handling step 2: a cash refund belongs to the Conductor shift that SOLD the ticket, a shift's expected
// cash is opening cash + cash sales - cash refunds, and Start Shift takes an opening cash (default 0).
// Self-contained fixtures with made-up names.

function scUser(string $role): User
{
    $user = User::create(['username' => $role.Str::random(6), 'email' => Str::random(8).'@example.com', 'password' => bcrypt('password123'), 'role' => $role]);
    StaffUser::create(['user_id' => $user->user_id, 'company_user_uuid' => (string) Str::uuid(), 'name' => ucfirst($role).' '.Str::random(3), 'phone_num' => '0917', 'address' => 'x']);

    return $user->fresh();
}

function scId(User $user): int
{
    return (int) $user->companyProfile->company_user_id;
}

/** An operator with a trip on one fleet, and two Conductors. */
function shiftWorld(string $tripStatus = 'in-progress'): array
{
    $operator = scUser('operator');
    $a = scUser('conductor');
    $b = scUser('conductor');
    $route = Route::create(['route_name' => 'Cash Route', 'origin' => 'Alpha', 'destination' => 'Omega']);
    $fleet = Fleet::create(['company_user_id' => scId($operator), 'plate_number' => 'SH-'.Str::random(4), 'capacity' => 40, 'seated_capacity' => 30, 'standing_capacity' => 10, 'status' => 'active']);
    $fleetRoute = FleetRoute::create(['fleet_id' => $fleet->fleet_id, 'route_id' => $route->route_id, 'start_time' => '06:00', 'end_time' => '22:00', 'status' => 'active']);
    $trip = Trip::create([
        'fleet_route_id' => $fleetRoute->fleet_route_id, 'company_user_id' => scId($operator), 'conductor_id' => scId($a), 'conductor_accepted_at' => now(),
        'trip_date' => '2026-10-05', 'departure_time' => '08:00', 'trip_type' => 'one_way', 'leg_direction' => 'outbound', 'status' => $tripStatus,
        'current_seated_capacity' => 0, 'current_standing_capacity' => 0, 'total_occupancy' => 0,
    ]);

    return compact('operator', 'a', 'b', 'fleet', 'fleetRoute', 'trip');
}

function shiftLog(array $w, User $conductor, string $start, ?string $end, float $opening = 0): int
{
    return DB::table('conductor_shift_logs')->insertGetId([
        'conductor_company_user_id' => scId($conductor), 'trip_id' => $w['trip']->trip_id, 'fleet_id' => $w['fleet']->fleet_id, 'shift_date' => '2026-10-05',
        'started_at' => $start, 'ended_at' => $end, 'start_source' => 'dashboard', 'opening_cash' => $opening, 'created_at' => $start, 'updated_at' => $start,
    ]);
}

/** A paid cash sale of one ticket, made by $conductor in $shiftId (null = a sale from before the link existed). */
function cashTicket(array $w, User $conductor, ?int $shiftId, float $amount, string $issuedUtc = '2026-10-05 01:00:00'): int
{
    $paymentId = DB::table('payments')->insertGetId([
        'amount' => $amount, 'payment_created' => '2026-10-05 09:00:00', 'transaction_reference' => 'TX-'.Str::upper(Str::random(8)), 'payment_method' => 'cash',
        'payment_channel' => 'onsite', 'status' => 'paid', 'payment_uuid' => (string) Str::uuid(), 'is_valid' => true, 'created_at' => '2026-10-05 09:00:00', 'updated_at' => '2026-10-05 09:00:00',
    ]);
    DB::table('onsite_payment')->insert(['payment_id' => $paymentId, 'conductor_id' => scId($conductor), 'conductor_shift_log_id' => $shiftId, 'issued_at' => $issuedUtc, 'created_at' => now(), 'updated_at' => now()]);

    return DB::table('tickets')->insertGetId([
        'fleet_route_id' => $w['fleetRoute']->fleet_route_id, 'trip_id' => $w['trip']->trip_id, 'payment_id' => $paymentId, 'ticket_uuid' => (string) Str::uuid(),
        'status' => 'trip_cancelled', 'seat_type' => 'seated', 'amount' => $amount, 'created_at' => '2026-10-05 09:00:00', 'updated_at' => '2026-10-05 09:00:00',
    ]);
}

/** A cash refund waiting for the Operator's cash entry. */
function cashRefund(array $w, int $ticketId, float $amount, string $status = 'pending', ?int $shiftId = null): int
{
    $cancellationId = DB::table('trip_cancellations')->where('trip_id', $w['trip']->trip_id)->value('id')
        ?? DB::table('trip_cancellations')->insertGetId(['trip_id' => $w['trip']->trip_id, 'stage' => 'before_departure', 'cancelled_at' => '2026-10-05 07:00:00', 'created_at' => now(), 'updated_at' => now()]);

    return DB::table('ticket_resolutions')->insertGetId([
        'ticket_id' => $ticketId, 'trip_cancellation_id' => $cancellationId, 'resolution' => 'refund', 'status' => $status, 'method' => 'cash', 'amount' => $amount, 'fare_paid' => $amount,
        'conductor_shift_log_id' => $shiftId, 'requested_at' => '2026-10-05 02:00:00', 'completed_at' => $status === 'succeeded' ? '2026-10-05 03:00:00' : null, 'created_at' => now(), 'updated_at' => now(),
    ]);
}

beforeEach(fn () => Carbon::setTestNow(Carbon::parse('2026-10-05 10:00:00', 'Asia/Manila')));
afterEach(fn () => Carbon::setTestNow());

it('attaches a late cash refund to the shift that SOLD the ticket, not to the shift open when it is paid out', function () {
    $w = shiftWorld();
    $shiftA = shiftLog($w, $w['a'], '2026-10-05 06:00:00', '2026-10-05 09:30:00', 100);
    $shiftB = shiftLog($w, $w['b'], '2026-10-05 09:45:00', null, 50); // open now, another Conductor
    $sold = cashTicket($w, $w['a'], $shiftA, 80.00);
    $resolutionId = cashRefund($w, $sold, 80.00);

    $this->actingAs($w['operator'])->postJson("/api/operator/ticket-resolutions/$resolutionId/cash", ['note' => 'Handed back next day'])->assertSuccessful();

    expect((int) DB::table('ticket_resolutions')->where('id', $resolutionId)->value('conductor_shift_log_id'))->toBe($shiftA);
    $summary = app(ShiftCashService::class);
    expect($summary->summary($shiftA)['cash_refunds'])->toEqual(80);
    expect($summary->summary($shiftB)['cash_refunds'])->toEqual(0);   // the shift open at payout time is not charged
    expect($summary->summary($shiftB)['expected_cash'])->toEqual(50);
});

it('finds the selling shift of an older sale by its window when the sale was never linked', function () {
    $w = shiftWorld();
    $shiftA = shiftLog($w, $w['a'], '2026-10-05 06:00:00', '2026-10-05 09:30:00');
    shiftLog($w, $w['a'], '2026-10-05 12:00:00', null);                                    // a later shift of the same Conductor
    $sold = cashTicket($w, $w['a'], null, 45.00, '2026-10-05 01:15:00');                   // 09:15 Manila, inside the first shift
    $resolutionId = cashRefund($w, $sold, 45.00);

    $this->actingAs($w['operator'])->postJson("/api/operator/ticket-resolutions/$resolutionId/cash")->assertSuccessful();

    expect((int) DB::table('ticket_resolutions')->where('id', $resolutionId)->value('conductor_shift_log_id'))->toBe($shiftA);
});

it('computes expected cash as opening + cash sales - cash refunds, counting only refunds that were paid out', function () {
    $w = shiftWorld();
    $shift = shiftLog($w, $w['a'], '2026-10-05 06:00:00', null, 100);
    $t1 = cashTicket($w, $w['a'], $shift, 50.00);
    cashTicket($w, $w['a'], $shift, 40.50);
    cashTicket($w, $w['a'], $shift, 20.00);
    cashRefund($w, $t1, 20.50, 'succeeded', $shift);                                        // paid out: counts
    cashRefund($w, cashTicket($w, $w['a'], $shift, 10.00), 10.00, 'pending', $shift);      // not paid yet: does not count
    // Another Conductor's sale never mixes in.
    cashTicket($w, $w['b'], shiftLog($w, $w['b'], '2026-10-05 06:00:00', null), 999.00);

    $s = app(ShiftCashService::class)->summary($shift);

    expect($s['opening_cash'])->toEqual(100);
    expect($s['cash_sales'])->toEqual(120.5);          // 50 + 40.50 + 20 + 10
    expect($s['cash_sales_tickets'])->toBe(4);
    expect($s['cash_refunds'])->toEqual(20.5);
    expect($s['cash_refund_count'])->toBe(1);
    expect($s['expected_cash'])->toEqual(200);         // 100 + 120.50 - 20.50
});

it('shows the Chauffeur earnings net of refunds and the cash of their open shift, and shows a Driver no shift cash', function () {
    $w = shiftWorld();
    $shift = shiftLog($w, $w['a'], '2026-10-05 06:00:00', null, 100);
    $t = cashTicket($w, $w['a'], $shift, 60.00);
    cashTicket($w, $w['a'], $shift, 40.00);
    cashRefund($w, $t, 60.00, 'succeeded', $shift);

    // The earnings screens need the crew paired (as in the real flow).
    $driver = scUser('driver');
    DB::table('trips')->where('trip_id', $w['trip']->trip_id)->update(['driver_id' => scId($driver), 'driver_accepted_at' => now()]);
    \App\Models\FleetDailyPin::create(['trip_id' => $w['trip']->trip_id, 'driver_id' => scId($driver), 'conductor_id' => scId($w['a']), 'pin_code' => '123456', 'pin_date' => '2026-10-05', 'driver_verified_at' => now(), 'conductor_verified_at' => now(), 'paired_at' => now()]);

    $data = $this->actingAs($w['a'])->getJson('/api/conductor/trips/current/earnings')->assertSuccessful()->json('data');

    expect($data['total_fare'])->toEqual(100);         // sales as before
    expect($data['onsite_amount'])->toEqual(100);
    expect($data['onsite_refunds'])->toEqual(60);
    expect($data['refunded_amount'])->toEqual(60);
    expect($data['net_total'])->toEqual(40);
    expect($data['onsite_net'])->toEqual(40);
    expect($data['online_net'])->toEqual(0);
    expect($data['shift'])->toMatchArray(['opening_cash' => 100.0, 'cash_sales' => 100.0, 'cash_refunds' => 60.0, 'expected_cash' => 140.0]);

    DB::table('driver_shift_logs')->insert(['driver_company_user_id' => scId($driver), 'trip_id' => $w['trip']->trip_id, 'fleet_id' => $w['fleet']->fleet_id, 'shift_date' => '2026-10-05', 'started_at' => '2026-10-05 06:00:00', 'start_source' => 'dashboard', 'created_at' => now(), 'updated_at' => now()]);
    $driverData = $this->actingAs($driver)->getJson('/api/driver/trips/current/earnings')->assertSuccessful()->json('data');
    expect($driverData)->not->toHaveKey('shift');
    expect($driverData['net_total'])->toEqual(40);
});

it('takes the opening cash at Start Shift, defaults it to 0, and refuses a negative or non-numeric amount', function () {
    $w = shiftWorld('scheduled');

    $this->actingAs($w['a'])->postJson('/api/conductor/shift/start', ['opening_cash' => -5])->assertUnprocessable()->assertJsonValidationErrors('opening_cash');
    $this->actingAs($w['a'])->postJson('/api/conductor/shift/start', ['opening_cash' => 'lots'])->assertUnprocessable()->assertJsonValidationErrors('opening_cash');
    expect(DB::table('conductor_shift_logs')->count())->toBe(0);

    $shift = $this->actingAs($w['a'])->postJson('/api/conductor/shift/start', ['opening_cash' => 250.5])->assertSuccessful()->json('data.shift');
    expect($shift['opening_cash'])->toEqual(250.5);
    expect(app(ShiftCashService::class)->summary($shift['shift_log_id'])['expected_cash'])->toEqual(250.5);

    // The default is 0 when nothing is declared.
    $w2 = shiftWorld('scheduled');
    $none = $this->actingAs($w2['a'])->postJson('/api/conductor/shift/start')->assertSuccessful()->json('data.shift');
    expect($none['opening_cash'])->toEqual(0);
});
