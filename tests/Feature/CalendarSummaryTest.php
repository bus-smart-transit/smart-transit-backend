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


test('calendar markers list only the days with the caller\'s own trips or shifts, by role', function () {
    $operator = calSummaryStaff('operator');
    $otherOperator = calSummaryStaff('operator');
    $driver = calSummaryStaff('driver');
    $conductor = calSummaryStaff('conductor');
    $otherDriver = calSummaryStaff('driver');
    $fr = calSummaryFleetRoute($operator);
    $otherFr = calSummaryFleetRoute($otherOperator);

    calSummaryTrip($fr, $operator, $driver, $conductor, '2026-10-15', '08:00', 'scheduled', 91);
    calSummaryTrip($fr, $operator, $driver, $conductor, '2026-10-15', '15:00', 'scheduled', 91);
    calSummaryTrip($fr, $operator, $driver, null, '2026-10-20', '09:00');
    calSummaryTrip($fr, $operator, $driver, null, '2026-10-21', '09:00', 'cancelled'); // never marked
    calSummaryTrip($fr, $operator, $otherDriver, null, '2026-10-22', '09:00'); // someone else's
    calSummaryTrip($otherFr, $otherOperator, null, null, '2026-10-23', '09:00'); // another company
    calSummaryTrip($fr, $operator, $driver, null, '2026-11-20', '09:00'); // outside the asked range

    $range = ['from' => '2026-10-01', 'to' => '2026-10-31'];

    $this->actingAs($driver)->getJson('/api/staff/calendar/days?'.http_build_query($range))
        ->assertSuccessful()
        ->assertJsonPath('data.days', [
            ['date' => '2026-10-15', 'trips' => 2, 'shifts' => 1],
            ['date' => '2026-10-20', 'trips' => 1, 'shifts' => 0],
        ]);

    // The Chauffeur sees the legs they are on; the Operator sees their own fleets' days, not another company's.
    $this->actingAs($conductor)->getJson('/api/staff/calendar/days?'.http_build_query($range))
        ->assertJsonCount(1, 'data.days')->assertJsonPath('data.days.0.date', '2026-10-15');
    $this->actingAs($operator)->getJson('/api/staff/calendar/days?'.http_build_query($range))
        ->assertJsonCount(3, 'data.days'); // the 15th, 20th and 22nd (its own fleet, another driver); not the cancelled 21st or another company's 23rd
    $this->actingAs($otherOperator)->getJson('/api/staff/calendar/days?'.http_build_query($range))
        ->assertJsonCount(1, 'data.days')->assertJsonPath('data.days.0.date', '2026-10-23');
});

test('calendar markers validate the range', function () {
    $driver = calSummaryStaff('driver');
    $get = fn (array $q) => $this->actingAs($driver)->getJson('/api/staff/calendar/days?'.http_build_query($q));

    $get([])->assertStatus(422)->assertJsonValidationErrors(['from', 'to']);
    $get(['from' => '2026-10-10', 'to' => '2026-10-01'])->assertStatus(422)->assertJsonValidationErrors(['to']);
    $get(['from' => 'x', 'to' => '2026-10-01'])->assertStatus(422)->assertJsonValidationErrors(['from']);
    $get(['from' => '2026-01-01', 'to' => '2026-12-31'])->assertStatus(422)->assertJsonValidationErrors(['to']); // wider than allowed
    $get(['from' => '2026-09-27', 'to' => '2026-11-07'])->assertSuccessful();
});

test('the day list holds only that day\'s own items, earliest first, paginated, with the leg\'s direction and the caller\'s role', function () {
    $operator = calSummaryStaff('operator');
    $driver = calSummaryStaff('driver');
    $otherDriver = calSummaryStaff('driver');
    $fr = calSummaryFleetRoute($operator);

    $late = calSummaryTrip($fr, $operator, $driver, null, '2026-10-20', '15:00');
    $early = calSummaryTrip($fr, $operator, $driver, null, '2026-10-20', '08:00', 'scheduled', 93);
    $back = calSummaryTrip($fr, $operator, $driver, null, '2026-10-20', '12:00');
    $back->update(['leg_direction' => 'reverse']);
    calSummaryTrip($fr, $operator, $driver, null, '2026-10-21', '08:00'); // another day
    calSummaryTrip($fr, $operator, $driver, null, '2026-10-20', '09:00', 'cancelled');
    calSummaryTrip($fr, $operator, $otherDriver, null, '2026-10-20', '07:00'); // someone else's

    $page1 = $this->actingAs($driver)->getJson('/api/staff/calendar/day?date=2026-10-20&per_page=2')->assertSuccessful()->json('data');

    expect(array_column($page1['items'], 'trip_id'))->toBe([$early->trip_id, $back->trip_id]);
    expect([$page1['date'], $page1['page'], $page1['per_page'], $page1['total'], $page1['last_page']])->toBe(['2026-10-20', 1, 2, 3, 2]);
    expect($page1['items'][0])->toMatchArray(['type' => 'shift', 'origin' => 'A', 'destination' => 'B', 'role' => 'driver', 'status' => 'scheduled', 'leg_direction' => 'outbound']);
    expect($page1['items'][1])->toMatchArray(['type' => 'trip', 'origin' => 'B', 'destination' => 'A', 'leg_direction' => 'reverse']); // the return leg reads B to A

    $page2 = $this->actingAs($driver)->getJson('/api/staff/calendar/day?date=2026-10-20&per_page=2&page=2')->json('data');
    expect(array_column($page2['items'], 'trip_id'))->toBe([$late->trip_id]);

    // The Operator's list for the day covers the whole fleet, labelled with their own role.
    $operatorDay = $this->actingAs($operator)->getJson('/api/staff/calendar/day?date=2026-10-20')->json('data');
    expect($operatorDay['total'])->toBe(4);
    expect($operatorDay['items'][0]['role'])->toBe('operator');

    $this->actingAs($driver)->getJson('/api/staff/calendar/day?date=2026-10-25')->assertSuccessful()->assertJsonPath('data.items', [])->assertJsonPath('data.total', 0);
});

test('the day list validates its input and the calendar endpoints reject passengers', function () {
    $driver = calSummaryStaff('driver');

    foreach (['/api/staff/calendar/days?from=2026-10-01&to=2026-10-31', '/api/staff/calendar/day?date=2026-10-20'] as $url) {
        $this->getJson($url)->assertUnauthorized();
    }

    $this->actingAs($driver)->getJson('/api/staff/calendar/day')->assertStatus(422)->assertJsonValidationErrors(['date']);
    $this->actingAs($driver)->getJson('/api/staff/calendar/day?date=tomorrow')->assertStatus(422)->assertJsonValidationErrors(['date']);
    $this->actingAs($driver)->getJson('/api/staff/calendar/day?date=2026-10-20&per_page=999')->assertStatus(422)->assertJsonValidationErrors(['per_page']);

    foreach (['/api/staff/calendar/days?from=2026-10-01&to=2026-10-31', '/api/staff/calendar/day?date=2026-10-20'] as $url) {
        $passenger = User::create(['username' => 'pax'.md5($url), 'email' => md5($url).'@example.com', 'password' => bcrypt('password123'), 'role' => 'passenger']);
        $this->actingAs($passenger)->getJson($url)->assertForbidden();
    }
});
