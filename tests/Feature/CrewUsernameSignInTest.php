<?php

use App\Models\StaffUser;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

// 2026-11-05 (approved): on the staff page a Driver or Chauffeur may sign in with their username instead of their
// email. Operators and Admins keep email only, and every miss answers like a wrong password.

function cusStaff(string $role, string $username, string $email): User
{
    $user = User::create(['username' => $username, 'email' => $email, 'password' => 'right-password-1', 'role' => $role]);
    StaffUser::create(['user_id' => $user->user_id, 'company_user_uuid' => (string) Str::uuid(), 'name' => ucfirst($role), 'phone_num' => '0917'.random_int(1000000, 9999999), 'address' => 'x']);

    return $user;
}

function cusLogin($test, string $identifier, string $password = 'right-password-1', string $portal = 'staff')
{
    return $test->postJson('/api/auth/login', ['portal' => $portal, 'email' => $identifier, 'password' => $password]);
}

it('signs a Driver and a Chauffeur in with their username, in any letter case', function () {
    cusStaff('driver', 'Juan_Driver', 'juan.driver@example.com');
    cusStaff('conductor', 'ana_chauffeur', 'ana.chauffeur@example.com');

    cusLogin($this, 'juan_driver')->assertSuccessful()->assertJsonPath('data.otp_required', false);
    cusLogin($this, 'ANA_CHAUFFEUR')->assertSuccessful();
});

it('still signs crews in with their email', function () {
    cusStaff('driver', 'juan_driver', 'juan.driver@example.com');

    cusLogin($this, 'Juan.Driver@Example.com')->assertSuccessful();
});

it('answers a wrong password or an unknown username exactly like before', function () {
    cusStaff('driver', 'juan_driver', 'juan.driver@example.com');

    $wrong = cusLogin($this, 'juan_driver', 'nope')->assertStatus(422)->json('errors');
    $unknown = cusLogin($this, 'nobody_here')->assertStatus(422)->json('errors');

    expect($unknown)->toBe($wrong);
});

it('never lets an Operator or Admin in by username', function (string $role) {
    cusStaff($role, "the_{$role}", "the.{$role}@example.com");

    $byUsername = cusLogin($this, "the_{$role}")->assertStatus(422)->json('errors');
    $wrongPassword = cusLogin($this, "the.{$role}@example.com", 'nope')->assertStatus(422)->json('errors');

    expect($byUsername)->toBe($wrongPassword);
})->with(['operator', 'admin']);

it('keeps the passenger page on email only', function () {
    cusLogin($this, 'some_user', 'right-password-1', 'passenger')->assertStatus(422)->assertJsonValidationErrors(['email']);
});

it('still checks an address that has an @ as an email', function () {
    cusLogin($this, 'not-an@-email')->assertStatus(422)->assertJsonValidationErrors(['email']);
});
