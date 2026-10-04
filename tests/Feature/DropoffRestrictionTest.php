<?php

use App\Models\AlightingPoint;
use App\Models\CustomDropoffRestriction;
use App\Models\FareRule;
use App\Models\Fleet;
use App\Models\FleetRoute;
use App\Models\Route;
use App\Models\RouteStop;
use App\Models\StaffUser;
use App\Models\Stop;
use App\Models\Ticket;
use App\Models\Trip;
use App\Models\User;
use App\Repositories\RouteGeometryRepository;
use App\Services\AlightingPointService;
use App\Services\CustomDropoffService;
use App\Services\DropoffRestrictionService;
use App\Services\RouteSnapService;
use App\Services\TicketService;
use App\Support\Geo\GeoLine;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

uses(RefreshDatabase::class);

// Batch 28 Stage 3, item 10. Every route, stop, point and restricted-area name below is test data (a straight line of
// stops on a made-up meridian); no real place, coordinate or fare is relied on.

function drStaff(string $role): User
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

/**
 * Five stops on one meridian, about 10 km apart. $lng and $lat0 let a second, different route be built next to it.
 *
 * @return array{0: Route, 1: array<string, Stop>, 2: array<string, RouteStop>, 3: Closure, 4: User}
 */
function drFixture(string $mode = 'designated_points', float $lng = 125.0, float $lat0 = 7.00, ?User $operator = null, string $prefix = 'Stop'): array
{
    $operator ??= drStaff('operator');
    $route = Route::create(['route_name' => "{$prefix} Route", 'origin' => 'A', 'destination' => 'E', 'custom_dropoff_mode' => $mode]);

    $defs = [];
    foreach (['A', 'B', 'C', 'D', 'E'] as $i => $letter) {
        $defs[] = ["{$prefix} {$letter}", $lat0 + 0.09 * $i, $lng, 10 * $i, 15 * $i, in_array($letter, ['A', 'E'], true) ? 'terminal' : 'stop'];
    }
    app(RouteGeometryRepository::class)->save($route->route_id, [
        'type' => 'LineString',
        'coordinates' => collect($defs)->map(fn ($d) => [$d[2], $d[1]])->all(),
    ]);
    $snap = app(RouteSnapService::class);

    $stops = [];
    $routeStops = [];
    foreach ($defs as $i => [$name, $lat, $lg, $km, $offset, $type]) {
        $stop = Stop::create(['stop_name' => $name, 'latitude' => $lat, 'longitude' => $lg, 'municipality' => 'Mati City']);
        $created = RouteStop::create([
            'route_id' => $route->route_id,
            'stop_id' => $stop->stop_id,
            'stop_order' => $i + 1,
            'distance_from_origin_km' => $km,
            'stop_type' => $type,
            'scheduled_offset_min' => $offset,
        ]);
        // The metres column is the stop's distance measured on the route line (the route builder writes it that way; the
        // model would copy the km value, so it is set directly here).
        DB::table('route_stop')->where('id', $created->id)->update(['distance_from_origin_m' => (int) round($snap->snap($route->route_id, $lat, $lg)['distance_along_route_m'])]);
        $routeStops[$name] = RouteStop::with('stop')->find($created->id);
        $stops[$name] = $stop;
    }

    $fleet = Fleet::create([
        'company_user_id' => $operator->companyProfile->company_user_id,
        'plate_number' => 'DR-'.fake()->unique()->numerify('###'),
        'capacity' => 40, 'seated_capacity' => 30, 'standing_capacity' => 10, 'status' => 'active',
    ]);
    FareRule::create(['fleet_id' => $fleet->fleet_id, 'base_fare' => 15, 'fare_per_km' => 2.35, 'status' => 'active', 'seat_type' => 'seated']);
    $fleetRoute = FleetRoute::create(['fleet_id' => $fleet->fleet_id, 'route_id' => $route->route_id, 'start_time' => '06:00', 'end_time' => '22:00', 'status' => 'active']);

    $makeTrip = fn (string $direction = 'outbound') => Trip::create([
        'fleet_route_id' => $fleetRoute->fleet_route_id,
        'company_user_id' => $operator->companyProfile->company_user_id,
        'trip_date' => now('Asia/Manila')->toDateString(),
        'departure_time' => '08:00',
        'trip_type' => 'one_way',
        'leg_direction' => $direction,
        'status' => 'scheduled',
        'current_seated_capacity' => 0, 'current_standing_capacity' => 0, 'total_occupancy' => 0,
    ]);

    return [$route, $stops, $routeStops, $makeTrip, $operator];
}

