<?php

use App\Models\FareRule;
use App\Models\Fleet;
use App\Models\FleetRoute;
use App\Models\PassengerUser;
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
use App\Services\PaymentService;
use App\Services\TicketTrackingService;
use App\Services\TripProgressService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

// Batch 49 item 2 (user, 2026-11-07): a trip that has left stays bookable online from a stop the bus has not reached,
// until the Driver taps that stop or the live position shows the bus has gone past it. From a stop behind the bus it is
// "Departed". No fresh position means nothing is known: not bookable. Coordinates and fares are SAMPLE test values
// (stops every 0.09 degrees of latitude, about 10 km apart, on a straight north-south line).

function dtFixture(): array
{
    $user = User::create(['username' => 'dt'.Str::random(8), 'email' => Str::random(8).'@example.com', 'password' => bcrypt('password123'), 'role' => 'operator']);
    StaffUser::create(['user_id' => $user->user_id, 'company_user_uuid' => (string) Str::uuid(), 'name' => 'Dt Operator', 'phone_num' => '09170000081', 'address' => 'x']);
    $companyUserId = $user->fresh()->companyProfile->company_user_id;
    $route = Route::create(['route_name' => 'Dt Route', 'origin' => 'A', 'destination' => 'E']);
    $defs = [['Dt Stop A', 7.00, 0, 'terminal'], ['Dt Stop B', 7.09, 10, 'stop'], ['Dt Stop D', 7.27, 30, 'stop'], ['Dt Stop E', 7.36, 40, 'terminal']];
    $stops = [];
    foreach ($defs as $i => [$name, $lat, $km, $type]) {
        $stop = Stop::create(['stop_name' => $name, 'latitude' => $lat, 'longitude' => 125.0, 'municipality' => 'Sample City']);
        RouteStop::create(['route_id' => $route->route_id, 'stop_id' => $stop->stop_id, 'stop_order' => $i + 1, 'distance_from_origin_km' => $km, 'stop_type' => $type, 'scheduled_offset_min' => $km]);
        $stops[substr($name, -1)] = $stop;
    }
    app(RouteGeometryRepository::class)->save($route->route_id, ['type' => 'LineString', 'coordinates' => collect($defs)->map(fn ($d) => [125.0, $d[1]])->all()]);
    $fleet = Fleet::create([
        'company_user_id' => $companyUserId, 'plate_number' => 'DT-'.fake()->unique()->numerify('####'), 'bus_company_name' => 'Co D', 'body_no' => '4',
        'capacity' => 30, 'seated_capacity' => 30, 'standing_capacity' => 0, 'status' => 'active', 'vehicle_class' => 'aircon',
    ]);
    $fleetRoute = FleetRoute::create(['fleet_id' => $fleet->fleet_id, 'route_id' => $route->route_id, 'start_time' => '06:00', 'end_time' => '22:00', 'status' => 'active']);
    $trip = Trip::create([
        'fleet_route_id' => $fleetRoute->fleet_route_id, 'company_user_id' => $companyUserId, 'trip_date' => '2026-10-05', 'departure_time' => '08:00',
        'trip_type' => 'one_way', 'leg_direction' => 'outbound', 'status' => 'departed', 'current_seated_capacity' => 0, 'current_standing_capacity' => 0, 'total_occupancy' => 0,
    ]);
    // Set like the Driver's tap does (not mass assignable): the bus left A at 08:02.
    $trip->forceFill(['last_acknowledged_stop_id' => $stops['A']->stop_id, 'last_acknowledged_at' => Carbon::parse('2026-10-05 08:02:00', 'Asia/Manila')])->save();
    FareRule::create(['fleet_id' => null, 'vehicle_class' => 'aircon', 'service_type' => 'regular', 'seat_type' => 'seated', 'status' => 'active', 'base_fare' => 13, 'fare_per_km' => 2.25, 'base_distance_km' => 5, 'note' => 'SAMPLE']);

    return compact('stops', 'trip', 'fleet');
}

function dtBusAt(array $f, float $lat): void
{
    app(FleetLocationRepository::class)->upsertLocation(['fleet_id' => $f['fleet']->fleet_id, 'trip_id' => $f['trip']->trip_id, 'latitude' => $lat, 'longitude' => 125.0, 'accuracy' => 8.0]);
}

function dtSearch($test, array $f, string $from, string $to = 'E'): array
{
    return $test->getJson('/api/booking/trips?from='.$f['stops'][$from]->stop_code.'&to='.$f['stops'][$to]->stop_code.'&date=2026-10-05')->assertOk()->json('data.trips');
}

