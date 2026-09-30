<?php

use App\Models\Fleet;
use App\Models\StaffUser;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

function stepUpPwOperator(): User
{
    $user = User::create([
        'username' => 'op_'.uniqid(),
        'email' => 'op+'.uniqid().'@smarttransit.test',
        'password' => bcrypt('correct-horse-9'),
        'role' => 'operator',
    ]);

    StaffUser::create([
        'company_user_uuid' => (string) Str::uuid(),
        'user_id' => $user->user_id,
        'name' => 'Operator User',
        'phone_num' => '09170000000',
        'address' => 'Test Address',
    ]);

    return $user->fresh();
}

function stepUpPwFleet(User $operator): Fleet
{
    return Fleet::create([
        'company_user_id' => $operator->companyProfile->company_user_id,
        'plate_number' => 'SUP-'.strtoupper(substr((string) Str::uuid(), 0, 6)),
        'capacity' => 40,
        'seated_capacity' => 30,
        'standing_capacity' => 10,
        'status' => 'active',
        'fleet_type' => 'public',
    ]);
}

it('issues a step-up token for the correct password that unlocks fare rules', function () {
    $operator = stepUpPwOperator();
    $fleet = stepUpPwFleet($operator);
    $payload = ['fleet_id' => $fleet->fleet_id, 'base_fare' => 12, 'fare_per_km' => 2, 'seat_type' => 'seated'];

    $this->actingAs($operator)
        ->postJson('/api/operator/fare-rules', $payload)
        ->assertStatus(403)
        ->assertJsonPath('step_up', true);

    $response = $this->actingAs($operator)
        ->postJson('/api/step-up/verify-password', ['password' => 'correct-horse-9'])
        ->assertOk()
        ->assertJsonPath('data.expires_in', 900);

    $token = $response->json('data.step_up_token');
    expect($token)->toBeString()->toHaveLength(64);

    expect(DB::table('step_up_tokens')->where('user_id', $operator->user_id)->value('token_hash'))
        ->toBe(hash('sha256', $token));

    $this->actingAs($operator)
        ->withHeader('X-Step-Up-Token', $token)
        ->postJson('/api/operator/fare-rules', $payload)
        ->assertSuccessful();
});

it('rejects a wrong password with 422 (never 401) and issues no token', function () {
    $operator = stepUpPwOperator();

    $this->actingAs($operator)
        ->postJson('/api/step-up/verify-password', ['password' => 'wrong-password'])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['password']);

    expect(DB::table('step_up_tokens')->where('user_id', $operator->user_id)->count())->toBe(0);
});

it('locks out after repeated wrong passwords, even for the correct one', function () {
    $operator = stepUpPwOperator();
    RateLimiter::clear('step-up-password:'.$operator->user_id);

    foreach (range(1, 5) as $_) {
        $this->actingAs($operator)
            ->postJson('/api/step-up/verify-password', ['password' => 'nope-nope'])
            ->assertStatus(422);
    }

    $this->actingAs($operator)
        ->postJson('/api/step-up/verify-password', ['password' => 'correct-horse-9'])
        ->assertStatus(429);

    expect(DB::table('step_up_tokens')->where('user_id', $operator->user_id)->count())->toBe(0);
});

it('requires authentication and a password', function () {
    $this->postJson('/api/step-up/verify-password', ['password' => 'x'])
        ->assertStatus(401);

    $operator = stepUpPwOperator();
    $this->actingAs($operator)
        ->postJson('/api/step-up/verify-password', [])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['password']);
});
