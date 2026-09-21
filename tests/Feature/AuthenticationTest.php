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

    it('authenticates admin user and requires OTP step-up', function () {
        $admin = makeStaffForAuth('admin');

        $response = $this->postJson('/api/staff/login', [
            'email' => $admin->email,
            'password' => 'password123',
        ]);

        $response->assertStatus(200);
        $response->assertJsonStructure([
            'status',
            'message',
            'data' => ['otp_required', 'user_id', 'email_masked'],
        ]);
        $response->assertJsonPath('data.otp_required', true);
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

    // AUTHZ-01 (account_security.md) — "Extend to operator/admin-only endpoint
    // denial tests." Batch B: closes the test-evidence gap noted in
    // THESIS_OBJECTIVES_AUDIT.md Objective 1 ("no test exists where a
    // Driver/Conductor token is used to call an Operator-only or Admin-only
    // endpoint and is denied"). The `role:operator`/`role:admin` middleware
    // groups already enforce this in routes/api.php — these tests exist to
    // provide the missing evidence, not to fix a bug.
    it('denies a driver token on an operator-only endpoint', function () {
        $driver = makeStaffForAuth('driver');
        $driverLogin = $this->postJson('/api/staff/login', [
            'email' => $driver->email,
            'password' => 'password123',
        ]);
        $driverLogin->assertStatus(200);
        $driverToken = $driverLogin->json('data.token');

        $response = $this->withHeader('Authorization', "Bearer $driverToken")
            ->getJson('/api/operator/fleets');

        $response->assertStatus(403);
    });

    it('denies a conductor token on an operator-only endpoint', function () {
        $conductor = makeStaffForAuth('conductor');
        $conductorLogin = $this->postJson('/api/staff/login', [
            'email' => $conductor->email,
            'password' => 'password123',
        ]);
        $conductorLogin->assertStatus(200);
        $conductorToken = $conductorLogin->json('data.token');

        $response = $this->withHeader('Authorization', "Bearer $conductorToken")
            ->getJson('/api/operator/drivers');

        $response->assertStatus(403);
    });

    it('denies a driver token on an admin-only endpoint', function () {
        $driver = makeStaffForAuth('driver');
        $driverLogin = $this->postJson('/api/staff/login', [
            'email' => $driver->email,
            'password' => 'password123',
        ]);
        $driverLogin->assertStatus(200);
        $driverToken = $driverLogin->json('data.token');

        $response = $this->withHeader('Authorization', "Bearer $driverToken")
            ->getJson('/api/admin/drivers');

        $response->assertStatus(403);
    });

    it('denies a conductor token on an admin-only endpoint', function () {
        $conductor = makeStaffForAuth('conductor');
        $conductorLogin = $this->postJson('/api/staff/login', [
            'email' => $conductor->email,
            'password' => 'password123',
        ]);
        $conductorLogin->assertStatus(200);
        $conductorToken = $conductorLogin->json('data.token');

        $response = $this->withHeader('Authorization', "Bearer $conductorToken")
            ->getJson('/api/admin/fleets');

        $response->assertStatus(403);
    });

    it('denies an operator token on an admin-only endpoint', function () {
        $operator = makeStaffForAuth('operator');

        // Operator login is MFA-mandatory (UserService::MFA_MANDATORY_ROLES
        // includes 'operator', same as 'admin') — simulate a verified session
        // by issuing a token directly, since this test only cares about
        // role-middleware denial, not the OTP flow.
        $operatorToken = $operator->fresh()->createToken('test-operator-token')->plainTextToken;

        // Admin-only account-management endpoint — operator has its own
        // scoped equivalent (POST /operator/accounts) but must not reach
        // the admin-gated route directly.
        $response = $this->withHeader('Authorization', "Bearer $operatorToken")
            ->getJson('/api/admin/drivers');

        $response->assertStatus(403);
    });

    it('denies an admin token on operator/driver/conductor-only endpoints', function () {
        $admin = makeStaffForAuth('admin');
        $adminUser = $admin->fresh();

        // Admin login requires OTP step-up (per the first test in this file) —
        // simulate a verified session by issuing a token directly, since this
        // test only cares about role-middleware denial, not the OTP flow.
        $adminToken = $adminUser->createToken('test-admin-token')->plainTextToken;

        $this->withHeader('Authorization', "Bearer $adminToken")
            ->getJson('/api/operator/fleets')
            ->assertStatus(403);

        $this->withHeader('Authorization', "Bearer $adminToken")
            ->getJson('/api/driver/trips')
            ->assertStatus(403);

        $this->withHeader('Authorization', "Bearer $adminToken")
            ->getJson('/api/conductor/trips')
            ->assertStatus(403);
    });

});
