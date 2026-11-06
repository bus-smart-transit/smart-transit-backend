<?php

use App\Models\Company;
use App\Models\Route;
use App\Models\RouteStop;
use App\Models\StaffUser;
use App\Models\Stop;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

// 2026-11-06 (decided by the user): a company admin manages the company's buses, fares, staff, routes and stops; other
// Operators dispatch and only view. Routes and stops belong to a company; another company's are not found.

function cadOperator(Company $company, bool $admin, string $name = 'Op'): User
{
    $user = User::create(['username' => 'cad'.Str::random(6), 'email' => 'cad.'.Str::lower(Str::random(6)).'@example.com', 'password' => 'Right-password-1', 'role' => 'operator']);
    StaffUser::create(['user_id' => $user->user_id, 'company_user_uuid' => (string) Str::uuid(), 'name' => $name, 'phone_num' => '+639171234567', 'address' => 'Tagum', 'company_id' => $company->company_id, 'is_company_admin' => $admin]);

    return $user->fresh();
}

function cadRoute(Company $company, string $name): Route
{
    $route = Route::create(['route_name' => $name, 'origin' => 'A', 'destination' => 'B', 'company_id' => $company->company_id]);
    $stop = Stop::create(['stop_name' => "{$name} Stop", 'latitude' => 7.07, 'longitude' => 125.6, 'company_id' => $company->company_id]);
    RouteStop::create(['route_id' => $route->route_id, 'stop_id' => $stop->stop_id, 'stop_order' => 1, 'distance_from_origin_km' => 0, 'stop_type' => 'terminal']);

    return $route;
}

it('lets a dispatcher view but not change buses, staff, fares, routes or stops', function () {
    $metro = Company::create(['company_name' => 'Davao Metro Shuttle']);
    $dispatcher = cadOperator($metro, false, 'Dispatcher');
    cadRoute($metro, 'Metro Line');

    $this->actingAs($dispatcher, 'sanctum');
    $this->getJson('/api/operator/routes')->assertSuccessful();
    $this->getJson('/api/operator/stops')->assertSuccessful();
    $this->getJson('/api/operator/fleets')->assertSuccessful();

    $refused = [
        $this->postJson('/api/operator/fleets', ['plate_number' => 'X-1', 'seated_capacity' => 1, 'standing_capacity' => 0, 'fleet_type' => 'public']),
        $this->postJson('/api/operator/accounts', ['role' => 'driver']),
        $this->postJson('/api/operator/fare-rules', ['fleet_id' => 1]),
        $this->postJson('/api/operator/routes', ['route_name' => 'New', 'origin' => 'A', 'destination' => 'B']),
        $this->postJson('/api/operator/stops', ['stop_name' => 'New', 'latitude' => 7.07, 'longitude' => 125.6]),
    ];
    foreach ($refused as $response) {
        $response->assertForbidden()->assertJsonPath('message', 'Only your company admin can change this. Ask them, or the platform Admin.');
    }
});

it('lets a company admin create routes and stops for their own company only', function () {
    $metro = Company::create(['company_name' => 'Davao Metro Shuttle']);
    $admin = cadOperator($metro, true);

    $this->actingAs($admin, 'sanctum');
    $routeId = $this->postJson('/api/operator/routes', ['route_name' => 'Metro New', 'origin' => 'A', 'destination' => 'B'])->assertSuccessful()->json('data.route_id');
    $stopId = $this->postJson('/api/operator/stops', ['stop_name' => 'Metro Stop', 'latitude' => 7.07, 'longitude' => 125.6])->assertSuccessful()->json('data.stop_id');

    expect((int) Route::find($routeId)->company_id)->toBe((int) $metro->company_id)
        ->and((int) Stop::find($stopId)->company_id)->toBe((int) $metro->company_id);
});

it('hides another company\'s routes and stops, and refuses to change or use them', function () {
    $metro = Company::create(['company_name' => 'Davao Metro Shuttle']);
    $rival = Company::create(['company_name' => 'Rival Lines']);
    $admin = cadOperator($metro, true);
    $mine = cadRoute($metro, 'Metro Line');
    $theirs = cadRoute($rival, 'Rival Line');
    $theirStop = Stop::where('company_id', $rival->company_id)->first();

    $this->actingAs($admin, 'sanctum');
    expect(collect($this->getJson('/api/operator/routes')->json('data'))->pluck('route_name')->all())->toBe(['Metro Line'])
        ->and(collect($this->getJson('/api/operator/stops')->json('data'))->pluck('stop_name')->all())->toBe(['Metro Line Stop']);

    $this->getJson("/api/operator/routes/{$theirs->route_id}")->assertNotFound();
    $this->patchJson("/api/operator/routes/{$theirs->route_id}/custom-dropoff", ['allow_custom_dropoff' => true])->assertNotFound();
    $this->putJson("/api/operator/stops/{$theirStop->stop_id}", ['stop_name' => 'Taken'])->assertNotFound();
    $this->deleteJson("/api/operator/stops/{$theirStop->stop_id}")->assertNotFound();
    $this->postJson("/api/operator/routes/{$mine->route_id}/stops", ['stop_id' => $theirStop->stop_id, 'stop_order' => 2])->assertNotFound();

    expect($theirStop->fresh()->stop_name)->toBe('Rival Line Stop')
        ->and(RouteStop::where('route_id', $mine->route_id)->count())->toBe(1);
});

it('switches the company admin permission with staff:create\'s companion command', function () {
    $metro = Company::create(['company_name' => 'Davao Metro Shuttle']);
    $op = cadOperator($metro, false);

    $this->artisan('staff:company-admin', ['email' => $op->email])->assertSuccessful();
    expect($op->fresh()->companyProfile->is_company_admin)->toBeTrue();

    $this->artisan('staff:company-admin', ['email' => $op->email, '--off' => true])->assertSuccessful();
    expect($op->fresh()->companyProfile->is_company_admin)->toBeFalse();

    $this->artisan('staff:company-admin', ['email' => 'nobody@example.com'])->assertFailed();
});
