<?php

use App\Models\StaffUser;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

// Batch 15, Items 4/9: "Available" status toggle — distinct from
// shift/pairing state, applies to both Driver and Conductor roles,
// visible to Operators via listDrivers()/listConductors().
function makeStaffForAvailabilityTest(string $role): User
{
    $user = User::create([
        'username' => $role . '_' . uniqid(),
        'email' => $role . '+' . uniqid() . '@smarttransit.test',
        'password' => bcrypt('password123'),
        'role' => $role,
    ]);

    StaffUser::create([
        'company_user_uuid' => (string) Str::uuid(),
        'user_id' => $user->user_id,
        'name' => ucfirst($role) . ' Availability Test',
        'phone_num' => '09170000022',
        'address' => 'Test Address',
    ]);

    return $user;
}

test('driver can toggle availability on and off', function () {
    $driver = makeStaffForAvailabilityTest('driver');
    $token = $driver->createToken('test-token')->plainTextToken;

    $on = $this->withHeader('Authorization', "Bearer $token")
        ->patchJson('/api/driver/availability', ['available' => true]);
    $on->assertStatus(200)->assertJsonPath('data.is_available', true);
    expect($driver->companyProfile->fresh()->available_since)->not->toBeNull();

    $off = $this->withHeader('Authorization', "Bearer $token")
        ->patchJson('/api/driver/availability', ['available' => false]);
    $off->assertStatus(200)->assertJsonPath('data.is_available', false);
    expect($driver->companyProfile->fresh()->available_since)->toBeNull();
});

test('conductor can toggle availability independent of shift state', function () {
    $conductor = makeStaffForAvailabilityTest('conductor');
    $token = $conductor->createToken('test-token')->plainTextToken;

    $response = $this->withHeader('Authorization', "Bearer $token")
        ->patchJson('/api/conductor/availability', ['available' => true]);

    $response->assertStatus(200)->assertJsonPath('data.is_available', true);
});

test('operator cannot toggle availability (role-restricted feature)', function () {
    $operatorUser = User::create([
        'username' => 'operator_' . uniqid(),
        'email' => 'operator+' . uniqid() . '@smarttransit.test',
        'password' => bcrypt('password123'),
        'role' => 'operator',
    ]);
    StaffUser::create([
        'company_user_uuid' => (string) Str::uuid(),
        'user_id' => $operatorUser->user_id,
        'name' => 'Operator Availability Test',
        'phone_num' => '09170000023',
        'address' => 'Test Address',
    ]);
    $token = $operatorUser->createToken('test-token')->plainTextToken;

    // No /operator/availability route exists at all (feature is
    // driver/conductor-only) — confirm it 404s rather than silently working.
    $response = $this->withHeader('Authorization', "Bearer $token")
        ->patchJson('/api/operator/availability', ['available' => true]);

    $response->assertStatus(404);
});

test('operator staff listing surfaces driver availability status', function () {
    $driver = makeStaffForAvailabilityTest('driver');
    // Set availability directly on the model rather than via a second
    // authenticated HTTP call in this same test — Sanctum's guard caches
    // the first resolved user for the lifetime of the test's HTTP client,
    // so a second request with a DIFFERENT bearer token can incorrectly
    // resolve to the FIRST actor's user (a known Sanctum+testing gotcha,
    // not a real app bug). Keep each test to one authenticated actor.
    $driver->companyProfile->forceFill(['is_available' => true, 'available_since' => now()])->save();

    $operatorUser = User::create([
        'username' => 'operator_' . uniqid(),
        'email' => 'operator+' . uniqid() . '@smarttransit.test',
        'password' => bcrypt('password123'),
        'role' => 'operator',
    ]);
    StaffUser::create([
        'company_user_uuid' => (string) Str::uuid(),
        'user_id' => $operatorUser->user_id,
        'name' => 'Operator List Test',
        'phone_num' => '09170000024',
        'address' => 'Test Address',
    ]);
    $operatorToken = $operatorUser->createToken('test-token')->plainTextToken;

    $response = $this->withHeader('Authorization', "Bearer $operatorToken")
        ->getJson('/api/operator/drivers');

    $response->assertStatus(200);
    $driverRow = collect($response->json('data'))->firstWhere('user_id', $driver->user_id);
    expect($driverRow['is_available'] ?? null)->toBeTrue();
});
