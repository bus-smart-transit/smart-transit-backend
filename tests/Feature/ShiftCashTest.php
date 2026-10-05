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


// ---------------------------------------------------------------------------------------------------------------
// Batch 29, cash step 3: the remittance batch of each ended Conductor shift, confirmed by the Operator.

it('opens a pending batch with the declared closing cash when the Chauffeur ends the shift', function () {
    $w = shiftWorld('completed');
    $shift = shiftLog($w, $w['a'], '2026-10-05 06:00:00', null, 100);
    cashTicket($w, $w['a'], $shift, 80.00);

    $this->actingAs($w['a'])->postJson('/api/conductor/shift/end', ['closing_cash' => 175.5])->assertSuccessful();

    $batch = DB::table('cash_remittances')->first();
    expect((int) $batch->conductor_shift_log_id)->toBe($shift);
    expect($batch->status)->toBe('pending');
    expect($batch->declaration)->toBe('declared');
    expect((float) $batch->declared_cash)->toEqual(175.5);
    expect((int) $batch->operator_company_user_id)->toBe(scId($w['operator']));
    expect((int) $batch->conductor_company_user_id)->toBe(scId($w['a']));
    expect($batch->confirmed_at)->toBeNull();
});

it('records that there was no Conductor count when the shift is ended without one, or closed automatically', function () {
    $w = shiftWorld('completed');
    shiftLog($w, $w['a'], '2026-10-05 06:00:00', null);
    $this->actingAs($w['a'])->postJson('/api/conductor/shift/end')->assertSuccessful();
    $manual = DB::table('cash_remittances')->first();
    expect($manual->declaration)->toBe('none');
    expect($manual->declared_cash)->toBeNull();

    // A shift closed by the trip itself (no one pressed End Shift): a pending batch, declaration none.
    $w2 = shiftWorld('departed');
    $auto = shiftLog($w2, $w2['a'], '2026-10-05 06:00:00', null);
    expect(app(\App\Repositories\ConductorShiftLogRepository::class)->endOpenForTrip($w2['trip']->trip_id))->toBeTrue();
    $batch = DB::table('cash_remittances')->where('conductor_shift_log_id', $auto)->first();
    expect($batch->status)->toBe('pending');
    expect($batch->declaration)->toBe('none');
    expect($batch->declared_cash)->toBeNull();
});

it('refuses a negative closing cash without ending the shift, and keeps one batch per shift', function () {
    $w = shiftWorld('completed');
    $shift = shiftLog($w, $w['a'], '2026-10-05 06:00:00', null);

    $this->actingAs($w['a'])->postJson('/api/conductor/shift/end', ['closing_cash' => -1])->assertUnprocessable()->assertJsonValidationErrors('closing_cash');
    $this->actingAs($w['a'])->postJson('/api/conductor/shift/end', ['closing_cash' => 'lots'])->assertUnprocessable()->assertJsonValidationErrors('closing_cash');
    expect(DB::table('conductor_shift_logs')->where('shift_log_id', $shift)->value('ended_at'))->toBeNull();
    expect(DB::table('cash_remittances')->count())->toBe(0);

    $log = \App\Models\ConductorShiftLog::find($shift);
    $service = app(\App\Services\CashRemittanceService::class);
    $first = $service->openForShift($log, 50.0);
    $second = $service->openForShift($log, 99.0);
    expect($second->id)->toBe($first->id);
    expect((float) $second->declared_cash)->toEqual(50.0); // the first declaration stands
    expect(DB::table('cash_remittances')->count())->toBe(1);
});

it('lists an Operator only the batches of their own fleets, and refuses every other role', function () {
    $w = shiftWorld('completed');
    $shift = shiftLog($w, $w['a'], '2026-10-05 06:00:00', '2026-10-05 09:30:00', 100);
    cashTicket($w, $w['a'], $shift, 60.00);
    app(\App\Services\CashRemittanceService::class)->openForShift(\App\Models\ConductorShiftLog::find($shift), 150.0);

    $list = $this->actingAs($w['operator'])->getJson('/api/operator/remittances')->assertSuccessful()->json('data');
    expect($list['data'])->toHaveCount(1);
    $row = $list['data'][0];
    expect($row['status'])->toBe('pending');
    expect($row['declared_cash'])->toEqual(150);
    expect($row['no_declaration'])->toBeFalse();
    expect([$row['opening_cash'], $row['cash_sales'], $row['cash_refunds'], $row['expected_cash']])->toEqual([100, 60, 0, 160]);
    expect($row['shift']['plate_number'])->toBe($w['fleet']->plate_number);
    expect($row['shift']['route_name'])->toBe('Cash Route');

    $other = scUser('operator');
    expect($this->actingAs($other)->getJson('/api/operator/remittances?status=all')->json('data.data'))->toBe([]);
    $this->actingAs($w['a'])->getJson('/api/operator/remittances')->assertForbidden();
    $this->actingAs(scUser('driver'))->postJson('/api/operator/remittances/1/confirm')->assertForbidden();
    $this->actingAs(scUser('admin'))->getJson('/api/operator/remittances')->assertForbidden();
});

