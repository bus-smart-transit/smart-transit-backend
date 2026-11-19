<?php

use App\Models\DropOffConfirmation;
use App\Models\FareRule;
use App\Models\Fleet;
use App\Models\FleetDailyPin;
use App\Models\FleetRoute;
use App\Models\Payment;
use App\Models\Route;
use App\Models\RouteStop;
use App\Models\StaffUser;
use App\Models\Stop;
use App\Models\Ticket;
use App\Models\Trip;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

// 2026-10-27 (smarttransit_prompt.md §7): the Chauffeur's passengers grouped by where they get off, computed on the
// server, and one action to confirm a group got off. The example from the prompt: an Ecoland to Tagum trip with
// passengers getting off at Maa and at Tagum.

function dogStaff(string $role): User
{
    $user = User::create(['username' => fake()->unique()->userName(), 'email' => fake()->unique()->safeEmail(), 'password' => bcrypt('password123'), 'role' => $role]);
    StaffUser::create(['user_id' => $user->user_id, 'company_user_uuid' => (string) Str::uuid(), 'name' => 'Dog '.ucfirst($role), 'phone_num' => '09170000063', 'address' => 'x']);

    return $user->fresh();
}

/** Ecoland (0 km), Maa (5 km), Tagum (40 km); the Chauffeur is paired and on shift. */
function dogFixture($test): array
{
    $operator = dogStaff('operator');
    $driver = dogStaff('driver');
    $conductor = dogStaff('conductor');
    $route = Route::create(['route_name' => 'Ecoland - Tagum', 'origin' => 'Ecoland', 'destination' => 'Tagum']);
    $stops = [];
    foreach ([['Ecoland', 0, 'terminal'], ['Maa', 5, 'stop'], ['Tagum', 40, 'terminal']] as $i => [$name, $km, $type]) {
        $stop = Stop::create(['stop_name' => $name, 'latitude' => 7.0 + $km / 100, 'longitude' => 125.5]);
        RouteStop::create(['route_id' => $route->route_id, 'stop_id' => $stop->stop_id, 'stop_order' => $i + 1, 'distance_from_origin_km' => $km, 'stop_type' => $type]);
        $stops[$name] = $stop;
    }
    $fleet = Fleet::create(['company_user_id' => $operator->companyProfile->company_user_id, 'plate_number' => 'DOG-'.fake()->unique()->numerify('###'), 'capacity' => 40, 'seated_capacity' => 30, 'standing_capacity' => 10, 'status' => 'active']);
    FareRule::create(['fleet_id' => $fleet->fleet_id, 'base_fare' => 15, 'fare_per_km' => 2, 'status' => 'active', 'seat_type' => 'seated']);
    $fleetRoute = FleetRoute::create(['fleet_id' => $fleet->fleet_id, 'route_id' => $route->route_id, 'start_time' => '05:00', 'end_time' => '22:00', 'status' => 'active']);
    $trip = Trip::create([
        'fleet_route_id' => $fleetRoute->fleet_route_id, 'company_user_id' => $operator->companyProfile->company_user_id,
        'driver_id' => $driver->companyProfile->company_user_id, 'conductor_id' => $conductor->companyProfile->company_user_id,
        'driver_accepted_at' => now(), 'conductor_accepted_at' => now(), 'trip_date' => now('Asia/Manila')->toDateString(), 'departure_time' => '08:00',
        'trip_type' => 'one_way', 'leg_direction' => 'outbound', 'status' => 'boarding',
        'current_seated_capacity' => 0, 'current_standing_capacity' => 0, 'total_occupancy' => 0,
    ]);
    FleetDailyPin::create([
        'trip_id' => $trip->trip_id, 'driver_id' => $driver->companyProfile->company_user_id, 'conductor_id' => $conductor->companyProfile->company_user_id,
        'pin_code' => '123456', 'pin_date' => now('Asia/Manila')->toDateString(), 'driver_verified_at' => now(), 'conductor_verified_at' => now(), 'paired_at' => now(),
    ]);
    $test->actingAs($conductor)->postJson('/api/conductor/shift/start')->assertSuccessful();

    return [$conductor, $trip, $stops];
}

/** A cash sale is boarded at once. */
function dogSell($test, User $conductor, Trip $trip, array $stops, string $to): int
{
    return (int) $test->actingAs($conductor)->postJson('/api/conductor/checkout', ['items' => [[
        'trip_id' => $trip->trip_id, 'seat_type' => 'seated', 'origin_stop_id' => $stops['Ecoland']->stop_id, 'destination_stop_id' => $stops[$to]->stop_id,
    ]]])->assertSuccessful()->json('data.tickets.0.ticket_id');
}

function dogGroups($test, User $conductor): array
{
    return collect($test->actingAs($conductor)->getJson('/api/conductor/trips/current/passengers')->assertSuccessful()->json('data.destination_groups'))->keyBy('destination')->all();
}

function dogConfirm($test, User $conductor, string $key, array $ids)
{
    return $test->actingAs($conductor)->postJson('/api/conductor/trips/current/drop-off-groups/confirm', ['destination_key' => $key, 'ticket_ids' => $ids]);
}

