<?php

use App\Models\FareRule;
use App\Models\Fleet;
use App\Models\FleetRoute;
use App\Models\Route;
use App\Models\RouteStop;
use App\Models\StaffUser;
use App\Models\Stop;
use App\Models\Trip;
use App\Models\User;
use App\Services\TicketService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

// Batch 29 end-to-end flow: one working day across every feature of the batch, through the real HTTP endpoints.
// Schedule a two-leg block -> accept before starting -> pair -> cash sale -> Operator cancels the trip and refunds the cash
// ticket -> the Chauffeur ends the shift with a count -> the remittance batch is confirmed, flagged and resolved ->
// the Financials page, the CSV exports and the cards all agree. Made-up names throughout.

function e2eUser(string $role): User
{
    $user = User::create(['username' => $role.Str::random(6), 'email' => Str::random(8).'@example.com', 'password' => bcrypt('password123'), 'role' => $role]);
    if ($role !== 'admin') {
        StaffUser::create(['user_id' => $user->user_id, 'company_user_uuid' => (string) Str::uuid(), 'name' => ucfirst($role).' '.Str::random(3), 'phone_num' => '0917', 'address' => 'x']);
    }

    return $user->fresh();
}

function e2eId(User $user): int
{
    return (int) $user->companyProfile->company_user_id;
}

beforeEach(function () {
    Carbon::setTestNow(Carbon::parse('2026-10-05 07:00:00', 'Asia/Manila'));
    config(['shift_blocks.legs_per_block' => 2, 'shift_blocks.leg_location_rule_enabled' => false, 'remittance.tolerance_amount' => 0]);
});
afterEach(fn () => Carbon::setTestNow());

