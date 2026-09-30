<?php

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
use App\Repositories\RouteGeometryRepository;
use App\Services\TicketService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

uses(RefreshDatabase::class);

// Any route, any stops: this fixture is a straight line of five stops about 10 km
// apart. Coordinates and names are test data only.
function tlStaff(string $role): User
{
    $user = User::create([
        'username' => fake()->unique()->userName(),
        'email' => fake()->unique()->safeEmail(),
        'password' => bcrypt('password123'),
        'role' => $role,
    ]);
    StaffUser::create([
        'user_id' => $user->user_id,
        'company_user_id' => null,
        'company_user_uuid' => (string) Str::uuid(),
        'name' => fake()->name(),
        'phone_num' => fake()->phoneNumber(),
        'address' => fake()->address(),
    ]);

    return $user->fresh();
}

function tlFixture(bool $offsets = true): array
{
    $operator = tlStaff('operator');
    $route = Route::create(['route_name' => 'Timeline Route', 'origin' => 'A', 'destination' => 'E']);

    // [name, lat, lng, km, offset_min, type, municipality]
    $defs = [
        ['Stop A', 7.00, 125.0, 0, 0, 'terminal', 'Mati City'],
        ['Stop B', 7.09, 125.0, 10, 15, 'stop', 'Mati City'],
        ['Stop C', 7.18, 125.0, 20, 30, 'pass_through', 'Mabini'],
        ['Stop D', 7.27, 125.0, 30, 45, 'stop', 'Mabini'],
        ['Stop E', 7.36, 125.0, 40, 60, 'terminal', 'Digos City'],
    ];
    $stops = [];
    foreach ($defs as $i => [$name, $lat, $lng, $km, $offset, $type, $municipality]) {
        $stop = Stop::create(['stop_name' => $name, 'latitude' => $lat, 'longitude' => $lng, 'municipality' => $municipality]);
        RouteStop::create([
            'route_id' => $route->route_id,
            'stop_id' => $stop->stop_id,
            'stop_order' => $i + 1,
            'distance_from_origin_km' => $km,
            'stop_type' => $type,
            'scheduled_offset_min' => $offsets ? $offset : null,
        ]);
        $stops[$name] = $stop;
    }

    app(RouteGeometryRepository::class)->save($route->route_id, [
        'type' => 'LineString',
        'coordinates' => collect($defs)->map(fn ($d) => [$d[2], $d[1]])->all(),
    ]);

    $fleet = Fleet::create([
        'company_user_id' => $operator->companyProfile->company_user_id,
        'plate_number' => 'TL-'.fake()->unique()->numerify('###'),
        'capacity' => 40, 'seated_capacity' => 30, 'standing_capacity' => 10, 'status' => 'active',
    ]);
    FareRule::create(['fleet_id' => $fleet->fleet_id, 'base_fare' => 15, 'fare_per_km' => 2.35, 'status' => 'active', 'seat_type' => 'seated']);
    $fleetRoute = FleetRoute::create(['fleet_id' => $fleet->fleet_id, 'route_id' => $route->route_id, 'start_time' => '06:00', 'end_time' => '22:00', 'status' => 'active']);

    $makeTrip = fn (string $direction, string $status = 'scheduled') => Trip::create([
        'fleet_route_id' => $fleetRoute->fleet_route_id,
        'company_user_id' => $operator->companyProfile->company_user_id,
        'trip_date' => now('Asia/Manila')->toDateString(),
        'departure_time' => '08:00',
        'trip_type' => 'one_way',
        'leg_direction' => $direction,
        'status' => $status,
        'current_seated_capacity' => 0, 'current_standing_capacity' => 0, 'total_occupancy' => 0,
    ]);

    return [$route, $stops, $makeTrip, $operator];
}

it('serves the timeline in travel order with scheduled ETAs, via and bound-for from data', function () {
    [$route, $stops, $makeTrip] = tlFixture();
    $trip = $makeTrip('outbound');

    $data = $this->getJson("/api/trips/{$trip->trip_id}/timeline")->assertSuccessful()->json('data');

    expect(array_column($data['stops'], 'name'))->toBe(['Stop A', 'Stop B', 'Stop C', 'Stop D', 'Stop E']);
    expect(array_column($data['stops'], 'eta_time'))->toBe(['08:00', '08:15', '08:30', '08:45', '09:00']);
    expect(array_column($data['stops'], 'progress'))->each->toBe('upcoming');
    expect($data['header']['bound_for'])->toBe('Stop E');
    expect($data['header']['via'])->toBe(['Stop B', 'Stop D']); // pass-through Stop C is not "major"
    expect($data['stops'][1]['municipality'])->toBe('Mati City');
    expect($data['refresh']['eta_basis'])->toBe('scheduled');
    expect($data['bus']['class'])->toBeNull();
});

