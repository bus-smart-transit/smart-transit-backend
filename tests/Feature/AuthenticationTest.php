<?php

use App\Models\StaffUser;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

function makeStaffForAuth(string $role): User
{
    $user = User::create([
        'username' => 'u_' . Str::lower($role) . '_' . uniqid(),
        'email' => Str::lower($role) . '+' . uniqid() . '@smarttransit.test',
        'password' => bcrypt('password123'),
        'role' => $role,
    ]);

    StaffUser::create([
        'company_user_uuid' => (string) Str::uuid(),
        'user_id' => $user->user_id,
        'name' => Str::title($role) . ' User',
        'phone_num' => '09170000000',
        'address' => 'Test Address',
    ]);

    return $user;
}

describe('Authentication & Authorization', function () {

    it('authenticates admin user and returns token', function () {
        $admin = makeStaffForAuth('admin');

        $response = $this->postJson('/api/staff/login', [
            'email' => $admin->email,
            'password' => 'password123',
        ]);

        $response->assertStatus(200);
        $response->assertJsonStructure([
            'status',
            'message',
            'data' => ['token', 'user'],
        ]);
        $response->assertJsonPath('status', 'success');
    });

    it('authenticates driver and returns token', function () {
        $driver = makeStaffForAuth('driver');

        $response = $this->postJson('/api/staff/login', [
            'email' => $driver->email,
            'password' => 'password123',
        ]);

        $response->assertStatus(200);
        $response->assertJsonPath('status', 'success');
        $response->assertJsonStructure([
            'data' => ['token'],
        ]);
    });

    it('authenticates conductor and returns token', function () {
        $conductor = makeStaffForAuth('conductor');

        $response = $this->postJson('/api/staff/login', [
            'email' => $conductor->email,
            'password' => 'password123',
        ]);

        $response->assertStatus(200);
        $response->assertJsonPath('status', 'success');
    });

    it('rejects invalid credentials', function () {
        $admin = makeStaffForAuth('admin');

        $response = $this->postJson('/api/staff/login', [
            'email' => $admin->email,
            'password' => 'wrongpassword',
        ]);

        $response->assertStatus(422);
    });

    it('enforces role-based access control on driver routes', function () {
        $conductor = makeStaffForAuth('conductor');

        $conductorLogin = $this->postJson('/api/staff/login', [
            'email' => $conductor->email,
            'password' => 'password123',
        ]);
        $conductorLogin->assertStatus(200);

        $conductorToken = $conductorLogin->json('data.token');

        $response = $this->withHeader('Authorization', "Bearer $conductorToken")
            ->getJson('/api/driver/trips/current/stops');

        $response->assertStatus(403);
    });

    it('enforces role-based access control on conductor routes', function () {
        $driver = makeStaffForAuth('driver');

        $driverLogin = $this->postJson('/api/staff/login', [
            'email' => $driver->email,
            'password' => 'password123',
        ]);
        $driverLogin->assertStatus(200);

        $driverToken = $driverLogin->json('data.token');

        $response = $this->withHeader('Authorization', "Bearer $driverToken")
            ->getJson('/api/conductor/trips/current/occupancy');

        $response->assertStatus(403);
    });

});
