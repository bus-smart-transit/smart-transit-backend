<?php

use App\Models\Fleet;
use App\Models\FleetRoute;
use App\Models\ShiftBlock;
use App\Models\Route;
use App\Models\StaffUser;
use App\Models\Trip;
use App\Models\TripAssignmentRequest;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

function calSummaryStaff(string $role): User
{
    $user = User::create([
        'username' => fake()->unique()->userName(),
        'email' => fake()->unique()->safeEmail(),
        'password' => bcrypt('password123'),
        'role' => $role,
    ]);

    StaffUser::create([
        'user_id' => $user->user_id,
        'company_user_uuid' => (string) Str::uuid(),
        'name' => fake()->name(),
        'phone_num' => fake()->phoneNumber(),
        'address' => fake()->address(),
    ]);

    return $user->fresh();
}

function calSummaryFleetRoute(User $operator): FleetRoute
{
    $route = Route::create(['route_name' => 'Cal Route', 'origin' => 'A', 'destination' => 'B']);
    $fleet = Fleet::create([
        'company_user_id' => $operator->companyProfile->company_user_id,
        'plate_number' => 'CAL-'.fake()->unique()->numerify('###'),
        'capacity' => 50,
        'seated_capacity' => 40,
        'standing_capacity' => 10,
        'status' => 'active',
    ]);

    return FleetRoute::create([
        'fleet_id' => $fleet->fleet_id,
        'route_id' => $route->route_id,
        'start_time' => '06:00',
        'end_time' => '22:00',
        'status' => 'active',
    ]);
}

/** $blockKey groups legs into one real shift block per operator and key. */
function calSummaryTrip(FleetRoute $fr, User $operator, ?User $driver, ?User $conductor, string $date, string $time, string $status = 'scheduled', ?int $blockKey = null): Trip
{
    static $blocks = [];
    $blockId = null;
    if ($blockKey !== null) {
        $cacheKey = $operator->user_id.'-'.$blockKey;
        $blocks[$cacheKey] ??= ShiftBlock::create([
            'fleet_id' => $fr->fleet_id,
            'driver_id' => ($driver ?? $operator)->companyProfile->company_user_id,
            'conductor_id' => ($conductor ?? $operator)->companyProfile->company_user_id,
            'company_user_id' => $operator->companyProfile->company_user_id,
            'block_type' => 'morning',
            'status' => 'scheduled',
            'audit_status' => 'not_applicable',
            'scheduled_date' => $date,
        ])->shift_block_id;
        $blockId = $blocks[$cacheKey];
    }

    return Trip::create([
        'fleet_route_id' => $fr->fleet_route_id,
        'company_user_id' => $operator->companyProfile->company_user_id,
        'trip_date' => $date,
        'departure_time' => $time,
        'status' => $status,
        'driver_id' => $driver?->companyProfile->company_user_id,
        'conductor_id' => $conductor?->companyProfile->company_user_id,
        'shift_block_id' => $blockId,
        'current_seated_capacity' => 0,
        'current_standing_capacity' => 0,
        'total_occupancy' => 0,
    ]);
}

beforeEach(function () {
    config(['app.timezone' => 'Asia/Manila', 'calendar.week_start_day' => 0]);
    // 00:30 on Thursday 2026-10-15 in Manila is still 2026-10-14 in UTC: day/week bounds must follow Manila.
    Carbon::setTestNow(Carbon::parse('2026-10-15 00:30:00', 'Asia/Manila'));
});

afterEach(fn () => Carbon::setTestNow());

