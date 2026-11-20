<?php

use App\Models\Company;
use App\Models\FareRule;
use App\Models\Fleet;
use App\Models\Route;
use App\Models\StaffUser;
use App\Models\User;
use App\Services\FareRuleResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

// 2026-11-20 (user): the Company Admin sets the company's general fare per vehicle class and service type. It prices
// only that company's buses, one rule prices seated and standing, and a bus's own rule still wins. The amounts are the
// fares the user confirmed as the ones in use (PHP 12 base; 2.20/km ordinary, 2.45/km aircon, 2.75/km express).

function cgfOperator(string $companyName, bool $admin = true): User
{
    $company = Company::firstOrCreate(['company_name' => $companyName]);
    $user = User::create(['username' => 'cgf'.Str::random(6), 'email' => 'cgf.'.Str::lower(Str::random(6)).'@example.com', 'password' => 'Right-password-1', 'role' => 'operator']);
    StaffUser::create(['user_id' => $user->user_id, 'company_user_uuid' => (string) Str::uuid(), 'name' => 'Cgf Op', 'phone_num' => '+639171234567', 'address' => 'Tagum', 'company_id' => $company->company_id, 'is_company_admin' => $admin]);

    return $user->fresh();
}

function cgfFleet(User $owner, string $class): Fleet
{
    return Fleet::create(['company_user_id' => $owner->companyProfile->company_user_id, 'company_id' => $owner->companyProfile->company_id, 'plate_number' => 'CGF-'.fake()->unique()->numerify('####'), 'capacity' => 50, 'seated_capacity' => 40, 'standing_capacity' => 10, 'status' => 'active', 'vehicle_class' => $class]);
}

/** Acts as the user with a fresh step-up token (the extra check fare changes need). */
function cgfAs($test, User $user)
{
    $raw = bin2hex(random_bytes(32));
    DB::table('step_up_tokens')->insert(['guard' => 'sanctum', 'user_id' => $user->user_id, 'token_hash' => hash('sha256', $raw), 'expires_at' => now()->addMinutes(15), 'created_at' => now()]);

    return $test->actingAs($user, 'sanctum')->withHeader('X-Step-Up-Token', $raw);
}

function cgfGeneral($test, User $admin, string $class, string $service, float $perKm): int
{
    return (int) cgfAs($test, $admin)->postJson('/api/operator/fare-rules', [
        'vehicle_class' => $class, 'service_type' => $service, 'seat_type' => 'seated', 'base_fare' => 12, 'fare_per_km' => $perKm, 'base_distance_km' => 5,
    ])->assertSuccessful()->json('data.fare_rule_id');
}

it('lets a Company Admin set a general fare that prices only their company\'s buses, seated and standing', function () {
    $metro = cgfOperator('Davao Metro Shuttle');
    $other = cgfOperator('Other Bus Co');
    $route = Route::create(['route_name' => 'Cgf Route', 'origin' => 'A', 'destination' => 'B']);
    $mine = cgfFleet($metro, 'ordinary');
    $theirs = cgfFleet($other, 'ordinary');

    $ruleId = cgfGeneral($this, $metro, 'ordinary', 'regular', 2.20);

    $resolver = app(FareRuleResolver::class);
    expect((int) FareRule::find($ruleId)->company_id)->toBe((int) $metro->companyProfile->company_id)
        ->and($resolver->resolve($mine, $route->route_id, 'regular', 'seated')?->fare_rule_id)->toBe($ruleId)
        ->and($resolver->resolve($mine, $route->route_id, 'regular', 'standing')?->fare_rule_id)->toBe($ruleId) // one rule, both seat types
        ->and($resolver->resolve($theirs, $route->route_id, 'regular', 'seated'))->toBeNull(); // another company's bus
});

it('keeps a bus\'s own rule ahead of the company general rule, and uses the general one once the bus rule is off', function () {
    $metro = cgfOperator('Davao Metro Shuttle');
    $route = Route::create(['route_name' => 'Cgf Route', 'origin' => 'A', 'destination' => 'B']);
    $bus = cgfFleet($metro, 'aircon');
    $own = FareRule::create(['fleet_id' => $bus->fleet_id, 'service_type' => 'regular', 'seat_type' => 'seated', 'base_fare' => 12, 'fare_per_km' => 2.45, 'status' => 'active']);
    $general = cgfGeneral($this, $metro, 'aircon', 'regular', 2.45);

    $resolver = app(FareRuleResolver::class);
    expect($resolver->resolve($bus, $route->route_id, 'regular', 'seated')?->fare_rule_id)->toBe($own->fare_rule_id);

    $own->update(['status' => 'inactive']);
    expect($resolver->resolve($bus, $route->route_id, 'regular', 'seated')?->fare_rule_id)->toBe($general);
});

