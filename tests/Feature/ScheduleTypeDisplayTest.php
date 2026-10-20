<?php

use App\Models\Fleet;
use App\Models\FleetRoute;
use App\Models\PassengerUser;
use App\Models\Payment;
use App\Models\Route;
use App\Models\RouteStop;
use App\Models\ShiftBlock;
use App\Models\StaffUser;
use App\Models\Stop;
use App\Models\Ticket;
use App\Models\Trip;
use App\Models\User;
use App\Services\ShiftBlockService;
use App\Support\TripRunInfo;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

// Batch 28 Stage 3, item 8: the run pattern ("One-way run" / "Round-trip run") and the service (Regular, Express,
// Express - Non-stop, with the stops an express serves), derived from stored data wherever a trip is shown.

function sdStaff(string $role): User
{
    $user = User::create(['username' => 'sd'.Str::random(8), 'email' => Str::random(8).'@example.com', 'password' => bcrypt('password123'), 'role' => $role]);
    StaffUser::create(['user_id' => $user->user_id, 'company_user_uuid' => (string) Str::uuid(), 'name' => 'Sd '.ucfirst($role), 'phone_num' => '09170000071', 'address' => 'x']);

    return $user->fresh();
}

/** Route A(terminal) B C(pass-through) D E(terminal); $express = which stop letters an express serves. */
function sdFixture(array $express = ['A', 'B', 'D', 'E']): array
{
    $operator = sdStaff('operator');
    $route = Route::create(['route_name' => 'Sd Route', 'origin' => 'A', 'destination' => 'E']);
    $stops = [];
    foreach ([['A', 0, 'terminal'], ['B', 10, 'stop'], ['C', 20, 'pass_through'], ['D', 30, 'stop'], ['E', 40, 'terminal']] as $i => [$name, $km, $type]) {
        $stop = Stop::create(['stop_name' => "Stop $name", 'latitude' => 7.0 + $km / 100, 'longitude' => 125.0, 'municipality' => 'Mati City']);
        RouteStop::create([
            'route_id' => $route->route_id, 'stop_id' => $stop->stop_id, 'stop_order' => $i + 1, 'distance_from_origin_km' => $km, 'distance_from_origin_m' => $km * 1000, 'stop_type' => $type,
            'scheduled_offset_min' => $km, 'express_offset_min' => (int) ($km * 0.8), 'served_by_express' => in_array($name, $express, true),
        ]);
        $stops[$name] = $stop;
    }
    $company = $operator->companyProfile->company_user_id;
    $fleet = Fleet::create(['company_user_id' => $company, 'plate_number' => 'SD-'.fake()->unique()->numerify('####'), 'capacity' => 40, 'seated_capacity' => 30, 'standing_capacity' => 10, 'status' => 'active', 'vehicle_class' => 'aircon']);
    $fleetRoute = FleetRoute::create(['fleet_id' => $fleet->fleet_id, 'route_id' => $route->route_id, 'start_time' => '06:00', 'end_time' => '22:00', 'status' => 'active']);
    $mk = fn (array $o = []) => Trip::create(array_merge([
        'fleet_route_id' => $fleetRoute->fleet_route_id, 'company_user_id' => $company, 'trip_date' => '2026-10-05', 'departure_time' => '08:00', 'trip_type' => 'one_way',
        'leg_direction' => 'outbound', 'service_type' => 'regular', 'status' => 'scheduled', 'current_seated_capacity' => 0, 'current_standing_capacity' => 0, 'total_occupancy' => 0,
    ], $o));

    return compact('operator', 'route', 'stops', 'fleet', 'fleetRoute', 'mk', 'company');
}

function sdBlock(array $f, array $times): ShiftBlock
{
    config(['shift_blocks.legs_per_block' => count($times)]);
    $driver = sdStaff('driver');
    $conductor = sdStaff('conductor');

    return app(ShiftBlockService::class)->createBlock([
        'fleet_route_id' => $f['fleetRoute']->fleet_route_id, 'driver_id' => $driver->companyProfile->company_user_id, 'conductor_id' => $conductor->companyProfile->company_user_id,
        'company_user_id' => $f['company'], 'block_type' => 'morning', 'scheduled_date' => '2026-10-05', 'leg_departure_times' => $times,
    ]);
}

beforeEach(fn () => Carbon::setTestNow(Carbon::parse('2026-10-04 07:00:00', 'Asia/Manila')));
afterEach(fn () => Carbon::setTestNow());

