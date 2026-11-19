<?php

use App\Mail\PassengerOtpMail;
use App\Models\StaffUser;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
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

    $this->postJson('/api/auth/login', ['portal' => 'staff', 'email' => $user->email, 'password' => 'password123', 'role' => 'operator'])
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

// 2026-11-08 (user): the email and the sign-in page's countdown state the code's real lifetime (one config value).
it('states the real login code lifetime in the email and to the sign-in page', function () {
    [$user, $staff] = sluOperator();
    config(['auth.login_code_minutes' => 7]);

    $this->postJson('/api/auth/login', ['portal' => 'staff', 'email' => $user->email, 'password' => 'password123', 'role' => 'operator'])
        ->assertSuccessful()
        ->assertJsonPath('data.otp_expires_in_seconds', 420);

    $code = null;
    Mail::assertSent(PassengerOtpMail::class, function (PassengerOtpMail $mail) use (&$code) {
        $code = $mail->otp;
        $html = $mail->render();
        expect($html)->toContain('It expires in <strong>7 minutes</strong>')->not->toContain('10 minutes');

        return true;
    });

    // The code still works inside 7 minutes and has expired after them.
    $this->travel(6)->minutes();
    expect(Cache::has('staff-otp:'.$user->user_id))->toBeTrue();
    $this->travel(2)->minutes();
    $this->postJson('/api/staff/verify-otp', ['account_uuid' => $staff->company_user_uuid, 'otp' => $code])->assertStatus(422);
});

it('refuses the numeric user id and an unknown uuid at the code step', function () {
    [$user] = sluOperator();
    $this->postJson('/api/auth/login', ['portal' => 'staff', 'email' => $user->email, 'password' => 'password123', 'role' => 'operator'])->assertSuccessful();

    $this->postJson('/api/staff/verify-otp', ['user_id' => $user->user_id, 'otp' => '123456'])
        ->assertStatus(422)->assertJsonValidationErrors(['account_uuid']);
    $this->postJson('/api/staff/verify-otp', ['account_uuid' => (string) Str::uuid(), 'otp' => '123456'])
        ->assertStatus(422);
});
