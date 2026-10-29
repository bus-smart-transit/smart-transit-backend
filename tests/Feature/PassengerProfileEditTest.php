<?php

use App\Models\PassengerUser;
use App\Models\StaffUser;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

// 2026-10-29 (Batch 36 Phase 2): a signed-in passenger edits their own name, phone and gender. Nothing else in the
// request is read; other devices stay signed in; staff cannot use it.

function ppePassenger(): PassengerUser
{
    $user = User::create(['username' => 'ppe'.Str::random(6), 'email' => strtolower('ppe'.Str::random(6).'@example.com'), 'password' => bcrypt('password123'), 'role' => 'passenger', 'email_verified_at' => now()]);

    return PassengerUser::create([
        'passenger_uuid' => (string) Str::uuid(), 'user_id' => $user->user_id, 'name' => 'Old Name',
        'phone_num' => '09170000074', 'address' => 'Tagum City', 'birthdate' => '2000-05-05',
    ]);
}

it('saves a new name, phone and gender and shows them back', function () {
    $passenger = ppePassenger();

    $this->actingAs($passenger->user)->patchJson('/api/passengers/profile', [
        'name' => '  Ana   Reyes ', 'phone_num' => '0917 123 4567', 'gender' => 'female',
    ])->assertSuccessful()
        ->assertJsonPath('data.name', 'Ana Reyes')
        ->assertJsonPath('data.phone_num', '0917 123 4567')
        ->assertJsonPath('data.gender', 'female');
});

it('reads nothing but the name, phone and gender', function () {
    $passenger = ppePassenger();

    $this->actingAs($passenger->user)->patchJson('/api/passengers/profile', [
        'name' => 'Ana Reyes', 'address' => 'Somewhere else', 'birthdate' => '1990-01-01', 'reward_points' => 9999,
        'email' => 'stolen@example.com', 'passenger_uuid' => (string) Str::uuid(), 'user_id' => 1,
    ])->assertSuccessful();

    $fresh = $passenger->fresh();
    expect($fresh->name)->toBe('Ana Reyes')
        ->and($fresh->address)->toBe('Tagum City')
        ->and($fresh->reward_points)->not->toBe(9999)
        ->and($fresh->user->email)->not->toBe('stolen@example.com');
});

it('refuses an empty name, a bad phone number and an unknown gender', function () {
    $passenger = ppePassenger();

    $this->actingAs($passenger->user)->patchJson('/api/passengers/profile', ['name' => '  ', 'phone_num' => 'call me', 'gender' => 'robot'])
        ->assertStatus(422)->assertJsonValidationErrors(['name', 'phone_num', 'gender']);
});

it('keeps the passenger signed in on their other devices after a name or phone change', function () {
    $passenger = ppePassenger();
    $passenger->user->createToken('other-phone');

    $this->actingAs($passenger->user)->patchJson('/api/passengers/profile', ['name' => 'Ana Reyes'])->assertSuccessful();

    expect($passenger->user->tokens()->where('name', 'other-phone')->exists())->toBeTrue();
});

it('is closed to staff', function () {
    $staffUser = User::create(['username' => 'ppedrv'.Str::random(4), 'email' => strtolower('ppedrv'.Str::random(6).'@example.com'), 'password' => bcrypt('password123'), 'role' => 'driver']);
    StaffUser::create(['user_id' => $staffUser->user_id, 'company_user_uuid' => (string) Str::uuid(), 'name' => 'Driver', 'phone_num' => '09170000075', 'address' => 'x']);

    $this->actingAs($staffUser)->patchJson('/api/passengers/profile', ['name' => 'Hacker'])->assertForbidden();
});
