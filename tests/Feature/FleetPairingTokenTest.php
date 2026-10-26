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

// Phase A (2026-10-26): pairing opens 60 minutes before the departure, so the clock is fixed inside that window for the
// 08:00 trip below (the test used the real time, and before 07:00 the pairing code is rightly refused).
beforeEach(fn () => Carbon::setTestNow(Carbon::parse('2026-10-06 07:30:00', 'Asia/Manila')));
afterEach(fn () => Carbon::setTestNow());

function pairingStaff(string $role): User
{
    $user = User::create([
        'username' => $role.'_'.uniqid(),
        'email' => $role.'+'.uniqid().'@smarttransit.test',
        'password' => bcrypt('password123'),
        'role' => $role,
    ]);
    StaffUser::create([
        'company_user_uuid' => (string) Str::uuid(),
        'user_id' => $user->user_id,
        'name' => ucfirst($role).' Pairing',
        'phone_num' => '0917'.random_int(1000000, 9999999),
        'address' => 'Test Address',
    ]);

    return $user->fresh();
}

it('returns the pairing token for the client to draw itself, and no third-party image URL', function () {
    $operator = pairingStaff('operator');
    $driver = pairingStaff('driver');
    $route = Route::create(['route_name' => 'Pairing Route', 'origin' => 'A', 'destination' => 'B']);
    $fleet = Fleet::create([
        'company_user_id' => $operator->companyProfile->company_user_id,
        'plate_number' => 'PR-'.fake()->unique()->numerify('###'),
        'capacity' => 40, 'seated_capacity' => 30, 'standing_capacity' => 10, 'status' => 'active',
    ]);
    $fleetRoute = FleetRoute::create(['fleet_id' => $fleet->fleet_id, 'route_id' => $route->route_id, 'start_time' => '06:00', 'end_time' => '22:00', 'status' => 'active']);
    Trip::create([
        'fleet_route_id' => $fleetRoute->fleet_route_id,
        'company_user_id' => $operator->companyProfile->company_user_id,
        'driver_id' => $driver->companyProfile->company_user_id,
        'driver_accepted_at' => now(), 'conductor_accepted_at' => now(), // Batch 29: the assignment is accepted
        'trip_date' => now('Asia/Manila')->toDateString(),
        'departure_time' => '08:00',
        'status' => 'scheduled',
        'current_seated_capacity' => 0, 'current_standing_capacity' => 0, 'total_occupancy' => 0,
    ]);

    $response = $this->actingAs($driver)->getJson('/api/driver/pairing-token')->assertSuccessful();
    $data = $response->json('data');

    // The QR encodes `token`; the partner's scanner posts exactly that string back to pair.
    expect($data['token'])->toBeString()->not->toBeEmpty()
        ->and($data['token_pin'])->toMatch('/^\d{6}$/')
        ->and($data)->not->toHaveKey('qr_url')
        ->and($response->getContent())->not->toContain('qrserver');
});