/** Move a stop along the line, keeping its stored distance consistent with where it now is (as a rebuilt route would). */
function drMoveStop(RouteStop $routeStop, float $latShift): void
{
    $stop = $routeStop->stop;
    $lat = (float) $stop->latitude + $latShift;
    $m = (int) round(app(RouteSnapService::class)->snap($routeStop->route_id, $lat, (float) $stop->longitude)['distance_along_route_m']);
    DB::table('route_stop')->where('id', $routeStop->id)->update(['snapped_latitude' => $lat, 'snapped_longitude' => $stop->longitude, 'distance_from_origin_m' => $m]);
}

/** A designated point on the route line at latitude $lat (a few metres off the line, as a real point would be). */
function drPoint(Route $route, float $lat, string $name = 'Point', string $kind = 'landmark', bool $active = true, float $lng = 125.0002): AlightingPoint
{
    $attributes = app(AlightingPointService::class)->prepare([
        'route_id' => $route->route_id, 'name' => $name, 'kind' => $kind, 'latitude' => $lat, 'longitude' => $lng,
        'municipality' => 'Mati City', 'source' => 'test', 'is_active' => $active,
    ]);

    return AlightingPoint::create($attributes);
}

/** A restricted area between two stops of the route, by stop id. */
function drArea(Route $route, Stop $from, Stop $to, string $name = 'Test Corridor Area', bool $active = true): CustomDropoffRestriction
{
    return app(DropoffRestrictionService::class)->save([
        'route_id' => $route->route_id, 'name' => $name, 'from_stop_id' => $from->stop_id, 'to_stop_id' => $to->stop_id,
        'source' => 'test', 'is_active' => $active,
    ]);
}

function drTimeline($test, Trip $trip, array $query)
{
    return $test->getJson("/api/trips/{$trip->trip_id}/timeline?".http_build_query($query + ['seat_type' => 'seated']));
}

// ---------------------------------------------------------------------------------------------------------------------

it('lets an official stop inside the restricted area through: restrictions never touch official stops', function () {
    [$route, $stops, , $makeTrip] = drFixture();
    drArea($route, $stops['Stop B'], $stops['Stop D']);
    $trip = $makeTrip();

    $data = drTimeline($this, $trip, ['boarding_stop_id' => $stops['Stop A']->stop_id, 'alighting_stop_id' => $stops['Stop C']->stop_id])
        ->assertSuccessful()->json('data');
    expect($data['journey']['alighting_stop_id'])->toBe($stops['Stop C']->stop_id);

    $reserved = app(TicketService::class)->reserveAndPrice([
        'trip_id' => $trip->trip_id, 'seat_type' => 'seated',
        'origin_stop_id' => $stops['Stop A']->stop_id, 'destination_stop_id' => $stops['Stop C']->stop_id,
    ]);
    expect($reserved['destination_stop_id'])->toBe($stops['Stop C']->stop_id);
});

it('rejects a designated point inside the restricted area with a message built from the area\'s own name', function () {
    [$route, $stops, , $makeTrip] = drFixture();
    drArea($route, $stops['Stop B'], $stops['Stop D'], 'Inner Zone X');
    $inside = drPoint($route, 7.15, 'Inside point');
    $trip = $makeTrip();

    $response = drTimeline($this, $trip, ['boarding_stop_id' => $stops['Stop A']->stop_id, 'alighting_point_id' => $inside->id])->assertStatus(422);
    expect($response->json('errors.dropoff.0'))->toBe('Custom drop-off is not available inside Inner Zone X. Please choose an official stop.');

    // The same refusal at checkout (a tampered or stale selection).
    expect(fn () => app(TicketService::class)->reserveAndPrice([
        'trip_id' => $trip->trip_id, 'seat_type' => 'seated', 'origin_stop_id' => $stops['Stop A']->stop_id, 'alighting_point_id' => $inside->id,
    ]))->toThrow(ValidationException::class, 'Inner Zone X');
    expect(Trip::find($trip->trip_id)->current_seated_capacity)->toBe(0);
});

