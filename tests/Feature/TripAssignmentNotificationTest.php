<?php

use App\Models\Fleet;
use App\Models\FleetRoute;
use App\Models\Route;
use App\Models\StaffUser;
use App\Models\Trip;
use App\Models\User;
use App\Services\FcmService;
use App\Services\TripService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

// Follow-up fix: assignDriver() sent a push notification (Batch 15, Item 2)
// but assignConductor() did not, an inconsistency caught by the user
// directly asking "are both driver and conductor notified?" — the answer
// was no until this fix. These tests lock in that both roles are now
// notified symmetrically, using a mocked FcmService bound in the container
// (never a real Firebase network call).
function makeStaffMemberForAssignmentNotificationTest(string $role): StaffUser
{
    $user = User::create([
        'username' => $role . '_' . uniqid(),
        'email' => $role . '+' . uniqid() . '@smarttransit.test',
        'password' => bcrypt('password123'),
        'role' => $role,
    ]);

    return StaffUser::create([
        'company_user_uuid' => (string) Str::uuid(),
        'user_id' => $user->user_id,
        'name' => ucfirst($role) . ' Notification Test',
        'phone_num' => '09170000025',
        'address' => 'Test Address',
    ]);
}

function makeUnassignedTripForNotificationTest(int $companyUserId): Trip
{
    $route = Route::create([
        'route_name' => 'Notification Test Route',
        'origin' => 'A',
        'destination' => 'B',
    ]);

    $fleet = Fleet::create([
        'company_user_id' => $companyUserId,
        'plate_number' => 'NOTIF-' . fake()->unique()->numerify('###'),
        'capacity' => 40,
        'seated_capacity' => 30,
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
        'trip_date' => now()->toDateString(),
        'departure_time' => '08:30',
        'trip_type' => 'one_way',
        'status' => 'scheduled',
        'current_seated_capacity' => 0,
        'current_standing_capacity' => 0,
        'total_occupancy' => 0,
    ]);
}

test('assigning a driver sends that driver a push notification', function () {
    $operator = makeStaffMemberForAssignmentNotificationTest('operator');
    $driver = makeStaffMemberForAssignmentNotificationTest('driver');
    $trip = makeUnassignedTripForNotificationTest($operator->company_user_id);

    $this->mock(FcmService::class, function ($mock) use ($driver) {
        $mock->shouldReceive('sendToUser')
            ->once()
            ->withArgs(fn ($user, $title) => $user->user_id === $driver->user_id && $title === 'New Trip Assignment')
            ->andReturn(true);
    });

    app(TripService::class)->assignDriver($trip->trip_id, ['driver_id' => $driver->company_user_id]);
});

test('assigning a conductor sends that conductor a push notification', function () {
    $operator = makeStaffMemberForAssignmentNotificationTest('operator');
    $conductor = makeStaffMemberForAssignmentNotificationTest('conductor');
    $trip = makeUnassignedTripForNotificationTest($operator->company_user_id);

    $this->mock(FcmService::class, function ($mock) use ($conductor) {
        $mock->shouldReceive('sendToUser')
            ->once()
            ->withArgs(fn ($user, $title) => $user->user_id === $conductor->user_id && $title === 'New Trip Assignment')
            ->andReturn(true);
    });

    app(TripService::class)->assignConductor($trip->trip_id, ['conductor_id' => $conductor->company_user_id]);
});

test('assigning a driver does not notify any other driver', function () {
    $operator = makeStaffMemberForAssignmentNotificationTest('operator');
    $assignedDriver = makeStaffMemberForAssignmentNotificationTest('driver');
    $otherDriver = makeStaffMemberForAssignmentNotificationTest('driver');
    $trip = makeUnassignedTripForNotificationTest($operator->company_user_id);

    $this->mock(FcmService::class, function ($mock) use ($assignedDriver, $otherDriver) {
        $mock->shouldReceive('sendToUser')
            ->once()
            ->withArgs(function ($user) use ($assignedDriver, $otherDriver) {
                expect($user->user_id)->not->toBe($otherDriver->user_id);
                return $user->user_id === $assignedDriver->user_id;
            })
            ->andReturn(true);
    });

    app(TripService::class)->assignDriver($trip->trip_id, ['driver_id' => $assignedDriver->company_user_id]);
});