it('reverses order, distances and offsets on a reverse leg (B1)', function () {
    [, , $makeTrip] = tlFixture();
    $trip = $makeTrip('reverse');

    $data = $this->getJson("/api/trips/{$trip->trip_id}/timeline")->assertSuccessful()->json('data');

    expect(array_column($data['stops'], 'name'))->toBe(['Stop E', 'Stop D', 'Stop C', 'Stop B', 'Stop A']);
    expect(array_column($data['stops'], 'distance_km'))->toEqual([0, 10, 20, 30, 40]);
    expect(array_column($data['stops'], 'eta_time'))->toBe(['08:00', '08:15', '08:30', '08:45', '09:00']);
    expect($data['header']['bound_for'])->toBe('Stop A');
});

it('recomputes badges, duration, stop count, distance, fare and journey rows for a chosen alighting stop', function () {
    [$route, $stops, $makeTrip] = tlFixture();
    $trip = $makeTrip('outbound');

    $data = $this->getJson("/api/trips/{$trip->trip_id}/timeline?".http_build_query([
        'boarding_stop_id' => $stops['Stop B']->stop_id,
        'alighting_stop_id' => $stops['Stop E']->stop_id,
        'seat_type' => 'seated',
    ]))->assertSuccessful()->json('data');

    expect($data['header']['duration_minutes'])->toBe(45);
    expect($data['header']['stops_between'])->toBe(1); // Stop D; pass-through Stop C is not a stop
    expect($data['header']['distance_km'])->toEqual(30);
    expect($data['fare']['amount'])->toEqual(85.5); // 15 + 30 * 2.35

    $byName = collect($data['stops'])->keyBy('name');
    expect($byName['Stop B']['is_boarding'])->toBeTrue();
    expect($byName['Stop E']['is_alighting'])->toBeTrue();
    expect($byName['Stop A']['in_journey'])->toBeFalse();
    expect($byName['Stop C']['in_journey'])->toBeTrue();
    // Pass-through, boarding and earlier stops are never selectable.
    expect($byName['Stop C']['selectable'])->toBeFalse();
    expect($byName['Stop A']['selectable'])->toBeFalse();
    expect($byName['Stop B']['selectable'])->toBeFalse();
    expect($byName['Stop D']['selectable'])->toBeTrue();
    expect($byName['Stop E']['selectable'])->toBeTrue();
});

it('rejects invalid boarding and alighting choices', function () {
    [, $stops, $makeTrip] = tlFixture();
    $trip = $makeTrip('outbound');
    $url = "/api/trips/{$trip->trip_id}/timeline?";

    $this->getJson($url.http_build_query(['boarding_stop_id' => $stops['Stop B']->stop_id, 'alighting_stop_id' => $stops['Stop C']->stop_id]))
        ->assertStatus(422)->assertJsonValidationErrors(['alighting_stop_id']); // pass-through
    $this->getJson($url.http_build_query(['boarding_stop_id' => $stops['Stop D']->stop_id, 'alighting_stop_id' => $stops['Stop B']->stop_id]))
        ->assertStatus(422)->assertJsonValidationErrors(['alighting_stop_id']); // before boarding
    $this->getJson($url.http_build_query(['alighting_stop_id' => $stops['Stop D']->stop_id]))
        ->assertStatus(422)->assertJsonValidationErrors(['boarding_stop_id']);
    $this->getJson($url.http_build_query(['boarding_stop_id' => $stops['Stop A']->stop_id, 'alighting_stop_id' => $stops['Stop D']->stop_id, 'dropoff_lat' => 7.2, 'dropoff_lng' => 125.0]))
        ->assertStatus(422);
});