it('allows a designated point outside the area, prices it point to point in whole pesos, and stores which point it was', function () {
    [$route, $stops, , $makeTrip] = drFixture();
    drArea($route, $stops['Stop B'], $stops['Stop D']);
    $outside = drPoint($route, 7.31, 'Outside point', 'barangay');
    $trip = $makeTrip();

    $data = drTimeline($this, $trip, ['boarding_stop_id' => $stops['Stop B']->stop_id, 'alighting_point_id' => $outside->id])->assertSuccessful()->json('data');
    $row = collect($data['stops'])->firstWhere('custom', true);
    expect($row['name'])->toBe('Outside point')
        ->and($row['point_kind'])->toBe('barangay')
        ->and($row['alighting_point_id'])->toBe($outside->id);
    $amount = (float) $data['fare']['amount'];
    expect(fmod($amount, 1.0))->toBe(0.0)->and($amount)->toBeGreaterThan(15.0);

    $reserved = app(TicketService::class)->reserveAndPrice([
        'trip_id' => $trip->trip_id, 'seat_type' => 'seated', 'origin_stop_id' => $stops['Stop B']->stop_id, 'alighting_point_id' => $outside->id,
    ]);
    expect((float) $reserved['amount'])->toBe($amount)
        ->and($reserved['alighting_point_id'])->toBe($outside->id)
        ->and($reserved['destination_label'])->toBe('Outside point');

    // The ticket row keeps the point, and the readers show its name and kind.
    $payment = \App\Models\Payment::create(['amount' => 15, 'payment_created' => now(), 'transaction_reference' => 'TXN-DR-'.Str::upper(Str::random(8)), 'payment_method' => 'online', 'payment_channel' => 'gcash', 'status' => 'paid', 'payment_uuid' => (string) Str::uuid(), 'is_valid' => true, 'items_payload' => []]);
    $ticket = app(TicketService::class)->finalizeTicket($reserved, null, $payment->payment_id);
    expect(Ticket::find($ticket->ticket_id)->alighting_point_id)->toBe($outside->id);
});

it('applies the restriction on the reverse leg too, from the same row', function () {
    [$route, $stops, , $makeTrip] = drFixture();
    drArea($route, $stops['Stop B'], $stops['Stop D'], 'Inner Zone X');
    $inside = drPoint($route, 7.15, 'Inside point');
    $outside = drPoint($route, 7.05, 'Outside point');
    $reverse = $makeTrip('reverse');

    drTimeline($this, $reverse, ['boarding_stop_id' => $stops['Stop E']->stop_id, 'alighting_point_id' => $inside->id])->assertStatus(422)
        ->assertJsonPath('errors.dropoff.0', 'Custom drop-off is not available inside Inner Zone X. Please choose an official stop.');
    drTimeline($this, $reverse, ['boarding_stop_id' => $stops['Stop E']->stop_id, 'alighting_point_id' => $outside->id])->assertSuccessful();
});

it('rejects a point behind or at the boarding point, an inactive point, and a point of another route', function () {
    [$route, $stops, , $makeTrip] = drFixture();
    [$other] = drFixture('designated_points', 126.0, 6.0, null, 'Other');
    $behind = drPoint($route, 7.05, 'Behind point');
    $ahead = drPoint($route, 7.31, 'Ahead point');
    $off = drPoint($route, 7.22, 'Switched off', 'landmark', false);
    $elsewhere = drPoint($other, 6.05, 'Other route point', 'landmark', true, 126.0002);
    $trip = $makeTrip();
    $board = fn (string $stop) => ['boarding_stop_id' => $stops[$stop]->stop_id];

    drTimeline($this, $trip, $board('Stop D') + ['alighting_point_id' => $behind->id])->assertStatus(422);
    drTimeline($this, $trip, $board('Stop B') + ['alighting_point_id' => $off->id])->assertStatus(422);
    drTimeline($this, $trip, $board('Stop B') + ['alighting_point_id' => $elsewhere->id])->assertStatus(422);
    drTimeline($this, $trip, $board('Stop B') + ['alighting_point_id' => $ahead->id])->assertSuccessful();
});

it('never trusts coordinates in designated-points mode: raw coordinates alone are refused, and with a point id they are ignored', function () {
    [$route, $stops, , $makeTrip] = drFixture();
    $point = drPoint($route, 7.31, 'Ahead point');
    $trip = $makeTrip();
    $service = app(TicketService::class);

    // A raw pin on a designated-points route is not accepted.
    expect(fn () => $service->reserveAndPrice([
        'trip_id' => $trip->trip_id, 'seat_type' => 'seated', 'origin_stop_id' => $stops['Stop B']->stop_id,
        'destination_lat' => 7.22, 'destination_lng' => 125.0,
    ]))->toThrow(ValidationException::class, 'listed drop-off points');

    // Point id plus a tampered pin, label and amount: the stored point wins.
    $reserved = $service->reserveAndPrice([
        'trip_id' => $trip->trip_id, 'seat_type' => 'seated', 'origin_stop_id' => $stops['Stop B']->stop_id,
        'alighting_point_id' => $point->id, 'destination_lat' => 7.10, 'destination_lng' => 125.0, 'destination_label' => 'Hacked', 'amount' => 1,
    ]);
    expect($reserved['destination_label'])->toBe('Ahead point')
        ->and(round($reserved['destination_lat'], 2))->toBe(7.31)
        ->and((float) $reserved['amount'])->toBeGreaterThan(15.0);

    // A stop and a point together are ambiguous.
    expect(fn () => $service->reserveAndPrice([
        'trip_id' => $trip->trip_id, 'seat_type' => 'seated', 'origin_stop_id' => $stops['Stop B']->stop_id,
        'destination_stop_id' => $stops['Stop D']->stop_id, 'alighting_point_id' => $point->id,
    ]))->toThrow(ValidationException::class);
});

