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

    expect($meta)->toBe([
        'today' => '2026-10-04',
        'max_advance_days' => (int) config('booking.max_advance_days'),
        'last_bookable_date' => Carbon::parse('2026-10-04')->addDays((int) config('booking.max_advance_days'))->toDateString(),
        'home_preview_limit' => (int) config('booking.home_preview_limit'),
        'trips_per_page' => (int) config('booking.trips_per_page'),
    ]);
});


it('lists the available trips of the chosen date only, earliest first, paginated, with times and the fare', function () use ($eastWest) {
    [, $stops, $fleetRoute, $operator] = bkRoute($eastWest);
    $q = ['from' => $stops['E2']->stop_code, 'to' => $stops['E4']->stop_code];

    bkTrip($fleetRoute, $operator, '2026-10-04', '06:00'); // E2 at 06:15, already gone (now 07:00)
    $a = bkTrip($fleetRoute, $operator, '2026-10-04', '08:00');
    $b = bkTrip($fleetRoute, $operator, '2026-10-04', '13:00');
    $c = bkTrip($fleetRoute, $operator, '2026-10-05', '06:00');
    $d = bkTrip($fleetRoute, $operator, '2026-10-05', '09:00');
    $e = bkTrip($fleetRoute, $operator, '2026-10-05', '12:00');
    bkTrip($fleetRoute, $operator, '2026-10-05', '10:00', 'reverse'); // wrong direction

    $today = $this->getJson('/api/booking/trips?'.http_build_query($q + ['date' => '2026-10-04']))->assertSuccessful()->json('data');
    expect(array_column($today['trips'], 'trip_id'))->toBe([$a->trip_id, $b->trip_id]); // never another day's trips
    expect($today['total'])->toBe(2);
    expect($today['next_available_date'])->toBeNull();
    expect($today['trips'][0]['boarding_time'])->toBe('08:15');
    expect($today['trips'][0]['arrival_time'])->toBe('08:45');
    expect($today['trips'][0]['duration_minutes'])->toBe(30);
    expect($today['trips'][0]['seats_left'])->toBe(30);
    expect($today['trips'][0]['fare'])->toBeNumeric()->toBeGreaterThan(0);
    expect($today['trips'][0]['origin']['name'])->toBe('E2');
    expect($today['trips'][0]['destination']['name'])->toBe('E4');

    $page1 = $this->getJson('/api/booking/trips?'.http_build_query($q + ['date' => '2026-10-05', 'per_page' => 2]))->assertSuccessful()->json('data');
    expect(array_column($page1['trips'], 'trip_id'))->toBe([$c->trip_id, $d->trip_id]);
    expect([$page1['page'], $page1['per_page'], $page1['total'], $page1['last_page']])->toBe([1, 2, 3, 2]);

    $page2 = $this->getJson('/api/booking/trips?'.http_build_query($q + ['date' => '2026-10-05', 'per_page' => 2, 'page' => 2]))->assertSuccessful()->json('data');
    expect(array_column($page2['trips'], 'trip_id'))->toBe([$e->trip_id]);

    // A page past the end is clamped to the last page rather than failing.
    $beyond = $this->getJson('/api/booking/trips?'.http_build_query($q + ['date' => '2026-10-05', 'per_page' => 2, 'page' => 9]))->assertSuccessful()->json('data');
    expect($beyond['page'])->toBe(2);
});

it('uses the Manila date for the default day, across the UTC midnight', function () use ($eastWest) {
    // 00:30 on Monday 2026-10-05 in Manila is still Sunday 16:30 in UTC.
    Carbon::setTestNow(Carbon::parse('2026-10-05 00:30:00', 'Asia/Manila'));
    [, $stops, $fleetRoute, $operator] = bkRoute($eastWest);
    $q = ['from' => $stops['E2']->stop_code, 'to' => $stops['E4']->stop_code];
    bkTrip($fleetRoute, $operator, '2026-10-04', '08:00');
    $monday = bkTrip($fleetRoute, $operator, '2026-10-05', '08:00');

    $data = $this->getJson('/api/booking/trips?'.http_build_query($q))->assertSuccessful()->json('data');

    expect($data['date'])->toBe('2026-10-05');
    expect(array_column($data['trips'], 'trip_id'))->toBe([$monday->trip_id]);
    // Sunday has already ended in Manila.
    $this->getJson('/api/booking/trips?'.http_build_query($q + ['date' => '2026-10-04']))->assertStatus(422)->assertJsonValidationErrors(['date']);
});

