<?php

use App\Models\Fleet;
use App\Models\FleetRoute;
use App\Models\Route;
use App\Models\StaffUser;
use App\Models\Trip;
use App\Models\TripNotification;
use App\Models\User;
use App\Models\UserNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

// 2026-10-27: the bell groups notifications of the same kind ("3 new shift block assignments"); a group is marked read
// in one request (PATCH {role}/notifications/read-many). Only the caller's own notifications are touched.

function ngrStaff(string $role): User
{
    $user = User::create(['username' => 'ngr_'.Str::random(8), 'email' => Str::random(8).'@example.com', 'password' => bcrypt('password123'), 'role' => $role]);
    StaffUser::create(['user_id' => $user->user_id, 'company_user_uuid' => (string) Str::uuid(), 'name' => 'Ngr '.ucfirst($role), 'phone_num' => '09170000064', 'address' => 'x']);

    return $user->fresh();
}

function ngrNotify(User $user, string $title = 'New Shift Block Assignment'): UserNotification
{
    return UserNotification::create(['user_id' => $user->user_id, 'type' => 'block_assigned', 'title' => $title, 'body' => 'You have been assigned a block.', 'data' => ['type' => 'block_assigned']]);
}

it('marks a whole group of a Driver\'s notifications read at once, and never another user\'s', function () {
    $driver = ngrStaff('driver');
    $other = ngrStaff('driver');
    $group = [ngrNotify($driver), ngrNotify($driver), ngrNotify($driver)];
    $kept = ngrNotify($driver, 'Trip cancelled');
    $notMine = ngrNotify($other);

    $ids = array_merge(array_map(fn ($n) => $n->notification_id, $group), [$notMine->notification_id]);
    $this->actingAs($driver)->patchJson('/api/driver/notifications/read-many', ['ids' => $ids])
        ->assertSuccessful()
        ->assertJsonPath('data.marked', 3);

    expect(UserNotification::whereIn('notification_id', array_map(fn ($n) => $n->notification_id, $group))->whereNull('read_at')->count())->toBe(0)
        ->and($kept->fresh()->read_at)->toBeNull()
        ->and($notMine->fresh()->read_at)->toBeNull();
    $this->actingAs($driver)->getJson('/api/driver/notifications')->assertJsonPath('data.unread_count', 1);
});

it('works the same for the Chauffeur', function () {
    $chauffeur = ngrStaff('conductor');
    $n = ngrNotify($chauffeur);

    $this->actingAs($chauffeur)->patchJson('/api/conductor/notifications/read-many', ['ids' => [$n->notification_id]])->assertSuccessful();

    expect($n->fresh()->read_at)->not->toBeNull();
});

it('marks a group of the Operator\'s notifications read at once', function () {
    $operator = ngrStaff('operator');
    $route = Route::create(['route_name' => 'Ngr Route', 'origin' => 'A', 'destination' => 'B']);
    $fleet = Fleet::create(['company_user_id' => $operator->companyProfile->company_user_id, 'plate_number' => 'NGR-'.fake()->unique()->numerify('###'), 'capacity' => 40, 'seated_capacity' => 30, 'standing_capacity' => 10, 'status' => 'active']);
    $fleetRoute = FleetRoute::create(['fleet_id' => $fleet->fleet_id, 'route_id' => $route->route_id, 'start_time' => '06:00', 'end_time' => '22:00', 'status' => 'active']);
    $trip = Trip::create(['fleet_route_id' => $fleetRoute->fleet_route_id, 'company_user_id' => $operator->companyProfile->company_user_id, 'trip_date' => '2026-10-28', 'departure_time' => '08:00', 'trip_type' => 'one_way', 'status' => 'scheduled', 'current_seated_capacity' => 0, 'current_standing_capacity' => 0, 'total_occupancy' => 0]);
    $a = TripNotification::create(['trip_id' => $trip->trip_id, 'type' => 'trip_declined', 'title' => 'Trip declined', 'message' => 'A Driver declined a trip.']);
    $b = TripNotification::create(['trip_id' => $trip->trip_id, 'type' => 'trip_declined', 'title' => 'Trip declined', 'message' => 'A Chauffeur declined a trip.']);

    $this->actingAs($operator)->patchJson('/api/operator/notifications/read-many', ['ids' => [$a->id, $b->id]])
        ->assertSuccessful()
        ->assertJsonPath('data.marked', 2);
});

it('needs at least one id', function () {
    $driver = ngrStaff('driver');

    $this->actingAs($driver)->patchJson('/api/driver/notifications/read-many', ['ids' => []])->assertStatus(422);
});
