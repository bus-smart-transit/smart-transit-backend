<?php

use App\Models\StaffUser;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

// Staff sessions (Priority 2, item 16): an Operator's token that has not been used for 30 minutes stops working and is
// deleted. Driver and Chauffeur tokens are NOT limited that way: their phones send GPS and run a shift with nobody
// touching the screen.

function idStaff(string $role): User
{
    $user = User::create(['username' => 'id'.Str::random(8), 'email' => Str::random(8).'@example.com', 'password' => bcrypt('password123'), 'role' => $role]);
    StaffUser::create(['user_id' => $user->user_id, 'company_user_uuid' => (string) Str::uuid(), 'name' => 'Id '.ucfirst($role), 'phone_num' => '09170000091', 'address' => 'x']);

    return $user->fresh();
}

/** One request with a bearer token. The auth guards are reset first, as a real new request would find them (a test keeps one app between calls). */
function idGet($test, string $plain, string $uri)
{
    app('auth')->forgetGuards();

    return $test->withToken($plain)->getJson($uri);
}

/** A real bearer token whose last use was $minutesAgo minutes ago (null = never used). */
function idToken(User $user, ?int $minutesAgo): string
{
    $token = $user->createToken('test');
    DB::table('personal_access_tokens')->where('id', $token->accessToken->id)->update([
        'last_used_at' => $minutesAgo === null ? null : now()->subMinutes($minutesAgo),
        'created_at' => $minutesAgo === null ? now()->subMinutes(5) : now()->subMinutes($minutesAgo + 1),
    ]);

    return $token->plainTextToken;
}

it('ends an Operator session that has been idle for more than 30 minutes, and deletes the token', function () {
    $operator = idStaff('operator');
    $plain = idToken($operator, 31);

    idGet($this, $plain, '/api/operator/profile')->assertUnauthorized();

    expect(DB::table('personal_access_tokens')->where('tokenable_id', $operator->user_id)->count())->toBe(0);
});

it('keeps an Operator session that was used recently, and counts that use', function () {
    $operator = idStaff('operator');
    $plain = idToken($operator, 5);

    idGet($this, $plain, '/api/operator/profile')->assertOk();
    idGet($this, $plain, '/api/operator/profile')->assertOk();

    $lastUsed = DB::table('personal_access_tokens')->where('tokenable_id', $operator->user_id)->value('last_used_at');
    expect(\Illuminate\Support\Carbon::parse($lastUsed)->gt(now()->subMinute()))->toBeTrue();
});

it('lets a session that keeps being used go on past 30 minutes in total', function () {
    $operator = idStaff('operator');
    $plain = idToken($operator, 20);

    idGet($this, $plain, '/api/operator/profile')->assertOk();                 // used at minute 20: fine
    DB::table('personal_access_tokens')->where('tokenable_id', $operator->user_id)->update(['last_used_at' => now()->subMinutes(20)]);
    idGet($this, $plain, '/api/operator/profile')->assertOk();                 // idle 20 again: fine
});

it('treats a token that was never used by when it was made', function () {
    $operator = idStaff('operator');
    $fresh = idToken($operator, null);                                                      // made 5 minutes ago, never used

    idGet($this, $fresh, '/api/operator/profile')->assertOk();

    $stale = $operator->createToken('old');
    DB::table('personal_access_tokens')->where('id', $stale->accessToken->id)->update(['last_used_at' => null, 'created_at' => now()->subMinutes(40)]);
    idGet($this, $stale->plainTextToken, '/api/operator/profile')->assertUnauthorized();
});

it('does not limit Driver or Chauffeur sessions by idle time', function (string $role) {
    $staff = idStaff($role);
    $plain = idToken($staff, 180);                                                          // three hours without a request

    idGet($this, $plain, "/api/$role/profile")->assertOk();
})->with(['driver', 'conductor']);

it('can be changed or switched off by configuration', function () {
    $operator = idStaff('operator');

    config(['sanctum.idle_minutes.operator' => 120]);
    idGet($this, idToken($operator, 90), '/api/operator/profile')->assertOk();

    config(['sanctum.idle_minutes.operator' => 0]);
    idGet($this, idToken($operator, 600), '/api/operator/profile')->assertOk();
});

it('still ends a session that is past the 24 hour life, whatever the idle limit says', function () {
    $operator = idStaff('operator');
    $plain = idToken($operator, 2);
    DB::table('personal_access_tokens')->where('tokenable_id', $operator->user_id)->update(['created_at' => now()->subHours(25)]);

    idGet($this, $plain, '/api/operator/profile')->assertUnauthorized();
});
