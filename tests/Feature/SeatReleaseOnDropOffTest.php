<?php

use App\Models\FareRule;
use App\Models\Fleet;
use App\Models\FleetDailyPin;
use App\Models\FleetRoute;
use App\Models\Route;
use App\Models\RouteStop;
use App\Models\StaffUser;
use App\Models\Stop;
use App\Models\Ticket;
use App\Models\Trip;
use App\Models\User;
use App\Repositories\TicketRepository;
use App\Support\TripCapacity;
use App\Support\TripPassengerCount;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

// 2026-11-26 (user): a passenger who gets off gives the seat back, so it can be sold again (online from a stop the bus
// has not passed, or on the bus). The trip's seat counter is the seats taken now; tickets sold are counted from tickets.
// A cash sale takes seats from the same counter the online booking reads. SAMPLE values: a bus with 2 seats.

function srdStaff(string $role): User
{
    $user = User::create(['username' => 'srd'.Str::random(8), 'email' => Str::random(8).'@example.com', 'password' => bcrypt('password123'), 'role' => $role]);
    StaffUser::create(['user_id' => $user->user_id, 'company_user_uuid' => (string) Str::uuid(), 'name' => 'Srd '.ucfirst($role), 'phone_num' => '09170000026', 'address' => 'x']);

    return $user->fresh();
}

/** A boarding trip on a 2-seat bus, crew paired, Chauffeur shift open. */
function srdFixture($test): array
{
    $operator = srdStaff('operator');
    $driver = srdStaff('driver');
    $conductor = srdStaff('conductor');
    $route = Route::create(['route_name' => 'Srd Route', 'origin' => 'A', 'destination' => 'C']);
    $stops = [];
    foreach ([['Stop A', 0, 'terminal'], ['Stop B', 5, 'stop'], ['Stop C', 10, 'terminal']] as $i => [$name, $km, $type]) {
        $stop = Stop::create(['stop_name' => $name, 'latitude' => 7.0 + $km / 100, 'longitude' => 125.0]);
        RouteStop::create(['route_id' => $route->route_id, 'stop_id' => $stop->stop_id, 'stop_order' => $i + 1, 'distance_from_origin_km' => $km, 'stop_type' => $type]);
        $stops[$name] = $stop;
    }
    $fleet = Fleet::create([
        'company_user_id' => $operator->companyProfile->company_user_id, 'plate_number' => 'SRD-'.fake()->unique()->numerify('###'),
        'capacity' => 2, 'seated_capacity' => 2, 'standing_capacity' => 0, 'status' => 'active',
    ]);
    FareRule::create(['fleet_id' => $fleet->fleet_id, 'base_fare' => 15, 'fare_per_km' => 2, 'status' => 'active', 'seat_type' => 'seated']);
    $fleetRoute = FleetRoute::create(['fleet_id' => $fleet->fleet_id, 'route_id' => $route->route_id, 'start_time' => '06:00', 'end_time' => '22:00', 'status' => 'active']);
    $trip = Trip::create([
        'fleet_route_id' => $fleetRoute->fleet_route_id, 'company_user_id' => $operator->companyProfile->company_user_id,
        'driver_id' => $driver->companyProfile->company_user_id, 'conductor_id' => $conductor->companyProfile->company_user_id,
        'driver_accepted_at' => now(), 'conductor_accepted_at' => now(),
        'trip_date' => now('Asia/Manila')->toDateString(), 'departure_time' => '08:00', 'trip_type' => 'one_way', 'leg_direction' => 'outbound',
        'status' => 'boarding', 'current_seated_capacity' => 0, 'current_standing_capacity' => 0, 'total_occupancy' => 0,
    ]);
    FleetDailyPin::create([
        'trip_id' => $trip->trip_id, 'driver_id' => $driver->companyProfile->company_user_id, 'conductor_id' => $conductor->companyProfile->company_user_id,
        'pin_code' => '123456', 'pin_date' => now('Asia/Manila')->toDateString(), 'driver_verified_at' => now(), 'conductor_verified_at' => now(), 'paired_at' => now(),
    ]);
    $test->actingAs($conductor)->postJson('/api/conductor/shift/start')->assertSuccessful();

    return compact('conductor', 'trip', 'stops');
}

function srdSold(Trip $trip): int
{
    return TripPassengerCount::from(app(TicketRepository::class)->countPaidByStatusForTrips([(int) $trip->trip_id])[(int) $trip->trip_id] ?? [])['tickets_sold'];
}

it('takes seats with a cash sale, and gives a seat back when a passenger gets off, one at a time or as a group', function () {
    $f = srdFixture($this);
    $item = ['trip_id' => $f['trip']->trip_id, 'seat_type' => 'seated', 'origin_stop_id' => $f['stops']['Stop A']->stop_id, 'destination_stop_id' => $f['stops']['Stop C']->stop_id];

    $tickets = $this->actingAs($f['conductor'])->postJson('/api/conductor/checkout', ['items' => [$item, $item]])->assertSuccessful()->json('data.tickets');
    $trip = $f['trip']->fresh();
    // The cash sale fills the bus: the online booking reads this same counter, so it has no seat to sell.
    expect((int) $trip->current_seated_capacity)->toBe(2)->and(TripCapacity::wouldExceed($trip, 'seated', 1))->toBeTrue();
    $this->actingAs($f['conductor'])->postJson('/api/conductor/checkout', ['items' => [$item]])->assertStatus(422);

    // One passenger gets off: the seat is back, the ticket still counts as sold.
    $this->actingAs($f['conductor'])->postJson("/api/conductor/tickets/{$tickets[0]['ticket_id']}/alight")->assertSuccessful();
    $trip = $f['trip']->fresh();
    expect((int) $trip->current_seated_capacity)->toBe(1)->and(TripCapacity::wouldExceed($trip, 'seated', 1))->toBeFalse()->and(srdSold($trip))->toBe(2);

    // A second tap on the same passenger gives nothing back twice.
    $this->actingAs($f['conductor'])->postJson("/api/conductor/tickets/{$tickets[0]['ticket_id']}/alight")->assertStatus(422);
    expect((int) $f['trip']->fresh()->current_seated_capacity)->toBe(1);

    // The other gets off with their drop-off group.
    $this->actingAs($f['conductor'])->postJson('/api/conductor/trips/current/drop-off-groups/confirm', [
        'destination_key' => 'stop:'.$f['stops']['Stop C']->stop_id, 'ticket_ids' => [$tickets[1]['ticket_id']],
    ])->assertSuccessful();
    $trip = $f['trip']->fresh();
    expect((int) $trip->current_seated_capacity)->toBe(0)->and(srdSold($trip))->toBe(2)
        ->and(Ticket::where('trip_id', $trip->trip_id)->where('status', 'alighted')->count())->toBe(2);

    // The freed seats can be sold again.
    $this->actingAs($f['conductor'])->postJson('/api/conductor/checkout', ['items' => [$item]])->assertSuccessful();
    expect((int) $f['trip']->fresh()->current_seated_capacity)->toBe(1);
});
