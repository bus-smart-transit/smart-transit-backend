<?php

use App\Mail\PasswordResetMail;
use App\Models\PassengerUser;
use App\Models\StaffUser;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

// Password reset by email, for passengers and for staff. The email carries a link to the frontend reset page (the
// passenger page or the staff page) with the token and the email filled in. With MAIL_INTERCEPT_EMAIL set (Resend's
// free sender only reaches its owner) the mail goes to that address and says which account it is for.

const PR_OLD = 'old-password-1';
const PR_NEW = 'new-password-2';

function prUser(string $role = 'passenger'): User
{
    $user = User::create(['username' => $role.Str::random(6), 'email' => strtolower(Str::random(8)).'@example.com', 'password' => bcrypt(PR_OLD), 'role' => $role, 'two_factor_enabled' => false]);
    if ($role === 'passenger') {
        PassengerUser::create(['user_id' => $user->user_id, 'passenger_uuid' => (string) Str::uuid(), 'name' => 'Pax', 'phone_num' => '09170000003', 'address' => 'x', 'birthdate' => '2000-01-01', 'reward_points' => 0]);
    } else {
        StaffUser::create(['user_id' => $user->user_id, 'company_user_uuid' => (string) Str::uuid(), 'name' => ucfirst($role), 'phone_num' => '09170000004', 'address' => 'x']);
    }

    return $user->fresh();
}

/** The mailable that was sent, and the token and email read back out of its link. */
function prSentLink(): array
{
    $mail = null;
    Mail::assertSent(PasswordResetMail::class, function ($m) use (&$mail) {
        $mail = $m;

        return true;
    });
    parse_str((string) parse_url($mail->resetUrl, PHP_URL_QUERY), $query);

    return [$mail, $query['token'] ?? null, $query['email'] ?? null, parse_url($mail->resetUrl, PHP_URL_PATH)];
}

beforeEach(function () {
    Mail::fake();
    Cache::flush();
    RateLimiter::clear('x');
    config(['app.frontend_url' => 'https://app.example', 'mail.intercept_email' => null]);
});

it('emails a passenger a link to the passenger reset page, and the new password works', function () {
    $user = prUser();

    $this->postJson('/api/passengers/forgot-password', ['email' => $user->email])
        ->assertOk()->assertJsonPath('message', 'If that email is registered, a reset link has been sent.');

    Mail::assertSent(PasswordResetMail::class, 1);
    Mail::assertSent(PasswordResetMail::class, fn ($m) => $m->hasTo($user->email));
    [$mail, $token, $email, $path] = prSentLink();
    expect($path)->toBe('/passenger/reset-password')->and($email)->toBe($user->email)->and($token)->not->toBeEmpty();
    expect($mail->resetUrl)->toStartWith('https://app.example/passenger/reset-password?');
    expect($mail->redirected)->toBeFalse();

    $user->createToken('old-session');
    $this->postJson('/api/passengers/reset-password', ['token' => $token, 'email' => $email, 'password' => PR_NEW, 'password_confirmation' => PR_NEW])
        ->assertOk();

    expect(Hash::check(PR_NEW, $user->fresh()->password))->toBeTrue();
    expect($user->fresh()->tokens()->count())->toBe(0);          // every open session is signed out
    $this->postJson('/api/auth/login', ['portal' => 'passenger', 'email' => $user->email, 'password' => PR_OLD])->assertStatus(422);
    $this->postJson('/api/auth/login', ['portal' => 'passenger', 'email' => $user->email, 'password' => PR_NEW])->assertOk();
});

it('emails a staff member a link to the staff reset page, and the new password works', function () {
    $user = prUser('operator');

    $this->postJson('/api/staff/forgot-password', ['email' => $user->email])->assertOk();

    [$mail, $token, $email, $path] = prSentLink();
    expect($path)->toBe('/employee/reset-password')->and($email)->toBe($user->email);

    $this->postJson('/api/staff/reset-password', ['token' => $token, 'email' => $email, 'password' => PR_NEW, 'password_confirmation' => PR_NEW])->assertOk();
    expect(Hash::check(PR_NEW, $user->fresh()->password))->toBeTrue();
});

it('sends the mail to the owner address when the demo redirect is set, and says which account it is for', function () {
    config(['mail.intercept_email' => 'owner@example.com']);
    $user = prUser();

    $this->postJson('/api/passengers/forgot-password', ['email' => $user->email])->assertOk();

    Mail::assertSent(PasswordResetMail::class, fn ($m) => $m->hasTo('owner@example.com') && ! $m->hasTo($user->email));
    [$mail] = prSentLink();
    expect($mail->redirected)->toBeTrue()->and($mail->accountEmail)->toBe($user->email);
    expect($mail->render())->toContain($user->email)->and($mail->render())->toContain('Reset password');
});

