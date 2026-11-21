<?php

use App\Models\Company;
use App\Models\FareRule;
use App\Models\Fleet;
use App\Models\FleetRoute;
use App\Models\Payment;
use App\Models\Route;
use App\Models\RouteStop;
use App\Models\StaffUser;
use App\Models\Stop;
use App\Models\Ticket;
use App\Models\Trip;
use App\Models\User;
use App\Services\OccupancyService;
use App\Support\TripRunInfo;
use App\Support\VehicleClass;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

// Batch 75 (panel and user, 2026-11-15): Non-aircon label, the Operator's fare table, the Chauffeur's "to board" count,
// and an express trip read as point A to point B. Fare numbers are SAMPLE test values (13 + 2.25 a km after 5 km).

function b75Staff(string $role): User
{
    $user = User::create(['username' => 'b75'.Str::random(8), 'email' => Str::random(8).'@example.com', 'password' => bcrypt('password123'), 'role' => $role]);
    StaffUser::create(['user_id' => $user->user_id, 'company_user_uuid' => (string) Str::uuid(), 'name' => 'B75 '.ucfirst($role), 'phone_num' => '09170000071', 'address' => 'x']);

    return $user->fresh();
}

function b75Fixture(): array
{
    $company = Company::create(['company_name' => 'B75 Bus Co']);
    $operator = b75Staff('operator');
    StaffUser::where('user_id', $operator->user_id)->update(['company_id' => $company->company_id]);
    $operator = $operator->fresh();
    $route = Route::create(['route_name' => 'B75 Route', 'origin' => 'A', 'destination' => 'D', 'company_id' => $company->company_id]);
    $stops = [];
    // A and D are terminals served by express; B and C are skipped by express.
    foreach ([['A', 0, 'terminal', true], ['B', 10, 'stop', false], ['C', 20, 'stop', false], ['D', 30, 'terminal', true]] as $i => [$name, $km, $type, $express]) {
        $stop = Stop::create(['stop_name' => "Stop $name", 'latitude' => 7.0 + $km / 100, 'longitude' => 125.0, 'municipality' => 'Mati City']);
        RouteStop::create(['route_id' => $route->route_id, 'stop_id' => $stop->stop_id, 'stop_order' => $i + 1, 'distance_from_origin_km' => $km, 'stop_type' => $type, 'scheduled_offset_min' => $km, 'served_by_express' => $express, 'express_offset_min' => $express ? $km : null]);
        $stops[$name] = $stop;
    }
    $fleet = Fleet::create([
        'company_user_id' => $operator->companyProfile->company_user_id, 'plate_number' => 'B75-'.fake()->unique()->numerify('####'),
        'bus_company_name' => 'Co B', 'body_no' => '7', 'capacity' => 30, 'seated_capacity' => 30, 'standing_capacity' => 0, 'status' => 'active', 'vehicle_class' => 'ordinary', 'company_id' => $company->company_id,
    ]);
    $fleetRoute = FleetRoute::create(['fleet_id' => $fleet->fleet_id, 'route_id' => $route->route_id, 'start_time' => '06:00', 'end_time' => '22:00', 'status' => 'active']);
    FareRule::create(['fleet_id' => null, 'vehicle_class' => 'ordinary', 'service_type' => 'regular', 'seat_type' => 'seated', 'status' => 'active', 'base_fare' => 13, 'fare_per_km' => 2.25, 'base_distance_km' => 5, 'note' => 'SAMPLE']);

    return compact('operator', 'route', 'stops', 'fleet', 'fleetRoute');
}

function b75Trip(array $f, string $status = 'boarding', string $service = 'regular'): Trip
{
    return Trip::create([
        'fleet_route_id' => $f['fleetRoute']->fleet_route_id, 'company_user_id' => $f['operator']->companyProfile->company_user_id, 'trip_date' => '2026-10-05', 'departure_time' => '08:00',
        'trip_type' => 'one_way', 'leg_direction' => 'outbound', 'status' => $status, 'service_type' => $service, 'current_seated_capacity' => 0, 'current_standing_capacity' => 0, 'total_occupancy' => 0,
    ]);
}

/** A paid ticket waiting to board at $from. */
function b75Ticket(Trip $trip, Stop $from, Stop $to, string $status = 'valid'): Ticket
{
    $payment = Payment::create(['amount' => 50, 'payment_created' => now(), 'transaction_reference' => 'TXN-B75-'.Str::random(6), 'payment_method' => 'online', 'payment_channel' => 'gcash', 'status' => 'paid', 'payment_uuid' => (string) Str::uuid(), 'is_valid' => true, 'items_payload' => []]);

    return Ticket::create([
        'ticket_uuid' => (string) Str::uuid(), 'fleet_route_id' => $trip->fleet_route_id, 'trip_id' => $trip->trip_id, 'payment_id' => $payment->payment_id,
        'status' => $status, 'amount' => 50, 'seat_type' => 'seated', 'origin_stop_id' => $from->stop_id, 'destination_stop_id' => $to->stop_id, 'distance_km' => 10,
    ]);
}

beforeEach(fn () => Carbon::setTestNow(Carbon::parse('2026-10-05 07:30:00', 'Asia/Manila')));
afterEach(fn () => Carbon::setTestNow());

it('labels the ordinary class "Non-aircon" and accepts that name on input', function () {
    expect(VehicleClass::label('ordinary'))->toBe('Non-aircon')
        ->and(VehicleClass::normalize('Non-aircon'))->toBe('ordinary')
        ->and(VehicleClass::normalize('ordinary'))->toBe('ordinary');
});

