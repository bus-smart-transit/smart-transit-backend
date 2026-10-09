<?php

use App\Models\Fleet;
use App\Models\FleetRoute;
use App\Models\Route;
use App\Models\RouteStop;
use App\Models\ShiftBlock;
use App\Models\StaffUser;
use App\Models\Stop;
use App\Models\Trip;
use App\Models\User;
use App\Services\FleetService;
use App\Services\ShiftBlockService;
use App\Services\TripService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

uses(RefreshDatabase::class);

// L-30: a bus is run only inside the operating window its route was assigned with, and is never on two trips at once.
// A trip occupies the bus from its departure for the route's expected run time plus the turnaround buffer (30 minutes
// in the tests: config shift_blocks.terminal_turnaround_buffer_minutes). Staff double-booking was already refused.

function fgStaff(string $role): User
{
    $user = User::create(['username' => 'fg'.Str::random(8), 'email' => Str::random(8).'@example.com', 'password' => bcrypt('password123'), 'role' => $role]);
    StaffUser::create(['user_id' => $user->user_id, 'company_user_uuid' => (string) Str::uuid(), 'name' => 'Fg '.ucfirst($role), 'phone_num' => '09170000111', 'address' => 'x']);

    return $user->fresh();
}

/** A route (optionally with stop offsets, so its run time is known) and an operator. */
function fgWorld(?int $runMinutes = null, string $name = 'Fg Route'): array
{
    $operator = fgStaff('operator');
    $route = Route::create(['route_name' => $name, 'origin' => 'A', 'destination' => 'B']);
    if ($runMinutes !== null) {
        foreach ([[0, 'terminal'], [$runMinutes, 'terminal']] as $i => [$offset, $type]) {
            $stop = Stop::create(['stop_name' => "Fg stop $i", 'latitude' => 7.0 + $i / 10, 'longitude' => 125.0, 'municipality' => 'Mati City']);
            RouteStop::create(['route_id' => $route->route_id, 'stop_id' => $stop->stop_id, 'stop_order' => $i + 1, 'distance_from_origin_km' => $i * 20, 'stop_type' => $type, 'scheduled_offset_min' => $offset]);
        }
    }

    return ['operator' => $operator, 'company' => $operator->companyProfile->company_user_id, 'route' => $route];
}

/** A bus with this route assigned, running 06:00 to 22:00 unless told otherwise. */
function fgBus(array $w, string $start = '06:00', string $end = '22:00', ?Route $route = null): FleetRoute
{
    $fleet = Fleet::create(['company_user_id' => $w['company'], 'plate_number' => 'FG-'.fake()->unique()->numerify('####'), 'capacity' => 40, 'seated_capacity' => 30, 'standing_capacity' => 10, 'status' => 'active', 'vehicle_class' => 'aircon']);

    return FleetRoute::create(['fleet_id' => $fleet->fleet_id, 'route_id' => ($route ?? $w['route'])->route_id, 'start_time' => $start, 'end_time' => $end, 'status' => 'active']);
}

function fgSchedule(array $w, FleetRoute $bus, string $time, string $date = '2026-10-05'): object
{
    return app(TripService::class)->scheduleTrip([
        'fleet_route_id' => $bus->fleet_route_id, 'company_user_id' => $w['company'], 'trip_date' => $date, 'departure_time' => $time, 'trip_type' => 'one_way',
    ]);
}

function fgTrip(array $w, FleetRoute $bus, string $time, string $status = 'scheduled', string $date = '2026-10-05'): Trip
{
    return Trip::create([
        'fleet_route_id' => $bus->fleet_route_id, 'company_user_id' => $w['company'], 'trip_date' => $date, 'departure_time' => $time, 'trip_type' => 'one_way',
        'leg_direction' => 'outbound', 'status' => $status, 'current_seated_capacity' => 0, 'current_standing_capacity' => 0, 'total_occupancy' => 0,
    ]);
}

