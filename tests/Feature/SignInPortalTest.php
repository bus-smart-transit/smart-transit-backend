<?php

use App\Models\PassengerUser;
use App\Models\StaffUser;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

// 2026-10-30: passengers and staff sign in at one address, POST /api/auth/login, and `portal` picks the flow.

function sipStaff(): User
{
    $user = User::create(['username' => 'sipop', 'email' => 'sip.operator@example.com', 'password' => 'right-password-1', 'role' => 'operator']);
    StaffUser::create(['user_id' => $user->user_id, 'company_user_uuid' => (string) Str::uuid(), 'name' => 'Sip Operator', 'phone_num' => '09170000081', 'address' => 'x']);

    return $user;
}

function sipPassenger(): User
{
    $user = User::create(['username' => 'sippax', 'email' => 'sip.passenger@example.com', 'password' => 'right-password-1', 'role' => 'passenger', 'email_verified_at' => now()]);
    PassengerUser::create(['passenger_uuid' => (string) Str::uuid(), 'user_id' => $user->user_id, 'name' => 'Sip Passenger', 'phone_num' => '09170000082', 'address' => 'x', 'birthdate' => '2000-01-01']);

    return $user;
}

it('signs a staff member in on the staff portal and a passenger on the passenger portal', function () {
    sipStaff();
    sipPassenger();

    $this->postJson('/api/auth/login', ['portal' => 'staff', 'email' => 'sip.operator@example.com', 'password' => 'right-password-1'])
        ->assertSuccessful()->assertJsonPath('data.otp_required', true);
    $this->postJson('/api/auth/login', ['portal' => 'passenger', 'email' => 'sip.passenger@example.com', 'password' => 'right-password-1'])
        ->assertSuccessful();
});

it('needs a portal, and the old addresses are gone', function () {
    $this->postJson('/api/auth/login', ['email' => 'a@example.com', 'password' => 'x'])
        ->assertStatus(422)->assertJsonValidationErrors(['portal']);
    $this->postJson('/api/auth/login', ['portal' => 'admin', 'email' => 'a@example.com', 'password' => 'x'])
        ->assertStatus(422)->assertJsonValidationErrors(['portal']);

    $this->postJson('/api/passengers/login', ['email' => 'a@example.com', 'password' => 'x'])->assertNotFound();
    $this->postJson('/api/staff/login', ['email' => 'a@example.com', 'password' => 'x'])->assertNotFound();
});

it('answers the wrong portal exactly like a wrong password, so it reveals nothing about the account', function () {
    sipStaff();
    sipPassenger();

    $wrongPassword = $this->postJson('/api/auth/login', ['portal' => 'staff', 'email' => 'sip.operator@example.com', 'password' => 'nope'])->assertStatus(422)->json('errors');
    $staffOnPassenger = $this->postJson('/api/auth/login', ['portal' => 'passenger', 'email' => 'sip.operator@example.com', 'password' => 'right-password-1'])->assertStatus(422)->json('errors');
    $passengerOnStaff = $this->postJson('/api/auth/login', ['portal' => 'staff', 'email' => 'sip.passenger@example.com', 'password' => 'right-password-1'])->assertStatus(422)->json('errors');

    expect($staffOnPassenger)->toBe($wrongPassword)
        ->and($passengerOnStaff)->toBe($wrongPassword);
});