it('mode off blocks every custom drop-off, pin or point; free_pin mode keeps the pin and honours the restriction', function () {
    [$route, $stops, , $makeTrip] = drFixture('off');
    drArea($route, $stops['Stop B'], $stops['Stop D'], 'Inner Zone X');
    $point = drPoint($route, 7.31, 'Ahead point');
    $trip = $makeTrip();
    $board = ['boarding_stop_id' => $stops['Stop A']->stop_id];

    drTimeline($this, $trip, $board + ['alighting_point_id' => $point->id])->assertStatus(422);
    drTimeline($this, $trip, $board + ['dropoff_lat' => 7.22, 'dropoff_lng' => 125.0])->assertStatus(422);
    expect(drTimeline($this, $trip, $board)->json('data.dropoff_mode'))->toBe('off');

    $route->update(['custom_dropoff_mode' => 'free_pin']);
    drTimeline($this, $trip, $board + ['dropoff_lat' => 7.31, 'dropoff_lng' => 125.0])->assertSuccessful();
    drTimeline($this, $trip, $board + ['dropoff_lat' => 7.15, 'dropoff_lng' => 125.0])->assertStatus(422)
        ->assertJsonPath('errors.dropoff.0', 'Custom drop-off is not available inside Inner Zone X. Please choose an official stop.');
});

it('an inactive restricted area has no effect', function () {
    [$route, $stops, , $makeTrip] = drFixture('free_pin');
    $area = drArea($route, $stops['Stop B'], $stops['Stop D'], 'Inner Zone X', false);
    $trip = $makeTrip();
    $query = ['boarding_stop_id' => $stops['Stop A']->stop_id, 'dropoff_lat' => 7.15, 'dropoff_lng' => 125.0];

    drTimeline($this, $trip, $query)->assertSuccessful();
    $area->update(['is_active' => true]);
    drTimeline($this, $trip, $query)->assertStatus(422);
});

it('flags an area whose end stop was deleted, reordered or moved as needs_review and fails CLOSED until reviewed', function () {
    foreach (['deleted', 'reordered', 'moved'] as $case) {
        [$route, $stops, $routeStops, $makeTrip] = drFixture('free_pin', 125.0, 7.0, null, "Case {$case}");
        $area = drArea($route, $stops["Case {$case} B"], $stops["Case {$case} D"], 'Inner Zone X');
        $trip = $makeTrip();
        $query = ['boarding_stop_id' => $stops["Case {$case} A"]->stop_id, 'dropoff_lat' => 7.31, 'dropoff_lng' => 125.0]; // well outside the area

        drTimeline($this, $trip, $query)->assertSuccessful(); // healthy: outside the area is fine

        $d = $routeStops["Case {$case} D"];
        match ($case) {
            'deleted' => $d->delete(),
            'reordered' => $routeStops["Case {$case} B"]->update(['stop_order' => 9]),
            'moved' => drMoveStop($d, 0.008),
        };

        // Fails closed: custom drop-off is blocked on the whole route, official stops still work.
        $response = drTimeline($this, $trip, $query)->assertStatus(422);
        expect($response->json('errors.dropoff.0'))->toContain('has to be reviewed')->toContain('Inner Zone X');
        drTimeline($this, $trip, ['boarding_stop_id' => $stops["Case {$case} A"]->stop_id, 'alighting_stop_id' => $stops["Case {$case} E"]->stop_id])->assertSuccessful();

        $fresh = $area->fresh();
        expect($fresh->needs_review)->toBeTrue()->and($fresh->review_reason)->not->toBeEmpty();

        $options = app(CustomDropoffService::class)->options($route->route_id, 'outbound', $stops["Case {$case} A"]->stop_id);
        expect($options['available'])->toBeFalse()->and($options['reason'])->toContain('has to be reviewed')->and($options['needs_review'])->toHaveCount(1);
    }
});

it('lets staff confirm a moved area after review, which clears the flag and applies the area to the new stretch', function () {
    [$route, $stops, $routeStops, $makeTrip, $operator] = drFixture('free_pin');
    $area = drArea($route, $stops['Stop B'], $stops['Stop D'], 'Inner Zone X');
    drMoveStop($routeStops['Stop D'], 0.008);
    expect(app(DropoffRestrictionService::class)->forRoute($route->route_id)->first()['resolved']['valid'])->toBeFalse();

    $this->actingAs($operator)->postJson("/api/operator/dropoff/restrictions/{$area->id}/review")->assertSuccessful()
        ->assertJsonPath('data.needs_review', false);
    expect($area->fresh()->needs_review)->toBeFalse();
    expect($area->fresh()->to_distance_m)->toBe((int) $routeStops['Stop D']->fresh()->distance_from_origin_m);
});