it('groups the passengers by where they get off, in travel order, with how many get off there', function () {
    [$conductor, $trip, $stops] = dogFixture($this);
    dogSell($this, $conductor, $trip, $stops, 'Maa');
    dogSell($this, $conductor, $trip, $stops, 'Maa');
    dogSell($this, $conductor, $trip, $stops, 'Tagum');
    // An online ticket to Maa not scanned yet: shown apart, not counted as getting off.
    $payment = Payment::create(['amount' => 25, 'payment_created' => now(), 'transaction_reference' => 'txn-'.uniqid(), 'payment_method' => 'online', 'payment_channel' => 'gcash', 'status' => 'paid', 'payment_uuid' => (string) Str::uuid()]);
    Ticket::create(['fleet_route_id' => $trip->fleet_route_id, 'trip_id' => $trip->trip_id, 'payment_id' => $payment->payment_id, 'ticket_uuid' => (string) Str::uuid(), 'status' => 'valid', 'seat_type' => 'seated', 'amount' => 25, 'origin_stop_id' => $stops['Ecoland']->stop_id, 'destination_stop_id' => $stops['Maa']->stop_id]);

    $groups = dogGroups($this, $conductor);

    expect(array_keys($groups))->toBe(['Maa', 'Tagum'])
        ->and($groups['Maa']['alighting_count'])->toBe(2)
        ->and($groups['Maa']['not_boarded_count'])->toBe(1)
        ->and($groups['Maa']['key'])->toBe('stop:'.$stops['Maa']->stop_id)
        ->and($groups['Tagum']['alighting_count'])->toBe(1);
});

it('confirms a whole group in one action, records which tickets it included, and leaves the others aboard', function () {
    [$conductor, $trip, $stops] = dogFixture($this);
    $maa = [dogSell($this, $conductor, $trip, $stops, 'Maa'), dogSell($this, $conductor, $trip, $stops, 'Maa')];
    $tagum = dogSell($this, $conductor, $trip, $stops, 'Tagum');
    $group = dogGroups($this, $conductor)['Maa'];

    dogConfirm($this, $conductor, $group['key'], $group['ticket_ids'])->assertSuccessful()->assertJsonPath('data.confirmed', 2);

    expect(Ticket::whereIn('ticket_id', $maa)->pluck('status')->unique()->all())->toBe(['alighted'])
        ->and(Ticket::find($tagum)->status)->toBe('boarded')
        ->and(Ticket::find($maa[0])->destination_stop_id)->toBe($stops['Maa']->stop_id);
    $record = DropOffConfirmation::firstOrFail();
    expect($record->ticket_ids)->toEqualCanonicalizing($maa)
        ->and($record->ticket_count)->toBe(2)
        ->and($record->destination_label)->toBe('Maa')
        ->and((int) $record->conductor_company_user_id)->toBe((int) $conductor->companyProfile->company_user_id);

    // The remaining route after Maa: only the Tagum group is left.
    expect(array_keys(dogGroups($this, $conductor)))->toBe(['Tagum']);
});

it('keeps a passenger who stayed on aboard (the exception path)', function () {
    [$conductor, $trip, $stops] = dogFixture($this);
    $first = dogSell($this, $conductor, $trip, $stops, 'Maa');
    $stayed = dogSell($this, $conductor, $trip, $stops, 'Maa');
    $group = dogGroups($this, $conductor)['Maa'];

    dogConfirm($this, $conductor, $group['key'], [$first])->assertSuccessful()->assertJsonPath('data.confirmed', 1);

    expect(Ticket::find($stayed)->status)->toBe('boarded')
        ->and(dogGroups($this, $conductor)['Maa']['alighting_count'])->toBe(1);
});

it('never marks a passenger of another destination, and changes nothing when the list is out of date', function () {
    [$conductor, $trip, $stops] = dogFixture($this);
    $maa = dogSell($this, $conductor, $trip, $stops, 'Maa');
    $tagum = dogSell($this, $conductor, $trip, $stops, 'Tagum');
    $group = dogGroups($this, $conductor)['Maa'];

    dogConfirm($this, $conductor, $group['key'], [$maa, $tagum])->assertStatus(422)->assertJsonFragment(['The passenger list has changed. Refresh and confirm again.']);
    expect(Ticket::find($maa)->status)->toBe('boarded')->and(Ticket::find($tagum)->status)->toBe('boarded');

    // Already off the bus (marked one by one): a second confirm of the same list is refused.
    $this->actingAs($conductor)->postJson("/api/conductor/tickets/{$maa}/alight")->assertSuccessful();
    dogConfirm($this, $conductor, $group['key'], [$maa])->assertStatus(422);
    expect(DropOffConfirmation::count())->toBe(0);
});

it('refuses a destination key that is not a stop, point or pin', function () {
    [$conductor] = dogFixture($this);

    dogConfirm($this, $conductor, 'everyone', [1])->assertStatus(422)->assertJsonValidationErrors(['destination_key']);
});

// §6 (2026-10-27): on a bus already on the road, a cash ticket cannot go to a stop the Driver has already passed; the
// boarding stop may still be behind the bus (someone who got on at the terminal pays after departure).
it('refuses a cash ticket to a stop the bus has passed, but still sells from a stop behind it', function () {
    [$conductor, $trip, $stops] = dogFixture($this);
    $trip->update(['status' => 'departed']);
    $trip->forceFill(['last_acknowledged_stop_id' => $stops['Maa']->stop_id, 'last_acknowledged_at' => now()])->save();
    $sale = fn (string $to) => $this->actingAs($conductor)->postJson('/api/conductor/checkout', ['items' => [[
        'trip_id' => $trip->trip_id, 'seat_type' => 'seated', 'origin_stop_id' => $stops['Ecoland']->stop_id, 'destination_stop_id' => $stops[$to]->stop_id,
    ]]]);

    $sale('Maa')->assertStatus(422)->assertJsonFragment(['The bus has already passed Maa. Choose a stop ahead.']);
    $sale('Tagum')->assertSuccessful();
});
