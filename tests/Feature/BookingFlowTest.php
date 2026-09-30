<?php

use App\Models\FareRule;
use App\Models\Fleet;
use App\Models\FleetRoute;
use App\Models\Route;
use App\Models\RouteStop;
use App\Models\StaffUser;
use App\Models\Stop;
use App\Models\Trip;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

// Self-contained fixture: a route defined only by data. Names and coordinates are test data.
function bkOperator(): User
{
    $user = User::create([
        'username' => fake()->unique()->userName(),
        'email' => fake()->unique()->safeEmail(),
        'password' => bcrypt('password123'),
        'role' => 'operator',
    ]);
    StaffUser::create([
        'user_id' => $user->user_id,
        'company_user_uuid' => (string) Str::uuid(),
        'name' => fake()->name(),
        'phone_num' => fake()->phoneNumber(),
        'address' => fake()->address(),
    ]);

    return $user->fresh();
}

/** @param array<int, array{0:string,1:float,2:float,3:float,4:int,5:string,6:?string}> $defs name, lat, lng, km, offset, type, municipality */
function bkRoute(array $defs, string $name = 'Booking Route', bool $activeFleet = true, int $seated = 30): array
{
    $operator = bkOperator();
    $route = Route::create(['route_name' => $name, 'origin' => 'first', 'destination' => 'last']);
    $stops = [];
    foreach ($defs as $i => [$stopName, $lat, $lng, $km, $offset, $type, $municipality]) {
        $stop = Stop::create(['stop_name' => $stopName, 'latitude' => $lat, 'longitude' => $lng, 'municipality' => $municipality]);
        RouteStop::create(['route_id' => $route->route_id, 'stop_id' => $stop->stop_id, 'stop_order' => $i + 1, 'distance_from_origin_km' => $km, 'stop_type' => $type, 'scheduled_offset_min' => $offset]);
        $stops[$stopName] = $stop;
    }
    $fleet = Fleet::create([
        'company_user_id' => $operator->companyProfile->company_user_id,
        'plate_number' => 'BK-'.fake()->unique()->numerify('####'),
        'capacity' => $seated + 10, 'seated_capacity' => $seated, 'standing_capacity' => 10, 'status' => 'active',
    ]);
    FareRule::create(['fleet_id' => $fleet->fleet_id, 'base_fare' => 15, 'fare_per_km' => 2, 'status' => 'active', 'seat_type' => 'seated']);
    $fleetRoute = FleetRoute::create(['fleet_id' => $fleet->fleet_id, 'route_id' => $route->route_id, 'start_time' => '06:00', 'end_time' => '22:00', 'status' => $activeFleet ? 'active' : 'inactive']);

    return [$route, $stops, $fleetRoute, $operator];
}

function bkTrip(FleetRoute $fleetRoute, User $operator, string $date, string $time, string $direction = 'outbound', int $seatedTaken = 0): Trip
{
    return Trip::create([
        'fleet_route_id' => $fleetRoute->fleet_route_id,
        'company_user_id' => $operator->companyProfile->company_user_id,
        'trip_date' => $date,
        'departure_time' => $time,
        'trip_type' => 'one_way',
        'leg_direction' => $direction,
        'status' => 'scheduled',
        'current_seated_capacity' => $seatedTaken, 'current_standing_capacity' => 0, 'total_occupancy' => $seatedTaken,
    ]);
}

$eastWest = [
    ['E1', 7.00, 125.0, 0, 0, 'terminal', 'Mati City'],
    ['E2', 7.09, 125.0, 10, 15, 'stop', 'Mati City'],
    ['E3', 7.18, 125.0, 20, 30, 'pass_through', 'Mabini'],
    ['E4', 7.27, 125.0, 30, 45, 'stop', 'Mabini'],
    ['E5', 7.36, 125.0, 40, 60, 'terminal', null],
];

beforeEach(function () {
    // Sunday 2026-10-04, 07:00 in Manila.
    Carbon::setTestNow(Carbon::parse('2026-10-04 07:00:00', 'Asia/Manila'));
    Cache::flush();
});

afterEach(fn () => Carbon::setTestNow());