it('runs a whole working day: schedule, accept, start, pair, sell cash, cancel and refund, end shift, remit, flag, resolve, report', function () {
    // ------------------------------------------------------------------ the world
    $operator = e2eUser('operator');
    $driver = e2eUser('driver');
    $conductor = e2eUser('conductor');
    $route = Route::create(['route_name' => 'Flow Route', 'origin' => 'Alpha', 'destination' => 'Omega']);
    $stops = [];
    foreach ([['Stop A', 0, 'terminal'], ['Stop B', 5, 'stop'], ['Stop C', 10, 'terminal']] as $i => [$name, $km, $type]) {
        $stop = Stop::create(['stop_name' => $name, 'latitude' => 7.0 + $km / 100, 'longitude' => 125.0, 'municipality' => 'Mati City']);
        RouteStop::create(['route_id' => $route->route_id, 'stop_id' => $stop->stop_id, 'stop_order' => $i + 1, 'distance_from_origin_km' => $km, 'stop_type' => $type]);
        $stops[$name] = $stop;
    }
    $fleet = Fleet::create(['company_user_id' => e2eId($operator), 'plate_number' => 'E2E-001', 'capacity' => 40, 'seated_capacity' => 30, 'standing_capacity' => 10, 'status' => 'active', 'bus_company_name' => 'Flow Lines', 'body_no' => 'F-1']);
    FareRule::create(['fleet_id' => $fleet->fleet_id, 'base_fare' => 15, 'fare_per_km' => 2, 'status' => 'active', 'seat_type' => 'seated']);
    $fleetRoute = FleetRoute::create(['fleet_id' => $fleet->fleet_id, 'route_id' => $route->route_id, 'start_time' => '06:00', 'end_time' => '22:00', 'status' => 'active']);

    // ------------------------------------------------------------------ 1. the Operator schedules a two-leg block for today
    $blockId = $this->actingAs($operator)->postJson('/api/operator/shift-blocks', [
        'fleet_route_id' => $fleetRoute->fleet_route_id, 'driver_id' => e2eId($driver), 'conductor_id' => e2eId($conductor),
        'block_type' => config('shift_blocks.block_types')[0], 'scheduled_date' => '2026-10-05', 'leg_departure_times' => ['08:00', '11:00'],
    ])->assertSuccessful()->json('data.shift_block_id');
    $legs = Trip::where('shift_block_id', $blockId)->orderBy('departure_time')->get();
    expect($legs)->toHaveCount(2);

    // One status on every portal, from the legs.
    foreach ([[$driver, 'driver'], [$conductor, 'conductor'], [$operator, 'operator']] as [$user, $portal]) {
        $card = collect($this->actingAs($user)->getJson("/api/$portal/assignments")->assertSuccessful()->json('data'))->firstWhere('shift_block_id', $blockId);
        expect([$card['status'], $card['section']])->toBe(['scheduled', 'today']);
    }
    // Passengers see the same booking window the Operator form warns about.
    expect($this->getJson('/api/booking/window')->assertSuccessful()->json('data.last_bookable_date'))->toBe('2026-11-04');

    // ------------------------------------------------------------------ 2. nothing starts before the assignment is accepted
    $this->actingAs($driver)->postJson('/api/driver/shift/start')->assertUnprocessable()->assertJsonValidationErrors('shift');
    $this->actingAs($conductor)->postJson('/api/conductor/shift/start', ['opening_cash' => 100])->assertUnprocessable();
    $this->actingAs($driver)->getJson('/api/driver/pairing-token')->assertUnprocessable();
    $this->actingAs($driver)->patchJson("/api/driver/assignments/block-$blockId/accept")->assertSuccessful();
    $this->actingAs($conductor)->patchJson("/api/conductor/assignments/block-$blockId/accept")->assertSuccessful();

    // ------------------------------------------------------------------ 3. start with an opening cash, pair, board
    $this->actingAs($conductor)->postJson('/api/conductor/shift/start', ['opening_cash' => -1])->assertUnprocessable();
    $shift = $this->actingAs($conductor)->postJson('/api/conductor/shift/start', ['opening_cash' => 100])->assertSuccessful()->json('data.shift');
    expect($shift['opening_cash'])->toEqual(100);
    $this->actingAs($driver)->postJson('/api/driver/shift/start')->assertSuccessful();
    $token = $this->actingAs($driver)->getJson('/api/driver/pairing-token')->assertSuccessful()->json('data.token');
    $this->actingAs($conductor)->postJson('/api/conductor/pair', ['token' => $token])->assertSuccessful();
    // Phase A: Start Boarding opens 30 minutes before the 08:00 departure.
    Carbon::setTestNow(Carbon::parse('2026-10-05 07:30:00', 'Asia/Manila'));
    $this->actingAs($driver)->patchJson("/api/driver/trips/{$legs[0]->trip_id}/boarding")->assertSuccessful();

    // ------------------------------------------------------------------ 4. the Chauffeur sells one cash ticket; the server prices it
    $sale = $this->actingAs($conductor)->postJson('/api/conductor/checkout', ['items' => [[
        'trip_id' => $legs[0]->trip_id, 'seat_type' => 'seated', 'origin_stop_id' => $stops['Stop A']->stop_id, 'destination_stop_id' => $stops['Stop C']->stop_id,
    ]]])->assertSuccessful();
    $ticket = DB::table('tickets')->where('trip_id', $legs[0]->trip_id)->first();
    $fare = (float) $ticket->amount;
    expect($fare)->toBeGreaterThan(0.0);
    $onsite = DB::table('onsite_payment')->first();
    expect((int) $onsite->conductor_shift_log_id)->toBe((int) $shift['shift_log_id']);   // the sale knows its shift

    $earnings = $this->actingAs($conductor)->getJson('/api/conductor/trips/current/earnings')->assertSuccessful()->json('data');
    expect($earnings['total_fare'])->toEqual($fare);
    expect($earnings['shift']['expected_cash'])->toEqual(round(100 + $fare, 2));

    // ------------------------------------------------------------------ 5. the Operator cancels the trip and refunds the cash ticket
    $this->actingAs($operator)->patchJson("/api/operator/trips/{$legs[0]->trip_id}/cancel", ['reason' => 'Bus breakdown'])->assertSuccessful();
    $this->actingAs($operator)->patchJson("/api/operator/trips/{$legs[1]->trip_id}/cancel", ['reason' => 'Bus breakdown'])->assertSuccessful();
    $this->actingAs($operator)->postJson("/api/operator/tickets/{$ticket->ticket_uuid}/resolution", ['choice' => 'refund', 'note' => 'Cash handed back'])->assertSuccessful();
    $resolution = DB::table('ticket_resolutions')->where('ticket_id', $ticket->ticket_id)->first();
    expect([$resolution->method, $resolution->status])->toBe(['cash', 'succeeded']);
    expect((int) $resolution->conductor_shift_log_id)->toBe((int) $shift['shift_log_id']);   // the refund goes to the shift that sold it
    $refund = (float) $resolution->amount;
    expect($refund)->toEqual($fare);

    // With every leg cancelled there is no active trip, so the trip earnings screen has nothing; the shift's cash does not
    // depend on a trip (the End Shift dialog needs it exactly then) and has fallen with the refund.
    $this->actingAs($conductor)->getJson('/api/conductor/trips/current/earnings')->assertNotFound();
    $status = $this->actingAs($conductor)->getJson('/api/conductor/shift/status')->assertSuccessful()->json('data');
    expect([$status['shift_cash']['cash_sales'], $status['shift_cash']['cash_refunds'], $status['shift_cash']['expected_cash']])->toEqual([$fare, $refund, 100]);
    $perTrip = app(TicketService::class)->getTripEarnings($legs[0]->trip_id);
    expect([$perTrip['refunded_amount'], $perTrip['net_total']])->toEqual([$refund, 0]);

    // ------------------------------------------------------------------ 6. the Chauffeur ends the shift with a count that is 10 short
    $declared = round(100 + $fare - $refund - 10, 2);
    $this->actingAs($conductor)->postJson('/api/conductor/shift/end', ['closing_cash' => -2])->assertUnprocessable();
    $this->actingAs($conductor)->postJson('/api/conductor/shift/end', ['closing_cash' => $declared])->assertSuccessful();
    $batch = DB::table('cash_remittances')->first();
    expect([$batch->status, $batch->declaration])->toBe(['pending', 'declared']);
    expect((float) $batch->declared_cash)->toEqual($declared);

    // ------------------------------------------------------------------ 7. the Operator confirms: 10 short, tolerance 0 -> flagged, then resolved
    $row = $this->actingAs($operator)->getJson('/api/operator/remittances')->assertSuccessful()->json('data.data.0');
    expect([$row['opening_cash'], $row['cash_sales'], $row['cash_refunds'], $row['expected_cash']])->toEqual([100, $fare, $refund, 100]);
    expect($row['variance_now'])->toEqual(-10);

    $flagged = $this->actingAs($operator)->postJson("/api/operator/remittances/{$batch->id}/confirm", ['counted_amount' => $declared])->assertSuccessful()->json('data');
    expect([$flagged['status'], $flagged['variance'], $flagged['variance_kind']])->toEqual(['flagged', -10, 'short']);
    $this->actingAs($operator)->postJson("/api/operator/remittances/{$batch->id}/resolve")->assertUnprocessable();
    $resolved = $this->actingAs($operator)->postJson("/api/operator/remittances/{$batch->id}/resolve", ['resolution_note' => 'Ten pesos change given to a passenger without a receipt'])->assertSuccessful()->json('data');
    expect([$resolved['status'], $resolved['variance']])->toEqual(['resolved', -10]);   // resolved, and the variance is still on the record

    // ------------------------------------------------------------------ 8. every card agrees: the block is cancelled everywhere
    foreach ([[$driver, 'driver'], [$conductor, 'conductor']] as [$user, $portal]) {
        expect(collect($this->actingAs($user)->getJson("/api/$portal/assignments")->json('data'))->firstWhere('shift_block_id', $blockId))->toBeNull();
    }

    // ------------------------------------------------------------------ 9. Financials: paid ticket, refund as its own figure, net 0, same numbers in the CSV
    $f = $this->actingAs($operator)->getJson('/api/operator/financials?from=2026-10-05&to=2026-10-05')->assertSuccessful()->json('data');
    expect([$f['kpis']['gross'], $f['kpis']['refunds'], $f['kpis']['net'], $f['kpis']['tickets']])->toEqual([$fare, $refund, 0, 1]);
    expect($f['kpis']['cash']['percent'])->toEqual(100);
    expect(collect($f['channels'])->pluck('label')->all())->toBe(['Cash']);
    expect($f['by_bus'][0]['bus_label'])->toBe('Flow Lines - F-1');

    $log = $this->actingAs($operator)->getJson('/api/operator/financials/transactions?from=2026-10-05&to=2026-10-05')->json('data.data');
    expect([$log[0]['status'], $log[0]['amount'], $log[0]['refunded']])->toEqual(['refunded', $fare, $refund]);

    $csv = $this->actingAs($operator)->get('/api/operator/financials/export?type=refunds&from=2026-10-05&to=2026-10-05')->assertSuccessful()->getContent();
    $rows = array_map('str_getcsv', array_values(array_filter(preg_split('/\r?\n/', substr($csv, 3)))));
    expect($rows)->toHaveCount(2);
    expect($rows[1][3])->toBe(DB::table('payments')->value('transaction_reference'));   // linked to the original sale
    expect($rows[1][2])->toBe('Cash');

    // ------------------------------------------------------------------ 10. nobody else can see or change any of it
    $other = e2eUser('operator');
    expect($this->actingAs($other)->getJson('/api/operator/financials?from=2026-10-05')->json('data.kpis.gross'))->toEqual(0);
    expect($this->actingAs($other)->getJson('/api/operator/remittances?status=all')->json('data.data'))->toBe([]);
    $this->actingAs($other)->postJson("/api/operator/remittances/{$batch->id}/resolve", ['resolution_note' => 'x'])->assertNotFound();
    $this->actingAs($conductor)->getJson('/api/operator/financials')->assertForbidden();
    $this->actingAs($conductor)->getJson('/api/admin/financials')->assertForbidden();
    $this->actingAs($driver)->getJson('/api/operator/remittances')->assertForbidden();
    foreach (['audit' => ['remittance.flagged', 'remittance.resolved', 'ticket.cash_refund_recorded', 'financials.export']] as $actions) {
        foreach ($actions as $action) {
            $this->assertDatabaseHas('audit_logs', ['action' => $action]);
        }
    }
});
