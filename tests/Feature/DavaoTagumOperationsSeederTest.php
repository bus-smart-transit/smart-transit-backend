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
use Database\Seeders\DavaoTagumOperationsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

function opsSeedStaff(string $role): StaffUser
{
    $user = User::create(['username' => $role.'_'.uniqid(), 'email' => $role.'+'.uniqid().'@t.test', 'password' => bcrypt('x'), 'role' => $role]);

    return StaffUser::create(['company_user_uuid' => (string) Str::uuid(), 'user_id' => $user->user_id, 'name' => Str::title($role), 'phone_num' => '0917', 'address' => 'x']);
}

function opsSeedFixture(bool $withRoute = true): array
{
    $operator = opsSeedStaff('operator');
    opsSeedStaff('driver');
    opsSeedStaff('conductor');
    $fleet = Fleet::create(['company_user_id' => $operator->company_user_id, 'plate_number' => 'OPS-001', 'capacity' => 30, 'seated_capacity' => 20, 'standing_capacity' => 10, 'status' => 'active']);

    if ($withRoute) {
        $route = Route::create(['route_name' => 'Davao - Tagum', 'origin' => 'Davao', 'destination' => 'Tagum']);
        foreach ([['First', 0], ['Middle', 40], ['Last', 98]] as $i => [$name, $offset]) {
            $stop = Stop::create(['stop_name' => $name, 'latitude' => 7 + $i / 10, 'longitude' => 125.6]);
            RouteStop::create(['route_id' => $route->route_id, 'stop_id' => $stop->stop_id, 'stop_order' => $i + 1, 'distance_from_origin_km' => $i * 10, 'stop_type' => 'stop', 'scheduled_offset_min' => $offset]);
        }
    }

    return [$fleet, $route ?? null];
}

it('puts a fleet on Davao - Tagum with fares and shift blocks whose legs alternate direction', function () {
    [$fleet, $route] = opsSeedFixture();

    $this->seed(DavaoTagumOperationsSeeder::class);

    $fleetRoute = FleetRoute::where('route_id', $route->route_id)->first();
    expect($fleetRoute->fleet_id)->toBe($fleet->fleet_id);
    expect($fleetRoute->status)->toBe('active');
    expect(FareRule::where('fleet_id', $fleet->fleet_id)->pluck('seat_type')->sort()->values()->all())->toBe(['seated', 'standing']);

    expect(ShiftBlock::count())->toBe(5);
    $firstDay = Trip::where('fleet_route_id', $fleetRoute->fleet_route_id)->orderBy('trip_date')->orderBy('departure_time')->get()->groupBy(fn ($t) => $t->trip_date->toDateString())->first();
    expect($firstDay)->toHaveCount(4);
    expect($firstDay->pluck('leg_direction')->all())->toBe(['outbound', 'reverse', 'outbound', 'reverse']);
    // 98 min end to end + 30 min turnaround, rounded up to 5 min = 130 min between legs.
    expect($firstDay->pluck('departure_time')->map(fn ($t) => substr((string) $t, 0, 5))->all())->toBe(['06:00', '08:10', '10:20', '12:30']);
});

it('is idempotent and never double-books a day', function () {
    opsSeedFixture();

    $this->seed(DavaoTagumOperationsSeeder::class);
    $blocks = ShiftBlock::count();
    $trips = Trip::count();
    $this->seed(DavaoTagumOperationsSeeder::class);

    expect(ShiftBlock::count())->toBe($blocks);
    expect(Trip::count())->toBe($trips);
    expect(FleetRoute::count())->toBe(1);
    expect(FareRule::count())->toBe(2);
});

it('does nothing, without failing, when the route has not been seeded', function () {
    opsSeedFixture(withRoute: false);

    $this->seed(DavaoTagumOperationsSeeder::class);

    expect(FleetRoute::count())->toBe(0);
    expect(ShiftBlock::count())->toBe(0);
});