function dtCheckout($test, array $f, string $from)
{
    $user = User::create(['username' => 'pax'.Str::random(6), 'email' => Str::random(8).'@example.com', 'password' => bcrypt('password123'), 'role' => 'passenger']);
    PassengerUser::create(['passenger_uuid' => (string) Str::uuid(), 'user_id' => $user->user_id, 'name' => 'Pax', 'phone_num' => '09170000002', 'address' => 'x', 'birthdate' => '2000-01-01']);

    return $test->actingAs($user)->postJson('/api/passengers/checkout', [
        'booking_date' => '2026-10-05', 'payment_channel' => 'gcash',
        'items' => [['trip_id' => $f['trip']->trip_id, 'seat_type' => 'seated', 'origin_stop_id' => $f['stops'][$from]->stop_id, 'destination_stop_id' => $f['stops']['E']->stop_id]],
    ]);
}

beforeEach(function () {
    // The day of the trip, the bus left at 08:02; it is now 08:20.
    Carbon::setTestNow(Carbon::parse('2026-10-05 08:20:00', 'Asia/Manila'));
    Cache::flush();
    config(['services.paymongo.secret_key' => 'sk_test_dummy']);
    Http::fake(['api.paymongo.com/*' => fn () => Http::response(['data' => ['id' => 'cs_'.Str::random(12), 'attributes' => ['checkout_url' => 'https://pay.example/x', 'payment_intent' => ['id' => 'pi_'.Str::random(8)]]]])]);
});
afterEach(fn () => Carbon::setTestNow());

// Batch 51 item 10 (user, 2026-11-09): "Available trips" lists only trips that can still be booked; a bus that has
// passed the passenger's stop is not listed (it is on the Track Bus map). Was: listed as "Departed", not bookable.
it('leaves the trip out of the list for someone searching from a stop the bus has passed, and refuses it', function () {
    $f = dtFixture();
    dtBusAt($f, 7.15); // between B and D

    expect(dtSearch($this, $f, 'B'))->toBe([]);
    dtCheckout($this, $f, 'B')->assertStatus(422)->assertJsonFragment(['The bus has already left that stop. Choose a stop ahead of it, or another trip.']);
});

it('lets a passenger at a stop still ahead book it, with the next stop named', function () {
    $f = dtFixture();
    dtBusAt($f, 7.15);

    $trips = dtSearch($this, $f, 'D');
    expect($trips)->toHaveCount(1)
        ->and($trips[0]['bookable'])->toBeTrue()
        ->and($trips[0]['progress'])->toBe(['state' => 'on_the_way', 'label' => 'On the way', 'next_stop' => 'Dt Stop D'])
        ->and($trips[0]['seats_left'])->toBe(30);
    dtCheckout($this, $f, 'D')->assertSuccessful();
});

it('stops selling a stop once the Driver taps it', function () {
    $f = dtFixture();
    dtBusAt($f, 7.26);
    $f['trip']->forceFill(['last_acknowledged_stop_id' => $f['stops']['D']->stop_id, 'last_acknowledged_at' => now()])->save();

    expect(dtSearch($this, $f, 'D'))->toBe([]); // Batch 51 item 10: not listed once it cannot be booked
    dtCheckout($this, $f, 'D')->assertStatus(422);
});

it('stops selling a stop once the live position is past it, even if the Driver forgot to tap it', function () {
    $f = dtFixture();
    dtBusAt($f, 7.30); // past D (7.27), about 3 km

    expect(dtSearch($this, $f, 'D'))->toBe([]); // Batch 51 item 10: not listed once it cannot be booked
    dtCheckout($this, $f, 'D')->assertStatus(422);
});

it('sells nothing on a departed trip when the bus position is missing or old, and says nothing is known', function () {
    $f = dtFixture();

    expect(dtSearch($this, $f, 'D'))->toBe([]); // no position at all: not listed (Batch 51 item 10)

    dtBusAt($f, 7.15);
    Carbon::setTestNow(now()->addSeconds((int) config('tracking.stale_seconds') + 60));
    expect(app(TripProgressService::class)->stopIsAhead($f['trip']->fresh(), $f['stops']['D']->stop_id))->toBeFalse();
});

it('refunds a payment whose stop the bus passed while the passenger was paying', function () {
    $f = dtFixture();
    dtBusAt($f, 7.15);
    dtCheckout($this, $f, 'D')->assertSuccessful();

    // While paying, the Driver taps D.
    $f['trip']->forceFill(['last_acknowledged_stop_id' => $f['stops']['D']->stop_id, 'last_acknowledged_at' => now()])->save();
    app(PaymentService::class)->finalizeOnlinePayment(Payment::firstOrFail());

    expect(Payment::firstOrFail()->status)->toBe('needs_refund')
        ->and(Ticket::count())->toBe(0)
        ->and((int) $f['trip']->fresh()->current_seated_capacity)->toBe(0);
});

it('issues the ticket when the stop is still ahead at payment', function () {
    $f = dtFixture();
    dtBusAt($f, 7.15);
    dtCheckout($this, $f, 'D')->assertSuccessful();

    app(PaymentService::class)->finalizeOnlinePayment(Payment::firstOrFail());

    expect(Payment::firstOrFail()->status)->toBe('paid')->and(Ticket::count())->toBe(1);
});

