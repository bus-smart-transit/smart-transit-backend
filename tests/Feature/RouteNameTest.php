<?php

use App\Models\Company;
use App\Models\Route;
use App\Models\StaffUser;
use App\Models\User;
use App\Support\RouteName;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

// 2026-11-20 (user): the Operator types the origin and destination once; the route name is made from them.

function rnAdmin(): User
{
    $company = Company::create(['company_name' => 'Davao Metro Shuttle']);
    $user = User::create(['username' => 'rn'.Str::random(6), 'email' => 'rn.'.Str::lower(Str::random(6)).'@example.com', 'password' => 'Right-password-1', 'role' => 'operator']);
    StaffUser::create(['user_id' => $user->user_id, 'company_user_uuid' => (string) Str::uuid(), 'name' => 'Admin', 'phone_num' => '+639171234567', 'address' => 'Tagum', 'company_id' => $company->company_id, 'is_company_admin' => true]);

    return $user->fresh();
}

it('names a new route from its origin and destination when no name is sent', function () {
    $this->actingAs(rnAdmin(), 'sanctum');

    $routeId = $this->postJson('/api/operator/routes', ['origin' => '  Ecoland   Terminal ', 'destination' => 'Tagum Terminal'])
        ->assertSuccessful()->json('data.route_id');

    expect(Route::find($routeId)->route_name)->toBe('Ecoland Terminal to Tagum Terminal');
});

it('keeps a name the Operator typed', function () {
    $this->actingAs(rnAdmin(), 'sanctum');

    $routeId = $this->postJson('/api/operator/routes', ['route_name' => 'Metro Express', 'origin' => 'A', 'destination' => 'B'])
        ->assertSuccessful()->json('data.route_id');

    expect(Route::find($routeId)->route_name)->toBe('Metro Express');
});

it('still needs the origin and destination', function () {
    $this->actingAs(rnAdmin(), 'sanctum');

    $this->postJson('/api/operator/routes', ['origin' => 'A'])->assertUnprocessable()->assertJsonValidationErrors(['destination']);
    expect(RouteName::from('A', 'B'))->toBe('A to B');
});
