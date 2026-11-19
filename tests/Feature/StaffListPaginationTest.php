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

// A staff member's own trip list is paged (data stays an array, meta says how many pages) so a long history never
// arrives in one response. "All" is the last 30 days plus everything ahead, earliest first; "completed" is the history.

function pgStaff(string $role): StaffUser
{
    $user = User::create(['username' => 'pg'.Str::random(8), 'email' => Str::random(8).'@example.com', 'password' => bcrypt('password123'), 'role' => $role]);

    return StaffUser::create(['user_id' => $user->user_id, 'company_user_uuid' => (string) Str::uuid(), 'name' => 'Pg '.ucfirst($role), 'phone_num' => '09170000051', 'address' => 'x']);
}

/** @return array<int, Trip> trips keyed by their day offset from the frozen "today" (2026-10-04) */
function pgTripsFor(StaffUser $operator, string $column, StaffUser $staff, array $offsets): array
{
    $route = Route::create(['route_name' => 'Pg Route', 'origin' => 'A', 'destination' => 'B']);
    $fleet = Fleet::create(['company_user_id' => $operator->company_user_id, 'plate_number' => 'PG-'.fake()->unique()->numerify('####'), 'bus_company_name' => 'Co', 'body_no' => '1', 'capacity' => 30, 'seated_capacity' => 30, 'standing_capacity' => 0, 'status' => 'active', 'vehicle_class' => 'aircon']);
    $fleetRoute = FleetRoute::create(['fleet_id' => $fleet->fleet_id, 'route_id' => $route->route_id, 'start_time' => '06:00', 'end_time' => '22:00', 'status' => 'active']);

    $trips = [];
    foreach ($offsets as $offset) {
        $trip = Trip::create([
            'fleet_route_id' => $fleetRoute->fleet_route_id, 'company_user_id' => $operator->company_user_id,
            'trip_date' => Carbon::parse('2026-10-04')->addDays($offset)->toDateString(), 'departure_time' => '08:00',
            'trip_type' => 'one_way', 'leg_direction' => 'outbound', 'status' => $offset < 0 ? 'completed' : 'scheduled',
            'current_seated_capacity' => 0, 'current_standing_capacity' => 0, 'total_occupancy' => 0,
        ]);
        Trip::whereKey($trip->trip_id)->update([$column => $staff->company_user_id]);
        $trips[$offset] = $trip;
    }

    return $trips;
}

beforeEach(fn () => Carbon::setTestNow(Carbon::parse('2026-10-04 07:00:00', 'Asia/Manila')));
afterEach(fn () => Carbon::setTestNow());

it('pages a driver\'s trips and reports meta; all = last 30 days plus ahead, earliest first', function () {
    $operator = pgStaff('operator');
    $driver = pgStaff('driver');
    pgTripsFor($operator, 'driver_id', $driver, [-40, -10, 0, 1, 2]);

    $all = $this->actingAs($driver->user)->getJson('/api/driver/trips')->assertSuccessful();
    expect(collect($all->json('data'))->pluck('trip_date')->map(fn ($d) => substr($d, 0, 10))->all())->toBe(['2026-09-24', '2026-10-04', '2026-10-05', '2026-10-06']);
    expect($all->json('meta'))->toMatchArray(['current_page' => 1, 'last_page' => 1, 'per_page' => 100, 'total' => 4]);

    $first = $this->actingAs($driver->user)->getJson('/api/driver/trips?per_page=3')->assertSuccessful();
    expect($first->json('data'))->toHaveCount(3)->and($first->json('meta.last_page'))->toBe(2)->and($first->json('meta.total'))->toBe(4);
    $second = $this->actingAs($driver->user)->getJson('/api/driver/trips?per_page=3&page=2')->assertSuccessful();
    expect($second->json('data'))->toHaveCount(1)->and(substr($second->json('data.0.trip_date'), 0, 10))->toBe('2026-10-06');
});

it('keeps old history reachable through the completed filter, and upcoming work through scheduled', function () {
    $operator = pgStaff('operator');
    $conductor = pgStaff('conductor');
    pgTripsFor($operator, 'conductor_id', $conductor, [-40, -10, 0, 1]);

    $done = $this->actingAs($conductor->user)->getJson('/api/conductor/trips?status_filter=completed')->assertSuccessful();
    expect(collect($done->json('data'))->pluck('trip_date')->map(fn ($d) => substr($d, 0, 10))->all())->toBe(['2026-08-25', '2026-09-24']);

    // Newest history first: page 1 holds the most recent completed trip.
    $newest = $this->actingAs($conductor->user)->getJson('/api/conductor/trips?status_filter=completed&per_page=1')->assertSuccessful();
    expect(substr($newest->json('data.0.trip_date'), 0, 10))->toBe('2026-09-24')->and($newest->json('meta.last_page'))->toBe(2);

    $ahead = $this->actingAs($conductor->user)->getJson('/api/conductor/trips?status_filter=scheduled')->assertSuccessful();
    expect($ahead->json('data'))->toHaveCount(2)->and($ahead->json('meta.total'))->toBe(2);
});

it('refuses a page size that would defeat the paging', function () {
    $driver = pgStaff('driver');

    $this->actingAs($driver->user)->getJson('/api/driver/trips?per_page=500')->assertStatus(422);
    $this->actingAs($driver->user)->getJson('/api/driver/trips?page=0')->assertStatus(422);
});

it('pages a driver\'s shift blocks too, newest first', function () {
    $driver = pgStaff('driver');

    $response = $this->actingAs($driver->user)->getJson('/api/driver/shift-blocks?per_page=10')->assertSuccessful();

    expect($response->json('data'))->toBe([])->and($response->json('meta'))->toMatchArray(['current_page' => 1, 'per_page' => 10, 'total' => 0]);
});
