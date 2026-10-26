<?php

use App\Models\ConductorShiftLog;
use App\Models\DriverShiftLog;
use App\Models\Fleet;
use App\Models\FleetRoute;
use App\Models\Route;
use App\Models\StaffUser;
use App\Models\Trip;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

// §4.8: Start Shift and End Shift are the crew's time in and time out. The Operator sees them for one day, on their own
// buses only, read only.

function ctStaff(string $role, string $name): User
{
    $user = User::create(['username' => 'ct'.Str::random(8), 'email' => Str::random(8).'@example.com', 'password' => bcrypt('password123'), 'role' => $role]);
    StaffUser::create(['user_id' => $user->user_id, 'company_user_uuid' => (string) Str::uuid(), 'name' => $name, 'phone_num' => '09170000080', 'address' => 'x']);

    return $user->fresh();
}

function ctTrip(User $operator, string $plate): Trip
{
    $route = Route::create(['route_name' => "Route {$plate}", 'origin' => 'A', 'destination' => 'B']);
    $fleet = Fleet::create(['company_user_id' => $operator->companyProfile->company_user_id, 'plate_number' => $plate, 'capacity' => 40, 'seated_capacity' => 30, 'standing_capacity' => 10, 'status' => 'active']);
    $fleetRoute = FleetRoute::create(['fleet_id' => $fleet->fleet_id, 'route_id' => $route->route_id, 'start_time' => '05:00', 'end_time' => '22:00', 'status' => 'active']);

    return Trip::create(['fleet_route_id' => $fleetRoute->fleet_route_id, 'company_user_id' => $operator->companyProfile->company_user_id, 'trip_date' => '2026-10-07', 'departure_time' => '08:00', 'trip_type' => 'one_way', 'leg_direction' => 'outbound', 'status' => 'completed', 'current_seated_capacity' => 0, 'current_standing_capacity' => 0, 'total_occupancy' => 0]);
}

afterEach(fn () => Carbon::setTestNow());

it('lists the day\'s time in and time out of the Operator\'s own crew, in order', function () {
    Carbon::setTestNow(Carbon::parse('2026-10-07 15:00:00', 'Asia/Manila'));
    $operator = ctStaff('operator', 'Op');
    $other = ctStaff('operator', 'Other Op');
    $driver = ctStaff('driver', 'Dina Driver');
    $chauffeur = ctStaff('conductor', 'Carl Chauffeur');
    $mine = ctTrip($operator, 'MINE-1');
    $theirs = ctTrip($other, 'THEIRS-1');

    DriverShiftLog::create(['driver_company_user_id' => $driver->companyProfile->company_user_id, 'trip_id' => $mine->trip_id, 'shift_date' => '2026-10-07', 'started_at' => '2026-10-07 07:05:00', 'ended_at' => '2026-10-07 13:20:00', 'start_source' => 'dashboard']);
    ConductorShiftLog::create(['conductor_company_user_id' => $chauffeur->companyProfile->company_user_id, 'trip_id' => $mine->trip_id, 'shift_date' => '2026-10-07', 'started_at' => '2026-10-07 07:10:00', 'start_source' => 'dashboard']);
    DriverShiftLog::create(['driver_company_user_id' => $driver->companyProfile->company_user_id, 'trip_id' => $theirs->trip_id, 'shift_date' => '2026-10-07', 'started_at' => '2026-10-07 06:00:00', 'start_source' => 'dashboard']);
    DriverShiftLog::create(['driver_company_user_id' => $driver->companyProfile->company_user_id, 'trip_id' => $mine->trip_id, 'shift_date' => '2026-10-06', 'started_at' => '2026-10-06 07:00:00', 'start_source' => 'dashboard']);

    $data = $this->actingAs($operator)->getJson('/api/operator/crew-shifts?date=2026-10-07')->assertSuccessful()->json('data');

    expect($data['date'])->toBe('2026-10-07');
    expect($data['shifts'])->toBe([
        ['role' => 'Driver', 'name' => 'Dina Driver', 'route' => 'Route MINE-1', 'bus' => 'MINE-1', 'time_in' => '07:05', 'time_out' => '13:20', 'minutes' => 375, 'open' => false],
        ['role' => 'Chauffeur', 'name' => 'Carl Chauffeur', 'route' => 'Route MINE-1', 'bus' => 'MINE-1', 'time_in' => '07:10', 'time_out' => null, 'minutes' => null, 'open' => true],
    ]);

    // Without a date it is today (Manila); another Operator's bus never shows.
    expect($this->actingAs($operator)->getJson('/api/operator/crew-shifts')->json('data.date'))->toBe('2026-10-07');
});

it('is for the Operator only and refuses a malformed date', function () {
    $driver = ctStaff('driver', 'D');
    $operator = ctStaff('operator', 'O');

    $this->actingAs($driver)->getJson('/api/operator/crew-shifts')->assertForbidden();
    $this->actingAs($operator)->getJson('/api/operator/crew-shifts?date=07/10/2026')->assertStatus(422);
});