it('adjusts ETAs and row states from real progress once the trip is under way', function () {
    [, $stops, $makeTrip] = tlFixture();
    $trip = $makeTrip('outbound', 'departed');
    $trip->forceFill([
        'last_acknowledged_stop_id' => $stops['Stop B']->stop_id,
        // 10 minutes behind the 08:15 schedule.
        'last_acknowledged_at' => Carbon::parse(now('Asia/Manila')->toDateString().' 08:25', 'Asia/Manila'),
    ])->save();

    $data = $this->getJson("/api/trips/{$trip->trip_id}/timeline")->assertSuccessful()->json('data');
    $rows = collect($data['stops'])->keyBy('name');

    expect($rows['Stop A']['progress'])->toBe('passed');
    expect($rows['Stop B']['progress'])->toBe('passed');
    expect($rows['Stop C']['progress'])->toBe('current');
    expect($rows['Stop D']['progress'])->toBe('upcoming');
    expect($rows['Stop C']['eta_time'])->toBe('08:40');
    expect($rows['Stop D']['eta_time'])->toBe('08:55');
    expect($rows['Stop D']['eta_source'])->toBe('live');
    expect($data['refresh']['eta_basis'])->toBe('live');
});

it('shows ETA unavailable instead of inventing times when offsets are missing', function () {
    [, , $makeTrip] = tlFixture(offsets: false);
    $trip = $makeTrip('outbound');

    $data = $this->getJson("/api/trips/{$trip->trip_id}/timeline")->assertSuccessful()->json('data');

    expect(array_column($data['stops'], 'eta'))->each->toBeNull();
    expect($data['refresh']['eta_basis'])->toBe('scheduled');
});

it('places a custom drop-off with an interpolated ETA and the same fare the ticket is priced at', function () {
    [$route, $stops, $makeTrip] = tlFixture();
    $route->update(['allow_custom_dropoff' => true]);
    $trip = $makeTrip('outbound');
    $query = [
        'boarding_stop_id' => $stops['Stop B']->stop_id,
        'dropoff_lat' => 7.22, // between Stop C and Stop D, a few metres off the line
        'dropoff_lng' => 125.0003,
        'seat_type' => 'seated',
    ];

    $data = $this->getJson("/api/trips/{$trip->trip_id}/timeline?".http_build_query($query))->assertSuccessful()->json('data');

    $names = array_column($data['stops'], 'name');
    expect($names)->toBe(['Stop A', 'Stop B', 'Stop C', 'Custom drop-off', 'Stop D', 'Stop E']);
    $custom = collect($data['stops'])->firstWhere('custom', true);
    expect($custom['is_alighting'])->toBeTrue();
    expect($custom['eta_time'])->toBe('08:37');
    expect($data['journey']['custom_dropoff'])->toBeTrue();
    expect($data['fare']['amount'])->toBeGreaterThan(15);

    // Checkout re-derives the same price from the same pin, and stores the snapped point.
    $reserved = app(TicketService::class)->reserveAndPrice([
        'trip_id' => $trip->trip_id,
        'seat_type' => 'seated',
        'origin_stop_id' => $stops['Stop B']->stop_id,
        'destination_lat' => 7.22,
        'destination_lng' => 125.0003,
    ]);
    expect((float) $reserved['amount'])->toBe((float) $data['fare']['amount']);
    expect(abs($reserved['destination_lng'] - 125.0))->toBeLessThan(0.00001);
});

it('enforces every custom drop-off rule on the server', function () {
    [$route, $stops, $makeTrip] = tlFixture();
    $trip = $makeTrip('outbound');
    $base = ['boarding_stop_id' => $stops['Stop B']->stop_id, 'seat_type' => 'seated'];
    $call = fn (array $pin) => $this->getJson("/api/trips/{$trip->trip_id}/timeline?".http_build_query($base + $pin));

    // Per-route flag (default off).
    $call(['dropoff_lat' => 7.22, 'dropoff_lng' => 125.0])->assertStatus(422)->assertJsonValidationErrors(['dropoff']);

    $route->update(['allow_custom_dropoff' => true]);
    $call(['dropoff_lat' => 7.22, 'dropoff_lng' => 125.0])->assertSuccessful();
    // Farther than the snap threshold from the route line (about 1.1 km east).
    $call(['dropoff_lat' => 7.22, 'dropoff_lng' => 125.01])->assertStatus(422)->assertJsonValidationErrors(['dropoff']);
    // Behind the boarding point, and too near it.
    $call(['dropoff_lat' => 7.04, 'dropoff_lng' => 125.0])->assertStatus(422)->assertJsonValidationErrors(['dropoff']);
    $call(['dropoff_lat' => 7.092, 'dropoff_lng' => 125.0])->assertStatus(422)->assertJsonValidationErrors(['dropoff']);
    // Too close to an existing stop.
    $call(['dropoff_lat' => 7.2705, 'dropoff_lng' => 125.0])->assertStatus(422)->assertJsonValidationErrors(['dropoff']);
    // Outside the configured region.
    config(['region.bbox' => ['min_lat' => 10, 'max_lat' => 11, 'min_lng' => 122, 'max_lng' => 123]]);
    $call(['dropoff_lat' => 7.22, 'dropoff_lng' => 125.0])->assertStatus(422)->assertJsonValidationErrors(['dropoff']);
});

