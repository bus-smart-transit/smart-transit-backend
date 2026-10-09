<?php

use App\Models\StaffUser;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

// Crew pairing PIN (Priority 1, item 9): a 6-digit PIN has a million values, so wrong tries are limited per signed-in
// account; and a rate limit now says how long to wait instead of always claiming a login lockout (L-04).

function cpStaff(string $role): User
{
    $user = User::create(['username' => 'cp'.Str::random(8), 'email' => Str::random(8).'@example.com', 'password' => bcrypt('password123'), 'role' => $role]);
    StaffUser::create(['user_id' => $user->user_id, 'company_user_uuid' => (string) Str::uuid(), 'name' => 'Cp '.ucfirst($role), 'phone_num' => '09170000071', 'address' => 'x']);

    return $user->fresh();
}

beforeEach(function () {
    Cache::flush();
    RateLimiter::clear('crew-pairing');
});

it('limits PIN tries per account, for both the PIN check and the pairing call, on both staff apps', function (string $role, string $path) {
    $staff = cpStaff($role);

    foreach (range(1, 12) as $try) {
        // Whatever the answer is (no trip, wrong PIN, not paired), it is not the limit yet.
        expect($this->actingAs($staff)->postJson("/api/$role/$path", ['pin_code' => '000000', 'token' => 'x'])->status())->not->toBe(429);
    }

    $blocked = $this->actingAs($staff)->postJson("/api/$role/$path", ['pin_code' => '000000', 'token' => 'x']);
    $blocked->assertStatus(429);
    expect($blocked->json('message'))->toContain('Too many attempts')->not->toContain('login');
    expect($blocked->headers->get('Retry-After'))->not->toBeNull();
})->with([
    'driver pin' => ['driver', 'pin/verify'],
    'driver pair' => ['driver', 'pair'],
    'conductor pin' => ['conductor', 'pin/verify'],
    'conductor pair' => ['conductor', 'pair'],
]);

it('counts each account separately, so one crew member being slowed does not lock out the others', function () {
    $first = cpStaff('driver');
    $second = cpStaff('driver');

    foreach (range(1, 13) as $try) {
        $this->actingAs($first)->postJson('/api/driver/pin/verify', ['pin_code' => '000000']);
    }

    $this->actingAs($first)->postJson('/api/driver/pin/verify', ['pin_code' => '000000'])->assertStatus(429);
    expect($this->actingAs($second)->postJson('/api/driver/pin/verify', ['pin_code' => '000000'])->status())->not->toBe(429);
});

it('shares no counter between the PIN check and other staff routes', function () {
    $driver = cpStaff('driver');

    foreach (range(1, 13) as $try) {
        $this->actingAs($driver)->postJson('/api/driver/pin/verify', ['pin_code' => '000000']);
    }

    // Reading the PIN (a different route) is not blocked by the failed tries.
    expect($this->actingAs($driver)->getJson('/api/driver/pin')->status())->not->toBe(429);
});