it('keeps routes independent: a second route with a different restricted segment (dummy route proof)', function () {
    [$route1, $stops1, , $makeTrip1] = drFixture('designated_points');
    [$route2, $stops2, , $makeTrip2] = drFixture('designated_points', 126.0, 6.0, null, 'Other');
    drArea($route1, $stops1['Stop B'], $stops1['Stop D'], 'Zone One');
    drArea($route2, $stops2['Other D'], $stops2['Other E'], 'Zone Two');
    $in1 = drPoint($route1, 7.15, 'One inside');
    $out1 = drPoint($route1, 7.31, 'One outside');
    $in2 = drPoint($route2, 6.31, 'Two inside', 'landmark', true, 126.0002);
    $out2 = drPoint($route2, 6.15, 'Two outside', 'landmark', true, 126.0002);
    $trip1 = $makeTrip1();
    $trip2 = $makeTrip2();

    drTimeline($this, $trip1, ['boarding_stop_id' => $stops1['Stop A']->stop_id, 'alighting_point_id' => $in1->id])->assertStatus(422)->assertJsonPath('errors.dropoff.0', 'Custom drop-off is not available inside Zone One. Please choose an official stop.');
    drTimeline($this, $trip1, ['boarding_stop_id' => $stops1['Stop A']->stop_id, 'alighting_point_id' => $out1->id])->assertSuccessful();
    drTimeline($this, $trip2, ['boarding_stop_id' => $stops2['Other A']->stop_id, 'alighting_point_id' => $in2->id])->assertStatus(422)->assertJsonPath('errors.dropoff.0', 'Custom drop-off is not available inside Zone Two. Please choose an official stop.');
    drTimeline($this, $trip2, ['boarding_stop_id' => $stops2['Other A']->stop_id, 'alighting_point_id' => $out2->id])->assertSuccessful();
});

it('old tickets and the old on/off flag are unaffected: the flag and the mode follow each other', function () {
    $old = Route::create(['route_name' => 'Old flag route', 'origin' => 'A', 'destination' => 'B', 'allow_custom_dropoff' => true]);
    expect($old->fresh()->custom_dropoff_mode)->toBe('free_pin')->and($old->fresh()->dropoffMode())->toBe('free_pin');
    $old->update(['allow_custom_dropoff' => false]);
    expect($old->fresh()->custom_dropoff_mode)->toBe('off');
    $old->update(['custom_dropoff_mode' => 'designated_points']);
    expect($old->fresh()->allow_custom_dropoff)->toBeTrue();
    $old->update(['allow_custom_dropoff' => false]);
    expect($old->fresh()->custom_dropoff_mode)->toBe('off');

    // A ticket sold before this feature has no point and still reads as a pin.
    [$route, $stops, , $makeTrip] = drFixture('free_pin');
    $trip = $makeTrip();
    $reserved = app(TicketService::class)->reserveAndPrice(['trip_id' => $trip->trip_id, 'seat_type' => 'seated', 'origin_stop_id' => $stops['Stop B']->stop_id, 'destination_lat' => 7.31, 'destination_lng' => 125.0]);
    expect($reserved['alighting_point_id'])->toBeNull();
});

it('serves the drop-off options for a trip: restricted areas with their line, and only the points ahead and outside', function () {
    [$route, $stops, , $makeTrip] = drFixture('designated_points');
    drArea($route, $stops['Stop B'], $stops['Stop D'], 'Inner Zone X');
    drPoint($route, 7.05, 'Before area');
    drPoint($route, 7.15, 'In area');
    drPoint($route, 7.31, 'After area B', 'barangay');
    drPoint($route, 7.29, 'After area A', 'intersection');
    drPoint($route, 7.33, 'Switched off', 'landmark', false);
    $trip = $makeTrip();

    $data = $this->getJson("/api/trips/{$trip->trip_id}/dropoff-options?boarding_stop_id={$stops['Stop A']->stop_id}")->assertSuccessful()->json('data');
    expect($data['mode'])->toBe('designated_points')->and($data['available'])->toBeTrue();
    expect($data['restricted_areas'])->toHaveCount(1);
    expect($data['restricted_areas'][0]['name'])->toBe('Inner Zone X');
    expect(count($data['restricted_areas'][0]['path']))->toBeGreaterThanOrEqual(2);
    // Route order, outside the area, active only; the point before the area is ahead of boarding at A.
    expect(array_column($data['designated_points'], 'name'))->toBe(['Before area', 'After area A', 'After area B']);

    // Boarding after the area: only points further on.
    $later = $this->getJson("/api/trips/{$trip->trip_id}/dropoff-options?boarding_stop_id={$stops['Stop D']->stop_id}")->json('data');
    expect(array_column($later['designated_points'], 'name'))->toBe(['After area A', 'After area B']);

    // Reverse leg: the same rows, listed in that leg's order.
    $reverse = $makeTrip('reverse');
    $rev = $this->getJson("/api/trips/{$reverse->trip_id}/dropoff-options?boarding_stop_id={$stops['Stop E']->stop_id}")->json('data');
    expect(array_column($rev['designated_points'], 'name'))->toBe(['After area B', 'After area A', 'Before area']);
});

