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
use App\Support\PlaceGroups;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

// Batch 49 item 6 (user, 2026-11-07): the passenger stop list shows each place once, and picking it finds the buses of
// every company that serves it. Two companies each added their own copy of the same places (as on production, a few
// metres apart). Stop names and coordinates are SAMPLE test values.

function pgCompany(string $name, array $stops, string $departure): array
{
    $user = User::create(['username' => 'pg'.Str::random(8), 'email' => Str::random(8).'@example.com', 'password' => bcrypt('password123'), 'role' => 'operator']);
    StaffUser::create(['user_id' => $user->user_id, 'company_user_uuid' => (string) Str::uuid(), 'name' => $name.' Operator', 'phone_num' => '09170000071', 'address' => 'x']);
    $companyUserId = $user->fresh()->companyProfile->company_user_id;
    $route = Route::create(['route_name' => $name.' Route', 'origin' => 'Alpha', 'destination' => 'Gamma']);
    $made = [];
    foreach ($stops as $i => [$stopName, $lat, $km]) {
        $stop = Stop::create(['stop_name' => $stopName, 'latitude' => $lat, 'longitude' => 125.6, 'municipality' => 'Sample City']);
        RouteStop::create(['route_id' => $route->route_id, 'stop_id' => $stop->stop_id, 'stop_order' => $i + 1, 'distance_from_origin_km' => $km, 'stop_type' => $i === 0 || $i === count($stops) - 1 ? 'terminal' : 'stop', 'scheduled_offset_min' => $km]);
        $made[] = $stop;
    }
    $fleet = Fleet::create([
        'company_user_id' => $companyUserId, 'plate_number' => 'PG-'.fake()->unique()->numerify('####'),
        'bus_company_name' => $name, 'body_no' => '1', 'capacity' => 30, 'seated_capacity' => 30, 'standing_capacity' => 0, 'status' => 'active', 'vehicle_class' => 'aircon',
    ]);
    $fleetRoute = FleetRoute::create(['fleet_id' => $fleet->fleet_id, 'route_id' => $route->route_id, 'start_time' => '06:00', 'end_time' => '22:00', 'status' => 'active']);
    $trip = Trip::create([
        'fleet_route_id' => $fleetRoute->fleet_route_id, 'company_user_id' => $companyUserId, 'trip_date' => '2026-10-05', 'departure_time' => $departure,
        'trip_type' => 'one_way', 'leg_direction' => 'outbound', 'status' => 'scheduled', 'current_seated_capacity' => 0, 'current_standing_capacity' => 0, 'total_occupancy' => 0,
    ]);

    return ['stops' => $made, 'trip' => $trip];
}

/** Company A made the places first; Company B's copies are 0 to 9 m away, one spelled with other case and spaces. */
function pgTwoCompanies(): array
{
    $a = pgCompany('Alpha Lines', [['Alpha Terminal', 7.0500, 0], ['Beta Stop', 7.1000, 10], ['Gamma Terminal', 7.2000, 30]], '08:00');
    $b = pgCompany('Bravo Bus', [['alpha  terminal', 7.05005, 0], ['Beta Stop', 7.10008, 10], ['Gamma Terminal', 7.2000, 30]], '09:00');
    FareRule::create(['fleet_id' => null, 'vehicle_class' => 'aircon', 'service_type' => 'regular', 'seat_type' => 'seated', 'status' => 'active', 'base_fare' => 13, 'fare_per_km' => 2.25, 'base_distance_km' => 5, 'note' => 'SAMPLE']);

    return [$a, $b];
}

/** @return list<array<string, mixed>> */
function pgListedStops($test, ?int $origin = null): array
{
    $data = $test->getJson('/api/booking/stops'.($origin ? '?origin_stop_id='.$origin : ''))->assertOk()->json('data.groups');

    return collect($data)->flatMap(fn ($group) => $group['stops'])->values()->all();
}

beforeEach(function () {
    Carbon::setTestNow(Carbon::parse('2026-10-04 07:00:00', 'Asia/Manila'));
    Cache::flush();
});
afterEach(fn () => Carbon::setTestNow());

it('normalises names for grouping: case, spaces, accents and punctuation', function () {
    expect(PlaceGroups::normalize('  Sto. Niño  Terminal '))->toBe('sto nino terminal')
        ->and(PlaceGroups::normalize('ALPHA-terminal'))->toBe('alpha terminal');
});

