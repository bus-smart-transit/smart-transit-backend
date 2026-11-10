<?php

use App\Models\ConductorShiftLog;
use App\Models\Fleet;
use App\Models\FleetRoute;
use App\Models\Payment;
use App\Models\Route;
use App\Models\RouteStop;
use App\Models\StaffUser;
use App\Models\Stop;
use App\Models\Ticket;
use App\Models\Trip;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

// Batch 54 (user, 2026-11-10): the nightly repair closes earlier days' trips that were never ended, so none stays live
// for ever (a bus "arriving now" days later on the map, a Chauffeur unable to start the next shift).

afterEach(fn () => Carbon::setTestNow());

function stcStaff(string $role): User
{
    $user = User::create(['username' => $role.Str::random(6), 'email' => Str::random(8).'@example.com', 'password' => bcrypt('password123'), 'role' => $role]);
    joinTestCompany(StaffUser::create(['user_id' => $user->user_id, 'company_user_uuid' => (string) Str::uuid(), 'name' => ucfirst($role).' '.Str::random(3), 'phone_num' => '0917', 'address' => 'x']));

    return $user->fresh();
}

/** A one-hour route (last stop at 60 minutes) and a standalone trip on it. */
function stcTrip(string $date, string $time, string $status): array
{
    $operator = stcStaff('operator');
    $driver = stcStaff('driver');
    $conductor = stcStaff('conductor');
    $route = Route::create(['route_name' => 'Stale Route', 'origin' => 'Alpha', 'destination' => 'Omega']);
    foreach ([['Alpha', 7.00, 0, 0], ['Omega', 7.09, 30, 60]] as $i => [$name, $lat, $km, $offset]) {
        $stop = Stop::create(['stop_name' => $name, 'latitude' => $lat, 'longitude' => 125.0]);
        RouteStop::create(['route_id' => $route->route_id, 'stop_id' => $stop->stop_id, 'stop_order' => $i + 1, 'distance_from_origin_km' => $km, 'stop_type' => 'terminal', 'scheduled_offset_min' => $offset]);
    }
    $fleet = Fleet::create(['company_user_id' => $operator->companyProfile->company_user_id, 'plate_number' => 'ST-'.fake()->unique()->numerify('####'), 'capacity' => 40, 'seated_capacity' => 30, 'standing_capacity' => 10, 'status' => 'active']);
    $fleetRoute = FleetRoute::create(['fleet_id' => $fleet->fleet_id, 'route_id' => $route->route_id, 'start_time' => '05:00', 'end_time' => '23:59', 'status' => 'active']);
    $trip = Trip::create([
        'fleet_route_id' => $fleetRoute->fleet_route_id, 'company_user_id' => $operator->companyProfile->company_user_id,
        'driver_id' => $driver->companyProfile->company_user_id, 'conductor_id' => $conductor->companyProfile->company_user_id,
        'trip_date' => $date, 'departure_time' => $time, 'status' => $status, 'trip_type' => 'one_way',
        'current_seated_capacity' => 0, 'current_standing_capacity' => 0, 'total_occupancy' => 0,
    ]);
    $log = ConductorShiftLog::create(['conductor_company_user_id' => $conductor->companyProfile->company_user_id, 'trip_id' => $trip->trip_id, 'fleet_id' => $fleet->fleet_id, 'shift_date' => $date, 'started_at' => Carbon::parse("{$date} {$time}", 'Asia/Manila')->subMinutes(30)]);

    return [$trip, $log, $conductor];
}

function stcTicket(Trip $trip, bool $boarded): Ticket
{
    $payment = Payment::create(['amount' => 10, 'payment_created' => now(), 'transaction_reference' => 'TXN-STC-'.Str::random(6), 'payment_method' => 'online', 'payment_channel' => 'gcash', 'status' => 'paid', 'payment_uuid' => (string) Str::uuid(), 'is_valid' => true, 'items_payload' => []]);

    return Ticket::create(['fleet_route_id' => $trip->fleet_route_id, 'trip_id' => $trip->trip_id, 'payment_id' => $payment->payment_id, 'ticket_uuid' => (string) Str::uuid(), 'status' => 'valid', 'amount' => 10, 'distance_km' => 1, 'seat_type' => 'seated', 'boarded_at' => $boarded ? now() : null]);
}

it('leaves a late-night bus that may still be on the road, and completes it once it is long past its arrival', function () {
    [$trip, $log] = stcTrip('2026-11-09', '23:30', 'departed');

    // 00:30: arrival 00:30 plus the 120-minute margin is still ahead.
    Carbon::setTestNow(Carbon::parse('2026-11-10 00:30:00', 'Asia/Manila'));
    Artisan::call('shift:repair-stale', ['--apply' => true]);
    expect($trip->fresh()->status)->toBe('departed');
    expect($log->fresh()->ended_at)->toBeNull();

    // 02:31: past arrival plus the margin, so it is completed and its Chauffeur's shift closes with it.
    Carbon::setTestNow(Carbon::parse('2026-11-10 02:31:00', 'Asia/Manila'));
    Artisan::call('shift:repair-stale', ['--apply' => true]);
    expect($trip->fresh()->status)->toBe('completed');
    expect($log->fresh()->ended_at)->not->toBeNull();
});

it('completes a trip whose passengers boarded although nobody tapped Depart', function () {
    [$trip] = stcTrip('2026-11-09', '15:00', 'boarding');
    stcTicket($trip, true);

    Carbon::setTestNow(Carbon::parse('2026-11-10 00:30:00', 'Asia/Manila'));
    Artisan::call('shift:repair-stale', ['--apply' => true]);
    expect($trip->fresh()->status)->toBe('completed');
});

it('cancels a trip that never left but has paid tickets, so its passengers are told, and frees the Chauffeur', function () {
    [$trip, $log, $conductor] = stcTrip('2026-11-09', '15:00', 'boarding');
    $ticket = stcTicket($trip, false);

    Carbon::setTestNow(Carbon::parse('2026-11-10 00:30:00', 'Asia/Manila'));
    Artisan::call('shift:repair-stale');
    expect($trip->fresh()->status)->toBe('boarding'); // the dry run changes nothing

    Artisan::call('shift:repair-stale', ['--apply' => true]);
    expect($trip->fresh()->status)->toBe('cancelled');
    expect($ticket->fresh()->status)->not->toBe('valid');
    expect($log->fresh()->ended_at)->not->toBeNull();
    expect(ConductorShiftLog::where('conductor_company_user_id', $conductor->companyProfile->company_user_id)->whereNull('ended_at')->exists())->toBeFalse();
});

it('does not touch today\'s trips', function () {
    [$trip] = stcTrip('2026-11-10', '00:10', 'departed');

    Carbon::setTestNow(Carbon::parse('2026-11-10 23:00:00', 'Asia/Manila'));
    Artisan::call('shift:repair-stale', ['--apply' => true]);
    expect($trip->fresh()->status)->toBe('departed');
});