it('derives the run pattern from the block for block legs and from the stored trip_type for a standalone trip, and shows nothing when neither exists', function () {
    // The operator's schedule field is trips.trip_type; block legs are always stamped one_way, so the block decides for them.
    expect(TripRunInfo::runPattern('one_way', 7, 1))->toBe(['key' => 'one_way', 'label' => 'One-way run', 'source' => 'shift_block']);
    expect(TripRunInfo::runPattern('one_way', 7, 4))->toBe(['key' => 'round_trip', 'label' => 'Round-trip run', 'source' => 'shift_block']);
    expect(TripRunInfo::runPattern('round_trip', null, null))->toBe(['key' => 'round_trip', 'label' => 'Round-trip run', 'source' => 'trip_type']);
    expect(TripRunInfo::runPattern('one_way', null, null)['label'])->toBe('One-way run');
    expect(TripRunInfo::runPattern(null, null, null))->toBeNull();       // missing data: nothing invented
    expect(TripRunInfo::runPattern('something_else', null, null))->toBeNull();
    expect(TripRunInfo::runPattern('one_way', 7, 0)['source'])->toBe('trip_type'); // a block with no legs on record falls back to the stored field

    // The label cannot be mistaken for a passenger's roundtrip booking.
    expect(TripRunInfo::runPattern('round_trip', null, null)['label'])->not->toMatch('/^roundtrip$/i')->and(TripRunInfo::runPattern('round_trip', null, null)['label'])->toContain('run');
});

it('labels a one-leg block One-way run, a multi-leg block Round-trip run, and a standalone trip by its stored trip_type', function () {
    $f = sdFixture();
    $oneLeg = sdBlock($f, ['06:00']);
    // The same bus cannot be on the one-leg block and the four-leg block at the same time, so the second starts later.
    $fourLegs = sdBlock($f, ['08:00', '11:00', '14:00', '17:00']);
    $standaloneRound = $f['mk'](['trip_type' => 'round_trip', 'return_departure_time' => '17:00', 'departure_time' => '10:00']);
    $standaloneOne = $f['mk'](['departure_time' => '11:00']);

    expect(TripRunInfo::forTrip($oneLeg->legs->first())['run_pattern'])->toMatchArray(['label' => 'One-way run', 'source' => 'shift_block']);
    expect(TripRunInfo::forTrip($fourLegs->legs->first())['run_pattern'])->toMatchArray(['label' => 'Round-trip run', 'source' => 'shift_block']);
    expect(TripRunInfo::forTrip($standaloneRound)['run_pattern'])->toMatchArray(['label' => 'Round-trip run', 'source' => 'trip_type']);
    expect(TripRunInfo::forTrip($standaloneOne)['run_pattern'])->toMatchArray(['label' => 'One-way run', 'source' => 'trip_type']);
});

it('describes a regular trip as Regular with no stop list, an express as Express with the stops it serves, and Non-stop only when those are just the terminals', function () {
    $f = sdFixture(['A', 'B', 'D', 'E']);
    $regular = $f['mk']();
    $express = $f['mk'](['service_type' => 'express']);

    $reg = TripRunInfo::forTrip($regular)['service'];
    expect($reg)->toMatchArray(['type' => 'regular', 'label' => 'Regular', 'non_stop' => false, 'served_stops' => []]);

    $exp = TripRunInfo::forTrip($express)['service'];
    expect($exp['label'])->toBe('Express')->and($exp['non_stop'])->toBeFalse();
    expect(collect($exp['served_stops'])->pluck('name')->all())->toBe(['Stop A', 'Stop B', 'Stop D', 'Stop E']); // several stops, in travel order

    // On the reverse leg the same stops are listed in the order the bus meets them.
    $reverse = TripRunInfo::forTrip($f['mk'](['service_type' => 'express', 'leg_direction' => 'reverse']))['service'];
    expect(collect($reverse['served_stops'])->pluck('name')->all())->toBe(['Stop E', 'Stop D', 'Stop B', 'Stop A']);

    // Derived from data, not a flag: serve only the terminals and the same trip becomes Non-stop.
    RouteStop::where('route_id', $f['route']->route_id)->update(['served_by_express' => false]);
    RouteStop::where('route_id', $f['route']->route_id)->where('stop_type', 'terminal')->update(['served_by_express' => true]);
    $nonStop = TripRunInfo::forTrip($f['mk'](['service_type' => 'express']))['service'];
    expect($nonStop['label'])->toBe('Express - Non-stop')->and($nonStop['non_stop'])->toBeTrue()->and(collect($nonStop['served_stops'])->pluck('name')->all())->toBe(['Stop A', 'Stop E']);
});

