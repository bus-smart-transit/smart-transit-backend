<?php

use App\Mail\PassengerOtpMail;
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
    expect(User::find($response->json('data.user_id'))->email_verified_at)->toBeNull();
});

it('verifies the email and signs the passenger in with the code', function () {
    $response = evfRegister($this, 'evf'.Str::random(6).'@example.com')->assertCreated();
    $userId = $response->json('data.user_id');

    $this->postJson('/api/passengers/verify-otp', ['user_id' => $userId, 'otp' => evfLastCode()])
        ->assertSuccessful()
        ->assertJsonStructure(['data' => ['token']]);

    expect(User::find($userId)->email_verified_at)->not->toBeNull();
});

it('refuses a wrong code and leaves the email unverified', function () {
    $userId = evfRegister($this, 'evf'.Str::random(6).'@example.com')->json('data.user_id');
    $wrong = evfLastCode() === '000000' ? '111111' : '000000';

    $this->postJson('/api/passengers/verify-otp', ['user_id' => $userId, 'otp' => $wrong])->assertStatus(422);

    expect(User::find($userId)->email_verified_at)->toBeNull();
});

it('asks an unverified passenger for a code at sign-in even with the login code switched off', function () {
    $email = 'evf'.Str::random(6).'@example.com';
    $userId = evfRegister($this, $email)->json('data.user_id');
    User::whereKey($userId)->update(['two_factor_enabled' => false]);

    $this->postJson('/api/passengers/login', ['email' => $email, 'password' => 'Password123'])
        ->assertSuccessful()
        ->assertJsonPath('data.otp_required', true)
        ->assertJsonPath('data.verification_required', true)
        ->assertJsonMissingPath('data.token');
});

it('lets a verified passenger with the login code off sign in directly', function () {
    $email = 'evf'.Str::random(6).'@example.com';
    $userId = evfRegister($this, $email)->json('data.user_id');
    User::whereKey($userId)->update(['two_factor_enabled' => false, 'email_verified_at' => now()]);

    $this->postJson('/api/passengers/login', ['email' => $email, 'password' => 'Password123'])
        ->assertSuccessful()
        ->assertJsonStructure(['data' => ['token']]);
});

it('sends a new code on request only to an account still waiting for verification', function () {
    $userId = evfRegister($this, 'evf'.Str::random(6).'@example.com')->json('data.user_id');

    $this->postJson('/api/passengers/verify-email/resend', ['user_id' => $userId])->assertSuccessful();
    Mail::assertSent(PassengerOtpMail::class, 2);

    User::whereKey($userId)->update(['email_verified_at' => now()]);
    $this->postJson('/api/passengers/verify-email/resend', ['user_id' => $userId])->assertSuccessful();
    Mail::assertSent(PassengerOtpMail::class, 2);
});

it('signs in a passenger who typed capitals in their email at sign-up', function () {
    $userId = evfRegister($this, 'Juan.Cruz.'.Str::random(4).'@Gmail.com')->json('data.user_id');
    $stored = User::find($userId)->email;
    User::whereKey($userId)->update(['two_factor_enabled' => false, 'email_verified_at' => now()]);

    expect($stored)->toBe(strtolower($stored));
    $this->postJson('/api/passengers/login', ['email' => strtoupper($stored), 'password' => 'Password123'])
        ->assertSuccessful()
        ->assertJsonStructure(['data' => ['token']]);
});
