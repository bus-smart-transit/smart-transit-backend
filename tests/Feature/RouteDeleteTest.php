<?php

use App\Models\Company;
use App\Models\FareRule;
use App\Models\Fleet;
use App\Models\FleetRoute;
use App\Models\Route;
use App\Models\RouteStop;
use App\Models\StaffUser;
use App\Models\Stop;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

// Batch 59 (user, 2026-11-11): the Company Admin deletes a route that was never used (no stops, no bus ever assigned,
// no fare rule). A route in use is never deleted, and the message says what still uses it. SAMPLE names.

function rdStaff(Company $company, bool $admin): User
{
    $user = User::create(['username' => 'rd'.Str::random(8), 'email' => Str::random(8).'@example.com', 'password' => bcrypt('password123'), 'role' => 'operator']);
    StaffUser::create(['user_id' => $user->user_id, 'company_user_uuid' => (string) Str::uuid(), 'name' => 'Rd '.Str::random(3), 'phone_num' => '09170000096', 'address' => 'x', 'company_id' => $company->company_id, 'is_company_admin' => $admin]);

    return $user->fresh();
}

function rdRoute(Company $company): Route
{
    return Route::create(['route_name' => 'Rd Route '.Str::random(4), 'origin' => 'A', 'destination' => 'B', 'company_id' => $company->company_id]);
}

function rdAs($test, User $user): mixed
{
    app('auth')->forgetGuards();

    return $test->actingAs($user);
}

it('lets the Company Admin delete a route that was never used', function () {
    $company = Company::create(['company_name' => 'Rd Co '.Str::random(4)]);
    $admin = rdStaff($company, true);
    $route = rdRoute($company);

    rdAs($this, $admin)->deleteJson("/api/operator/routes/{$route->route_id}")->assertOk()->assertJsonPath('message', 'Route deleted.');
    expect(Route::find($route->route_id))->toBeNull();
});

it('refuses a route that has stops, a bus or a fare rule, and says what still uses it', function () {
    $company = Company::create(['company_name' => 'Rd Co '.Str::random(4)]);
    $admin = rdStaff($company, true);

    $withStop = rdRoute($company);
    $stop = Stop::create(['stop_name' => 'Rd Stop', 'latitude' => 7.0, 'longitude' => 125.0, 'company_id' => $company->company_id]);
    RouteStop::create(['route_id' => $withStop->route_id, 'stop_id' => $stop->stop_id, 'stop_order' => 1, 'distance_from_origin_km' => 0, 'stop_type' => 'stop']);
    rdAs($this, $admin)->deleteJson("/api/operator/routes/{$withStop->route_id}")->assertUnprocessable()
        ->assertJsonPath('errors.route.0', 'This route is still in use (1 stop), so it cannot be deleted.');

    $withBus = rdRoute($company);
    $fleet = Fleet::create(['company_user_id' => $admin->companyProfile->company_user_id, 'plate_number' => 'RD-'.fake()->unique()->numerify('####'), 'capacity' => 30, 'seated_capacity' => 30, 'standing_capacity' => 0, 'status' => 'active']);
    FleetRoute::create(['fleet_id' => $fleet->fleet_id, 'route_id' => $withBus->route_id, 'start_time' => '05:00', 'end_time' => '22:00', 'status' => 'inactive']);
    rdAs($this, $admin)->deleteJson("/api/operator/routes/{$withBus->route_id}")->assertUnprocessable()
        ->assertJsonPath('errors.route.0', 'This route is still in use (1 bus assignment), so it cannot be deleted.');

    $withFare = rdRoute($company);
    FareRule::create(['fleet_id' => $fleet->fleet_id, 'route_id' => $withFare->route_id, 'service_type' => 'regular', 'seat_type' => 'seated', 'status' => 'active', 'base_fare' => 12, 'fare_per_km' => 2.2]);
    rdAs($this, $admin)->deleteJson("/api/operator/routes/{$withFare->route_id}")->assertUnprocessable()
        ->assertJsonPath('errors.route.0', 'This route is still in use (1 fare rule), so it cannot be deleted.');

    expect(Route::whereIn('route_id', [$withStop->route_id, $withBus->route_id, $withFare->route_id])->count())->toBe(3);
});

it('lets only a Company Admin of the route\'s own company delete it', function () {
    $company = Company::create(['company_name' => 'Rd Co '.Str::random(4)]);
    $other = Company::create(['company_name' => 'Rd Other '.Str::random(4)]);
    $route = rdRoute($company);

    rdAs($this, rdStaff($company, false))->deleteJson("/api/operator/routes/{$route->route_id}")->assertForbidden();
    rdAs($this, rdStaff($other, true))->deleteJson("/api/operator/routes/{$route->route_id}")->assertNotFound();
    expect(Route::find($route->route_id))->not->toBeNull();
});