it('confirms a pending batch with the counted amount, who confirmed and when, and freezes the expected cash', function () {
    $w = shiftWorld('completed');
    $shift = shiftLog($w, $w['a'], '2026-10-05 06:00:00', '2026-10-05 09:30:00', 100);
    $t = cashTicket($w, $w['a'], $shift, 60.00);
    $batch = app(\App\Services\CashRemittanceService::class)->openForShift(\App\Models\ConductorShiftLog::find($shift), 160.0);

    $done = $this->actingAs($w['operator'])->postJson("/api/operator/remittances/{$batch->id}/confirm", ['counted_amount' => 158.5, 'note' => 'Counted at the office <b>ok</b>'])->assertSuccessful()->json('data');

    expect($done['status'])->toBe('confirmed');
    expect($done['confirmed_amount'])->toEqual(158.5);
    expect($done['confirmed_by_name'])->toBe($w['operator']->companyProfile->name);
    expect($done['confirmed_at'])->not->toBeNull();
    expect($done['expected_cash'])->toEqual(160);
    expect($done['note'])->toBe('Counted at the office ok'); // tags stripped
    $this->assertDatabaseHas('audit_logs', ['action' => 'remittance.confirmed', 'subject_id' => $batch->id]);

    // A cash refund paid out AFTER the confirmation does not rewrite what was expected then; it is visible as expected_now.
    $resolution = cashRefund($w, $t, 60.00);
    $this->actingAs($w['operator'])->postJson("/api/operator/ticket-resolutions/$resolution/cash")->assertSuccessful();
    $after = collect($this->actingAs($w['operator'])->getJson('/api/operator/remittances?status=confirmed')->json('data.data'))->first();
    expect($after['expected_cash'])->toEqual(160);
    expect($after['expected_now'])->toEqual(100);

    // Once decided it cannot be confirmed again.
    $this->actingAs($w['operator'])->postJson("/api/operator/remittances/{$batch->id}/confirm", ['counted_amount' => 1])->assertUnprocessable()->assertJsonValidationErrors('status');
});

it('lets the Operator accept the declared count, and requires a count when the Chauffeur gave none', function () {
    $w = shiftWorld('completed');
    $service = app(\App\Services\CashRemittanceService::class);
    $declared = $service->openForShift(\App\Models\ConductorShiftLog::find(shiftLog($w, $w['a'], '2026-10-05 06:00:00', '2026-10-05 09:00:00')), 42.0);
    $none = $service->openForShift(\App\Models\ConductorShiftLog::find(shiftLog($w, $w['a'], '2026-10-05 10:00:00', '2026-10-05 12:00:00')), null);

    $this->actingAs($w['operator'])->postJson("/api/operator/remittances/{$declared->id}/confirm")->assertSuccessful()->assertJsonPath('data.confirmed_amount', 42);

    $this->actingAs($w['operator'])->postJson("/api/operator/remittances/{$none->id}/confirm")->assertUnprocessable()->assertJsonValidationErrors('counted_amount');
    $this->actingAs($w['operator'])->postJson("/api/operator/remittances/{$none->id}/confirm", ['counted_amount' => -3])->assertUnprocessable()->assertJsonValidationErrors('counted_amount');
    expect(DB::table('cash_remittances')->where('id', $none->id)->value('status'))->toBe('pending');

    $ok = $this->actingAs($w['operator'])->postJson("/api/operator/remittances/{$none->id}/confirm", ['counted_amount' => 0])->assertSuccessful()->json('data');
    expect($ok['no_declaration'])->toBeTrue();   // still recorded as a batch with no Conductor count
    expect($ok['declared_cash'])->toBeNull();
    expect($ok['confirmed_amount'])->toEqual(0);
});

