<?php

use App\Models\Fleet;
use App\Models\FleetRoute;
use App\Models\Route;
use App\Models\StaffUser;
use App\Models\Trip;
use App\Models\User;
use App\Services\FcmService;
use App\Services\TripService;
use App\Support\Notifications\NotificationLink;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

// Batch 27, Task 1: the "New Trip Assignment" notification must be clickable.
// The server puts the trip id, the recipient's role and a relative deep link
// in the notification data; the push tap and the in-app list both use it.

function notificationLinkStaff(string $role): StaffUser
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
        'name' => ucfirst($role) . ' Link Test',
        'phone_num' => '09170000026',
        'address' => 'Test Address',
    ]);
}

function notificationLinkTrip(int $companyUserId): Trip
{
    $route = Route::create(['route_name' => 'Link Test Route', 'origin' => 'A', 'destination' => 'B']);
    $fleet = Fleet::create([
        'company_user_id' => $companyUserId,
        'plate_number' => 'LINK-' . fake()->unique()->numerify('###'),
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

it('builds a relative deep link per role', function () {
    expect(NotificationLink::tripAssignment('driver', 42))->toBe('/employee/driver/dashboard?tab=dashboard&trip=42')
        ->and(NotificationLink::tripAssignment('conductor', 7))->toBe('/employee/conductor/dashboard?tab=dashboard&trip=7')
        ->and(NotificationLink::tripAssignment('operator', 9))->toBe('/employee/operator/dashboard?tab=trips&trip=9');
});

it('never produces an external or protocol-relative link', function () {
    foreach (['driver', 'conductor', 'operator'] as $role) {
        $link = NotificationLink::tripAssignment($role, 1);
        expect($link)->toStartWith('/employee/')->and($link)->not->toContain('//')->and($link)->not->toContain('http');
    }
});

it('puts trip id, role and link in the data of an assigned-driver notification', function () {
    $operator = notificationLinkStaff('operator');
    $driver = notificationLinkStaff('driver');
    $trip = notificationLinkTrip($operator->company_user_id);

    $captured = null;
    $this->mock(FcmService::class, function ($mock) use (&$captured) {
        $mock->shouldReceive('sendToUser')->once()->withArgs(function ($user, $title, $body, $data) use (&$captured) {
            $captured = $data;
            return true;
        })->andReturn(true);
    });

    app(TripService::class)->assignDriver($trip->trip_id, ['driver_id' => $driver->company_user_id]);

    expect($captured)->toMatchArray([
        'type' => 'trip_assigned',
        'trip_id' => $trip->trip_id,
        'role' => 'driver',
        'link' => "/employee/driver/dashboard?tab=dashboard&trip={$trip->trip_id}",
    ]);
});

it('puts trip id, role and link in the data of an assigned-conductor notification', function () {
    $operator = notificationLinkStaff('operator');
    $conductor = notificationLinkStaff('conductor');
    $trip = notificationLinkTrip($operator->company_user_id);

    $captured = null;
    $this->mock(FcmService::class, function ($mock) use (&$captured) {
        $mock->shouldReceive('sendToUser')->once()->withArgs(function ($user, $title, $body, $data) use (&$captured) {
            $captured = $data;
            return true;
        })->andReturn(true);
    });

    app(TripService::class)->assignConductor($trip->trip_id, ['conductor_id' => $conductor->company_user_id]);

    expect($captured['role'])->toBe('conductor')
        ->and($captured['link'])->toBe("/employee/conductor/dashboard?tab=dashboard&trip={$trip->trip_id}");
});

it('stores the link in the in-app notification so the bell list can open it', function () {
    $operator = notificationLinkStaff('operator');
    $driver = notificationLinkStaff('driver');
    $trip = notificationLinkTrip($operator->company_user_id);

    app(TripService::class)->assignDriver($trip->trip_id, ['driver_id' => $driver->company_user_id]);

    $row = \App\Models\UserNotification::where('user_id', $driver->user_id)->where('type', 'trip_assigned')->first();
    expect($row)->not->toBeNull();
    $data = is_array($row->data) ? $row->data : json_decode((string) $row->data, true);
    expect($data['link'])->toBe("/employee/driver/dashboard?tab=dashboard&trip={$trip->trip_id}")
        ->and((int) $data['trip_id'])->toBe($trip->trip_id)
        ->and($data['role'])->toBe('driver');
});
