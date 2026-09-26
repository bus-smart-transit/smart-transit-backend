<?php

use App\Models\Fleet;
use App\Models\FleetRoute;
use App\Models\Route;
use App\Models\StaffUser;
use App\Models\Trip;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

// S1 (Batch 17): notification-bell feed. FcmService persists a row via
// UserNotificationRepository on every sendToUser() call regardless of
// whether the push itself succeeds — no Firebase credentials are
// configured in the testing env, so isEnabled() is false and the push is
// skipped, but persistence must still happen (that's the whole point: the
// bell must work even when push delivery fails/isn't configured).
function makeBellTestStaff(string $role): array
{
    $user = User::create([
        'username' => $role . '_bell_' . uniqid(),
        'email' => $role . '+bell' . uniqid() . '@smarttransit.test',
        'password' => bcrypt('password123'),
        'role' => $role,
    ]);

    $staff = StaffUser::create([
        'company_user_uuid' => (string) Str::uuid(),
        'user_id' => $user->user_id,
        'name' => ucfirst($role) . ' Bell Test',
        'phone_num' => '09170000099',
        'address' => 'Test Address',
    ]);

    return [$user->fresh(), $staff];
}

function makeBellTestTrip(int $companyUserId, ?string $tripDate = null): Trip
{
    $route = Route::create([
        'route_name' => 'Bell Test Route',
        'origin' => 'Terminal A',
        'destination' => 'Terminal B',
    ]);

    $fleet = Fleet::create([
        'company_user_id' => $companyUserId,
        'plate_number' => 'BEL-' . fake()->unique()->numerify('###'),
        'capacity' => 50,
        'seated_capacity' => 40,
        'standing_capacity' => 10,
        'status' => 'active',
    ]);

    $fleetRoute = FleetRoute::create([
        'fleet_id' => $fleet->fleet_id,
        'route_id' => $route->route_id,
        'start_time' => '06:00',
        'end_time' => '22:00',
        'status' => 'active',
    ]);

    return Trip::create([
        'fleet_route_id' => $fleetRoute->fleet_route_id,
        'company_user_id' => $companyUserId,
        'trip_date' => $tripDate ?? now()->toDateString(),
        'status' => 'scheduled',
        'current_seated_capacity' => 0,
        'current_standing_capacity' => 0,
        'total_occupancy' => 0,
    ]);
}

test('driver notification bell surfaces a trip assignment and can be marked read', function () {
    [$operatorUser, $operator] = makeBellTestStaff('operator');
    [$driverUser, $driver] = makeBellTestStaff('driver');
    $trip = makeBellTestTrip($operator->company_user_id);

    $this->actingAs($operatorUser)
        ->patchJson("/api/operator/trips/{$trip->trip_id}/driver", ['driver_id' => $driver->company_user_id])
        ->assertSuccessful();

    $list = $this->actingAs($driverUser)->getJson('/api/driver/notifications');

    $list->assertSuccessful()
        ->assertJsonPath('data.unread_count', 1)
        ->assertJsonCount(1, 'data.notifications')
        ->assertJsonPath('data.notifications.0.type', 'trip_assigned')
        ->assertJsonPath('data.notifications.0.read_at', null);

    $notificationId = $list->json('data.notifications.0.notification_id');

    $this->actingAs($driverUser)
        ->patchJson("/api/driver/notifications/{$notificationId}/read")
        ->assertSuccessful();

    $this->actingAs($driverUser)
        ->getJson('/api/driver/notifications')
        ->assertJsonPath('data.unread_count', 0);
});

test('conductor notification bell mark-all-read clears the unread count', function () {
    [$operatorUser, $operator] = makeBellTestStaff('operator');
    [$conductorUser, $conductor] = makeBellTestStaff('conductor');
    // S2 corrected model (Batch 17 continuation): a staff member can only
    // hold ONE assignment per day, so these two notification-generating
    // assignments must land on different dates.
    $tripA = makeBellTestTrip($operator->company_user_id, now()->toDateString());
    $tripB = makeBellTestTrip($operator->company_user_id, now()->addDay()->toDateString());

    $this->actingAs($operatorUser)->patchJson("/api/operator/trips/{$tripA->trip_id}/conductor", ['conductor_id' => $conductor->company_user_id])->assertSuccessful();
    $this->actingAs($operatorUser)->patchJson("/api/operator/trips/{$tripB->trip_id}/conductor", ['conductor_id' => $conductor->company_user_id])->assertSuccessful();

    $this->actingAs($conductorUser)
        ->getJson('/api/conductor/notifications')
        ->assertJsonPath('data.unread_count', 2);

    $this->actingAs($conductorUser)
        ->patchJson('/api/conductor/notifications/read-all')
        ->assertSuccessful();

    $this->actingAs($conductorUser)
        ->getJson('/api/conductor/notifications')
        ->assertJsonPath('data.unread_count', 0);
});

test('a driver cannot mark another driver\'s notification as read', function () {
    [$operatorUser, $operator] = makeBellTestStaff('operator');
    [$driverUser, $driver] = makeBellTestStaff('driver');
    [$otherDriverUser] = makeBellTestStaff('driver');
    $trip = makeBellTestTrip($operator->company_user_id);

    $this->actingAs($operatorUser)
        ->patchJson("/api/operator/trips/{$trip->trip_id}/driver", ['driver_id' => $driver->company_user_id])
        ->assertSuccessful();

    $notificationId = $this->actingAs($driverUser)
        ->getJson('/api/driver/notifications')
        ->json('data.notifications.0.notification_id');

    $this->actingAs($otherDriverUser)
        ->patchJson("/api/driver/notifications/{$notificationId}/read")
        ->assertSuccessful();

    // Still unread for the actual owner — the other driver's request was a
    // silent no-op (repository scopes the UPDATE by user_id).
    $this->actingAs($driverUser)
        ->getJson('/api/driver/notifications')
        ->assertJsonPath('data.unread_count', 1);
});