it('answers 404 for another Operator\'s batch and shows a pending batch the cash as it stands now', function () {
    $w = shiftWorld('completed');
    $shift = shiftLog($w, $w['a'], '2026-10-05 06:00:00', '2026-10-05 09:30:00', 50);
    $t = cashTicket($w, $w['a'], $shift, 30.00);
    $batch = app(\App\Services\CashRemittanceService::class)->openForShift(\App\Models\ConductorShiftLog::find($shift), 80.0);

    $this->actingAs(scUser('operator'))->postJson("/api/operator/remittances/{$batch->id}/confirm", ['counted_amount' => 80])->assertNotFound();
    expect(DB::table('cash_remittances')->where('id', $batch->id)->value('status'))->toBe('pending');

    // A late refund is paid out while the batch is still pending: the expected cash follows it.
    $this->actingAs($w['operator'])->postJson('/api/operator/ticket-resolutions/'.cashRefund($w, $t, 30.00).'/cash')->assertSuccessful();
    $row = $this->actingAs($w['operator'])->getJson('/api/operator/remittances')->json('data.data.0');
    expect([$row['cash_sales'], $row['cash_refunds'], $row['expected_cash']])->toEqual([30, 30, 50]);
});

// ---------------------------------------------------------------------------------------------------------------
// Batch 29, cash step 4: the tolerance check on confirm, and resolving a flagged batch.

/** An ended shift expecting exactly 160 (opening 100 + one cash sale of 60), with a batch declared at $declared. */
function remitBatch(array $w, ?float $declared): \App\Models\CashRemittance
{
    $shift = shiftLog($w, $w['a'], '2026-10-05 06:00:00', '2026-10-05 09:30:00', 100);
    cashTicket($w, $w['a'], $shift, 60.00);

    return app(\App\Services\CashRemittanceService::class)->openForShift(\App\Models\ConductorShiftLog::find($shift), $declared);
}

function confirmBatch($test, array $w, \App\Models\CashRemittance $batch, array $body = [])
{
    return $test->actingAs($w['operator'])->postJson("/api/operator/remittances/{$batch->id}/confirm", $body);
}

it('has a global tolerance of 0 by default, so any declared difference is flagged', function () {
    expect(config('remittance.tolerance_amount'))->toEqual(0);
    $w = shiftWorld('completed');

    $matched = confirmBatch($this, $w, remitBatch($w, 160.0), ['counted_amount' => 160])->assertSuccessful()->json('data');
    expect($matched['status'])->toBe('confirmed');
    expect($matched['variance'])->toEqual(0);
    expect($matched['variance_kind'])->toBe('matched');
    expect($matched['tolerance_applied'])->toEqual(0);

    $short = confirmBatch($this, $w, remitBatch($w, 159.99), ['counted_amount' => 159.99])->assertSuccessful()->json('data');
    expect($short['status'])->toBe('flagged');
    expect($short['variance'])->toEqual(-0.01);   // however small
    expect($short['variance_kind'])->toBe('short');
    expect($short['flagged_at'])->not->toBeNull();

    $over = confirmBatch($this, $w, remitBatch($w, 170.0))->assertSuccessful()->json('data');
    expect($over['status'])->toBe('flagged');
    expect($over['variance'])->toEqual(10);
    expect($over['variance_kind'])->toBe('over');
});

it('approves a variance within the configured tolerance, still records it, and flags one beyond it', function () {
    config(['remittance.tolerance_amount' => 5]);
    $w = shiftWorld('completed');

    $within = confirmBatch($this, $w, remitBatch($w, 157.0))->assertSuccessful()->json('data');
    expect($within['status'])->toBe('confirmed');          // approved
    expect($within['variance'])->toEqual(-3);              // but the small variance stays visible
    expect($within['tolerance_applied'])->toEqual(5);

    $edge = confirmBatch($this, $w, remitBatch($w, 165.0))->assertSuccessful()->json('data');
    expect($edge['status'])->toBe('confirmed');            // exactly the tolerance is still approved
    expect($edge['variance'])->toEqual(5);

    $beyond = confirmBatch($this, $w, remitBatch($w, 154.99))->assertSuccessful()->json('data');
    expect($beyond['status'])->toBe('flagged');
    expect($beyond['variance'])->toEqual(-5.01);

    // The value is read from config at confirm time, so changing it moves the line without a schema change.
    config(['remittance.tolerance_amount' => 20]);
    expect(confirmBatch($this, $w, remitBatch($w, 145.0))->json('data.status'))->toBe('confirmed');
    config(['remittance.tolerance_amount' => -3]); // a negative value is treated as 0
    expect(confirmBatch($this, $w, remitBatch($w, 159.0))->json('data.status'))->toBe('flagged');
});

it('never flags a batch with no Conductor declaration, and records no variance for it', function () {
    $w = shiftWorld('completed');
    $batch = remitBatch($w, null);

    $done = confirmBatch($this, $w, $batch, ['counted_amount' => 9999])->assertSuccessful()->json('data');

    expect($done['status'])->toBe('confirmed');
    expect($done['no_declaration'])->toBeTrue();
    expect($done['variance'])->toBeNull();
    expect($done['variance_kind'])->toBeNull();
    expect($done['tolerance_applied'])->toBeNull();
    expect($done['flagged_at'])->toBeNull();
});

