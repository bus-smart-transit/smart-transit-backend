<?php

use App\Models\Company;
use App\Models\FareRule;
use App\Models\Fleet;
use App\Models\FleetRoute;
use App\Models\Route;
use App\Models\StaffUser;
use App\Models\Trip;
use App\Models\User;
use App\Services\FcmService;
use App\Support\CompanyScope;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

// Batch 46 step 2 (decided 2026-11-06): buses, trips and shift blocks belong to the company. Every Operator of the
// company sees and runs them; another company's are not listed and cannot be used.

beforeEach(function () {
    $this->mock(FcmService::class, fn ($mock) => $mock->shouldReceive('sendToUser')->andReturn(true));
});

function cfsOperator(Company $company, string $name): User
{
    $user = User::create(['username' => 'cfs'.Str::random(6), 'email' => 'cfs.'.Str::random(6).'@example.com', 'password' => 'Right-password-1', 'role' => 'operator']);
    StaffUser::create(['user_id' => $user->user_id, 'company_user_uuid' => (string) Str::uuid(), 'name' => $name, 'phone_num' => '+639171234567', 'address' => 'Tagum', 'company_id' => $company->company_id, 'is_company_admin' => true]);

    return $user->fresh();
}

function cfsId(User $user): int
{
    return (int) $user->companyProfile->company_user_id;
}

function cfsBus(User $owner, string $plate): FleetRoute
{
    $route = Route::create(['route_name' => 'Cfs '.Str::random(4), 'origin' => 'A', 'destination' => 'B']);
    $fleet = Fleet::create(['company_user_id' => cfsId($owner), 'company_id' => $owner->companyProfile->company_id, 'plate_number' => $plate, 'capacity' => 50, 'seated_capacity' => 40, 'standing_capacity' => 10, 'status' => 'active']);
    FareRule::create(['fleet_id' => $fleet->fleet_id, 'service_type' => 'regular', 'seat_type' => 'seated', 'status' => 'active', 'base_fare' => 15, 'fare_per_km' => 2, 'note' => 'SAMPLE: Batch 50, a bus needs a fare to be scheduled']);

    return FleetRoute::create(['fleet_id' => $fleet->fleet_id, 'route_id' => $route->route_id, 'start_time' => '05:00', 'end_time' => '22:00', 'status' => 'active']);
}

it('lists every bus of the company to each of its Operators, and no other company\'s', function () {
    $metro = Company::create(['company_name' => 'Davao Metro Shuttle']);
    $other = Company::create(['company_name' => 'Other Lines']);
    $first = cfsOperator($metro, 'First');
    $second = cfsOperator($metro, 'Second');
    $rival = cfsOperator($other, 'Rival');
    cfsBus($first, 'MET-001');
    cfsBus($rival, 'OTH-001');

    $this->actingAs($second, 'sanctum');
    $plates = collect($this->getJson('/api/operator/fleets')->assertSuccessful()->json('data'))->pluck('plate_number')->all();
    $routes = collect($this->getJson('/api/operator/fleet-routes')->assertSuccessful()->json('data'))->pluck('fleet.plate_number')->all();

    expect($plates)->toBe(['MET-001'])->and($routes)->toBe(['MET-001']);
});

it('refuses to assign a route to another company\'s bus (404)', function () {
    $mine = cfsOperator(Company::create(['company_name' => 'Mine Co']), 'Mine');
    $rival = cfsOperator(Company::create(['company_name' => 'Rival Co']), 'Rival');
    $theirs = cfsBus($rival, 'OTH-002');
    $route = Route::create(['route_name' => 'Extra', 'origin' => 'C', 'destination' => 'D']);

    $this->actingAs($mine, 'sanctum');
    $this->postJson("/api/operator/fleets/{$theirs->fleet_id}/routes", ['route_id' => $route->route_id, 'start_time' => '06:00', 'end_time' => '20:00'])
        ->assertNotFound();

    expect(FleetRoute::where('fleet_id', $theirs->fleet_id)->count())->toBe(1);
});

it('refuses to schedule a trip on another company\'s bus, and lets a co-Operator see and act on a trip', function () {
    $metro = Company::create(['company_name' => 'Davao Metro Shuttle']);
    $first = cfsOperator($metro, 'First');
    $second = cfsOperator($metro, 'Second');
    $rival = cfsOperator(Company::create(['company_name' => 'Rival Co']), 'Rival');
    $mine = cfsBus($first, 'MET-002');
    $theirs = cfsBus($rival, 'OTH-003');
    $date = now()->addDays(2)->toDateString();

    $this->actingAs($first, 'sanctum');
    $this->postJson('/api/operator/trips', ['fleet_route_id' => $theirs->fleet_route_id, 'trip_date' => $date, 'departure_time' => '09:00', 'trip_type' => 'one_way'])
        ->assertUnprocessable()->assertJsonPath('errors.fleet_route_id.0', 'Choose one of your own buses.');
    $tripId = $this->postJson('/api/operator/trips', ['fleet_route_id' => $mine->fleet_route_id, 'trip_date' => $date, 'departure_time' => '09:00', 'trip_type' => 'one_way'])
        ->assertSuccessful()->json('data.trip_id');
    app('auth')->forgetGuards();

    $this->actingAs($second, 'sanctum');
    expect(collect($this->getJson('/api/operator/trips')->json('data.data') ?? $this->getJson('/api/operator/trips')->json('data'))->pluck('trip_id')->all())->toContain($tripId);
    $this->patchJson("/api/operator/trips/{$tripId}/cancel", ['reason' => 'Test'])->assertSuccessful();
    app('auth')->forgetGuards();

    $this->actingAs($rival, 'sanctum');
    expect(collect($this->getJson('/api/operator/trips')->json('data.data') ?? $this->getJson('/api/operator/trips')->json('data'))->pluck('trip_id')->all())->not->toContain($tripId);
    expect(Trip::find($tripId)->status)->toBe('cancelled');
});

it('gives a new bus its Operator\'s company and company name', function () {
    $metro = Company::create(['company_name' => 'Davao Metro Shuttle']);
    $op = cfsOperator($metro, 'First');

    $this->actingAs($op, 'sanctum');
    $this->postJson('/api/operator/fleets', ['plate_number' => 'NEW-123', 'bus_company_name' => 'Typed Name', 'body_no' => '12', 'seated_capacity' => 40, 'standing_capacity' => 10, 'fleet_type' => 'public'])
        ->assertSuccessful();

    $fleet = Fleet::where('plate_number', 'NEW-123')->first();
    expect((int) $fleet->company_id)->toBe((int) $metro->company_id)
        ->and($fleet->bus_company_name)->toBe('Davao Metro Shuttle');
});

it('treats an Operator without a company as a company of one', function () {
    $user = User::create(['username' => 'cfsloose', 'email' => 'cfs.loose@example.com', 'password' => 'Right-password-1', 'role' => 'operator']);
    $loose = StaffUser::create(['user_id' => $user->user_id, 'company_user_uuid' => (string) Str::uuid(), 'name' => 'Loose', 'phone_num' => '+639171234567', 'address' => 'Tagum']);
    $other = cfsOperator(Company::create(['company_name' => 'Some Co']), 'Other');

    expect(CompanyScope::operatorIdsFor((int) $loose->company_user_id))->toBe([(int) $loose->company_user_id])
        ->and(CompanyScope::sameCompany((int) $loose->company_user_id, cfsId($other)))->toBeFalse();
});
