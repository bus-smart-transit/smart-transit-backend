<?php

use App\Models\Company;
use App\Models\Route;
use App\Models\StaffUser;
use App\Models\User;
use App\Support\RouteName;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

// 2026-11-23 (advisor): a company may not add the same road twice, in either direction.

function rdAdmin(?Company $company = null): User
{
    $company ??= Company::create(['company_name' => 'Company '.Str::random(4)]);
    $user = User::create(['username' => 'rd'.Str::random(6), 'email' => 'rd.'.Str::lower(Str::random(6)).'@example.com', 'password' => 'Right-password-1', 'role' => 'operator']);
    StaffUser::create(['user_id' => $user->user_id, 'company_user_uuid' => (string) Str::uuid(), 'name' => 'Admin', 'phone_num' => '+639171234567', 'address' => 'Mati', 'company_id' => $company->company_id, 'is_company_admin' => true]);

    return $user->fresh();
}

it('refuses the same route twice in a company, ignoring case and spaces', function () {
    $this->actingAs(rdAdmin(), 'sanctum');
    $this->postJson('/api/operator/routes', ['origin' => 'Mati', 'destination' => 'Nabunturan'])->assertSuccessful();

    $this->postJson('/api/operator/routes', ['origin' => ' mati ', 'destination' => 'NABUNTURAN'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['origin' => 'This route already exists as Mati to Nabunturan. Its schedules run both ways.']);
    expect(Route::count())->toBe(1);
});

it('refuses the reverse direction of an existing route', function () {
    $this->actingAs(rdAdmin(), 'sanctum');
    $this->postJson('/api/operator/routes', ['origin' => 'Mati', 'destination' => 'Nabunturan'])->assertSuccessful();

    $this->postJson('/api/operator/routes', ['origin' => 'Nabunturan', 'destination' => 'Mati'])->assertUnprocessable();
    expect(Route::count())->toBe(1);
});

it('lets another company run the same road', function () {
    $this->actingAs(rdAdmin(), 'sanctum');
    $this->postJson('/api/operator/routes', ['origin' => 'Mati', 'destination' => 'Nabunturan'])->assertSuccessful();

    app('auth')->forgetGuards();
    $this->actingAs(rdAdmin(), 'sanctum');
    $this->postJson('/api/operator/routes', ['origin' => 'Mati', 'destination' => 'Nabunturan'])->assertSuccessful();
    expect(Route::count())->toBe(2);
});

it('refuses an edit that turns a route into a copy of another, but allows renaming it', function () {
    $this->actingAs(rdAdmin(), 'sanctum');
    $this->postJson('/api/operator/routes', ['origin' => 'Mati', 'destination' => 'Nabunturan'])->assertSuccessful();
    $other = $this->postJson('/api/operator/routes', ['origin' => 'Mati', 'destination' => 'Tagum'])->assertSuccessful()->json('data.route_id');

    $this->patchJson("/api/operator/routes/{$other}", ['destination' => 'Nabunturan'])->assertUnprocessable();
    $this->patchJson("/api/operator/routes/{$other}", ['route_name' => 'Mati Express'])->assertSuccessful();
    $this->patchJson("/api/operator/routes/{$other}", ['origin' => 'MATI'])->assertSuccessful();
});

it('treats the two ends as one road whatever their order', function () {
    expect(RouteName::endsKey('Mati', 'Nabunturan'))->toBe(RouteName::endsKey(' nabunturan ', 'MATI'))
        ->and(RouteName::endsKey('Mati', 'Tagum'))->not->toBe(RouteName::endsKey('Mati', 'Nabunturan'));
});