// Follow-up fix: scheduleTrip() creates the trip with driver_id/conductor_id
// already set directly in the payload (the normal way an Operator creates a
// trip) rather than via assignDriver()/assignConductor() — so a
// newly-created trip with both roles assigned up front previously sent NO
// notification at all. Caught by the user directly asking "does trip
// creation also notify the assigned driver/conductor?"
test('scheduling a new trip with driver and conductor assigned up front notifies both', function () {
    $operator = makeStaffMemberForAssignmentNotificationTest('operator');
    $driver = makeStaffMemberForAssignmentNotificationTest('driver');
    $conductor = makeStaffMemberForAssignmentNotificationTest('conductor');

    $route = Route::create(['route_name' => 'Schedule Notification Route', 'origin' => 'A', 'destination' => 'B']);
    $fleet = Fleet::create([
        'company_user_id' => $operator->company_user_id,
        'plate_number' => 'SCHED-' . fake()->unique()->numerify('###'),
        'capacity' => 40,
        'seated_capacity' => 30,
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

    $notifiedUserIds = [];
    $this->mock(FcmService::class, function ($mock) use (&$notifiedUserIds) {
        $mock->shouldReceive('sendToUser')
            ->twice()
            ->withArgs(function ($user, $title) use (&$notifiedUserIds) {
                $notifiedUserIds[] = $user->user_id;
                return $title === 'New Trip Assignment';
            })
            ->andReturn(true);
    });

    app(TripService::class)->scheduleTrip([
        'fleet_route_id' => $fleetRoute->fleet_route_id,
        'company_user_id' => $operator->company_user_id,
        'trip_date' => now()->toDateString(),
        'departure_time' => '08:30',
        'trip_type' => 'one_way',
        'driver_id' => $driver->company_user_id,
        'conductor_id' => $conductor->company_user_id,
    ]);

    expect($notifiedUserIds)->toContain($driver->user_id);
    expect($notifiedUserIds)->toContain($conductor->user_id);
});

// Follow-up fix, notifications audit: decline previously only reached the
// Operator via the 45-second-poll in-app feed (TripNotificationRepository)
// — arguably too slow for an event that needs urgent action (finding a
// replacement before departure). Accept previously notified no one at all.
// Both now also push to the Operator immediately.
test('a driver declining a trip notifies the owning Operator', function () {
    $operator = makeStaffMemberForAssignmentNotificationTest('operator');
    $driver = makeStaffMemberForAssignmentNotificationTest('driver');
    $trip = makeUnassignedTripForNotificationTest($operator->company_user_id);
    $trip->update(['driver_id' => $driver->company_user_id]);

    $this->mock(FcmService::class, function ($mock) use ($operator) {
        $mock->shouldReceive('sendToUser')
            ->once()
            ->withArgs(fn ($user, $title) => $user->user_id === $operator->user_id && $title === 'Trip Declined — Needs Replacement')
            ->andReturn(true);
    });

    app(TripService::class)->declineTrip($trip->trip_id, 'driver', $driver->company_user_id);
});

test('a conductor accepting a trip notifies the owning Operator', function () {
    $operator = makeStaffMemberForAssignmentNotificationTest('operator');
    $conductor = makeStaffMemberForAssignmentNotificationTest('conductor');
    $trip = makeUnassignedTripForNotificationTest($operator->company_user_id);
    $trip->update(['conductor_id' => $conductor->company_user_id]);

    $this->mock(FcmService::class, function ($mock) use ($operator) {
        $mock->shouldReceive('sendToUser')
            ->once()
            ->withArgs(fn ($user, $title) => $user->user_id === $operator->user_id && $title === 'Trip Assignment Accepted')
            ->andReturn(true);
    });

    app(TripService::class)->acceptTrip($trip->trip_id, 'conductor', $conductor->company_user_id);
});