it('lists destinations from data, grouped by municipality, with no code that knows a place', function () use ($eastWest) {
    [$routeA] = bkRoute($eastWest);
    // A second route elsewhere; a third with no active fleet must stay out.
    bkRoute([
        ['W1', 6.75, 125.36, 0, 0, 'terminal', 'Digos City'],
        ['W2', 6.90, 125.85, 40, 60, 'terminal', 'Mati City'],
        ['W3', 6.95, 126.20, 50, 80, 'stop', 'Compostela'],
    ], 'Other Route');
    bkRoute([['X1', 7.0, 125.5, 0, 0, 'terminal', 'Asuncion'], ['X2', 7.1, 125.6, 5, 10, 'terminal', 'Asuncion']], 'Idle Route', activeFleet: false);
    // Outside the configured region: excluded.
    bkRoute([['Z1', 12.0, 120.0, 0, 0, 'terminal', 'Nowhere'], ['Z2', 12.1, 120.1, 5, 10, 'terminal', 'Nowhere']], 'Far Route');

    $groups = collect($this->getJson('/api/booking/stops')->assertSuccessful()->json('data.groups'));

    $names = $groups->flatMap(fn ($g) => collect($g['stops'])->pluck('name'))->sort()->values()->all();
    expect($names)->toBe(['E1', 'E2', 'E4', 'E5', 'W1', 'W2', 'W3']); // no E3 (pass-through), X*, Z*
    expect($groups->firstWhere('municipality', 'Mati City')['stops'])->toHaveCount(3); // E1, E2, W2 across two routes
    expect($groups->firstWhere('municipality', 'Mati City')['provinces'])->toBe(['Davao Oriental']);
    expect($groups->firstWhere('municipality', null)['stops'][0]['name'])->toBe('E5'); // no municipality recorded

    $fromE2 = collect($this->getJson('/api/booking/stops?origin_stop_id='.Stop::where('stop_name', 'E2')->value('stop_id'))->json('data.groups'))
        ->flatMap(fn ($g) => collect($g['stops'])->pluck('name'))->sort()->values()->all();
    expect($fromE2)->toBe(['E1', 'E4', 'E5']); // only stops on routes that serve the origin, never the origin itself
});

it('resolves Book Now to the next eligible departure from the Manila time now', function () use ($eastWest) {
    [, $stops, $fleetRoute, $operator] = bkRoute($eastWest);
    bkTrip($fleetRoute, $operator, '2026-10-04', '06:00'); // boards E2 at 06:15: already gone
    $next = bkTrip($fleetRoute, $operator, '2026-10-04', '08:00'); // boards E2 at 08:15
    bkTrip($fleetRoute, $operator, '2026-10-04', '10:00');
    bkTrip($fleetRoute, $operator, '2026-10-04', '09:00', 'reverse'); // wrong direction for E2 -> E4

    $data = $this->getJson('/api/booking/resolve?'.http_build_query([
        'mode' => 'now', 'origin_stop_id' => $stops['E2']->stop_id, 'destination_stop_id' => $stops['E4']->stop_id,
    ]))->assertSuccessful()->json('data');

    expect($data['trip']['trip_id'])->toBe($next->trip_id);
    expect($data['trip']['boarding_time'])->toBe('08:15');
    expect($data['trip']['seats_left'])->toBe(30);
});

it('resolves Book Later on or after the chosen Manila date and time, and validates the window', function () use ($eastWest) {
    [, $stops, $fleetRoute, $operator] = bkRoute($eastWest);
    bkTrip($fleetRoute, $operator, '2026-10-05', '07:00');
    $later = bkTrip($fleetRoute, $operator, '2026-10-05', '11:00');
    $q = ['mode' => 'later', 'origin_stop_id' => $stops['E2']->stop_id, 'destination_stop_id' => $stops['E4']->stop_id];

    $data = $this->getJson('/api/booking/resolve?'.http_build_query($q + ['date' => '2026-10-05', 'time' => '09:00']))
        ->assertSuccessful()->json('data');
    expect($data['trip']['trip_id'])->toBe($later->trip_id); // 07:15 is before 09:00

    // Past, missing and too far ahead.
    $this->getJson('/api/booking/resolve?'.http_build_query($q + ['date' => '2026-10-04', 'time' => '06:00']))->assertStatus(422)->assertJsonValidationErrors(['date']);
    $this->getJson('/api/booking/resolve?'.http_build_query($q))->assertStatus(422);
    $this->getJson('/api/booking/resolve?'.http_build_query($q + ['date' => '2026-12-31', 'time' => '09:00']))->assertStatus(422)->assertJsonValidationErrors(['date']);
    config(['booking.max_advance_days' => 400]);
    $this->getJson('/api/booking/resolve?'.http_build_query($q + ['date' => '2026-12-31', 'time' => '09:00']))->assertSuccessful();
});