it('shows the Operator the fares from a stop to every later stop, as passengers are charged them', function () {
    $f = b75Fixture();

    $response = $this->actingAs($f['operator'])->getJson("/api/operator/routes/{$f['route']->route_id}/fares?fleet_route_id={$f['fleetRoute']->fleet_route_id}")->assertOk();
    // A -> B 10 km: 13 + 5 x 2.25 = 24.25 -> 24; A -> C 20 km: 46.75 -> 47; A -> D 30 km: 69.25 -> 69.
    expect($response->json('data.origin.name'))->toBe('Stop A')
        ->and($response->json('data.vehicle_class'))->toBe('Non-aircon')
        ->and(collect($response->json('data.rows'))->map(fn ($r) => [$r['name'], $r['fare']])->all())->toBe([['Stop B', 24], ['Stop C', 47], ['Stop D', 69]]);

    $fromB = $this->getJson("/api/operator/routes/{$f['route']->route_id}/fares?fleet_route_id={$f['fleetRoute']->fleet_route_id}&origin_stop_id={$f['stops']['B']->stop_id}")->assertOk();
    expect(collect($fromB->json('data.rows'))->pluck('name')->all())->toBe(['Stop C', 'Stop D']);

    // Express serves only the terminals; with no express fare rule the fare is unavailable, never guessed.
    $express = $this->getJson("/api/operator/routes/{$f['route']->route_id}/fares?fleet_route_id={$f['fleetRoute']->fleet_route_id}&service_type=express")->assertOk();
    expect($express->json('data.rows'))->toBe([['stop_id' => $f['stops']['D']->stop_id, 'name' => 'Stop D', 'fare' => null]]);
});

// 2026-11-21 (user, Dynamic Route Scheduling): any bus of the company can run any route, so the fare table also takes a
// bus by itself (fleet_id), priced on this route without saving a bus-and-route row; another company's bus is refused.
it('shows the fares of any company bus on the route, even one that never ran it, and saves nothing', function () {
    $f = b75Fixture();
    $spare = Fleet::create([
        'company_user_id' => $f['operator']->companyProfile->company_user_id, 'plate_number' => 'B75-SPARE', 'bus_company_name' => 'Co B', 'body_no' => '8',
        'capacity' => 30, 'seated_capacity' => 30, 'standing_capacity' => 0, 'status' => 'active', 'vehicle_class' => 'ordinary', 'company_id' => $f['fleet']->company_id,
    ]);
    $this->actingAs($f['operator']);

    $rows = $this->getJson("/api/operator/routes/{$f['route']->route_id}/fares?fleet_id={$spare->fleet_id}")->assertOk()->json('data.rows');
    expect(collect($rows)->map(fn ($r) => [$r['name'], $r['fare']])->all())->toBe([['Stop B', 24], ['Stop C', 47], ['Stop D', 69]])
        ->and(FleetRoute::where('fleet_id', $spare->fleet_id)->count())->toBe(0);

    $theirs = Fleet::create(['company_user_id' => b75Staff('operator')->companyProfile->company_user_id, 'plate_number' => 'B75-THEIRS', 'capacity' => 30, 'seated_capacity' => 30, 'standing_capacity' => 0, 'status' => 'active']);
    $this->getJson("/api/operator/routes/{$f['route']->route_id}/fares?fleet_id={$theirs->fleet_id}")
        ->assertUnprocessable()->assertJsonPath('errors.fleet_id.0', 'Choose one of your own buses.');
});

it('keeps another company\'s bus out of the fare table', function () {
    $f = b75Fixture();
    $other = b75Staff('operator');

    // Another company cannot even see the route (404), let alone its buses' fares.
    $this->actingAs($other)->getJson("/api/operator/routes/{$f['route']->route_id}/fares?fleet_route_id={$f['fleetRoute']->fleet_route_id}")->assertNotFound();
});

it('tells the Chauffeur the next stop and how many paid passengers are still to board there', function () {
    $f = b75Fixture();
    $trip = b75Trip($f);
    b75Ticket($trip, $f['stops']['A'], $f['stops']['D']);
    b75Ticket($trip, $f['stops']['B'], $f['stops']['D']);
    b75Ticket($trip, $f['stops']['B'], $f['stops']['C']);
    b75Ticket($trip, $f['stops']['B'], $f['stops']['D'], 'boarded'); // already scanned: not waiting

    $byStop = app(OccupancyService::class)->getOccupancyByStop((int) $trip->trip_id);
    expect($byStop['next_stop'])->toBe(['stop_id' => $f['stops']['A']->stop_id, 'stop_name' => 'Stop A', 'waiting_count' => 1, 'priority_count' => 0, 'baggage_count' => 0])
        ->and($byStop['waiting_total'])->toBe(3)
        ->and(collect($byStop['stops'])->firstWhere('stop_name', 'Stop B')['waiting_count'])->toBe(2);

    // After the Driver acknowledges A, the next stop is B.
    $trip->forceFill(['status' => 'departed', 'last_acknowledged_stop_id' => $f['stops']['A']->stop_id])->save();
    expect(app(OccupancyService::class)->getOccupancyByStop((int) $trip->trip_id)['next_stop']['stop_name'])->toBe('Stop B');
});

it('reads an express trip that serves only its terminals as Non-stop, point A to point B', function () {
    $f = b75Fixture();
    $stops = RouteStop::with('stop')->where('route_id', $f['route']->route_id)->orderBy('stop_order')->get();

    $service = TripRunInfo::service('express', $stops);
    expect($service['non_stop'])->toBeTrue()
        ->and(collect($service['served_stops'])->pluck('name')->all())->toBe(['Stop A', 'Stop D'])
        ->and(TripRunInfo::service('regular', $stops)['non_stop'])->toBeFalse();
});
