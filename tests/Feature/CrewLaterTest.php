<?php

use App\Models\FareRule;
use App\Models\Fleet;
use App\Models\FleetRoute;
use App\Models\Route;
use App\Models\RouteStop;
use App\Models\ShiftBlock;
use App\Models\StaffUser;
use App\Models\Stop;
use App\Models\Trip;
use App\Models\User;
use App\Services\FcmService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

// 2026-11-23 (advisor): the Operator schedules trips a month ahead and assigns the crew by the day, so a shift block
// may be saved without its Driver and Chauffeur; the crew is added later with the same checks, and is notified then.
// A trip still cannot start boarding without its full crew.

beforeEach(function () {
    Carbon::setTestNow(Carbon::parse('2026-10-05 07:00:00', 'Asia/Manila'));
});
afterEach(fn () => Carbon::setTestNow());

function clStaff(string $role): User
{
    $user = User::create(['username' => 'cl'.$role.Str::random(5), 'email' => 'cl.'.Str::lower(Str::random(8)).'@example.com', 'password' => bcrypt('password123'), 'role' => $role]);
    joinTestCompany(StaffUser::create(['user_id' => $user->user_id, 'company_user_uuid' => (string) Str::uuid(), 'name' => ucfirst($role), 'phone_num' => '0917', 'address' => 'x']));

    return $user->fresh();
}

/** @return array{0: int, 1: User} a block on 2026-10-09 with no crew, and its Operator */
function clBlockWithoutCrew($test, ?User $operator = null): array
{
    $operator ??= clStaff('operator');
    $route = Route::create(['route_name' => 'Cl Route '.Str::random(4), 'origin' => 'A', 'destination' => 'B']);
    foreach ([['A', 7.00, 0, 0], ['B', 7.10, 12, 30]] as $i => [$name, $lat, $km, $offset]) {
        $stop = Stop::create(['stop_name' => 'Cl '.$name, 'latitude' => $lat, 'longitude' => 125.0, 'municipality' => 'Mati City']);
        RouteStop::create(['route_id' => $route->route_id, 'stop_id' => $stop->stop_id, 'stop_order' => $i + 1, 'distance_from_origin_km' => $km, 'stop_type' => 'terminal', 'scheduled_offset_min' => $offset]);
    }
    $fleet = Fleet::create(['company_user_id' => $operator->companyProfile->company_user_id, 'plate_number' => 'CL-'.Str::upper(Str::random(5)), 'capacity' => 30, 'seated_capacity' => 30, 'standing_capacity' => 0, 'status' => 'active']);
    FareRule::create(['fleet_id' => $fleet->fleet_id, 'service_type' => 'regular', 'seat_type' => 'seated', 'status' => 'active', 'base_fare' => 15, 'fare_per_km' => 2, 'note' => 'SAMPLE']);
    $fleetRoute = FleetRoute::create(['fleet_id' => $fleet->fleet_id, 'route_id' => $route->route_id, 'start_time' => '05:00', 'end_time' => '22:00', 'status' => 'active']);
    $id = $test->actingAs($operator)->postJson('/api/operator/shift-blocks', [
        'fleet_route_id' => $fleetRoute->fleet_route_id,
        'block_type' => config('shift_blocks.block_types')[0], 'scheduled_date' => '2026-10-09', 'leg_departure_times' => ['06:00', '09:00'],
    ])->assertSuccessful()->json('data.shift_block_id');

    return [(int) $id, $operator];
}

it('schedules a block without a crew and notifies nobody', function () {
    $this->mock(FcmService::class, fn ($mock) => $mock->shouldNotReceive('sendToUser'));
    [$id] = clBlockWithoutCrew($this);

    $block = ShiftBlock::find($id);
    expect($block->driver_id)->toBeNull()->and($block->conductor_id)->toBeNull()
        ->and(Trip::where('shift_block_id', $id)->count())->toBe(2)
        ->and(Trip::where('shift_block_id', $id)->whereNotNull('driver_id')->count())->toBe(0);
});

it('assigns the crew later, puts them on every leg and notifies them', function () {
    $sent = [];
    $this->mock(FcmService::class, function ($mock) use (&$sent) {
        $mock->shouldReceive('sendToUser')->andReturnUsing(function ($user) use (&$sent) {
            $sent[] = $user->user_id;

            return true;
        });
    });
    [$id, $operator] = clBlockWithoutCrew($this);
    $driver = clStaff('driver');
    $conductor = clStaff('conductor');

    $this->actingAs($operator)->patchJson("/api/operator/shift-blocks/{$id}", [
        'driver_id' => $driver->companyProfile->company_user_id, 'conductor_id' => $conductor->companyProfile->company_user_id,
    ])->assertSuccessful();

    expect(Trip::where('shift_block_id', $id)->pluck('driver_id')->unique()->all())->toBe([$driver->companyProfile->company_user_id])
        ->and(Trip::where('shift_block_id', $id)->pluck('conductor_id')->unique()->all())->toBe([$conductor->companyProfile->company_user_id])
        ->and($sent)->toContain($driver->user_id, $conductor->user_id);
});

it('refuses a crew member who already works another block that day', function () {
    $this->mock(FcmService::class, fn ($mock) => $mock->shouldReceive('sendToUser')->andReturn(true));
    [$first, $operator] = clBlockWithoutCrew($this);
    $driver = clStaff('driver');
    $this->actingAs($operator)->patchJson("/api/operator/shift-blocks/{$first}", ['driver_id' => $driver->companyProfile->company_user_id])->assertSuccessful();

    [$second] = clBlockWithoutCrew($this, $operator);
    $this->actingAs($operator)->patchJson("/api/operator/shift-blocks/{$second}", ['driver_id' => $driver->companyProfile->company_user_id])
        ->assertUnprocessable()->assertJsonPath('errors.driver_id.0', 'This driver already has a shift block scheduled for this date.');
});

it('still refuses to start boarding a leg that has no crew', function () {
    $this->mock(FcmService::class, fn ($mock) => $mock->shouldReceive('sendToUser')->andReturn(true));
    [$id, $operator] = clBlockWithoutCrew($this);
    $leg = Trip::where('shift_block_id', $id)->orderBy('departure_time')->first();
    Carbon::setTestNow(Carbon::parse('2026-10-09 05:45:00', 'Asia/Manila'));

    $this->actingAs($operator)->patchJson("/api/operator/trips/{$leg->trip_id}/boarding")->assertStatus(422);
    expect($leg->fresh()->status)->toBe('scheduled');
});
