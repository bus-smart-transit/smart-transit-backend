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
use App\Support\TripTiming;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

// Phase A (smarttransit_prompt.md §4.11): pairing opens 60 minutes before the departure, Start Boarding 30 minutes
// before it, Depart at or after it (also when late), and the Driver ends a trip only after the final stop.

function ttStaff(string $role): User
{
    $user = User::create(['username' => 'tt'.Str::random(8), 'email' => Str::random(8).'@example.com', 'password' => bcrypt('password123'), 'role' => $role]);
    StaffUser::create(['user_id' => $user->user_id, 'company_user_uuid' => (string) Str::uuid(), 'name' => 'Tt '.ucfirst($role), 'phone_num' => '09170000040', 'address' => 'x']);

    return $user->fresh();
}

function ttAt(string $time): void
{
    Carbon::setTestNow(Carbon::parse("2026-10-06 {$time}:00", 'Asia/Manila'));
}

/** A standalone 08:00 trip on 2026-10-06 over stops A, B, C, accepted by its crew. */
function ttFixture(): array
{
    $operator = ttStaff('operator');
    $driver = ttStaff('driver');
    $conductor = ttStaff('conductor');

    $route = Route::create(['route_name' => 'Timing Route', 'origin' => 'A', 'destination' => 'C']);
    $stops = [];
    foreach ([['A', 0, 'terminal'], ['B', 10, 'stop'], ['C', 20, 'terminal']] as $i => [$name, $km, $type]) {
        $stop = Stop::create(['stop_name' => "Stop {$name}", 'latitude' => 7.0 + $km / 100, 'longitude' => 125.0, 'municipality' => 'Mati City']);
        RouteStop::create(['route_id' => $route->route_id, 'stop_id' => $stop->stop_id, 'stop_order' => $i + 1, 'distance_from_origin_km' => $km, 'stop_type' => $type]);
        $stops[$name] = $stop;
    }

    $fleet = Fleet::create(['company_user_id' => $operator->companyProfile->company_user_id, 'plate_number' => 'TT-'.fake()->unique()->numerify('####'), 'capacity' => 40, 'seated_capacity' => 30, 'standing_capacity' => 10, 'status' => 'active']);
    $fleetRoute = FleetRoute::create(['fleet_id' => $fleet->fleet_id, 'route_id' => $route->route_id, 'start_time' => '05:00', 'end_time' => '22:00', 'status' => 'active']);
    $trip = Trip::create([
        'fleet_route_id' => $fleetRoute->fleet_route_id, 'company_user_id' => $operator->companyProfile->company_user_id,
        'trip_date' => '2026-10-06', 'departure_time' => '08:00', 'trip_type' => 'one_way', 'leg_direction' => 'outbound', 'status' => 'scheduled',
        'driver_id' => $driver->companyProfile->company_user_id, 'conductor_id' => $conductor->companyProfile->company_user_id,
        'driver_accepted_at' => now(), 'conductor_accepted_at' => now(),
        'current_seated_capacity' => 0, 'current_standing_capacity' => 0, 'total_occupancy' => 0,
    ]);

    return compact('operator', 'driver', 'conductor', 'trip', 'stops');
}

/** Both shifts open and the crew paired, at 07:00 (pairing is open from 07:00 for an 08:00 trip). */
function ttReady($test, array $f): void
{
    ttAt('07:00');
    $test->actingAs($f['driver'])->postJson('/api/driver/shift/start')->assertSuccessful();
    $test->actingAs($f['conductor'])->postJson('/api/conductor/shift/start')->assertSuccessful();
    $token = $test->actingAs($f['driver'])->getJson('/api/driver/pairing-token')->assertSuccessful()->json('data.token');
    $test->actingAs($f['conductor'])->postJson('/api/conductor/pair', ['token' => $token])->assertSuccessful();
}

afterEach(fn () => Carbon::setTestNow());

it('opens pairing 60 minutes before the departure, for the code and for pairing', function () {
    $f = ttFixture();
    ttAt('06:59');

    $this->actingAs($f['driver'])->getJson('/api/driver/pairing-token')
        ->assertStatus(422)->assertJsonPath('message', 'Pairing opens at 7:00 AM.');
    $this->actingAs($f['conductor'])->postJson('/api/conductor/pair', ['pin_code' => '123456'])
        ->assertStatus(422)->assertJsonPath('message', 'Pairing opens at 7:00 AM.');

    $status = $this->actingAs($f['driver'])->getJson('/api/driver/pairing-status')->assertSuccessful()->json('data');
    expect($status['pairing_open'])->toBeFalse()
        ->and(Carbon::parse($status['pairing_opens_at'])->format('H:i'))->toBe('07:00');

    ttAt('07:00');
    $this->actingAs($f['driver'])->getJson('/api/driver/pairing-token')->assertSuccessful();
    expect($this->actingAs($f['driver'])->getJson('/api/driver/pairing-status')->json('data.pairing_open'))->toBeTrue();
});

