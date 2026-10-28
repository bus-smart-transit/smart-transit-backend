<?php

use App\Models\PassengerUser;
use App\Models\StaffUser;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

// 2026-10-28: an optional gender (male, female, prefer_not_to_say) chosen by a passenger at sign-up and set by the
// Operator on a new staff account. Not given means null.

function gndPassengerPayload(array $over = []): array
{
    return array_merge([
        'name' => 'Gnd Passenger', 'email' => 'gnd'.Str::random(6).'@example.com', 'password' => 'password123',
        'password_confirmation' => 'password123', 'phone_num' => '09170000066', 'birthdate' => '2000-01-01',
    ], $over);
}

it('keeps the gender a passenger chose at sign-up and shows it on their profile', function () {
    $this->postJson('/api/passengers/register', gndPassengerPayload(['gender' => 'female']))->assertSuccessful();

    $passenger = PassengerUser::latest('passenger_id')->firstOrFail();
    expect($passenger->gender)->toBe('female');

    $this->actingAs($passenger->user)->getJson('/api/passengers/profile')->assertSuccessful()->assertJsonPath('data.gender', 'female');
    // The passenger dashboard reads the profile from its summary (found by the system-flow Playwright run).
    $this->actingAs($passenger->user)->getJson('/api/passengers/dashboard-summary')->assertSuccessful()->assertJsonPath('data.profile.gender', 'female');
});

it('lets a passenger sign up without a gender', function () {
    $this->postJson('/api/passengers/register', gndPassengerPayload())->assertSuccessful();

    expect(PassengerUser::latest('passenger_id')->firstOrFail()->gender)->toBeNull();
});

it('refuses a gender that is not one of the choices', function () {
    $this->postJson('/api/passengers/register', gndPassengerPayload(['gender' => 'robot']))
        ->assertStatus(422)->assertJsonValidationErrors(['gender']);
});

it('keeps the gender the Operator set on a new Driver account', function () {
    $operatorUser = User::create(['username' => 'gndop'.Str::random(5), 'email' => Str::random(8).'@example.com', 'password' => bcrypt('password123'), 'role' => 'operator']);
    StaffUser::create(['user_id' => $operatorUser->user_id, 'company_user_uuid' => (string) Str::uuid(), 'name' => 'Gnd Operator', 'phone_num' => '09170000067', 'address' => 'x']);

    $this->actingAs($operatorUser)->postJson('/api/operator/accounts', [
        'name' => 'Gnd Driver', 'email' => 'gnddriver'.Str::random(5).'@example.com', 'password' => 'password123',
        'phone_num' => '09170000068', 'address' => 'Tagum', 'role' => 'driver', 'gender' => 'male',
    ])->assertSuccessful();

    expect(StaffUser::where('name', 'Gnd Driver')->value('gender'))->toBe('male');
});
