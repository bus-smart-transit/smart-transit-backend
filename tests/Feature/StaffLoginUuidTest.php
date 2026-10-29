<?php

use App\Mail\PassengerOtpMail;
use App\Models\StaffUser;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

// 2026-10-29: staff sign-in names the account by its company_user_uuid between the password step and the code step,
// never by the numeric user id.

function sluOperator(): array
{
    $user = User::create(['username' => 'slu'.Str::random(6), 'email' => strtolower('slu'.Str::random(6).'@example.com'), 'password' => bcrypt('password123'), 'role' => 'operator', 'two_factor_enabled' => true]);
    $staff = StaffUser::create(['user_id' => $user->user_id, 'company_user_uuid' => (string) Str::uuid(), 'name' => 'Slu Operator', 'phone_num' => '09170000073', 'address' => 'x']);

    return [$user, $staff];
}

beforeEach(fn () => Mail::fake());

it('gives the staff member\'s uuid after the password and signs them in with it and the code', function () {
    [$user, $staff] = sluOperator();

    $this->postJson('/api/staff/login', ['email' => $user->email, 'password' => 'password123', 'role' => 'operator'])
        ->assertSuccessful()
        ->assertJsonPath('data.otp_required', true)
        ->assertJsonPath('data.account_uuid', $staff->company_user_uuid)
        ->assertJsonMissingPath('data.user_id');

    $code = null;
    Mail::assertSent(PassengerOtpMail::class, function (PassengerOtpMail $mail) use (&$code) {
        $code = $mail->otp;

        return true;
    });

    $this->postJson('/api/staff/verify-otp', ['account_uuid' => $staff->company_user_uuid, 'otp' => $code])
        ->assertSuccessful()
        ->assertJsonStructure(['data' => ['token']]);
});

it('refuses the numeric user id and an unknown uuid at the code step', function () {
    [$user] = sluOperator();
    $this->postJson('/api/staff/login', ['email' => $user->email, 'password' => 'password123', 'role' => 'operator'])->assertSuccessful();

    $this->postJson('/api/staff/verify-otp', ['user_id' => $user->user_id, 'otp' => '123456'])
        ->assertStatus(422)->assertJsonValidationErrors(['account_uuid']);
    $this->postJson('/api/staff/verify-otp', ['account_uuid' => (string) Str::uuid(), 'otp' => '123456'])
        ->assertStatus(422);
});