it('answers the same for an unknown email and sends nothing', function () {
    $known = prUser();

    $a = $this->postJson('/api/passengers/forgot-password', ['email' => $known->email])->assertOk()->json('message');
    Mail::fake();
    $b = $this->postJson('/api/passengers/forgot-password', ['email' => 'nobody@example.com'])->assertOk()->json('message');
    $c = $this->postJson('/api/staff/forgot-password', ['email' => 'nobody@example.com'])->assertOk()->json('message');

    expect($b)->toBe($a)->and($c)->toBe($a);
    Mail::assertNothingSent();
});

it('answers the same when the mail provider fails, and never lets the failure out', function () {
    $user = prUser();
    Mail::shouldReceive('to')->andThrow(new RuntimeException('provider down'));

    $this->postJson('/api/passengers/forgot-password', ['email' => $user->email])
        ->assertOk()->assertJsonPath('message', 'If that email is registered, a reset link has been sent.');
});

it('refuses a wrong, used or mismatched reset, and a reset link works once', function () {
    $user = prUser();
    $this->postJson('/api/passengers/forgot-password', ['email' => $user->email])->assertOk();
    [, $token, $email] = prSentLink();

    $this->postJson('/api/passengers/reset-password', ['token' => 'wrong-token', 'email' => $email, 'password' => PR_NEW, 'password_confirmation' => PR_NEW])->assertStatus(422);
    $this->postJson('/api/passengers/reset-password', ['token' => $token, 'email' => $email, 'password' => PR_NEW, 'password_confirmation' => 'different'])->assertStatus(422)->assertJsonValidationErrors(['password']);
    $this->postJson('/api/passengers/reset-password', ['token' => $token, 'email' => $email, 'password' => 'short', 'password_confirmation' => 'short'])->assertStatus(422);
    expect(Hash::check(PR_OLD, $user->fresh()->password))->toBeTrue();   // nothing changed yet

    $this->postJson('/api/passengers/reset-password', ['token' => $token, 'email' => $email, 'password' => PR_NEW, 'password_confirmation' => PR_NEW])->assertOk();
    $this->postJson('/api/passengers/reset-password', ['token' => $token, 'email' => $email, 'password' => 'third-password-3', 'password_confirmation' => 'third-password-3'])->assertStatus(422);
    expect(Hash::check(PR_NEW, $user->fresh()->password))->toBeTrue();
});

it('expires a reset link after the configured time', function () {
    $user = prUser();
    $this->postJson('/api/passengers/forgot-password', ['email' => $user->email])->assertOk();
    [, $token, $email] = prSentLink();

    DB::table('password_reset_tokens')->where('email', $email)->update(['created_at' => now()->subMinutes(61)]);

    $this->postJson('/api/passengers/reset-password', ['token' => $token, 'email' => $email, 'password' => PR_NEW, 'password_confirmation' => PR_NEW])->assertStatus(422);
    expect(Hash::check(PR_OLD, $user->fresh()->password))->toBeTrue();
});

it('sends one email, not two, when the link is asked for twice in a row', function () {
    $user = prUser();

    $this->postJson('/api/passengers/forgot-password', ['email' => $user->email])->assertOk();
    $this->postJson('/api/passengers/forgot-password', ['email' => $user->email])->assertOk();   // throttled by the broker: same answer

    Mail::assertSent(PasswordResetMail::class, 1);
});

it('finds the account whatever the capitals or spaces in the email', function () {
    $user = prUser();

    $this->postJson('/api/passengers/forgot-password', ['email' => '  '.strtoupper($user->email).' '])->assertOk();

    Mail::assertSent(PasswordResetMail::class, fn ($m) => $m->hasTo($user->email));
    [, $token, $email] = prSentLink();
    $this->postJson('/api/passengers/reset-password', ['token' => $token, 'email' => strtoupper($email), 'password' => PR_NEW, 'password_confirmation' => PR_NEW])->assertOk();
    expect(Hash::check(PR_NEW, $user->fresh()->password))->toBeTrue();
});

it('keeps the forgot and reset limits apart, so a typo while resetting does not lock the next request', function () {
    $user = prUser();
    $this->postJson('/api/passengers/forgot-password', ['email' => $user->email])->assertOk();
    [, $token, $email] = prSentLink();

    foreach (range(1, 4) as $i) {
        $this->postJson('/api/passengers/reset-password', ['token' => 'typo-'.$i, 'email' => $email, 'password' => PR_NEW, 'password_confirmation' => PR_NEW])->assertStatus(422);
    }
    $this->postJson('/api/passengers/reset-password', ['token' => $token, 'email' => $email, 'password' => PR_NEW, 'password_confirmation' => PR_NEW])->assertOk();
    // A seventh reset attempt in the window is refused.
    foreach (range(1, 5) as $i) {
        $this->postJson('/api/passengers/reset-password', ['token' => 'x', 'email' => $email, 'password' => PR_NEW, 'password_confirmation' => PR_NEW]);
    }
    $this->postJson('/api/passengers/reset-password', ['token' => 'x', 'email' => $email, 'password' => PR_NEW, 'password_confirmation' => PR_NEW])->assertStatus(429);
});
