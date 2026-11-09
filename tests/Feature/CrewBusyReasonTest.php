<?php

use App\Models\Company;
use App\Models\Fleet;
use App\Models\FleetRoute;
use App\Models\Route;
use App\Models\StaffUser;
use App\Models\Trip;
use App\Models\User;
use App\Services\FcmService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

// Batch 51 item 6 (checked 2026-11-09): one trip per person per day. Scheduling a driver a week ahead and then today
// both work; once the driver has any trip that day that is not cancelled (a completed one too), they cannot get a
// second standalone trip that day, and the picker says why instead of hiding them.

beforeEach(function () {
    $this->mock(FcmService::class, fn ($mock) => $mock->shouldReceive('sendToUser')->andReturn(true));
});

function cbrFixture(): array
{
    $company = Company::create(['company_name' => 'Cbr Co '.Str::random(4)]);
    $make = function (string $role, string $name) use ($company): User {
        $user = User::create(['username' => 'cbr'.$role.Str::random(5), 'email' => Str::random(8).'@example.com', 'password' => 'right-password-1', 'role' => $role]);
        StaffUser::create(['user_id' => $user->user_id, 'company_user_uuid' => (string) Str::uuid(), 'name' => $name, 'phone_num' => '09170000000', 'address' => 'x', 'company_id' => $company->company_id, 'is_company_admin' => $role === 'operator', 'is_available' => $role !== 'operator']);

        return $user->fresh();
    };
    $operator = $make('operator', 'Cbr Operator');
    $driver = $make('driver', 'Cbr Driver');
    $route = Route::create(['route_name' => 'Cbr', 'origin' => 'A', 'destination' => 'B']);
    $fleet = Fleet::create(['company_user_id' => $operator->companyProfile->company_user_id, 'company_id' => $company->company_id, 'plate_number' => 'CBR-'.random_int(100, 999), 'capacity' => 50, 'seated_capacity' => 40, 'standing_capacity' => 10, 'status' => 'active']);
    $fleetRoute = FleetRoute::create(['fleet_id' => $fleet->fleet_id, 'route_id' => $route->route_id, 'start_time' => '00:00', 'end_time' => '23:59', 'status' => 'active']);
    $trip = fn (string $date, string $time = '23:00') => Trip::create([
        'fleet_route_id' => $fleetRoute->fleet_route_id, 'company_user_id' => $operator->companyProfile->company_user_id, 'trip_date' => $date, 'departure_time' => $time,
        'status' => 'scheduled', 'current_seated_capacity' => 0, 'current_standing_capacity' => 0, 'total_occupancy' => 0,
    ]);

    return [$operator, $driver, $trip];
}

it('books a driver a week ahead and then today', function () {
    [$operator, $driver, $trip] = cbrFixture();
    $driverId = $driver->companyProfile->company_user_id;
    $this->actingAs($operator, 'sanctum');

    $week = $trip(now('Asia/Manila')->addDays(7)->toDateString());
    $this->patchJson("/api/operator/trips/{$week->trip_id}/driver", ['driver_id' => $driverId])->assertSuccessful();
    $today = $trip(now('Asia/Manila')->toDateString());
    $this->patchJson("/api/operator/trips/{$today->trip_id}/driver", ['driver_id' => $driverId])->assertSuccessful();
});

it('keeps one trip a day, a completed one included, and says why in the picker', function () {
    [$operator, $driver, $trip] = cbrFixture();
    $driverId = $driver->companyProfile->company_user_id;
    $date = now('Asia/Manila')->toDateString();
    $this->actingAs($operator, 'sanctum');

    $first = $trip($date, '14:00');
    $this->patchJson("/api/operator/trips/{$first->trip_id}/driver", ['driver_id' => $driverId])->assertSuccessful();
    $first->forceFill(['status' => 'completed'])->save();

    // Without include_busy the busy driver is left out (unchanged); with it, the reason is given.
    expect($this->getJson("/api/operator/drivers?trip_date={$date}")->assertOk()->json('data'))->toBe([]);
    $listed = $this->getJson("/api/operator/drivers?trip_date={$date}&include_busy=1")->assertOk()->json('data');
    expect($listed)->toHaveCount(1)
        ->and($listed[0]['name'])->toBe('Cbr Driver')
        ->and($listed[0]['busy_reason'])->toBe('Already on a trip that day (14:00, completed)');

    $second = $trip($date, '18:00');
    $this->patchJson("/api/operator/trips/{$second->trip_id}/driver", ['driver_id' => $driverId])
        ->assertStatus(422)->assertJsonPath('errors.driver_id.0', 'Driver is already assigned to another trip on this date.');

    // A free day: no reason.
    expect($this->getJson('/api/operator/drivers?trip_date='.now('Asia/Manila')->addDays(2)->toDateString().'&include_busy=1')->json('data.0.busy_reason'))->toBeNull();
});