test('driver summary counts only their own trips, with Manila day and week bounds', function () {
    $operator = calSummaryStaff('operator');
    $driver = calSummaryStaff('driver');
    $other = calSummaryStaff('driver');
    $fr = calSummaryFleetRoute($operator);

    calSummaryTrip($fr, $operator, $driver, null, '2026-10-15', '08:00', 'completed', 1);
    calSummaryTrip($fr, $operator, $driver, null, '2026-10-15', '15:00', 'scheduled', 1);
    calSummaryTrip($fr, $operator, $driver, null, '2026-10-17', '09:00', 'scheduled');
    calSummaryTrip($fr, $operator, $driver, null, '2026-10-16', '09:00', 'cancelled');
    calSummaryTrip($fr, $operator, $driver, null, '2026-10-18', '09:00', 'scheduled'); // next Sunday: outside the week
    calSummaryTrip($fr, $operator, $driver, null, '2026-10-11', '09:00', 'completed'); // week start (Sunday)
    calSummaryTrip($fr, $operator, $other, null, '2026-10-15', '10:00');

    $response = $this->actingAs($driver)->getJson('/api/staff/calendar-summary')->assertSuccessful();

    $response
        ->assertJsonPath('data.today.date', '2026-10-15')
        ->assertJsonPath('data.today.trips', 2)
        ->assertJsonPath('data.today.shifts', 1)
        ->assertJsonPath('data.week.start', '2026-10-11')
        ->assertJsonPath('data.week.end', '2026-10-17')
        ->assertJsonPath('data.week.trips', 4)
        ->assertJsonPath('data.week.shifts', 1)
        ->assertJsonMissingPath('data.for_approval_count');
});

test('next item skips completed and cancelled trips', function () {
    $operator = calSummaryStaff('operator');
    $conductor = calSummaryStaff('conductor');
    $fr = calSummaryFleetRoute($operator);

    calSummaryTrip($fr, $operator, null, $conductor, '2026-10-15', '08:00', 'completed');
    calSummaryTrip($fr, $operator, null, $conductor, '2026-10-16', '05:00', 'cancelled');
    $next = calSummaryTrip($fr, $operator, null, $conductor, '2026-10-17', '07:30', 'scheduled', 5);
    calSummaryTrip($fr, $operator, null, $conductor, '2026-10-17', '09:00', 'scheduled', 5);

    $this->actingAs($conductor)->getJson('/api/staff/calendar-summary')
        ->assertSuccessful()
        ->assertJsonPath('data.next.trip_id', $next->trip_id)
        ->assertJsonPath('data.next.type', 'shift')
        ->assertJsonPath('data.next.date', '2026-10-17');
});

test('next item is null when nothing is left to run', function () {
    $driver = calSummaryStaff('driver');

    $this->actingAs($driver)->getJson('/api/staff/calendar-summary')
        ->assertSuccessful()
        ->assertJsonPath('data.next', null)
        ->assertJsonPath('data.today.trips', 0);
});

test('operator summary covers their own trips and their For Approval count only', function () {
    $operator = calSummaryStaff('operator');
    $otherOperator = calSummaryStaff('operator');
    $driver = calSummaryStaff('driver');
    $fr = calSummaryFleetRoute($operator);
    $otherFr = calSummaryFleetRoute($otherOperator);

    $mine = calSummaryTrip($fr, $operator, $driver, null, '2026-10-15', '15:00');
    $theirs = calSummaryTrip($otherFr, $otherOperator, null, null, '2026-10-15', '15:00');

    foreach ([$mine, $theirs] as $trip) {
        TripAssignmentRequest::create([
            'trip_id' => $trip->trip_id,
            'role' => 'driver',
            'staff_company_user_id' => $driver->companyProfile->company_user_id,
            'status' => TripAssignmentRequest::FOR_APPROVAL,
        ]);
    }

    $this->actingAs($operator)->getJson('/api/staff/calendar-summary')
        ->assertSuccessful()
        ->assertJsonPath('data.today.trips', 1)
        ->assertJsonPath('data.for_approval_count', 1);
});

test('week start day is configurable', function () {
    config(['calendar.week_start_day' => 1]);
    $driver = calSummaryStaff('driver');

    $this->actingAs($driver)->getJson('/api/staff/calendar-summary')
        ->assertJsonPath('data.week.start', '2026-10-12')
        ->assertJsonPath('data.week.end', '2026-10-18');
});

test('calendar summary rejects passengers and unauthenticated callers', function () {
    $this->getJson('/api/staff/calendar-summary')->assertUnauthorized();

    $passenger = User::create([
        'username' => 'pax',
        'email' => 'pax@example.com',
        'password' => bcrypt('password123'),
        'role' => 'passenger',
    ]);
    $this->actingAs($passenger)->getJson('/api/staff/calendar-summary')->assertForbidden();
});