beforeEach(function () {
    Carbon::setTestNow(Carbon::parse('2026-10-04 07:00:00', 'Asia/Manila'));
    config(['shift_blocks.terminal_turnaround_buffer_minutes' => 30]);
});
afterEach(fn () => Carbon::setTestNow());

// ------------------------------------------------------------ operating window

it('schedules a trip inside the bus\'s operating window, edges included', function () {
    $w = fgWorld();
    $bus = fgBus($w);

    expect(fgSchedule($w, $bus, '06:00')->trip_id)->toBeInt();
    expect(fgSchedule($w, $bus, '22:00')->trip_id)->toBeInt();
});

it('refuses a departure before the window opens or after it closes, and says what the window is', function (string $time) {
    $w = fgWorld();
    $bus = fgBus($w);

    expect(fn () => fgSchedule($w, $bus, $time))
        ->toThrow(ValidationException::class, 'between 06:00 and 22:00');
    expect(Trip::count())->toBe(0);
})->with(['05:59', '22:01', '23:30', '00:00']);

it('reads a window that runs past midnight as running past midnight', function () {
    $w = fgWorld();
    $night = fgBus($w, '22:00', '04:00');

    expect(fgSchedule($w, $night, '23:30')->trip_id)->toBeInt();
    expect(fgSchedule($w, $night, '02:00', '2026-10-06')->trip_id)->toBeInt();
    expect(fn () => fgSchedule($w, $night, '12:00', '2026-10-07'))->toThrow(ValidationException::class, 'between 22:00 and 04:00');
});

// ------------------------------------------------------------ one bus, one trip at a time

it('refuses a second trip while the bus is still on the first (no stop offsets: the turnaround buffer only)', function () {
    $w = fgWorld();
    $bus = fgBus($w);
    fgSchedule($w, $bus, '08:00');

    expect(fn () => fgSchedule($w, $bus, '08:00'))->toThrow(ValidationException::class, 'This bus is already on Fg Route from 08:00 to about 08:30');
    expect(fn () => fgSchedule($w, $bus, '08:20'))->toThrow(ValidationException::class, 'already on');
    expect(fgSchedule($w, $bus, '08:30')->trip_id)->toBeInt();   // the buffer has passed
});

it('uses the route\'s expected run time when it is known, so a trip cannot start while the bus is still out', function () {
    $w = fgWorld(120);                                      // a 2 hour run
    $bus = fgBus($w);
    fgSchedule($w, $bus, '08:00');                          // occupies 08:00 to 10:30 (120 + 30)

    expect(fn () => fgSchedule($w, $bus, '10:00'))->toThrow(ValidationException::class, 'from 08:00 to about 10:30');
    expect(fn () => fgSchedule($w, $bus, '06:30'))->toThrow(ValidationException::class, 'already on');   // would still be out at 08:00
    expect(fgSchedule($w, $bus, '10:30')->trip_id)->toBeInt();
});

it('lets a different bus, a different day, and a cancelled or completed trip share the time', function () {
    $w = fgWorld();
    $bus = fgBus($w);
    $other = fgBus($w);
    fgTrip($w, $bus, '09:00', 'cancelled');
    fgTrip($w, $bus, '09:00', 'completed');

    expect(fgSchedule($w, $bus, '09:00')->trip_id)->toBeInt();                 // the cancelled and completed ones do not hold the bus
    expect(fgSchedule($w, $other, '09:00')->trip_id)->toBeInt();               // another bus
    expect(fgSchedule($w, $bus, '09:00', '2026-10-06')->trip_id)->toBeInt();   // another day
});

// ------------------------------------------------------------ moving a trip to another bus