it('turns the option off, with a reason from data, when everything left on the trip is inside restricted areas', function () {
    [$route, $stops, , $makeTrip] = drFixture('free_pin');
    drArea($route, $stops['Stop B'], $stops['Stop E'], 'Whole Tail Zone');
    $trip = $makeTrip();

    $data = $this->getJson("/api/trips/{$trip->trip_id}/dropoff-options?boarding_stop_id={$stops['Stop C']->stop_id}")->assertSuccessful()->json('data');
    expect($data['available'])->toBeFalse()->and($data['reason'])->toContain('Whole Tail Zone');

    // The timeline says the same, and official stops are untouched.
    $timeline = drTimeline($this, $trip, ['boarding_stop_id' => $stops['Stop C']->stop_id])->json('data');
    expect($timeline['custom_dropoff_availability']['available'])->toBeFalse();
    drTimeline($this, $trip, ['boarding_stop_id' => $stops['Stop C']->stop_id, 'alighting_stop_id' => $stops['Stop D']->stop_id])->assertSuccessful();

    // Boarding at the start leaves a free stretch before the area, so it is available again.
    $start = $this->getJson("/api/trips/{$trip->trip_id}/dropoff-options?boarding_stop_id={$stops['Stop A']->stop_id}")->json('data');
    expect($start['available'])->toBeTrue();
});

it('offers no drop-off options on a route that is off, and none on an express trip', function () {
    [$route, $stops, , $makeTrip] = drFixture('off');
    $trip = $makeTrip();
    $data = $this->getJson("/api/trips/{$trip->trip_id}/dropoff-options?boarding_stop_id={$stops['Stop A']->stop_id}")->assertSuccessful()->json('data');
    expect($data['mode'])->toBe('off')->and($data['available'])->toBeFalse()->and($data['designated_points'])->toBe([]);

    $route->update(['custom_dropoff_mode' => 'designated_points']);
    $trip->update(['service_type' => 'express']);
    $express = $this->getJson("/api/trips/{$trip->trip_id}/dropoff-options?boarding_stop_id={$stops['Stop A']->stop_id}")->json('data');
    expect($express['available'])->toBeFalse()->and($express['reason'])->toContain('express');
});

it('keeps an operator to their own routes and lets an admin manage any', function () {
    [$route, $stops, , , $operator] = drFixture();
    [$other, $otherStops, , , $otherOperator] = drFixture('designated_points', 126.0, 6.0, null, 'Other');
    $admin = drStaff('admin');
    $point = ['route_id' => $route->route_id, 'name' => 'Typed point', 'kind' => 'landmark', 'latitude' => 7.22, 'longitude' => 125.0002, 'municipality' => 'Mati City'];

    $created = $this->actingAs($operator)->postJson('/api/operator/dropoff/points', $point)->assertCreated()->json('data');
    expect($created['distance_along_outbound_m'])->toBeGreaterThan(0)->and($created['distance_along_reverse_m'])->toBeGreaterThan(0);

    $this->actingAs($otherOperator)->postJson('/api/operator/dropoff/points', $point)->assertForbidden();
    $this->actingAs($otherOperator)->putJson("/api/operator/dropoff/points/{$created['id']}", $point)->assertForbidden();
    $this->actingAs($admin)->postJson('/api/admin/dropoff/points', ['name' => 'Admin point', 'latitude' => 7.31] + $point)->assertCreated();

    // Listing and filtering: an operator sees only their routes.
    $mine = $this->actingAs($operator)->getJson('/api/operator/dropoff/points')->json('data');
    expect(collect($mine)->pluck('route_id')->unique()->all())->toBe([$route->route_id]);
    $this->actingAs($admin)->getJson("/api/admin/dropoff/points?route_id={$route->route_id}&is_active=1")->assertSuccessful();
    $this->actingAs($admin)->getJson('/api/admin/dropoff/points?municipality=Mati%20City')->assertSuccessful()->assertJsonCount(2, 'data');

    // Deactivate (the only way a point is switched off; tickets keep their history).
    $this->actingAs($operator)->putJson("/api/operator/dropoff/points/{$created['id']}", array_merge($point, ['is_active' => false]))->assertSuccessful()->assertJsonPath('data.is_active', false);
    expect(AlightingPoint::find($created['id'])->is_active)->toBeFalse();
});

