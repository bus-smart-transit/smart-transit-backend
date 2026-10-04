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
use App\Models\Trip;
use App\Models\User;
use App\Repositories\RouteGeometryRepository;
use App\Services\TicketService;
use App\Support\BusLabel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

// Batch 28, item 2: passengers see "{bus_company_name} - {body_no}"; the plate stays on staff screens.

function blStaff(string $role): User
{
    $user = User::create(['username' => $role.Str::random(6), 'email' => Str::random(8).'@example.com', 'password' => bcrypt('password123'), 'role' => $role]);
    StaffUser::create(['user_id' => $user->user_id, 'company_user_uuid' => (string) Str::uuid(), 'name' => ucfirst($role), 'phone_num' => '0917', 'address' => 'x']);

    return $user->fresh();
}

/** One two-stop route, one fleet with the given label fields, one trip today at 08:00 (Manila). */
function blFixture(array $fleetLabel = []): array
{
    $operator = blStaff('operator');
    $route = Route::create(['route_name' => 'Label Route', 'origin' => 'A', 'destination' => 'B']);
    $stops = [];
    foreach ([['Stop A', 7.00, 0, 0, 'terminal'], ['Stop B', 7.09, 10, 15, 'terminal']] as $i => [$name, $lat, $km, $offset, $type]) {
        $stop = Stop::create(['stop_name' => $name, 'latitude' => $lat, 'longitude' => 125.0, 'municipality' => 'Mati City']);
        RouteStop::create(['route_id' => $route->route_id, 'stop_id' => $stop->stop_id, 'stop_order' => $i + 1, 'distance_from_origin_km' => $km, 'stop_type' => $type, 'scheduled_offset_min' => $offset]);
        $stops[$name] = $stop;
    }
    app(RouteGeometryRepository::class)->save($route->route_id, ['type' => 'LineString', 'coordinates' => [[125.0, 7.00], [125.0, 7.09]]]);

    $fleet = Fleet::create(array_merge([
        'company_user_id' => $operator->companyProfile->company_user_id,
        'plate_number' => 'SECRET-PLATE-'.fake()->unique()->numerify('###'),
        'capacity' => 40, 'seated_capacity' => 30, 'standing_capacity' => 10, 'status' => 'active',
    ], $fleetLabel));
    $fare = FareRule::create(['fleet_id' => $fleet->fleet_id, 'base_fare' => 15, 'fare_per_km' => 2, 'status' => 'active', 'seat_type' => 'seated']);
    $fleetRoute = FleetRoute::create(['fleet_id' => $fleet->fleet_id, 'route_id' => $route->route_id, 'start_time' => '06:00', 'end_time' => '22:00', 'status' => 'active']);
    $trip = Trip::create([
        'fleet_route_id' => $fleetRoute->fleet_route_id, 'company_user_id' => $operator->companyProfile->company_user_id,
        'trip_date' => '2026-10-04', 'departure_time' => '08:00', 'trip_type' => 'one_way', 'leg_direction' => 'outbound', 'status' => 'scheduled',
        'current_seated_capacity' => 0, 'current_standing_capacity' => 0, 'total_occupancy' => 0,
    ]);

    return [$trip, $stops, $fleet, $fare, $operator];
}

beforeEach(fn () => Carbon::setTestNow(Carbon::parse('2026-10-04 07:00:00', 'Asia/Manila')));
afterEach(fn () => Carbon::setTestNow());

it('builds the label only when both parts exist, and never from the plate', function () {
    expect(BusLabel::compose('Davao Bus', 'B-014'))->toBe('Davao Bus - B-014');
    expect(BusLabel::compose('  Davao Bus ', ' 14 '))->toBe('Davao Bus - 14');
    expect(BusLabel::compose('Davao Bus', null))->toBeNull();
    expect(BusLabel::compose(null, 'B-014'))->toBeNull();
    expect(BusLabel::compose('', '  '))->toBeNull();
    expect(BusLabel::for(null))->toBeNull();
});

it('puts the label, not the plate, on the timeline', function () {
    [$trip] = blFixture(['bus_company_name' => 'Davao Bus', 'body_no' => 'B-014']);
    $res = $this->getJson("/api/trips/{$trip->trip_id}/timeline")->assertSuccessful();

    expect($res->json('data.bus.label'))->toBe('Davao Bus - B-014');
    expect($res->getContent())->not->toContain('SECRET-PLATE');
});

it('reports a missing label as null on the timeline instead of inventing one', function () {
    [$trip] = blFixture(['bus_company_name' => 'Davao Bus']); // no body number
    $res = $this->getJson("/api/trips/{$trip->trip_id}/timeline")->assertSuccessful();

    expect($res->json('data.bus.label'))->toBeNull();
    expect($res->getContent())->not->toContain('SECRET-PLATE');
});