it('moves a trip to another bus only when that bus is free then', function () {
    $w = fgWorld();
    $a = fgBus($w);
    $b = fgBus($w);
    $trip = fgTrip($w, $a, '08:00');
    fgTrip($w, $b, '08:10');                                // the other bus is busy at 08:10

    expect(fn () => app(TripService::class)->changeFleet($trip->trip_id, $b->fleet_route_id))
        ->toThrow(ValidationException::class, 'already on');
    expect((int) $trip->fresh()->fleet_route_id)->toBe((int) $a->fleet_route_id);

    $free = fgBus($w);
    app(TripService::class)->changeFleet($trip->trip_id, $free->fleet_route_id);
    expect((int) $trip->fresh()->fleet_route_id)->toBe((int) $free->fleet_route_id);
});

it('does not count a trip against the bus it is being moved to when it is already there', function () {
    $w = fgWorld();
    $a = fgBus($w);
    $trip = fgTrip($w, $a, '08:00');

    // Asking for the bus it is already on changes nothing and is not a conflict with itself.
    expect(app(TripService::class)->changeFleet($trip->trip_id, $a->fleet_route_id)->trip_id)->toBe($trip->trip_id);
});

// ------------------------------------------------------------ shift blocks

function fgBlock(array $w, FleetRoute $bus, array $times): ShiftBlock
{
    config(['shift_blocks.legs_per_block' => count($times)]);

    return app(ShiftBlockService::class)->createBlock([
        'fleet_route_id' => $bus->fleet_route_id, 'driver_id' => fgStaff('driver')->companyProfile->company_user_id, 'conductor_id' => fgStaff('conductor')->companyProfile->company_user_id,
        'company_user_id' => $w['company'], 'block_type' => 'morning', 'scheduled_date' => '2026-10-05', 'leg_departure_times' => $times,
    ]);
}

it('creates a shift block when its legs fit the window and the bus is free', function () {
    $w = fgWorld();
    $bus = fgBus($w);

    $block = fgBlock($w, $bus, ['07:00', '10:00', '13:00', '16:00']);

    expect($block->legs)->toHaveCount(4);
});

it('refuses a shift block with a leg outside the window or on a busy bus, and creates nothing', function () {
    $w = fgWorld();
    $bus = fgBus($w);

    expect(fn () => fgBlock($w, $bus, ['07:00', '10:00', '13:00', '23:00']))->toThrow(ValidationException::class, 'between 06:00 and 22:00');
    expect(ShiftBlock::count())->toBe(0)->and(Trip::count())->toBe(0);

    fgTrip($w, $bus, '10:10');
    expect(fn () => fgBlock($w, $bus, ['07:00', '10:00', '13:00', '16:00']))->toThrow(ValidationException::class, 'already on');
    expect(ShiftBlock::count())->toBe(0)->and(Trip::count())->toBe(1);        // only the trip that was already there
});

// ------------------------------------------------------------ assigning a route to a bus

it('assigns a route to a bus once: the same route twice is refused, another route is fine', function () {
    $w = fgWorld();
    $fleet = Fleet::create(['company_user_id' => $w['company'], 'plate_number' => 'FG-ROUTE', 'capacity' => 40, 'seated_capacity' => 30, 'standing_capacity' => 10, 'status' => 'active']);
    $other = Route::create(['route_name' => 'Fg Other', 'origin' => 'C', 'destination' => 'D']);
    $service = app(FleetService::class);

    $service->assignRouteToFleet($fleet->fleet_id, ['route_id' => $w['route']->route_id, 'start_time' => '06:00', 'end_time' => '22:00']);

    expect(fn () => $service->assignRouteToFleet($fleet->fleet_id, ['route_id' => $w['route']->route_id, 'start_time' => '07:00', 'end_time' => '21:00']))
        ->toThrow(ValidationException::class, 'already has this route');
    expect(FleetRoute::where('fleet_id', $fleet->fleet_id)->count())->toBe(1);

    $service->assignRouteToFleet($fleet->fleet_id, ['route_id' => $other->route_id, 'start_time' => '06:00', 'end_time' => '22:00']);
    expect(FleetRoute::where('fleet_id', $fleet->fleet_id)->count())->toBe(2);
});
