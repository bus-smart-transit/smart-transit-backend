<?php

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
use App\Repositories\FleetLocationRepository;
use App\Repositories\RouteGeometryRepository;
use App\Services\TicketExpiryService;
use App\Services\TicketService;
use App\Services\TripService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

uses(RefreshDatabase::class);

// Batch 60 item 1 (user, 2026-11-11): a digital ticket that was never scanned stays usable while its ride can still
// happen. It expires once the bus has reached or passed the passenger's destination, or the trip (leg) has ended.
// Example from the user: to Panabo, the bus still at Maa, it can be scanned; past Panabo it is expired.
// SAMPLE stops every 0.09 degrees of latitude (about 10 km) on a straight north-south line.

function txFixture(string $direction = 'outbound'): array
{
    $user = User::create(['username' => 'tx'.Str::random(8), 'email' => Str::random(8).'@example.com', 'password' => bcrypt('password123'), 'role' => 'operator']);
    StaffUser::create(['user_id' => $user->user_id, 'company_user_uuid' => (string) Str::uuid(), 'name' => 'Tx Operator', 'phone_num' => '09170000097', 'address' => 'x']);
    $companyUserId = $user->fresh()->companyProfile->company_user_id;
    $route = Route::create(['route_name' => 'Tx Route', 'origin' => 'Maa', 'destination' => 'Tagum']);
    $defs = [['Maa', 7.00, 0, 'terminal'], ['Panabo', 7.09, 10, 'stop'], ['Carmen', 7.18, 20, 'stop'], ['Tagum', 7.27, 30, 'terminal']];
    $stops = [];
    foreach ($defs as $i => [$name, $lat, $km, $type]) {
        $stop = Stop::create(['stop_name' => "Tx $name", 'latitude' => $lat, 'longitude' => 125.0, 'municipality' => 'Sample City']);
        RouteStop::create(['route_id' => $route->route_id, 'stop_id' => $stop->stop_id, 'stop_order' => $i + 1, 'distance_from_origin_km' => $km, 'stop_type' => $type, 'scheduled_offset_min' => $km]);
        $stops[$name] = $stop;
    }
    app(RouteGeometryRepository::class)->save($route->route_id, ['type' => 'LineString', 'coordinates' => collect($defs)->map(fn ($d) => [125.0, $d[1]])->all()]);
    $fleet = Fleet::create([
        'company_user_id' => $companyUserId, 'plate_number' => 'TX-'.fake()->unique()->numerify('####'), 'bus_company_name' => 'Co X', 'body_no' => '7',
        'capacity' => 30, 'seated_capacity' => 30, 'standing_capacity' => 0, 'status' => 'active', 'vehicle_class' => 'aircon',
    ]);
    $fleetRoute = FleetRoute::create(['fleet_id' => $fleet->fleet_id, 'route_id' => $route->route_id, 'start_time' => '06:00', 'end_time' => '22:00', 'status' => 'active']);
    $trip = Trip::create([
        'fleet_route_id' => $fleetRoute->fleet_route_id, 'company_user_id' => $companyUserId, 'trip_date' => '2026-10-05', 'departure_time' => '08:00',
        'trip_type' => 'one_way', 'leg_direction' => $direction, 'status' => 'departed', 'current_seated_capacity' => 0, 'current_standing_capacity' => 0, 'total_occupancy' => 0,
    ]);

    return compact('stops', 'trip', 'fleet', 'fleetRoute');
}

function txTicket(array $f, string $from, string $to, string $status = 'valid'): Ticket
{
    $payment = Payment::create(['amount' => 30, 'payment_created' => now(), 'transaction_reference' => 'TXN-TX-'.Str::random(6), 'payment_method' => 'online', 'payment_channel' => 'gcash', 'status' => 'paid', 'payment_uuid' => (string) Str::uuid(), 'is_valid' => true, 'items_payload' => []]);
    $km = fn (string $name) => (int) RouteStop::where('stop_id', $f['stops'][$name]->stop_id)->value('distance_from_origin_km') * 1000;

    return Ticket::create([
        'fleet_route_id' => $f['fleetRoute']->fleet_route_id, 'trip_id' => $f['trip']->trip_id, 'payment_id' => $payment->payment_id, 'ticket_uuid' => (string) Str::uuid(),
        'status' => $status, 'amount' => 30, 'distance_km' => 10, 'seat_type' => 'seated',
        'origin_stop_id' => $f['stops'][$from]->stop_id, 'destination_stop_id' => $f['stops'][$to]->stop_id,
        'boarding_distance_m' => $km($from), 'alighting_distance_m' => $km($to),
    ]);
}