it('counts a shift block\'s pairing from its first leg', function () {
    $f = ttFixture();
    $first = $f['trip'];
    $blockId = ShiftBlock::create([
        'company_user_id' => $f['operator']->companyProfile->company_user_id, 'fleet_id' => $first->fleetRoute->fleet_id,
        'driver_id' => $first->driver_id, 'conductor_id' => $first->conductor_id, 'block_type' => 'morning', 'scheduled_date' => '2026-10-06', 'status' => 'scheduled',
    ])->shift_block_id;
    $first->update(['shift_block_id' => $blockId]);
    $second = $first->replicate()->fill(['departure_time' => '11:00', 'leg_direction' => 'reverse']);
    $second->save();

    ttAt('06:59');
    expect(TripTiming::pairingBlockedReason($first))->toBe('Pairing opens at 7:00 AM.');
    $this->actingAs($f['driver'])->getJson('/api/driver/pairing-token')->assertStatus(422);

    // Two hours before the second leg, but pairing is anchored to the first leg, which is already open.
    ttAt('09:00');
    $this->actingAs($f['driver'])->getJson('/api/driver/pairing-token')->assertSuccessful();
});

it('opens Start Boarding 30 minutes before the departure', function () {
    $f = ttFixture();
    ttReady($this, $f);
    $url = "/api/driver/trips/{$f['trip']->trip_id}/boarding";

    ttAt('07:29');
    $this->actingAs($f['driver'])->patchJson($url)->assertStatus(422)->assertJsonPath('message', 'Boarding opens at 7:30 AM.');
    expect($f['trip']->fresh()->status)->toBe('scheduled');

    ttAt('07:30');
    $this->actingAs($f['driver'])->patchJson($url)->assertSuccessful();
    expect($f['trip']->fresh()->status)->toBe('boarding');
});

it('holds Start Boarding and Depart to the clock for the Operator too', function () {
    $f = ttFixture();
    ttAt('07:00');

    $this->actingAs($f['operator'])->patchJson("/api/operator/trips/{$f['trip']->trip_id}/boarding")
        ->assertStatus(422)->assertJsonPath('message', 'Boarding opens at 7:30 AM.');
});

it('lets the trip depart at or after the departure time, and still when late', function () {
    $f = ttFixture();
    ttReady($this, $f);
    $url = "/api/driver/trips/{$f['trip']->trip_id}/depart";

    ttAt('07:59');
    $this->actingAs($f['driver'])->patchJson($url)->assertStatus(422)->assertJsonPath('message', 'You can depart at 8:00 AM.');

    ttAt('08:00');
    $this->actingAs($f['driver'])->patchJson($url)->assertSuccessful();
    expect($f['trip']->fresh()->status)->toBe('departed');
});

it('still lets a late trip depart', function () {
    $f = ttFixture();
    ttReady($this, $f);

    ttAt('09:15');
    $this->actingAs($f['driver'])->patchJson("/api/driver/trips/{$f['trip']->trip_id}/depart")->assertSuccessful();
});

it('lets the Driver end the trip only after the final stop is acknowledged', function () {
    $f = ttFixture();
    ttReady($this, $f);
    ttAt('08:00');
    $this->actingAs($f['driver'])->patchJson("/api/driver/trips/{$f['trip']->trip_id}/depart")->assertSuccessful();
    $complete = fn () => $this->actingAs($f['driver'])->patchJson("/api/driver/trips/{$f['trip']->trip_id}/complete");
    $acknowledge = fn (string $stop) => $this->actingAs($f['driver'])->postJson("/api/driver/trips/current/stops/{$f['stops'][$stop]->stop_id}/acknowledge")->assertSuccessful();

    $complete()->assertStatus(422)->assertJsonPath('message', 'Acknowledge the final stop before ending the trip.');

    $acknowledge('B');
    $complete()->assertStatus(422);

    $acknowledge('C');
    $complete()->assertSuccessful();
    expect($f['trip']->fresh()->status)->toBe('completed');
});

it('lets the Operator end a trip whose final stop was never acknowledged', function () {
    $f = ttFixture();
    ttReady($this, $f);
    ttAt('08:00');
    $this->actingAs($f['driver'])->patchJson("/api/driver/trips/{$f['trip']->trip_id}/depart")->assertSuccessful();

    app('auth')->forgetGuards();
    $this->actingAs($f['operator'])->patchJson("/api/operator/trips/{$f['trip']->trip_id}/complete")->assertSuccessful();
});

it('gives the Driver and Chauffeur the opening times with the current trip', function () {
    $f = ttFixture();
    ttReady($this, $f);

    $timing = $this->actingAs($f['driver'])->getJson('/api/driver/trips/current')->assertSuccessful()->json('data.action_timing');
    expect(Carbon::parse($timing['departure_at'])->format('H:i'))->toBe('08:00')
        ->and(Carbon::parse($timing['boarding_opens_at'])->format('H:i'))->toBe('07:30')
        ->and($timing['server_now'])->not->toBeNull();

    $conductorTiming = $this->actingAs($f['conductor'])->getJson('/api/conductor/trips/current')->assertSuccessful()->json('data.action_timing');
    expect(Carbon::parse($conductorTiming['boarding_opens_at'])->format('H:i'))->toBe('07:30');
});

it('reads both windows from their own settings and names another day', function () {
    $f = ttFixture();
    config(['trip_timing.boarding_window_minutes' => 45, 'trip_timing.pairing_opens_minutes_before' => 90]);
    $trip = $f['trip']->fresh();

    ttAt('06:00');
    expect(TripTiming::boardingBlockedReason($trip))->toBe('Boarding opens at 7:15 AM.')
        ->and(TripTiming::pairingBlockedReason($trip))->toBe('Pairing opens at 6:30 AM.');

    Carbon::setTestNow(Carbon::parse('2026-10-05 20:00:00', 'Asia/Manila'));
    expect(TripTiming::departBlockedReason($trip))->toBe('You can depart on Oct 6 at 8:00 AM.');
});