it('shows an express with no served stops recorded as Express with an empty list, never as Non-stop', function () {
    $f = sdFixture([]);
    $service = TripRunInfo::forTrip($f['mk'](['service_type' => 'express']))['service'];

    expect($service['label'])->toBe('Express')->and($service['non_stop'])->toBeFalse()->and($service['served_stops'])->toBe([]);
});

it('carries both facts to the booking list, the A2 timeline header, the operator schedule, the block cards, the ticket and the QR data', function () {
    $f = sdFixture(['A', 'B', 'D', 'E']);
    $block = sdBlock($f, ['06:00', '09:00', '12:00', '15:00']);
    $block->legs->each->update(['service_type' => 'express']);
    $leg = $block->legs->first();

    // Booking list (passenger).
    $dep = $this->getJson("/api/booking/departures?origin_stop_id={$f['stops']['B']->stop_id}&destination_stop_id={$f['stops']['D']->stop_id}&date=2026-10-05")->assertSuccessful()->json('data.departures.0');
    expect($dep['run_pattern']['label'])->toBe('Round-trip run')->and($dep['service']['label'])->toBe('Express')->and(collect($dep['service']['served_stops'])->pluck('name')->all())->toBe(['Stop A', 'Stop B', 'Stop D', 'Stop E']);

    // The A2 timeline header.
    $trip = $this->getJson("/api/trips/{$leg->trip_id}/timeline")->assertSuccessful()->json('data.trip');
    expect($trip['run_pattern']['label'])->toBe('Round-trip run')->and($trip['service']['type'])->toBe('express');

    // The operator's schedule list.
    $list = collect($this->actingAs($f['operator'])->getJson('/api/operator/trips?status_filter=all')->assertSuccessful()->json('data'));
    $row = $list->firstWhere('trip_id', $leg->trip_id);
    expect($row['run_info']['run_pattern']['label'])->toBe('Round-trip run')->and($row['run_info']['service']['label'])->toBe('Express');

    // The operator's block cards.
    $cards = collect($this->actingAs($f['operator'])->getJson('/api/operator/assignments')->assertSuccessful()->json('data'));
    $card = $cards->first(fn ($c) => ($c['shift_block_id'] ?? null) === $block->shift_block_id);
    expect($card['run_pattern']['label'])->toBe('Round-trip run')->and($card['service']['label'])->toBe('Express');

    // The ticket and its QR data (the saved image draws from the same payload).
    $user = User::create(['username' => 'pax'.Str::random(6), 'email' => Str::random(8).'@example.com', 'password' => bcrypt('password123'), 'role' => 'passenger']);
    $pax = PassengerUser::create(['passenger_uuid' => (string) Str::uuid(), 'user_id' => $user->user_id, 'name' => 'Pax', 'phone_num' => '09170000001', 'address' => 'x', 'birthdate' => '2000-01-01']);
    $payment = Payment::create(['amount' => 47, 'payment_created' => now(), 'transaction_reference' => 'TXN-SD-1', 'payment_method' => 'online', 'payment_channel' => 'gcash', 'status' => 'paid', 'payment_uuid' => (string) Str::uuid(), 'is_valid' => true, 'items_payload' => []]);
    $ticket = Ticket::create(['fleet_route_id' => $leg->fleet_route_id, 'trip_id' => $leg->trip_id, 'payment_id' => $payment->payment_id, 'passenger_id' => $pax->passenger_id, 'ticket_uuid' => (string) Str::uuid(), 'status' => 'valid', 'amount' => 47, 'seat_type' => 'seated',
        'origin_stop_id' => $f['stops']['B']->stop_id, 'destination_stop_id' => $f['stops']['D']->stop_id]);

    $mine = collect($this->actingAs($user)->getJson('/api/passengers/tickets')->assertSuccessful()->json('data.data'))->firstWhere('ticket_uuid', $ticket->ticket_uuid);
    expect($mine['run_info']['run_pattern']['label'])->toBe('Round-trip run')->and($mine['run_info']['service']['label'])->toBe('Express');
    $qr = $this->actingAs($user)->getJson("/api/passengers/tickets/{$ticket->ticket_uuid}/qr")->assertSuccessful()->json('data');
    expect($qr['run_info']['run_pattern']['label'])->toBe('Round-trip run')->and($qr['run_info']['service']['label'])->toBe('Express');
});