it('hides a trip once it is completed or cancelled', function () {
    $f = dtFixture();
    dtBusAt($f, 7.15);
    $f['trip']->update(['status' => 'completed']);
    expect(dtSearch($this, $f, 'D'))->toBe([]);
    $f['trip']->update(['status' => 'cancelled']);
    expect(dtSearch($this, $f, 'D'))->toBe([]);
});

// ---------------------------------------------------------------- Batch 50 (user, 2026-11-08)

it('works out passed stops and a live ETA from the bus position, without any Driver tap', function () {
    $f = dtFixture();
    $f['trip']->forceFill(['last_acknowledged_stop_id' => null, 'last_acknowledged_at' => null])->save();
    dtBusAt($f, 7.15); // past A and B, before D

    $timeline = $this->getJson('/api/trips/'.$f['trip']->trip_id.'/timeline')->assertOk()->json('data');
    $rows = collect($timeline['stops'])->keyBy('name');
    expect($rows['Dt Stop A']['progress'])->toBe('passed')
        ->and($rows['Dt Stop B']['progress'])->toBe('passed')
        ->and($rows['Dt Stop D']['progress'])->toBe('current')
        ->and($rows['Dt Stop D']['eta_source'])->toBe('live')
        ->and($rows['Dt Stop E']['eta_source'])->toBe('live')
        ->and($timeline['refresh']['arrived'])->toBeFalse();

    // ~13.3 km to D at the 30 km/h fallback: about 27 minutes from 08:20.
    expect(Carbon::parse($rows['Dt Stop D']['eta'])->setTimezone('Asia/Manila')->format('H:i'))->toBe('08:46');
});

it('says the bus has arrived at its final destination when it is at the last stop, even once its position is old', function () {
    $f = dtFixture();
    dtBusAt($f, 7.15);
    dtCheckout($this, $f, 'D')->assertSuccessful();
    app(PaymentService::class)->finalizeOnlinePayment(Payment::firstOrFail());
    $ticket = Ticket::firstOrFail();
    $token = app(TicketTrackingService::class)->tokenFor($ticket);
    dtBusAt($f, 7.3595); // now at E, the last stop

    $view = $this->getJson('/api/track/'.$token)->assertOk()->json('data');
    expect($view['state'])->toBe('arrived')
        ->and($view['message'])->toBe('The bus has arrived at its final destination, Dt Stop E.')
        ->and($view['passed_stop_ids'])->toContain($f['stops']['D']->stop_id);

    // Hours later the bus has sent nothing more (the simulation ended at the last stop): still arrived, not "old".
    Carbon::setTestNow(now()->addHours(2));
    expect($this->getJson('/api/track/'.$token)->assertOk()->json('data.state'))->toBe('arrived');

    $card = collect($this->getJson('/api/fleet/locations')->json('data'))->firstWhere('trip_id', $f['trip']->trip_id);
    if ($card) { // the PostgreSQL-only list; on SQLite the repository returns nothing
        expect($card->trip_progress['arrived'] ?? null)->toBeTrue();
    }
});

it('lists a departed trip from the next stop the bus has not reached when no start is chosen', function () {
    $f = dtFixture();
    dtBusAt($f, 7.15);

    $trips = $this->getJson('/api/booking/trips?date=2026-10-05')->assertOk()->json('data.trips');
    expect($trips)->toHaveCount(1)
        ->and($trips[0]['origin']['name'])->toBe('Dt Stop D')
        ->and($trips[0]['bookable'])->toBeTrue()
        ->and($trips[0]['progress']['label'])->toBe('On the way');
});

it('refuses to schedule a bus that has no fare in force', function () {
    $f = dtFixture();
    FareRule::query()->delete();
    $operator = User::where('role', 'operator')->firstOrFail();

    $this->actingAs($operator)->postJson('/api/operator/trips', [
        'fleet_route_id' => $f['trip']->fleet_route_id, 'trip_date' => '2026-10-06', 'departure_time' => '09:00', 'trip_type' => 'one_way',
    ])->assertStatus(422)->assertJsonPath('errors.fleet_route_id.0', 'This bus has no fare for this route on Oct 6, 2026, so its trips could not be sold. Add a fare rule for it first (Fleets, Fare rules), then schedule it.');
});

// Batch 51 item 10 (user, 2026-11-09): the homepage list (no start chosen) shows a bus on the road only while a stop
// ahead of it can be booked; a bus whose position is unknown or off its route is left out, the same for every company.
it('leaves a departed trip out of the homepage list when its position is unknown or off the route', function () {
    $f = dtFixture();

    expect($this->getJson('/api/booking/trips?date=2026-10-05')->assertOk()->json('data.trips'))->toBe([]); // no position

    app(FleetLocationRepository::class)->upsertLocation(['fleet_id' => $f['fleet']->fleet_id, 'trip_id' => $f['trip']->trip_id, 'latitude' => 7.15, 'longitude' => 125.05, 'accuracy' => 8.0]); // about 5.5 km off the line
    expect($this->getJson('/api/booking/trips?date=2026-10-05')->assertOk()->json('data.trips'))->toBe([]);

});