it('says so when nothing is eligible: full, or nothing runs', function () use ($eastWest) {
    [, $stops, $fleetRoute, $operator] = bkRoute($eastWest, seated: 2);
    bkTrip($fleetRoute, $operator, '2026-10-04', '08:00', seatedTaken: 2); // full
    $q = ['mode' => 'now', 'origin_stop_id' => $stops['E2']->stop_id, 'destination_stop_id' => $stops['E4']->stop_id];

    $data = $this->getJson('/api/booking/resolve?'.http_build_query($q))->assertSuccessful()->json('data');

    expect($data['trip'])->toBeNull();
    expect($data['message'])->toContain('No departures');
});

it('proxies address search restricted to the region, cached and never exposing a key', function () {
    Http::fake(['nominatim.openstreetmap.org/*' => Http::response([
        ['display_name' => 'Inside Place, Somewhere', 'lat' => '7.05', 'lon' => '125.6'],
        ['display_name' => 'Outside Place', 'lat' => '14.6', 'lon' => '121.0'],
    ])]);

    $first = $this->getJson('/api/booking/address-search?q=inside place')->assertSuccessful()->json('data.results');
    expect($first)->toHaveCount(1);
    expect($first[0]['label'])->toBe('Inside Place, Somewhere');
    expect($first[0])->toHaveKeys(['latitude', 'longitude']);

    $this->getJson('/api/booking/address-search?q=inside   PLACE')->assertSuccessful(); // same normalized query
    Http::assertSentCount(1);
    Http::assertSent(fn ($request) => str_contains($request->url(), 'bounded=1') && str_contains($request->url(), 'viewbox='));

    $this->getJson('/api/booking/address-search?q=ab')->assertStatus(422);
});

it('reports address search as unavailable instead of failing when the provider is down', function () {
    Http::fake(['nominatim.openstreetmap.org/*' => Http::response('down', 500)]);

    $this->getJson('/api/booking/address-search?q=anything here')->assertStatus(503);
});

it('rate limits the address search proxy', function () {
    Http::fake(['nominatim.openstreetmap.org/*' => Http::response([])]);

    foreach (range(1, 20) as $i) {
        $this->getJson('/api/booking/address-search?q=query '.$i)->assertSuccessful();
    }
    $this->getJson('/api/booking/address-search?q=one more')->assertStatus(429);
});


it('lists every eligible departure on a chosen date for Book Later, earliest first, and today only those still to come', function () use ($eastWest) {
    [, $stops, $fleetRoute, $operator] = bkRoute($eastWest);
    $q = ['origin_stop_id' => $stops['E2']->stop_id, 'destination_stop_id' => $stops['E4']->stop_id, 'seat_type' => 'seated'];

    bkTrip($fleetRoute, $operator, '2026-10-04', '06:00'); // E2 at 06:15, already gone (now 07:00)
    $todayLater = bkTrip($fleetRoute, $operator, '2026-10-04', '13:00');
    $b = bkTrip($fleetRoute, $operator, '2026-10-06', '12:00');
    $a = bkTrip($fleetRoute, $operator, '2026-10-06', '06:00');
    bkTrip($fleetRoute, $operator, '2026-10-06', '09:00', 'reverse'); // wrong direction
    bkTrip($fleetRoute, $operator, '2026-10-07', '06:00'); // another day

    $future = $this->getJson('/api/booking/departures?'.http_build_query($q + ['date' => '2026-10-06']))->assertSuccessful()->json('data');
    expect(array_column($future['departures'], 'trip_id'))->toBe([$a->trip_id, $b->trip_id]);
    expect(array_column($future['departures'], 'boarding_time'))->toBe(['06:15', '12:15']);
    expect($future['departures'][0]['seats_left'])->toBe(30);
    expect($future['message'])->toBeNull();

    $today = $this->getJson('/api/booking/departures?'.http_build_query($q + ['date' => '2026-10-04']))->assertSuccessful()->json('data');
    expect(array_column($today['departures'], 'trip_id'))->toBe([$todayLater->trip_id]);

    $none = $this->getJson('/api/booking/departures?'.http_build_query($q + ['date' => '2026-10-10']))->assertSuccessful()->json('data');
    expect($none['departures'])->toBe([]);
    expect($none['message'])->toContain('No departures');
});