it('shows a pending batch the difference as it stands now, without deciding anything', function () {
    $w = shiftWorld('completed');
    $batch = remitBatch($w, 150.0);

    $row = $this->actingAs($w['operator'])->getJson('/api/operator/remittances')->assertSuccessful()->json('data');

    expect($row['data'][0]['status'])->toBe('pending');
    expect($row['data'][0]['variance'])->toBeNull();       // not decided until it is confirmed
    expect($row['data'][0]['variance_now'])->toEqual(-10);
    expect($row['data'][0]['variance_now_kind'])->toBe('short');
    expect($row['meta']['tolerance_amount'])->toEqual(0);
});

it('lets the Operator resolve a flagged batch with a required explanation, keeping the flagged variance', function () {
    $w = shiftWorld('completed');
    $batch = confirmBatch($this, $w, remitBatch($w, 150.0), ['counted_amount' => 150])->json('data');
    expect($batch['status'])->toBe('flagged');
    $id = $batch['id'];

    // The explanation is required, and a blank one does not count.
    $this->actingAs($w['operator'])->postJson("/api/operator/remittances/$id/resolve")->assertUnprocessable()->assertJsonValidationErrors('resolution_note');
    $this->actingAs($w['operator'])->postJson("/api/operator/remittances/$id/resolve", ['resolution_note' => '   '])->assertUnprocessable();
    expect(DB::table('cash_remittances')->where('id', $id)->value('status'))->toBe('flagged');

    $done = $this->actingAs($w['operator'])->postJson("/api/operator/remittances/$id/resolve", ['resolution_note' => 'Fare of 10 returned to a passenger, receipt <i>kept</i>'])->assertSuccessful()->json('data');

    expect($done['status'])->toBe('resolved');              // not "confirmed": a resolved batch stays distinct
    expect($done['variance'])->toEqual(-10);                // the original variance is still there
    expect($done['variance_kind'])->toBe('short');
    expect($done['flagged_at'])->toBe($batch['flagged_at']);
    expect($done['resolution']['note'])->toBe('Fare of 10 returned to a passenger, receipt kept');
    expect($done['resolution']['resolved_by_name'])->toBe($w['operator']->companyProfile->name);
    expect($done['resolution']['resolved_at'])->not->toBeNull();
    expect($done['confirmed_amount'])->toEqual(150);        // what was counted stays too
    $this->assertDatabaseHas('audit_logs', ['action' => 'remittance.flagged', 'subject_id' => $id]);
    $this->assertDatabaseHas('audit_logs', ['action' => 'remittance.resolved', 'subject_id' => $id]);

    // It cannot be resolved twice, and the filter finds it under Resolved.
    $this->actingAs($w['operator'])->postJson("/api/operator/remittances/$id/resolve", ['resolution_note' => 'again'])->assertUnprocessable()->assertJsonValidationErrors('status');
    expect(collect($this->actingAs($w['operator'])->getJson('/api/operator/remittances?status=resolved')->json('data.data'))->pluck('id')->all())->toBe([$id]);
    expect($this->actingAs($w['operator'])->getJson('/api/operator/remittances?status=flagged')->json('data.data'))->toBe([]);
});

it('resolves only a flagged batch, and only for the Operator who owns it', function () {
    $w = shiftWorld('completed');
    $pending = remitBatch($w, 160.0);
    $clean = confirmBatch($this, $w, remitBatch($w, 160.0))->json('data');
    $flagged = confirmBatch($this, $w, remitBatch($w, 100.0))->json('data');

    $this->actingAs($w['operator'])->postJson("/api/operator/remittances/{$pending->id}/resolve", ['resolution_note' => 'x'])->assertUnprocessable()->assertJsonValidationErrors('status');
    $this->actingAs($w['operator'])->postJson("/api/operator/remittances/{$clean['id']}/resolve", ['resolution_note' => 'x'])->assertUnprocessable()->assertJsonValidationErrors('status');

    $this->actingAs(scUser('operator'))->postJson("/api/operator/remittances/{$flagged['id']}/resolve", ['resolution_note' => 'not mine'])->assertNotFound();
    $this->actingAs($w['a'])->postJson("/api/operator/remittances/{$flagged['id']}/resolve", ['resolution_note' => 'x'])->assertForbidden();
    $this->actingAs(scUser('admin'))->postJson("/api/operator/remittances/{$flagged['id']}/resolve", ['resolution_note' => 'x'])->assertForbidden();
    expect(DB::table('cash_remittances')->where('id', $flagged['id'])->value('status'))->toBe('flagged');
});