it('validates a point on the server: on the route line, inside the region, a real municipality, a known kind', function () {
    [$route, , , , $operator] = drFixture();
    $base = ['route_id' => $route->route_id, 'name' => 'Typed point', 'kind' => 'landmark', 'latitude' => 7.22, 'longitude' => 125.0002];
    $post = fn (array $over) => $this->actingAs($operator)->postJson('/api/operator/dropoff/points', array_merge($base, $over));

    $post(['longitude' => 125.05])->assertStatus(422)->assertJsonValidationErrors(['latitude']); // far from the line
    $post(['kind' => 'airport'])->assertStatus(422);
    $post(['municipality' => 'Nowhere Town'])->assertStatus(422)->assertJsonValidationErrors(['municipality']);
    config(['region.bbox' => ['min_lat' => 10, 'max_lat' => 11, 'min_lng' => 122, 'max_lng' => 123]]);
    $post([])->assertStatus(422)->assertJsonValidationErrors(['latitude']);

    // A route with no generated path cannot take a point.
    $bare = Route::create(['route_name' => 'No path', 'origin' => 'A', 'destination' => 'B']);
    config(['region.bbox' => ['min_lat' => 5.3, 'max_lat' => 8.1, 'min_lng' => 124.9, 'max_lng' => 126.7]]);
    $this->actingAs(drStaff('admin'))->postJson('/api/admin/dropoff/points', array_merge($base, ['route_id' => $bare->route_id]))->assertStatus(422)->assertJsonValidationErrors(['route_id']);
});

it('creates a restricted area from two stop ids, and refuses stops that are not on the route or out of order', function () {
    [$route, $stops, , , $operator] = drFixture();
    [, $otherStops] = drFixture('off', 126.0, 6.0, null, 'Other');
    $post = fn (array $body) => $this->actingAs($operator)->postJson('/api/operator/dropoff/restrictions', array_merge(['route_id' => $route->route_id, 'name' => 'Typed area'], $body));

    $ok = $post(['from_stop_id' => $stops['Stop B']->stop_id, 'to_stop_id' => $stops['Stop D']->stop_id, 'source' => 'pending adviser confirmation'])->assertCreated()->json('data');
    expect($ok['from_stop_name'])->toBe('Stop B')->and($ok['to_stop_name'])->toBe('Stop D')->and($ok['needs_review'])->toBeFalse()->and($ok['pending_confirmation'])->toBeTrue();

    $post(['from_stop_id' => $stops['Stop D']->stop_id, 'to_stop_id' => $stops['Stop B']->stop_id])->assertStatus(422)->assertJsonValidationErrors(['to_stop_id']);
    $post(['from_stop_id' => $otherStops['Other B']->stop_id, 'to_stop_id' => $stops['Stop D']->stop_id])->assertStatus(422)->assertJsonValidationErrors(['from_stop_id']);

    $list = $this->actingAs($operator)->getJson("/api/operator/dropoff/restrictions?route_id={$route->route_id}")->assertSuccessful()->json('data');
    expect($list)->toHaveCount(1);

    $update = $this->actingAs($operator)->putJson("/api/operator/dropoff/restrictions/{$ok['id']}", ['is_active' => false])->assertSuccessful()->json('data');
    expect($update['is_active'])->toBeFalse()->and($update['name'])->toBe('Typed area');
});

it('lets staff set the route mode, keeping the older flag in step', function () {
    [$route, , , , $operator] = drFixture('off');
    $patch = fn (array $body) => $this->actingAs($operator)->patchJson("/api/operator/routes/{$route->route_id}/custom-dropoff", $body);

    $patch(['custom_dropoff_mode' => 'designated_points'])->assertSuccessful()->assertJsonPath('data.custom_dropoff_mode', 'designated_points')->assertJsonPath('data.allow_custom_dropoff', true);
    $patch(['custom_dropoff_mode' => 'free_pin'])->assertSuccessful();
    expect($route->fresh()->dropoffMode())->toBe('free_pin');
    $patch(['custom_dropoff_mode' => 'sideways'])->assertStatus(422);
    $patch(['allow_custom_dropoff' => false])->assertSuccessful()->assertJsonPath('data.custom_dropoff_mode', 'off');
    $patch([])->assertStatus(422);
});

