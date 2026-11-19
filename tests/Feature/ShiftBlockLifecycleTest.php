<?php

use App\Models\Fleet;
use App\Models\FleetRoute;
use App\Models\Route;
use App\Models\ShiftBlock;
use App\Models\StaffUser;
use App\Models\Trip;
use App\Models\User;
use App\Services\LegProgressionService;
use App\Services\TripService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

// 2026-10-27: "Confirm Takeover" and the Chauffeur's hand-off are replaced. A shift block (the morning or afternoon
// shift of one bus) starts with its first leg and finishes with its last; two crews never run one bus at once.

function slcStaff(string $role): StaffUser
{
    $user = User::create(['username' => 'slc_'.Str::random(8), 'email' => Str::random(8).'@example.com', 'password' => bcrypt('password123'), 'role' => $role]);

    return StaffUser::create(['user_id' => $user->user_id, 'company_user_uuid' => (string) Str::uuid(), 'name' => 'Slc '.ucfirst($role), 'phone_num' => '09170000065', 'address' => 'x']);
}

/** One bus; returns a maker for a block of that type with legs at the given times on 2026-10-30. */
function slcBus(): array
{
    $operator = slcStaff('operator');
    $route = Route::create(['route_name' => 'Slc Route', 'origin' => 'A', 'destination' => 'B']);
    $fleet = Fleet::create(['company_user_id' => $operator->company_user_id, 'plate_number' => 'SLC-'.fake()->unique()->numerify('###'), 'capacity' => 40, 'seated_capacity' => 30, 'standing_capacity' => 10, 'status' => 'active']);
    $fleetRoute = FleetRoute::create(['fleet_id' => $fleet->fleet_id, 'route_id' => $route->route_id, 'start_time' => '05:00', 'end_time' => '22:00', 'status' => 'active']);

    $make = function (string $type, array $times) use ($operator, $fleet, $fleetRoute) {
        $driver = slcStaff('driver');
        $conductor = slcStaff('conductor');
        $block = ShiftBlock::create([
            'fleet_id' => $fleet->fleet_id, 'driver_id' => $driver->company_user_id, 'conductor_id' => $conductor->company_user_id,
            'company_user_id' => $operator->company_user_id, 'block_type' => $type, 'status' => 'scheduled', 'audit_status' => 'not_applicable', 'scheduled_date' => '2026-10-30',
        ]);
        $legs = [];
        foreach ($times as $i => $time) {
            $legs[] = Trip::create([
                'fleet_route_id' => $fleetRoute->fleet_route_id, 'company_user_id' => $operator->company_user_id, 'trip_date' => '2026-10-30', 'departure_time' => $time,
                'trip_type' => 'one_way', 'leg_direction' => $i % 2 === 0 ? 'outbound' : 'reverse', 'status' => 'scheduled', 'shift_block_id' => $block->shift_block_id,
                'driver_id' => $driver->company_user_id, 'conductor_id' => $conductor->company_user_id,
                'current_seated_capacity' => 0, 'current_standing_capacity' => 0, 'total_occupancy' => 0,
            ]);
        }

        return [$block, $legs];
    };

    return [$make];
}

function slcRun(Trip $leg): void
{
    $trips = app(TripService::class);
    $trips->startBoarding($leg->trip_id);
    Trip::whereKey($leg->trip_id)->update(['status' => 'departed']);
    $trips->completeTrip($leg->trip_id);
}

it('starts the block with its first leg and finishes it, for review, with its last', function () {
    [$make] = slcBus();
    [$block, $legs] = $make('morning', ['06:00', '08:00']);

    app(TripService::class)->startBoarding($legs[0]->trip_id);
    expect($block->fresh()->status)->toBe('in_progress');

    Trip::whereKey($legs[0]->trip_id)->update(['status' => 'departed']);
    app(TripService::class)->completeTrip($legs[0]->trip_id);
    expect($block->fresh()->status)->toBe('in_progress');

    slcRun($legs[1]);
    expect($block->fresh()->only(['status', 'audit_status']))->toBe(['status' => 'completed', 'audit_status' => 'pending_review']);
});

it('finishes the block as completed when the last leg is cancelled after others ran', function () {
    [$make] = slcBus();
    [$block, $legs] = $make('morning', ['06:00', '08:00']);

    slcRun($legs[0]);
    app(TripService::class)->cancelTrip($legs[1]->trip_id, null, 'Bus broke down');

    expect($block->fresh()->status)->toBe('completed');
});

it('cancels the block when every leg is cancelled', function () {
    [$make] = slcBus();
    [$block, $legs] = $make('morning', ['06:00', '08:00']);

    foreach ($legs as $leg) {
        app(TripService::class)->cancelTrip($leg->trip_id, null, 'No crew');
    }

    expect($block->fresh()->status)->toBe('cancelled');
});

it('does not let the afternoon shift start while the morning shift on the same bus is still running', function () {
    [$make] = slcBus();
    [, $morning] = $make('morning', ['06:00', '08:00']);
    [, $afternoon] = $make('afternoon', ['12:00', '14:00']);
    $guard = app(LegProgressionService::class);

    slcRun($morning[0]);
    expect($guard->startBlockedReason($afternoon[0]->fresh(), true)[0] ?? null)
        ->toBe('The morning shift on this bus has not finished its last trip yet. This shift starts after it ends.');

    // The Operator (no location or bus check) can still start it as the dispatcher override.
    expect($guard->startBlockedReason($afternoon[0]->fresh(), false))->toBeNull();

    slcRun($morning[1]);
    expect($guard->startBlockedReason($afternoon[0]->fresh(), true))->toBeNull();
});

it('lets the afternoon shift start when the morning shift\'s remaining legs were cancelled', function () {
    [$make] = slcBus();
    [, $morning] = $make('morning', ['06:00', '08:00']);
    [, $afternoon] = $make('afternoon', ['12:00', '14:00']);

    slcRun($morning[0]);
    app(TripService::class)->cancelTrip($morning[1]->trip_id, null, 'Short day');

    expect(app(LegProgressionService::class)->startBlockedReason($afternoon[0]->fresh(), true))->toBeNull();
});
