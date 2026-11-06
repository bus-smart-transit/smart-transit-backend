<?php

use App\Models\PassengerUser;
use App\Models\StaffUser;
use App\Models\User;
use App\Services\GoogleIdTokenVerifier;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

uses(RefreshDatabase::class);

// 2026-11-07: passengers sign in with Google. The Google check is replaced here; it never calls Google in tests.

function gsiGoogle(array $claims): void
{
    test()->mock(GoogleIdTokenVerifier::class, function ($mock) use ($claims) {
        $mock->shouldReceive('verify')->andReturnUsing(function (string $token) use ($claims) {
            if ($token === 'bad') {
                throw ValidationException::withMessages(['id_token' => ['Google sign-in did not work. Please try again.']]);
            }

            return array_merge(['uid' => 'g-1', 'email' => 'pia@example.com', 'email_verified' => true, 'name' => 'Pia Reyes'], $claims);
        });
    });
}

function gsiPassenger(string $email, array $user = [], array $profile = []): User
{
    $made = User::create(array_merge(['username' => 'gsi'.Str::random(6), 'email' => $email, 'password' => 'Right-password-1', 'role' => 'passenger'], $user));
    PassengerUser::create(array_merge(['user_id' => $made->user_id, 'passenger_uuid' => (string) Str::uuid(), 'name' => 'Old Name', 'phone_num' => '+639171234567', 'address' => 'x'], $profile));

    return $made->fresh();
}

it('makes a new passenger account from Google with no login code, asks for the phone, and signs in again later', function () {
    gsiGoogle([]);

    $first = $this->postJson('/api/auth/google', ['id_token' => 'good'])->assertOk()->json('data');
    expect($first)->toMatchArray(['is_new' => true, 'needs_phone' => true])
        ->and($first['token'])->toBeString()
        ->and($first)->not->toHaveKey('user_id');

    $user = User::where('email', 'pia@example.com')->first();
    expect($user->role)->toBe('passenger')
        ->and($user->google_uid)->toBe('g-1')
        ->and($user->email_verified_at)->not->toBeNull()
        ->and($user->passengerProfile->name)->toBe('Pia Reyes')
        ->and($user->toArray())->not->toHaveKey('google_uid')
        ->and(DB::table('audit_logs')->where('action', 'auth.google_signup')->count())->toBe(1);

    $again = $this->postJson('/api/auth/google', ['id_token' => 'good'])->assertOk()->json('data');
    expect($again['is_new'])->toBeFalse()->and(User::where('email', 'pia@example.com')->count())->toBe(1);
});

it('links an existing passenger with the same email, keeping their profile and phone', function () {
    $existing = gsiPassenger('pia@example.com');
    gsiGoogle([]);

    $data = $this->postJson('/api/auth/google', ['id_token' => 'good'])->assertOk()->json('data');

    expect($data)->toMatchArray(['is_new' => false, 'needs_phone' => false])
        ->and($existing->fresh()->google_uid)->toBe('g-1')
        ->and($existing->fresh()->passengerProfile->name)->toBe('Old Name');
});

it('refuses a staff email: staff keep password plus login code', function () {
    $staff = User::create(['username' => 'gsistaff', 'email' => 'staff@example.com', 'password' => 'Right-password-1', 'role' => 'operator']);
    StaffUser::create(['user_id' => $staff->user_id, 'company_user_uuid' => (string) Str::uuid(), 'name' => 'Op', 'phone_num' => '+639171234567', 'address' => 'x']);
    gsiGoogle(['email' => 'staff@example.com', 'uid' => 'g-staff']);

    $this->postJson('/api/auth/google', ['id_token' => 'good'])->assertUnprocessable()->assertJsonPath('errors.id_token.0', 'This email belongs to a staff account. Staff sign in on the staff login page.');
    expect($staff->fresh()->google_uid)->toBeNull();
});

it('refuses a turned-off passenger account', function () {
    gsiPassenger('off@example.com', ['deactivated_at' => now()]);
    gsiGoogle(['email' => 'off@example.com', 'uid' => 'g-off']);

    $this->postJson('/api/auth/google', ['id_token' => 'good'])->assertUnprocessable()->assertJsonPath('errors.id_token.0', 'This account is turned off. Contact SmartTransit to turn it on again.');
});

it('refuses an unverified Google email and a bad or missing token, making no account', function () {
    gsiGoogle(['email' => 'new@example.com', 'uid' => 'g-new', 'email_verified' => false]);

    $this->postJson('/api/auth/google', ['id_token' => 'good'])->assertUnprocessable();
    $this->postJson('/api/auth/google', ['id_token' => 'bad'])->assertUnprocessable()->assertJsonPath('errors.id_token.0', 'Google sign-in did not work. Please try again.');
    $this->postJson('/api/auth/google', [])->assertUnprocessable()->assertJsonValidationErrors(['id_token']);
    expect(User::where('email', 'new@example.com')->exists())->toBeFalse();
});

it('gives a second Google passenger with the same email start a different username', function () {
    gsiPassenger('other@example.com', ['username' => 'pia']);
    gsiGoogle([]);

    $this->postJson('/api/auth/google', ['id_token' => 'good'])->assertOk();

    expect(User::where('email', 'pia@example.com')->value('username'))->toStartWith('pia')->not->toBe('pia');
});