it('validates the departures date on the Manila calendar', function () use ($eastWest) {
    [, $stops] = bkRoute($eastWest);
    $q = ['origin_stop_id' => $stops['E2']->stop_id, 'destination_stop_id' => $stops['E4']->stop_id];

    $this->getJson('/api/booking/departures?'.http_build_query($q + ['date' => '2026-10-03']))->assertStatus(422)->assertJsonValidationErrors(['date']);
    $this->getJson('/api/booking/departures?'.http_build_query($q + ['date' => '2026-12-31']))->assertStatus(422)->assertJsonValidationErrors(['date']);
    $this->getJson('/api/booking/departures?'.http_build_query($q + ['date' => 'tomorrow']))->assertStatus(422)->assertJsonValidationErrors(['date']);
    $this->getJson('/api/booking/departures?'.http_build_query(['origin_stop_id' => $stops['E2']->stop_id, 'destination_stop_id' => $stops['E2']->stop_id, 'date' => '2026-10-05']))->assertStatus(422);
    $this->getJson('/api/booking/departures?'.http_build_query($q + ['date' => '2026-10-04']))->assertSuccessful(); // today is allowed
});

it('tells the date picker today in Manila and the booking window', function () use ($eastWest) {
    bkRoute($eastWest);

    $meta = $this->getJson('/api/booking/stops')->assertSuccessful()->json('data.meta');

    expect($meta)->toBe(['today' => '2026-10-04', 'max_advance_days' => (int) config('booking.max_advance_days')]);
});


it('lists the next trips for a journey from a chosen date, filling across days, with times and the fare', function () use ($eastWest) {
    [, $stops, $fleetRoute, $operator] = bkRoute($eastWest);
    $q = ['origin_stop_id' => $stops['E2']->stop_id, 'destination_stop_id' => $stops['E4']->stop_id];

    bkTrip($fleetRoute, $operator, '2026-10-04', '06:00'); // E2 at 06:15, already gone (now 07:00)
    $a = bkTrip($fleetRoute, $operator, '2026-10-04', '08:00');
    $b = bkTrip($fleetRoute, $operator, '2026-10-04', '13:00');
    $c = bkTrip($fleetRoute, $operator, '2026-10-05', '06:00');
    $d = bkTrip($fleetRoute, $operator, '2026-10-06', '06:00');
    bkTrip($fleetRoute, $operator, '2026-10-05', '09:00', 'reverse'); // wrong direction
    bkTrip($fleetRoute, $operator, '2026-10-20', '06:00'); // beyond the search window

    $short = $this->getJson('/api/booking/trips?'.http_build_query($q + ['date' => '2026-10-04', 'limit' => 3]))->assertSuccessful()->json('data');
    // Today has two buses left; the list fills up from the next day.
    expect(array_column($short['trips'], 'trip_id'))->toBe([$a->trip_id, $b->trip_id, $c->trip_id]);
    expect($short['total'])->toBe(4);
    expect($short['trips'][0]['boarding_time'])->toBe('08:15');
    expect($short['trips'][0]['arrival_time'])->toBe('08:45');
    expect($short['trips'][0]['duration_minutes'])->toBe(30);
    expect($short['trips'][0]['seats_left'])->toBe(30);
    expect($short['trips'][0]['fare'])->toBeNumeric()->toBeGreaterThan(0);

    $all = $this->getJson('/api/booking/trips?'.http_build_query($q + ['date' => '2026-10-04']))->assertSuccessful()->json('data');
    expect(array_column($all['trips'], 'trip_id'))->toBe([$a->trip_id, $b->trip_id, $c->trip_id, $d->trip_id]);

    $none = $this->getJson('/api/booking/trips?'.http_build_query($q + ['date' => '2026-10-25']))->assertSuccessful()->json('data');
    expect($none['trips'])->toBe([]);
    expect($none['message'])->toContain('No departures');
});

it('validates the trips search: stops, date window and limit', function () use ($eastWest) {
    [, $stops] = bkRoute($eastWest);
    $q = ['origin_stop_id' => $stops['E2']->stop_id, 'destination_stop_id' => $stops['E4']->stop_id, 'date' => '2026-10-04'];
    $get = fn (array $over) => $this->getJson('/api/booking/trips?'.http_build_query(array_merge($q, $over)));

    $get(['date' => '2026-10-03'])->assertStatus(422)->assertJsonValidationErrors(['date']);
    $get(['date' => '2026-12-31'])->assertStatus(422)->assertJsonValidationErrors(['date']);
    $get(['date' => 'soon'])->assertStatus(422)->assertJsonValidationErrors(['date']);
    $get(['destination_stop_id' => $stops['E2']->stop_id])->assertStatus(422);
    $get(['origin_stop_id' => 99999])->assertStatus(422);
    $get(['limit' => 0])->assertStatus(422)->assertJsonValidationErrors(['limit']);
    $get(['limit' => 500])->assertStatus(422)->assertJsonValidationErrors(['limit']);
    $get([])->assertSuccessful();
});