it('refuses a drop-off point in a roundtrip booking', function () {
    [$route, $stops, , $makeTrip] = drFixture();
    $point = drPoint($route, 7.31);
    $trip = $makeTrip();
    $date = now('Asia/Manila')->toDateString();
    $item = fn (string $leg) => ['trip_id' => $trip->trip_id, 'seat_type' => 'seated', 'booking_date' => $date, 'journey_leg' => $leg, 'origin_stop_id' => $stops['Stop A']->stop_id, 'alighting_point_id' => $point->id];

    $this->postJson('/api/checkout', ['journey_type' => 'roundtrip', 'items' => [$item('outbound'), $item('return')], 'payment_channel' => 'gcash', 'guest_email' => 'guest@example.com'])
        ->assertStatus(422)->assertJsonValidationErrors(['items']);
});

it('creates a restriction from the command by route and stop ids, labelled pending, and can create it switched off', function () {
    [$route, $stops] = drFixture();

    $this->artisan('dropoff:add-restriction', ['route_id' => $route->route_id, 'from_stop_id' => $stops['Stop B']->stop_id, 'to_stop_id' => $stops['Stop D']->stop_id, '--name' => 'Cmd area'])->assertSuccessful();
    $row = CustomDropoffRestriction::where('name', 'Cmd area')->first();
    expect($row->source)->toBe('pending adviser confirmation')->and($row->is_active)->toBeTrue();

    $this->artisan('dropoff:add-restriction', ['route_id' => $route->route_id, 'from_stop_id' => $stops['Stop C']->stop_id, 'to_stop_id' => $stops['Stop E']->stop_id, '--name' => 'Off area', '--inactive' => true])->assertSuccessful();
    expect(CustomDropoffRestriction::where('name', 'Off area')->first()->is_active)->toBeFalse();

    $this->artisan('dropoff:add-restriction', ['route_id' => $route->route_id, 'from_stop_id' => $stops['Stop D']->stop_id, 'to_stop_id' => $stops['Stop B']->stop_id, '--name' => 'Bad order'])->assertFailed();
    $this->artisan('dropoff:add-restriction', ['route_id' => $route->route_id, 'from_stop_id' => $stops['Stop B']->stop_id, 'to_stop_id' => $stops['Stop D']->stop_id])->assertFailed(); // no name
});

it('adds SAMPLE points taken from the route line itself, each labelled SAMPLE', function () {
    [$route] = drFixture();

    $this->artisan('dropoff:sample-points', ['route_id' => $route->route_id, '--count' => 3])->assertSuccessful();
    $points = AlightingPoint::where('route_id', $route->route_id)->orderBy('distance_along_outbound_m')->get();
    expect($points)->toHaveCount(3);
    foreach ($points as $p) {
        expect($p->name)->toStartWith('SAMPLE')->and($p->source)->toContain('SAMPLE')->and($p->snap_distance_m)->toBeLessThan(5.0);
    }
    // Running it again does not duplicate them.
    $this->artisan('dropoff:sample-points', ['route_id' => $route->route_id, '--count' => 3])->assertSuccessful();
    expect(AlightingPoint::where('route_id', $route->route_id)->count())->toBe(3);
});

it('cuts a stretch out of a line for shading, with interpolated ends', function () {
    $line = [[125.0, 7.0], [125.0, 7.1], [125.0, 7.2]];
    $length = GeoLine::lengthM($line);

    $slice = GeoLine::slice($line, $length * 0.25, $length * 0.75);
    expect($slice)->toHaveCount(3);
    expect(round($slice[0][1], 3))->toBe(7.05)->and(round($slice[1][1], 3))->toBe(7.1)->and(round($slice[2][1], 3))->toBe(7.15);
    expect(GeoLine::slice($line, 100.0, 50.0))->toBe([]);
});

it('flags an end stop whose stored distance no longer matches the route line (a hand-edited kilometre figure)', function () {
    [$route, $stops, $routeStops, $makeTrip] = drFixture('free_pin');
    drArea($route, $stops['Stop B'], $stops['Stop D'], 'Inner Zone X');
    $trip = $makeTrip();
    $query = ['boarding_stop_id' => $stops['Stop A']->stop_id, 'dropoff_lat' => 7.31, 'dropoff_lng' => 125.0];
    drTimeline($this, $trip, $query)->assertSuccessful();

    // Editing the km figure through the model copies it into the metres column, 1 km off the line.
    $routeStops['Stop D']->update(['distance_from_origin_km' => 31]);

    $response = drTimeline($this, $trip, $query)->assertStatus(422);
    expect($response->json('errors.dropoff.0'))->toContain('has to be reviewed');
    $resolved = app(DropoffRestrictionService::class)->forRoute($route->route_id)->first()['resolved'];
    expect($resolved['valid'])->toBeFalse()->and($resolved['reason'])->toContain('no longer matches the route line');
});