it('lists each place once, named by its oldest stop, never once per company', function () {
    [$a] = pgTwoCompanies();

    $stops = pgListedStops($this);
    expect(collect($stops)->pluck('name')->sort()->values()->all())->toBe(['Alpha Terminal', 'Beta Stop', 'Gamma Terminal'])
        ->and(collect($stops)->firstWhere('name', 'Alpha Terminal')['stop_id'])->toBe($a['stops'][0]->stop_id)
        ->and(collect($stops)->firstWhere('name', 'Alpha Terminal')['route_ids'])->toHaveCount(2);
});

it('offers the destinations of every company serving the chosen place', function () {
    [$a] = pgTwoCompanies();

    $destinations = pgListedStops($this, $a['stops'][0]->stop_id);
    expect(collect($destinations)->pluck('name')->sort()->values()->all())->toBe(['Beta Stop', 'Gamma Terminal']);
});

it('keeps two far-apart places with the same name separate', function () {
    pgTwoCompanies();
    pgCompany('Far Line', [['Beta Stop', 7.5000, 0], ['Delta Terminal', 7.6000, 10]], '10:00');

    expect(collect(pgListedStops($this))->where('name', 'Beta Stop'))->toHaveCount(2);
});

it('finds the buses of both companies from one place, each with its own stop, company and fare', function () {
    [$a, $b] = pgTwoCompanies();

    $trips = $this->getJson('/api/booking/trips?from=alpha-terminal&to=gamma-terminal&date=2026-10-05')->assertOk()->json('data.trips');

    expect($trips)->toHaveCount(2)
        ->and(collect($trips)->pluck('trip_id')->all())->toBe([$a['trip']->trip_id, $b['trip']->trip_id])
        ->and($trips[1]['origin']['stop_id'])->toBe($b['stops'][0]->stop_id) // the exact stop of Bravo's bus
        ->and($trips[1]['bus_label'])->toContain('Bravo Bus')
        ->and($trips[1]['fare'])->not->toBeNull()
        ->and($trips[1]['seats_left'])->toBe(30);
});

it('resolves an older stop code in a link to its place', function () {
    [$a, $b] = pgTwoCompanies();

    $journey = $this->getJson('/api/booking/trips?from='.$b['stops'][0]->stop_code.'&to=gamma-terminal&date=2026-10-05')->assertOk()->json('data');
    expect($journey['from']['stop_id'])->toBe($a['stops'][0]->stop_id)
        ->and($journey['from']['name'])->toBe('Alpha Terminal')
        ->and($journey['trips'])->toHaveCount(2);
});

it('books the exact stop of the chosen bus when the passenger picked the place', function () {
    [$a, $b] = pgTwoCompanies();
    config(['services.paymongo.secret_key' => 'sk_test_dummy']);
    Http::fake(['api.paymongo.com/*' => fn () => Http::response(['data' => ['id' => 'cs_'.Str::random(12), 'attributes' => ['checkout_url' => 'https://pay.example/x', 'payment_intent' => ['id' => 'pi_'.Str::random(8)]]]])]);
    $user = User::create(['username' => 'pax'.Str::random(6), 'email' => Str::random(8).'@example.com', 'password' => bcrypt('password123'), 'role' => 'passenger']);
    $passenger = PassengerUser::create(['passenger_uuid' => (string) Str::uuid(), 'user_id' => $user->user_id, 'name' => 'Pax', 'phone_num' => '09170000002', 'address' => 'x', 'birthdate' => '2000-01-01']);

    // The timeline (fare) for Bravo's bus, asked with the place ids: it reports back the ids chosen.
    $timeline = $this->getJson('/api/trips/'.$b['trip']->trip_id.'/timeline?boarding_stop_id='.$a['stops'][0]->stop_id.'&alighting_stop_id='.$a['stops'][2]->stop_id.'&seat_type=seated')
        ->assertOk()->json('data');
    expect($timeline['journey']['boarding_stop_id'])->toBe($a['stops'][0]->stop_id)
        ->and($timeline['journey']['route_boarding_stop_id'])->toBe($b['stops'][0]->stop_id)
        ->and($timeline['fare']['amount'] ?? null)->not->toBeNull();

    $this->actingAs($user)->postJson('/api/passengers/checkout', [
        'booking_date' => '2026-10-05', 'payment_channel' => 'gcash',
        'items' => [['trip_id' => $b['trip']->trip_id, 'seat_type' => 'seated', 'origin_stop_id' => $a['stops'][0]->stop_id, 'destination_stop_id' => $a['stops'][2]->stop_id]],
    ])->assertSuccessful();

    $item = Payment::firstOrFail()->items_payload[0];
    expect($item['origin_stop_id'])->toBe($b['stops'][0]->stop_id)
        ->and($item['destination_stop_id'])->toBe($b['stops'][2]->stop_id)
        ->and(Ticket::count())->toBe(0)
        ->and($passenger->exists)->toBeTrue();
});