it('re-validates a tampered custom drop-off at checkout', function () {
    [$route, $stops, $makeTrip] = tlFixture();
    $trip = $makeTrip('outbound');
    $item = ['trip_id' => $trip->trip_id, 'seat_type' => 'seated', 'origin_stop_id' => $stops['Stop B']->stop_id, 'destination_lat' => 7.22, 'destination_lng' => 125.0];

    // Route flag off.
    expect(fn () => app(TicketService::class)->reserveAndPrice($item))->toThrow(ValidationException::class);

    $route->update(['allow_custom_dropoff' => true]);
    // A pin far from the route, with a client-supplied price that must be ignored.
    $tampered = array_merge($item, ['destination_lng' => 125.05, 'amount' => 1]);
    expect(fn () => app(TicketService::class)->reserveAndPrice($tampered))->toThrow(ValidationException::class);
    expect(Trip::find($trip->trip_id)->current_seated_capacity)->toBe(0);
});

it('lets operators and admins switch custom drop-off per route, and nobody else', function () {
    [$route] = tlFixture();
    $operator = tlStaff('operator');
    $passenger = User::create(['username' => 'pax', 'email' => 'pax@example.com', 'password' => bcrypt('x'), 'role' => 'passenger']);

    $this->actingAs($passenger)->patchJson("/api/operator/routes/{$route->route_id}/custom-dropoff", ['allow_custom_dropoff' => true])->assertForbidden();
    $this->actingAs($operator)->patchJson("/api/operator/routes/{$route->route_id}/custom-dropoff", ['allow_custom_dropoff' => true])->assertSuccessful();
    expect($route->fresh()->allow_custom_dropoff)->toBeTrue();
    $this->actingAs($operator)->patchJson("/api/operator/routes/{$route->route_id}/custom-dropoff", ['allow_custom_dropoff' => 'maybe'])->assertStatus(422);
});

it('shows a custom drop-off to the driver as a requested stop and on the conductor manifest', function () {
    [$route, $stops, $makeTrip, $operator] = tlFixture();
    $driver = tlStaff('driver');
    $trip = $makeTrip('outbound');
    $trip->forceFill(['driver_id' => $driver->companyProfile->company_user_id])->save();

    $payment = Payment::create([
        'amount' => 60,
        'payment_created' => now(),
        'transaction_reference' => 'TXN-TL-'.strtoupper(substr((string) Str::uuid(), 0, 8)),
        'payment_method' => 'online',
        'payment_channel' => 'gcash',
        'status' => 'paid',
        'payment_uuid' => (string) Str::uuid(),
        'is_valid' => true,
        'items_payload' => [],
    ]);

    $ticket = Ticket::create([
        'payment_id' => $payment->payment_id,
        'fleet_route_id' => $trip->fleet_route_id,
        'trip_id' => $trip->trip_id,
        'ticket_uuid' => (string) Str::uuid(),
        'status' => 'valid',
        'amount' => 60,
        'distance_km' => 20,
        'seat_type' => 'seated',
        'origin_stop_id' => $stops['Stop B']->stop_id,
        'destination_lat' => 7.22,
        'destination_lng' => 125.0,
        'destination_label' => 'Barangay Hall',
    ]);

    $payload = app(App\Services\DriverNavigationService::class)->getCurrentTripWithStops($driver->companyProfile->company_user_id);

    expect($payload['requested_stops'])->toHaveCount(1);
    expect($payload['requested_stops'][0]['label'])->toBe('Barangay Hall');
    expect($payload['requested_stops'][0]['passenger_count'])->toBe(1);
    expect($payload['requested_stops'][0]['after_stop_sequence'])->toBe(3); // after Stop C, before Stop D

    $manifest = app(App\Services\OccupancyService::class)->getCurrentPassengers($trip->trip_id);
    expect($manifest['passengers'][0]['requested_stop'])->toBeTrue();
    expect($manifest['passengers'][0]['destination_stop'])->toBe('Requested stop: Barangay Hall');
});