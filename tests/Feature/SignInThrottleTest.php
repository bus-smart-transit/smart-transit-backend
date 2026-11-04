<?php

use App\Models\PassengerUser;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

// Batch 45 Todo 2 (decided 2026-11-04): 5 failed sign-ins in a row lock that email on that IP for 5 minutes;
// a wider per-IP cap (30 failures in 15 minutes); a successful sign-in clears only that email's count.

beforeEach(function () {
    config(['app.rate_limiting_enabled' => true]);
});

function sitPassenger(string $email = 'sit.passenger@example.com', string $phone = '09170000091'): User
{
    $user = User::create(['username' => Str::before($email, '@'), 'email' => $email, 'password' => 'right-password-1', 'role' => 'passenger', 'email_verified_at' => now()]);
    PassengerUser::create(['passenger_uuid' => (string) Str::uuid(), 'user_id' => $user->user_id, 'name' => 'Sit Passenger', 'phone_num' => $phone, 'address' => 'x', 'birthdate' => '2000-01-01']);

    return $user;
}

function sitLogin($test, string $email, string $password, string $ip = '10.0.0.1')
{
    return $test->withServerVariables(['REMOTE_ADDR' => $ip])
        ->postJson('/api/auth/login', ['portal' => 'passenger', 'email' => $email, 'password' => $password]);
}

function sitFail($test, int $times, string $email = 'sit.passenger@example.com', string $ip = '10.0.0.1'): void
{
    for ($i = 0; $i < $times; $i++) {
        sitLogin($test, $email, 'wrong-password', $ip)->assertStatus(422);
    }
}

function sitIsLocked($response): bool
{
    return str_contains((string) $response->json('errors.email.0'), 'Too many failed login attempts');
}

it('locks an email on one IP after five failed sign-ins, even with the right password, and says about 5 minutes', function () {
    sitPassenger();
    sitFail($this, 4);
    expect(sitIsLocked(sitLogin($this, 'sit.passenger@example.com', 'wrong-password')))->toBeFalse();

    $locked = sitLogin($this, 'sit.passenger@example.com', 'right-password-1');

    expect(sitIsLocked($locked))->toBeTrue()
        ->and($locked->json('errors.email.0'))->toContain('about 5 minutes');
});

it('keeps the lock for five minutes from the fifth failure, then lets the person in', function () {
    sitPassenger();
    sitFail($this, 4);
    $this->travel(10)->minutes(); // inside the 15-minute failure window
    sitFail($this, 1);

    $this->travel(4)->minutes();
    expect(sitIsLocked(sitLogin($this, 'sit.passenger@example.com', 'right-password-1')))->toBeTrue();

    $this->travel(61)->seconds();
    sitLogin($this, 'sit.passenger@example.com', 'right-password-1')->assertSuccessful();
});

it('does not lock other people on the same IP, or the same email on another IP', function () {
    sitPassenger();
    sitPassenger('sit.other@example.com', '09170000092');
    sitFail($this, 5);

    sitLogin($this, 'sit.other@example.com', 'right-password-1')->assertSuccessful();
    sitLogin($this, 'sit.passenger@example.com', 'right-password-1', '10.0.0.2')->assertSuccessful();
});

it('starts the count again after a successful sign-in', function () {
    sitPassenger();
    sitFail($this, 4);
    sitLogin($this, 'sit.passenger@example.com', 'right-password-1')->assertSuccessful();
    sitFail($this, 4);

    sitLogin($this, 'sit.passenger@example.com', 'right-password-1')->assertSuccessful();
});

it('stops one IP after thirty failures across many emails', function () {
    sitPassenger();
    for ($i = 1; $i <= 30; $i++) {
        $response = sitLogin($this, "sit.nobody{$i}@example.com", 'wrong-password');
        expect(sitIsLocked($response))->toBeFalse();
    }

    expect(sitIsLocked(sitLogin($this, 'sit.passenger@example.com', 'right-password-1')))->toBeTrue();
    sitLogin($this, 'sit.passenger@example.com', 'right-password-1', '10.0.0.2')->assertSuccessful();
});

it('counts an email in any letter case as the same email', function () {
    sitPassenger();
    sitFail($this, 3);
    sitFail($this, 2, 'SIT.Passenger@Example.com');

    expect(sitIsLocked(sitLogin($this, 'sit.passenger@example.com', 'right-password-1')))->toBeTrue();
});