it('offers the next available date when the chosen date has no departures', function () use ($eastWest) {
    [, $stops, $fleetRoute, $operator] = bkRoute($eastWest);
    $q = ['from' => $stops['E2']->stop_code, 'to' => $stops['E4']->stop_code];
    bkTrip($fleetRoute, $operator, '2026-10-06', '12:00', 'reverse'); // wrong direction: not a match
    $full = bkTrip($fleetRoute, $operator, '2026-10-07', '08:00', seatedTaken: 30); // full: not a match
    $next = bkTrip($fleetRoute, $operator, '2026-10-09', '08:00');
    bkTrip($fleetRoute, $operator, '2026-10-12', '08:00');
    bkTrip($fleetRoute, $operator, '2026-12-30', '08:00'); // beyond the booking window

    $data = $this->getJson('/api/booking/trips?'.http_build_query($q + ['date' => '2026-10-05']))->assertSuccessful()->json('data');

    expect($data['trips'])->toBe([]);
    expect($data['next_available_date'])->toBe('2026-10-09');
    expect($data['message'])->toContain('No departures');
    expect($full->trip_id)->not->toBe($next->trip_id);

    $last = $this->getJson('/api/booking/trips?'.http_build_query($q + ['date' => '2026-10-13']))->assertSuccessful()->json('data');
    expect($last['next_available_date'])->toBeNull(); // the 12-30 trip is outside the window
});

it('applies the same eligibility as booking: status, active fleet, direction, seats', function () use ($eastWest) {
    [, $stops, $fleetRoute, $operator] = bkRoute($eastWest, seated: 2);
    $q = ['from' => $stops['E2']->stop_code, 'to' => $stops['E4']->stop_code, 'date' => '2026-10-06'];
    $ok = bkTrip($fleetRoute, $operator, '2026-10-06', '08:00');
    $cancelled = bkTrip($fleetRoute, $operator, '2026-10-06', '09:00');
    $cancelled->update(['status' => 'cancelled']);
    bkTrip($fleetRoute, $operator, '2026-10-06', '10:00', seatedTaken: 2); // full

    $data = $this->getJson('/api/booking/trips?'.http_build_query($q))->assertSuccessful()->json('data');
    expect(array_column($data['trips'], 'trip_id'))->toBe([$ok->trip_id]);

    // The same trip is the one Book Later resolves.
    $resolved = $this->getJson('/api/booking/resolve?'.http_build_query(['mode' => 'later', 'date' => '2026-10-06', 'time' => '00:00', 'origin_stop_id' => $stops['E2']->stop_id, 'destination_stop_id' => $stops['E4']->stop_id]))->assertSuccessful()->json('data.trip.trip_id');
    expect($resolved)->toBe($ok->trip_id);

    $fleetRoute->update(['status' => 'inactive']);
    expect($this->getJson('/api/booking/trips?'.http_build_query($q))->json('data.trips'))->toBe([]);
});

it('lists today end to end before any stop is chosen, and narrows live with one stop', function () use ($eastWest) {
    [, $stops, $fleetRoute, $operator] = bkRoute($eastWest);
    $trip = bkTrip($fleetRoute, $operator, '2026-10-04', '08:00');

    $all = $this->getJson('/api/booking/trips')->assertSuccessful()->json('data');
    expect($all['date'])->toBe('2026-10-04');
    expect(array_column($all['trips'], 'trip_id'))->toBe([$trip->trip_id]);
    // End to end: the first and last stopping points (E3 is a pass-through), boarding at the first stop's offset.
    expect($all['trips'][0]['origin']['name'])->toBe('E1');
    expect($all['trips'][0]['destination']['name'])->toBe('E5');
    expect($all['trips'][0]['duration_minutes'])->toBe(60);

    $fromE2 = $this->getJson('/api/booking/trips?from='.$stops['E2']->stop_code)->json('data');
    expect($fromE2['trips'][0]['origin']['name'])->toBe('E2');
    expect($fromE2['trips'][0]['destination']['name'])->toBe('E5');
    expect($fromE2['trips'][0]['boarding_time'])->toBe('08:15');

    // From the last stop there is nowhere to go: no match, not an error.
    $fromEnd = $this->getJson('/api/booking/trips?from='.$stops['E5']->stop_code)->assertSuccessful()->json('data');
    expect($fromEnd['trips'])->toBe([]);
});

