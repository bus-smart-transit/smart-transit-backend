<?php

use App\Models\Fleet;
use App\Models\FleetRoute;
use App\Models\Route;
use App\Models\StaffUser;
use App\Models\Trip;
use App\Models\User;
use App\Repositories\ReportingRepository;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

// 2026-11-27 (production test): the bus reports counted a refunded online ticket as revenue (a refunded payment stays
// "paid"), and read 0 passengers for a finished trip (trips.total_occupancy is the seats taken now, given back at
// drop-off). Self-contained fixtures with made-up names.

/** @return array{fleet: Fleet, fleetRoute: FleetRoute, trip: Trip} */
function frnWorld(): array
{
    $user = User::create(['username' => 'op'.Str::random(6), 'email' => Str::random(8).'@example.com', 'password' => bcrypt('password123'), 'role' => 'operator']);
    $staff = StaffUser::create(['user_id' => $user->user_id, 'company_user_uuid' => (string) Str::uuid(), 'name' => 'Operator Frn', 'phone_num' => '0917', 'address' => 'x']);
    $route = Route::create(['route_name' => 'Frn Route', 'origin' => 'Alpha', 'destination' => 'Omega']);
    $fleet = Fleet::create(['company_user_id' => $staff->company_user_id, 'plate_number' => 'FRN-'.Str::upper(Str::random(4)), 'capacity' => 20, 'seated_capacity' => 20, 'standing_capacity' => 0, 'status' => 'active']);
    $fleetRoute = FleetRoute::create(['fleet_id' => $fleet->fleet_id, 'route_id' => $route->route_id, 'start_time' => '06:00', 'end_time' => '22:00', 'status' => 'active']);
    $trip = Trip::create([
        'fleet_route_id' => $fleetRoute->fleet_route_id, 'company_user_id' => $staff->company_user_id, 'trip_date' => '2026-10-05', 'departure_time' => '08:00',
        'trip_type' => 'one_way', 'status' => 'completed', 'current_seated_capacity' => 0, 'current_standing_capacity' => 0, 'total_occupancy' => 0,
    ]);

    return compact('fleet', 'fleetRoute', 'trip');
}

/** One paid payment with one ticket; returns the ticket id. */
function frnTicket(array $world, float $amount, string $method, string $channel, string $status, ?string $boardedAt): int
{
    $paymentId = DB::table('payments')->insertGetId([
        'amount' => $amount, 'payment_created' => '2026-10-05 07:00:00', 'transaction_reference' => 'TX-'.Str::upper(Str::random(8)), 'payment_method' => $method,
        'payment_channel' => $channel, 'status' => 'paid', 'payment_uuid' => (string) Str::uuid(), 'is_valid' => true, 'created_at' => now(), 'updated_at' => now(),
    ], 'payment_id');

    return DB::table('tickets')->insertGetId([
        'fleet_route_id' => $world['fleetRoute']->fleet_route_id, 'trip_id' => $world['trip']->trip_id, 'payment_id' => $paymentId, 'ticket_uuid' => (string) Str::uuid(),
        'status' => $status, 'seat_type' => 'seated', 'amount' => $amount, 'boarded_at' => $boardedAt, 'created_at' => now(), 'updated_at' => now(),
    ], 'ticket_id');
}

it('leaves a refunded ticket out of the bus reports and counts the passengers who rode a finished trip', function () {
    $w = frnWorld();
    frnTicket($w, 12, 'online', 'gcash', 'alighted', '2026-10-05 08:01:00');
    frnTicket($w, 12, 'cash', 'onsite', 'alighted', '2026-10-05 08:02:00');
    $refunded = frnTicket($w, 12, 'online', 'gcash', 'refunded', null);
    $cancellation = DB::table('trip_cancellations')->insertGetId(['trip_id' => $w['trip']->trip_id, 'stage' => 'before_departure', 'cancelled_at' => '2026-10-05 07:30:00', 'created_at' => now(), 'updated_at' => now()]);
    DB::table('ticket_resolutions')->insert([
        'ticket_id' => $refunded, 'trip_cancellation_id' => $cancellation, 'resolution' => 'refund', 'status' => 'succeeded', 'method' => 'gateway', 'amount' => 12,
        'requested_at' => '2026-10-04 23:30:00', 'completed_at' => '2026-10-04 23:31:00', 'created_at' => now(), 'updated_at' => now(),
    ]);
    $reports = app(ReportingRepository::class);
    $fleetId = $w['fleet']->fleet_id;

    $daily = $reports->getDailySummary($fleetId, '2026-10-05')['summary'];
    expect($daily['total_revenue'])->toEqual(24)
        ->and($daily['total_tickets'])->toBe(2)
        ->and($daily['average_occupancy'])->toEqual(2); // was 0: the seats were given back when the passengers got off

    $financial = $reports->getFleetFinancialSummary($fleetId, '2026-10-05', '2026-10-05');
    expect($financial['revenue']['total'])->toEqual(24)
        ->and($financial['revenue']['online'])->toEqual(12)
        ->and($financial['revenue']['onsite_cash'])->toEqual(12)
        ->and($financial['tickets']['total'])->toBe(2);

    $route = $reports->getRevenueByRoute($fleetId, '2026-10-05', '2026-10-05')->first();
    expect($route['total_revenue'])->toEqual(24)->and($route['total_tickets'])->toBe(2);

    $channels = collect($reports->getPaymentChannelBreakdown($fleetId, '2026-10-05', '2026-10-05')['channels'])->keyBy('channel');
    expect($channels['gcash']['total_amount'])->toEqual(12)->and($channels['onsite']['total_amount'])->toEqual(12);

    $occupancy = $reports->getOccupancyTrends($fleetId, '2026-10-05', '2026-10-05')['occupancy'];
    expect($occupancy['average'])->toEqual(2)->and($occupancy['peak'])->toBe(2);
});

it('keeps a pending refund in the revenue until it succeeds', function () {
    $w = frnWorld();
    $ticket = frnTicket($w, 12, 'online', 'gcash', 'valid', null);
    $cancellation = DB::table('trip_cancellations')->insertGetId(['trip_id' => $w['trip']->trip_id, 'stage' => 'before_departure', 'cancelled_at' => '2026-10-05 07:30:00', 'created_at' => now(), 'updated_at' => now()]);
    DB::table('ticket_resolutions')->insert([
        'ticket_id' => $ticket, 'trip_cancellation_id' => $cancellation, 'resolution' => 'refund', 'status' => 'pending', 'method' => 'gateway', 'amount' => 12,
        'requested_at' => '2026-10-04 23:30:00', 'created_at' => now(), 'updated_at' => now(),
    ]);

    expect(app(ReportingRepository::class)->getDailySummary($w['fleet']->fleet_id, '2026-10-05')['summary']['total_revenue'])->toEqual(12);
});