it('puts the label, not the plate, on booking results', function () {
    [$trip, $stops] = blFixture(['bus_company_name' => 'Davao Bus', 'body_no' => 'B-014']);
    $res = $this->getJson('/api/booking/resolve?'.http_build_query([
        'mode' => 'now', 'origin_stop_id' => $stops['Stop A']->stop_id, 'destination_stop_id' => $stops['Stop B']->stop_id,
    ]))->assertSuccessful();

    expect($res->json('data.trip.bus_label'))->toBe('Davao Bus - B-014');
    expect($res->getContent())->not->toContain('SECRET-PLATE');
});

it('puts the label on the passenger ticket list and the guest lookup, with no plate', function () {
    [$trip, $stops, , $fare] = blFixture(['bus_company_name' => 'Davao Bus', 'body_no' => 'B-014']);
    $pax = PassengerUser::create([
        'passenger_uuid' => (string) Str::uuid(),
        'user_id' => User::create(['username' => 'pax'.Str::random(5), 'email' => Str::random(8).'@example.com', 'password' => bcrypt('password123'), 'role' => 'passenger'])->user_id,
        'name' => 'Pax', 'phone_num' => '09170000001', 'address' => 'x', 'birthdate' => '2000-01-01',
    ]);
    $payment = Payment::create([
        'amount' => 35, 'payment_created' => now(), 'transaction_reference' => 'TXN-BL-'.strtoupper(Str::random(8)),
        'payment_method' => 'online', 'payment_channel' => 'gcash', 'status' => 'paid', 'payment_uuid' => (string) Str::uuid(),
        'is_valid' => true, 'items_payload' => [], 'guest_email' => 'g@example.com',
    ]);
    app(TicketService::class)->finalizeTicket([
        'fleet_route_id' => $trip->fleet_route_id, 'trip_id' => $trip->trip_id, 'fare_rule_id' => $fare->fare_rule_id,
        'distance_km' => 10, 'amount' => 35, 'seat_type' => 'seated',
        'origin_stop_id' => $stops['Stop A']->stop_id, 'destination_stop_id' => $stops['Stop B']->stop_id,
    ], $pax->passenger_id, $payment->payment_id);

    $mine = $this->actingAs($pax->user)->getJson('/api/passengers/tickets')->assertSuccessful();
    expect($mine->json('data.0.bus_label'))->toBe('Davao Bus - B-014');
    expect($mine->getContent())->not->toContain('SECRET-PLATE');

    app(TicketService::class)->finalizeTicket([
        'fleet_route_id' => $trip->fleet_route_id, 'trip_id' => $trip->trip_id, 'fare_rule_id' => $fare->fare_rule_id,
        'distance_km' => 10, 'amount' => 35, 'seat_type' => 'seated',
        'origin_stop_id' => $stops['Stop A']->stop_id, 'destination_stop_id' => $stops['Stop B']->stop_id,
    ], null, $payment->payment_id);
    $guest = $this->getJson('/api/tickets/lookup?'.http_build_query(['transaction_reference' => $payment->transaction_reference, 'email' => 'g@example.com']))->assertSuccessful();
    expect($guest->json('data.0.bus_label'))->toBe('Davao Bus - B-014');
    expect($guest->getContent())->not->toContain('SECRET-PLATE');
});

it('lets an operator register and edit the label fields, and rejects unsafe text', function () {
    $operator = blStaff('operator');
    $admin = blStaff('admin');

    $created = $this->actingAs($operator)->postJson('/api/operator/fleets', [
        'plate_number' => 'NEW-001', 'bus_company_name' => "Mindanao O'Brien Lines", 'body_no' => 'B-7',
        'seated_capacity' => 30, 'standing_capacity' => 10, 'fleet_type' => 'public',
    ])->assertSuccessful();
    $fleetId = $created->json('data.fleet_id');
    expect(Fleet::find($fleetId)->only(['bus_company_name', 'body_no']))->toBe(['bus_company_name' => "Mindanao O'Brien Lines", 'body_no' => 'B-7']);

    $this->actingAs($operator)->postJson('/api/operator/fleets', [
        'plate_number' => 'NEW-002', 'bus_company_name' => '<script>alert(1)</script>', 'seated_capacity' => 30, 'standing_capacity' => 10, 'fleet_type' => 'public',
    ])->assertUnprocessable()->assertJsonValidationErrors('bus_company_name');

    $this->actingAs($admin)->putJson("/api/admin/fleets/{$fleetId}", ['body_no' => 'B-8', 'seated_capacity' => 20])->assertSuccessful();
    $fleet = Fleet::find($fleetId);
    expect($fleet->body_no)->toBe('B-8');
    expect((int) $fleet->capacity)->toBe(30); // 20 seated + 10 standing

    $this->actingAs($admin)->putJson("/api/admin/fleets/{$fleetId}", ['body_no' => ''])->assertSuccessful();
    expect(Fleet::find($fleetId)->body_no)->toBeNull(); // empty clears it
    expect(BusLabel::for(Fleet::find($fleetId)))->toBeNull();
});