function txBusAt(array $f, float $lat): void
{
    app(FleetLocationRepository::class)->upsertLocation(['fleet_id' => $f['fleet']->fleet_id, 'trip_id' => $f['trip']->trip_id, 'latitude' => $lat, 'longitude' => 125.0, 'accuracy' => 8.0]);
}

/** Scan as the Chauffeur of this running trip (the shift guard is the controller's job, tested elsewhere). */
function txScan(array $f, Ticket $ticket): object
{
    test()->mock(TripService::class, fn ($mock) => $mock->shouldReceive('getCurrentTripForConductor')->andReturn($f['trip']->fresh()));

    return app(TicketService::class)->validateScan(['ticket_uuid' => $ticket->ticket_uuid], 999);
}

beforeEach(function () {
    Carbon::setTestNow(Carbon::parse('2026-10-05 08:20:00', 'Asia/Manila'));
    Cache::flush();
});
afterEach(fn () => Carbon::setTestNow());

it('still scans a ticket whose destination is ahead of the bus', function () {
    $f = txFixture();
    txBusAt($f, 7.01); // just left Maa
    $ticket = txTicket($f, 'Maa', 'Panabo');

    expect(txScan($f, $ticket)->status)->toBe('boarded');
});

it('refuses and expires a ticket once the bus has passed its destination', function () {
    $f = txFixture();
    txBusAt($f, 7.12); // about 3 km past Panabo
    $ticket = txTicket($f, 'Maa', 'Panabo');

    expect(fn () => txScan($f, $ticket))->toThrow(ValidationException::class, TicketExpiryService::PASSED_MESSAGE);
    expect($ticket->fresh()->status)->toBe('expired');
});

it('does not expire on GPS noise just past the destination', function () {
    $f = txFixture();
    txBusAt($f, 7.0905); // about 55 m past Panabo, inside the 100 m margin
    $ticket = txTicket($f, 'Maa', 'Panabo');

    expect(app(TicketExpiryService::class)->expirePassedForTrip($f['trip']->fresh()))->toBe(0)
        ->and($ticket->fresh()->status)->toBe('valid');
});

it('expires a ticket once the Driver taps its destination stop, even with no GPS', function () {
    $f = txFixture();
    $ticket = txTicket($f, 'Maa', 'Panabo');
    $later = txTicket($f, 'Maa', 'Tagum');
    $f['trip']->forceFill(['last_acknowledged_stop_id' => $f['stops']['Panabo']->stop_id, 'last_acknowledged_at' => now()])->save();

    expect(app(TicketExpiryService::class)->expirePassedForTrip($f['trip']->fresh()))->toBe(1)
        ->and($ticket->fresh()->status)->toBe('expired')
        ->and($later->fresh()->status)->toBe('valid');
});

it('knows nothing without a tap or a fresh position, so nothing expires', function () {
    $f = txFixture();
    txBusAt($f, 7.20);
    Carbon::setTestNow(now()->addSeconds((int) config('tracking.stale_seconds') + 60));
    $ticket = txTicket($f, 'Maa', 'Panabo');

    expect(app(TicketExpiryService::class)->expirePassedForTrip($f['trip']->fresh()))->toBe(0)
        ->and($ticket->fresh()->status)->toBe('valid');
});

it('follows the leg direction on a return leg', function () {
    $f = txFixture('reverse'); // Tagum -> Maa
    txBusAt($f, 7.15); // between Carmen and Panabo, heading south
    $carmen = txTicket($f, 'Tagum', 'Carmen');
    $panabo = txTicket($f, 'Tagum', 'Panabo');

    expect(app(TicketExpiryService::class)->expirePassedForTrip($f['trip']->fresh()))->toBe(1)
        ->and($carmen->fresh()->status)->toBe('expired')
        ->and($panabo->fresh()->status)->toBe('valid');
});

it('sweeps running trips every minute and never touches a boarded ticket', function () {
    $f = txFixture();
    txBusAt($f, 7.12);
    $passed = txTicket($f, 'Maa', 'Panabo');
    $boarded = txTicket($f, 'Maa', 'Panabo', 'boarded');
    $ahead = txTicket($f, 'Maa', 'Tagum');

    $this->artisan('tickets:expire-passed')->assertSuccessful();

    expect($passed->fresh()->status)->toBe('expired')
        ->and($boarded->fresh()->status)->toBe('boarded')
        ->and($ahead->fresh()->status)->toBe('valid');
});

it('expires every unscanned ticket when the trip ends', function () {
    $f = txFixture();
    $unscanned = txTicket($f, 'Maa', 'Tagum');
    $boarded = txTicket($f, 'Maa', 'Tagum', 'boarded');

    app(TripService::class)->completeTrip($f['trip']->trip_id);

    expect($unscanned->fresh()->status)->toBe('expired')
        ->and($boarded->fresh()->status)->toBe('boarded');
});
