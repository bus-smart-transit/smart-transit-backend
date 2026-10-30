<?php

use App\Mail\PassengerOtpMail;
use App\Models\PassengerUser;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

// 2026-10-28: a new passenger verifies their email with a code sent at sign-up. No session until the code is
// entered; the first correct code marks the email verified and signs them in. Accounts made before this are treated
// as verified (migration 2026_10_28_000002).

beforeEach(function () {
    config(['mail.intercept_email' => null]);
    Mail::fake();
});

function evfRegister($test, string $email)
{
    return $test->postJson('/api/passengers/register', [
        'name' => 'Evf Passenger', 'email' => $email, 'password' => 'Password123', 'password_confirmation' => 'Password123',
        'phone_num' => '09170000071', 'birthdate' => '2000-03-03',
    ]);
}

/** 2026-10-29: sign-in names the account by its passenger_uuid, so tests find the user from it. */
function evfUser(string $passengerUuid): User
{
    return PassengerUser::where('passenger_uuid', $passengerUuid)->firstOrFail()->user;
}

function evfLastCode(): string
{
    $code = null;
    Mail::assertSent(PassengerOtpMail::class, function (PassengerOtpMail $mail) use (&$code) {
        $code = $mail->otp;

        return true;
    });

    return (string) $code;
}

it('sends a verify-your-email code at sign-up and gives no session yet', function () {
    $email = 'evf'.Str::random(6).'@example.com';

    $response = evfRegister($this, $email)->assertCreated()
        ->assertJsonPath('data.verification_required', true)
        ->assertJsonMissingPath('data.token');

    Mail::assertSent(PassengerOtpMail::class, fn (PassengerOtpMail $mail) => $mail->verify && $mail->recipientEmail === strtolower($email));
    expect(evfUser($response->json('data.account_uuid'))->email_verified_at)->toBeNull();
});

it('verifies the email and signs the passenger in with the code', function () {
    $response = evfRegister($this, 'evf'.Str::random(6).'@example.com')->assertCreated();
    $userId = $response->json('data.account_uuid');

    $this->postJson('/api/passengers/verify-otp', ['account_uuid' => $userId, 'otp' => evfLastCode()])
        ->assertSuccessful()
        ->assertJsonStructure(['data' => ['token']]);

    expect(evfUser($userId)->email_verified_at)->not->toBeNull();
});

it('refuses a wrong code and leaves the email unverified', function () {
    $userId = evfRegister($this, 'evf'.Str::random(6).'@example.com')->json('data.account_uuid');
    $wrong = evfLastCode() === '000000' ? '111111' : '000000';

    $this->postJson('/api/passengers/verify-otp', ['account_uuid' => $userId, 'otp' => $wrong])->assertStatus(422);

    expect(evfUser($userId)->email_verified_at)->toBeNull();
});

it('asks an unverified passenger for a code at sign-in even with the login code switched off', function () {
    $email = 'evf'.Str::random(6).'@example.com';
    $userId = evfRegister($this, $email)->json('data.account_uuid');
    User::whereKey(evfUser($userId)->user_id)->update(['two_factor_enabled' => false]);

    $this->postJson('/api/auth/login', ['portal' => 'passenger', 'email' => $email, 'password' => 'Password123'])
        ->assertSuccessful()
        ->assertJsonPath('data.otp_required', true)
        ->assertJsonPath('data.verification_required', true)
        ->assertJsonMissingPath('data.token');
});

it('lets a verified passenger with the login code off sign in directly', function () {
    $email = 'evf'.Str::random(6).'@example.com';
    $userId = evfRegister($this, $email)->json('data.account_uuid');
    User::whereKey(evfUser($userId)->user_id)->update(['two_factor_enabled' => false, 'email_verified_at' => now()]);

    $this->postJson('/api/auth/login', ['portal' => 'passenger', 'email' => $email, 'password' => 'Password123'])
        ->assertSuccessful()
        ->assertJsonStructure(['data' => ['token']]);
});

it('sends a new code on request only to an account still waiting for verification', function () {
    $userId = evfRegister($this, 'evf'.Str::random(6).'@example.com')->json('data.account_uuid');

    $this->postJson('/api/passengers/verify-email/resend', ['account_uuid' => $userId])->assertSuccessful();
    Mail::assertSent(PassengerOtpMail::class, 2);

    User::whereKey(evfUser($userId)->user_id)->update(['email_verified_at' => now()]);
    $this->postJson('/api/passengers/verify-email/resend', ['account_uuid' => $userId])->assertSuccessful();
    Mail::assertSent(PassengerOtpMail::class, 2);
});

it('signs in a passenger who typed capitals in their email at sign-up', function () {
    $userId = evfRegister($this, 'Juan.Cruz.'.Str::random(4).'@Gmail.com')->json('data.account_uuid');
    $stored = evfUser($userId)->email;
    User::whereKey(evfUser($userId)->user_id)->update(['two_factor_enabled' => false, 'email_verified_at' => now()]);

    expect($stored)->toBe(strtolower($stored));
    $this->postJson('/api/auth/login', ['portal' => 'passenger', 'email' => strtoupper($stored), 'password' => 'Password123'])
        ->assertSuccessful()
        ->assertJsonStructure(['data' => ['token']]);
});

it('names the account by its uuid at sign-up and sign-in, never by the numeric user id', function () {
    $email = 'evf'.Str::random(6).'@example.com';
    $signup = evfRegister($this, $email)->assertCreated()->assertJsonMissingPath('data.user_id');
    $uuid = $signup->json('data.account_uuid');
    expect(Str::isUuid($uuid))->toBeTrue();

    $this->postJson('/api/auth/login', ['portal' => 'passenger', 'email' => $email, 'password' => 'Password123'])
        ->assertJsonPath('data.account_uuid', $uuid)
        ->assertJsonMissingPath('data.user_id');
    $this->postJson('/api/passengers/verify-otp', ['user_id' => evfUser($uuid)->user_id, 'otp' => '123456'])
        ->assertStatus(422)->assertJsonValidationErrors(['account_uuid']);
});