it('prices express on every bus of the class from one general express rule', function () {
    $metro = cgfOperator('Davao Metro Shuttle');
    $route = Route::create(['route_name' => 'Cgf Route', 'origin' => 'A', 'destination' => 'B']);
    $express = cgfGeneral($this, $metro, 'ordinary', 'express', 2.75);

    foreach ([cgfFleet($metro, 'ordinary'), cgfFleet($metro, 'ordinary')] as $bus) {
        expect(app(FareRuleResolver::class)->resolve($bus, $route->route_id, 'express', 'seated')?->fare_rule_id)->toBe($express);
    }
});

it('lets only the Company Admin write general fares, and only their own company\'s', function () {
    $metro = cgfOperator('Davao Metro Shuttle');
    $dispatcher = cgfOperator('Davao Metro Shuttle', false);
    $other = cgfOperator('Other Bus Co');
    $ruleId = cgfGeneral($this, $metro, 'ordinary', 'regular', 2.20);

    // A dispatcher of the same company: refused by the company admin check.
    cgfAs($this, $dispatcher)->postJson('/api/operator/fare-rules', ['vehicle_class' => 'aircon', 'service_type' => 'regular', 'seat_type' => 'seated', 'base_fare' => 12, 'fare_per_km' => 2.45])
        ->assertForbidden();

    // Another company's admin: does not see the rule and cannot change it.
    $this->app['auth']->forgetGuards();
    expect(collect(cgfAs($this, $other)->getJson('/api/operator/fare-rules')->assertSuccessful()->json('data'))->pluck('fare_rule_id')->all())
        ->not->toContain($ruleId);
    $this->app['auth']->forgetGuards();
    cgfAs($this, $other)->patchJson("/api/operator/fare-rules/{$ruleId}", ['status' => 'inactive'])->assertNotFound();

    // The owner sees it and can turn it off.
    $this->app['auth']->forgetGuards();
    expect(collect(cgfAs($this, $metro)->getJson('/api/operator/fare-rules')->json('data'))->pluck('fare_rule_id')->all())->toContain($ruleId);
    $this->app['auth']->forgetGuards();
    cgfAs($this, $metro)->patchJson("/api/operator/fare-rules/{$ruleId}", ['status' => 'inactive'])->assertSuccessful();
    expect(FareRule::find($ruleId)->status)->toBe('inactive');
});

it('refuses a general fare without a base distance, and accepts 0 km', function () {
    $metro = cgfOperator('Davao Metro Shuttle');
    $body = ['vehicle_class' => 'ordinary', 'service_type' => 'regular', 'seat_type' => 'seated', 'base_fare' => 12, 'fare_per_km' => 2.20];

    cgfAs($this, $metro)->postJson('/api/operator/fare-rules', $body)
        ->assertUnprocessable()->assertJsonValidationErrors('base_distance_km');
    $this->app['auth']->forgetGuards();
    cgfAs($this, $metro)->postJson('/api/operator/fare-rules', $body + ['base_distance_km' => 0])->assertSuccessful();
});

it('refuses two general rules for the same class, service type and seat type in the same company', function () {
    $metro = cgfOperator('Davao Metro Shuttle');
    $other = cgfOperator('Other Bus Co');
    cgfGeneral($this, $metro, 'ordinary', 'regular', 2.20);

    $this->app['auth']->forgetGuards();
    cgfAs($this, $metro)->postJson('/api/operator/fare-rules', ['vehicle_class' => 'ordinary', 'service_type' => 'regular', 'seat_type' => 'seated', 'base_fare' => 12, 'fare_per_km' => 2.30, 'base_distance_km' => 5])
        ->assertStatus(422);

    // Another company may set its own.
    $this->app['auth']->forgetGuards();
    cgfGeneral($this, $other, 'ordinary', 'regular', 2.30);
});
