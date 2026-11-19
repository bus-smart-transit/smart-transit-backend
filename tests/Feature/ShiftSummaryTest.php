<?php

use App\Http\Middleware\RequirePairedSession;
use App\Models\ConductorShiftLog;
use App\Models\DriverShiftLog;
use App\Models\Fleet;
use App\Models\FleetRoute;
use App\Models\Payment;
use App\Models\Route;
use App\Models\ShiftBlock;
use App\Models\StaffUser;
use App\Models\Ticket;
use App\Models\Trip;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

// §4.12 / §10: the Chauffeur's Shift Summary covers every leg of the open shift, with one Trip Breakdown row per leg.
// It used to read only the leg under way, so a finished leg's money dropped out and the breakdown was always empty.

function ssStaff(string $role): User
{
    $user = User::create(['username' => 'ss'.Str::random(8), 'email' => Str::random(8).'@example.com', 'password' => bcrypt('password123'), 'role' => $role]);
    StaffUser::create(['user_id' => $user->user_id, 'company_user_uuid' => (string) Str::uuid(), 'name' => 'Ss '.ucfirst($role), 'phone_num' => '09170000060', 'address' => 'x']);

    return $user->fresh();
}

function ssSale(Trip $leg, float $amount, string $method): void
{
    $payment = Payment::create([
        'amount' => $amount, 'payment_created' => now(), 'transaction_reference' => 'TXN-SS-'.Str::upper(Str::random(8)), 'payment_method' => $method,
        'payment_channel' => $method === 'cash' ? 'onsite' : 'gcash', 'status' => 'paid', 'payment_uuid' => (string) Str::uuid(), 'is_valid' => true, 'items_payload' => [],
    ]);
    Ticket::create([
        'payment_id' => $payment->payment_id, 'fleet_route_id' => $leg->fleet_route_id, 'trip_id' => $leg->trip_id, 'ticket_uuid' => (string) Str::uuid(),
        'status' => 'valid', 'amount' => $amount, 'seat_type' => 'seated',
    ]);
}

/** A two-leg block (Tagum to Panabo and back) for one crew; leg 1 is completed, leg 2 is under way. */
function ssFixture(): array
{
    $operator = ssStaff('operator');
    $driver = ssStaff('driver');
    $conductor = ssStaff('conductor');
    $route = Route::create(['route_name' => 'Tagum - Panabo', 'origin' => 'Tagum', 'destination' => 'Panabo']);
    $fleet = Fleet::create(['company_user_id' => $operator->companyProfile->company_user_id, 'plate_number' => 'SS-'.fake()->unique()->numerify('####'), 'capacity' => 40, 'seated_capacity' => 30, 'standing_capacity' => 10, 'status' => 'active']);
    $fleetRoute = FleetRoute::create(['fleet_id' => $fleet->fleet_id, 'route_id' => $route->route_id, 'start_time' => '05:00', 'end_time' => '22:00', 'status' => 'active']);
    $crew = ['driver_id' => $driver->companyProfile->company_user_id, 'conductor_id' => $conductor->companyProfile->company_user_id];
    $block = ShiftBlock::create($crew + ['fleet_id' => $fleet->fleet_id, 'company_user_id' => $operator->companyProfile->company_user_id, 'block_type' => 'morning', 'scheduled_date' => '2026-10-07', 'status' => 'scheduled']);

    // departed_at / completed_at are written by the trip actions (TripRepository), not mass-assigned, so they are forced here.
    $leg = fn (string $time, string $direction, string $status, array $at = []) => tap(Trip::create($crew + [
        'fleet_route_id' => $fleetRoute->fleet_route_id, 'company_user_id' => $operator->companyProfile->company_user_id, 'trip_date' => '2026-10-07',
        'departure_time' => $time, 'trip_type' => 'one_way', 'leg_direction' => $direction, 'status' => $status, 'shift_block_id' => $block->shift_block_id,
        'current_seated_capacity' => 0, 'current_standing_capacity' => 0, 'total_occupancy' => 0,
    ]), fn (Trip $trip) => $trip->forceFill($at)->save());
    $first = $leg('08:00', 'outbound', 'completed', ['departed_at' => '2026-10-07 08:02:00', 'completed_at' => '2026-10-07 08:41:00']);
    $second = $leg('09:30', 'reverse', 'departed', ['departed_at' => '2026-10-07 09:31:00']);

    $shift = ConductorShiftLog::create(['conductor_company_user_id' => $crew['conductor_id'], 'trip_id' => $first->trip_id, 'fleet_id' => $fleet->fleet_id, 'shift_date' => '2026-10-07', 'started_at' => '2026-10-07 07:30:00', 'start_source' => 'dashboard', 'opening_cash' => 0]);

    return compact('conductor', 'driver', 'first', 'second', 'shift');
}

// Pairing is not what these tests are about: the paired-session middleware (its own tests) is left out.
beforeEach(function () {
    Carbon::setTestNow(Carbon::parse('2026-10-07 09:45:00', 'Asia/Manila'));
    $this->withoutMiddleware(RequirePairedSession::class);
});
afterEach(fn () => Carbon::setTestNow());

it('adds up every leg of the open shift, not only the leg under way', function () {
    $f = ssFixture();
    ssSale($f['first'], 40, 'cash');
    ssSale($f['first'], 55, 'online');
    ssSale($f['second'], 30, 'cash');

    $summary = $this->actingAs($f['conductor'])->getJson('/api/conductor/trips/current/earnings')->assertSuccessful()->json('data');

    expect($summary['total_fare'])->toEqual(125)
        ->and($summary['onsite_amount'])->toEqual(70)
        ->and($summary['online_amount'])->toEqual(55)
        ->and($summary['net_total'])->toEqual(125)
        ->and($summary['passenger_count'])->toBe(3)
        ->and($summary['trip_id'])->toBe($f['second']->trip_id);
    expect($summary['shift'])->not->toBeNull();
});

it('gives one Trip Breakdown row per leg, in order, with the actual times and each leg\'s fare', function () {
    $f = ssFixture();
    ssSale($f['first'], 40, 'cash');
    ssSale($f['second'], 30, 'cash');

    $rows = $this->actingAs($f['conductor'])->getJson('/api/conductor/trips/current/earnings')->assertSuccessful()->json('data.trips');

    expect($rows)->toHaveCount(2);
    expect($rows[0])->toMatchArray(['trip_id' => $f['first']->trip_id, 'origin' => 'Tagum', 'destination' => 'Panabo', 'entry_time' => '08:02', 'exit_time' => '08:41', 'fare' => 40, 'status' => 'completed']);
    expect($rows[1])->toMatchArray(['trip_id' => $f['second']->trip_id, 'origin' => 'Panabo', 'destination' => 'Tagum', 'entry_time' => '09:31', 'exit_time' => null, 'fare' => 30]);
});

it('leaves the Driver endpoint on the current trip only', function () {
    $f = ssFixture();
    ssSale($f['first'], 40, 'cash');
    ssSale($f['second'], 30, 'cash');
    DriverShiftLog::create(['driver_company_user_id' => $f['driver']->companyProfile->company_user_id, 'trip_id' => $f['first']->trip_id, 'shift_date' => '2026-10-07', 'started_at' => '2026-10-07 07:30:00', 'start_source' => 'dashboard']);

    $driver = $this->actingAs($f['driver'])->getJson('/api/driver/trips/current/earnings')->assertSuccessful()->json('data');

    expect($driver['total_fare'])->toEqual(30)->and($driver)->not->toHaveKey('trips');
});