it('validates the trips search: stops, date window, paging', function () use ($eastWest) {
    [, $stops] = bkRoute($eastWest);
    $q = ['from' => $stops['E2']->stop_code, 'to' => $stops['E4']->stop_code, 'date' => '2026-10-04'];
    $get = fn (array $over) => $this->getJson('/api/booking/trips?'.http_build_query(array_merge($q, $over)));

    $get(['date' => '2026-10-03'])->assertStatus(422)->assertJsonValidationErrors(['date']);
    $get(['date' => '2026-12-31'])->assertStatus(422)->assertJsonValidationErrors(['date']);
    $get(['date' => 'soon'])->assertStatus(422)->assertJsonValidationErrors(['date']);
    $get(['to' => $stops['E2']->stop_code])->assertStatus(422)->assertJsonValidationErrors(['to']);
    $get(['from' => 'no-such-stop'])->assertStatus(422)->assertJsonValidationErrors(['from']);
    $get(['to' => 'Bad Code!'])->assertStatus(422)->assertJsonValidationErrors(['to']);
    $get(['from' => '12', 'to' => ''])->assertStatus(422)->assertJsonValidationErrors(['from']); // an id is not a code
    $get(['per_page' => 0])->assertStatus(422)->assertJsonValidationErrors(['per_page']);
    $get(['per_page' => 500])->assertStatus(422)->assertJsonValidationErrors(['per_page']);
    $get(['page' => 0])->assertStatus(422)->assertJsonValidationErrors(['page']);
    $get([])->assertSuccessful();
});

// Batch 29, issue 4: ONE booking window, from one config value, used by the client picker and every server check.
it('returns the booking window from one place, and a config change moves it', function () use ($eastWest) {
    bkRoute($eastWest);

    $window = $this->getJson('/api/booking/window')->assertSuccessful()->json('data');
    expect($window)->toBe(['today' => '2026-10-04', 'max_advance_days' => 30, 'last_bookable_date' => '2026-11-03']);

    $meta = $this->getJson('/api/booking/stops')->assertSuccessful()->json('data.meta');
    expect($meta['last_bookable_date'])->toBe('2026-11-03');

    config(['booking.max_advance_days' => 45]);
    $changed = $this->getJson('/api/booking/window')->assertSuccessful()->json('data');
    expect($changed['max_advance_days'])->toBe(45);
    expect($changed['last_bookable_date'])->toBe('2026-11-18');
    expect($this->getJson('/api/booking/stops')->json('data.meta.last_bookable_date'))->toBe('2026-11-18');
});

it('accepts the last bookable day, rejects the day after, and follows a config change', function () use ($eastWest) {
    [, $stops] = bkRoute($eastWest);
    $q = ['mode' => 'later', 'origin_stop_id' => $stops['E2']->stop_id, 'destination_stop_id' => $stops['E4']->stop_id, 'time' => '09:00'];
    $resolve = fn (string $date) => $this->getJson('/api/booking/resolve?'.http_build_query($q + ['date' => $date]));

    $resolve('2026-11-03')->assertSuccessful(); // today + 30: inclusive
    $day_after = $resolve('2026-11-04')->assertStatus(422)->assertJsonValidationErrors(['date']);
    expect($day_after->json('errors.date.0'))->toContain('up to 30 days ahead')->toContain('Nov 3, 2026');

    config(['booking.max_advance_days' => 10]);
    $resolve('2026-10-14')->assertSuccessful();
    $resolve('2026-10-15')->assertStatus(422)->assertJsonValidationErrors(['date']);
});

it('moves the last bookable day with Manila midnight, not UTC', function () use ($eastWest) {
    [, $stops] = bkRoute($eastWest);
    $q = ['mode' => 'later', 'origin_stop_id' => $stops['E2']->stop_id, 'destination_stop_id' => $stops['E4']->stop_id, 'time' => '23:00'];

    // 23:30 on the 4th in Manila (15:30 UTC): still the 4th.
    Carbon::setTestNow(Carbon::parse('2026-10-04 23:30:00', 'Asia/Manila'));
    expect($this->getJson('/api/booking/window')->json('data.last_bookable_date'))->toBe('2026-11-03');

    // 00:30 on the 5th in Manila is 16:30 UTC on the 4th: the window must already have moved.
    Carbon::setTestNow(Carbon::parse('2026-10-05 00:30:00', 'Asia/Manila'));
    expect($this->getJson('/api/booking/window')->json('data.today'))->toBe('2026-10-05');
    expect($this->getJson('/api/booking/window')->json('data.last_bookable_date'))->toBe('2026-11-04');
    $this->getJson('/api/booking/resolve?'.http_build_query($q + ['date' => '2026-11-04']))->assertSuccessful();
    $this->getJson('/api/booking/resolve?'.http_build_query($q + ['date' => '2026-11-05']))->assertStatus(422);
});
