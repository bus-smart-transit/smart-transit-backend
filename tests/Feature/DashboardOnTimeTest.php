<?php

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

// Batch 81 (user, 2026-11-19): the Operator dashboard's On-time trips, from the trips that really left. SAMPLE data.

function dmOperator(): User
{
    $user = User::create(['username' => 'dm'.Str::random(6), 'email' => Str::random(8).'@example.com', 'password' => bcrypt('password123'), 'role' => 'operator']);
    joinTestCompany(StaffUser::create(['user_id' => $user->user_id, 'company_user_uuid' => (string) Str::uuid(), 'name' => 'Dm Operator', 'phone_num' => '09170000000', 'address' => 'x']));

    return $user->fresh();
}

function dmTrip(User $operator, string $date, string $time, ?string $departedAt, string $status = 'completed'): Trip
{
    $company = $operator->companyProfile->company_user_id;
    $route = Route::create(['route_name' => 'Dm Route '.Str::random(4), 'origin' => 'A', 'destination' => 'B']);
    $fleet = Fleet::create(['company_user_id' => $company, 'plate_number' => 'DM-'.fake()->unique()->numerify('####'), 'capacity' => 30, 'seated_capacity' => 30, 'standing_capacity' => 0, 'status' => 'active']);
    $fleetRoute = FleetRoute::create(['fleet_id' => $fleet->fleet_id, 'route_id' => $route->route_id, 'start_time' => '05:00', 'end_time' => '22:00', 'status' => 'active']);

    $trip = Trip::create([
        'fleet_route_id' => $fleetRoute->fleet_route_id, 'company_user_id' => $company, 'trip_date' => $date, 'departure_time' => $time,
        'trip_type' => 'one_way', 'leg_direction' => 'outbound', 'status' => $status, 'current_seated_capacity' => 0, 'current_standing_capacity' => 0,
        'total_occupancy' => 0,
    ]);
    $trip->forceFill(['departed_at' => $departedAt ? Carbon::parse($departedAt, 'Asia/Manila') : null])->save();

    return $trip;
}

beforeEach(fn () => Carbon::setTestNow(Carbon::parse('2026-11-19 12:00:00', 'Asia/Manila')));
afterEach(fn () => Carbon::setTestNow());

it('counts a trip as on time when it left within 5 minutes of schedule, today and the same weekday last week', function () {
    $operator = dmOperator();
    dmTrip($operator, '2026-11-19', '08:00', '2026-11-19 08:03:00');                 // on time
    dmTrip($operator, '2026-11-19', '09:00', '2026-11-19 09:05:00');                 // on time (exactly 5)
    dmTrip($operator, '2026-11-19', '10:00', '2026-11-19 10:12:00');                 // late
    dmTrip($operator, '2026-11-19', '11:00', '2026-11-19 11:01:00', 'cancelled');    // cancelled: not counted
    dmTrip($operator, '2026-11-19', '15:00', null, 'scheduled');                      // not left yet: not counted
    dmTrip($operator, '2026-11-12', '08:00', '2026-11-12 08:20:00');                 // last week: late
    dmTrip($operator, '2026-11-12', '09:00', '2026-11-12 09:00:00');                 // last week: on time
    dmTrip(dmOperator(), '2026-11-19', '08:00', '2026-11-19 09:00:00');              // another company: never seen

    $data = $this->actingAs($operator)->getJson('/api/operator/dashboard/on-time')->assertOk()->json('data');

    expect($data['grace_minutes'])->toBe(5)
        ->and($data['today'])->toBe(['date' => '2026-11-19', 'departed' => 3, 'on_time' => 2, 'percent' => 67])
        ->and($data['last_week'])->toBe(['date' => '2026-11-12', 'departed' => 2, 'on_time' => 1, 'percent' => 50]);
});

it('says Not provided (null), never 0 percent, on a day with no departed trip', function () {
    $operator = dmOperator();

    $data = $this->actingAs($operator)->getJson('/api/operator/dashboard/on-time')->assertOk()->json('data');

    expect($data['today']['percent'])->toBeNull()->and($data['last_week']['percent'])->toBeNull();
});

it('is for Operators only', function () {
    $passenger = User::create(['username' => 'dmp'.Str::random(5), 'email' => Str::random(8).'@example.com', 'password' => bcrypt('password123'), 'role' => 'passenger']);
    $this->actingAs($passenger)->getJson('/api/operator/dashboard/on-time')->assertForbidden();
});
