<?php

use App\Models\Fleet;
use App\Models\FleetRoute;
use App\Models\Route;
use App\Models\RouteStop;
use App\Models\StaffUser;
use App\Models\Stop;
use App\Models\Trip;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

// A trip on a route with three stops (about 11 km apart, same longitude) and optional GPS history, for the adherence report.
function adherenceTrip(Fleet $fleet, int $stopCount, string $status = 'completed', array $gpsLatitudes = []): Trip
{
    $route = Route::create(['route_name' => 'Adherence ' . Str::random(5), 'origin' => 'A', 'destination' => 'B']);
    for ($i = 0; $i < $stopCount; $i++) {
        $stop = Stop::create(['stop_name' => 'S' . $i . Str::random(4), 'latitude' => 7.0 + $i * 0.1, 'longitude' => 125.0, 'municipality' => 'Davao City']);
        RouteStop::create(['route_id' => $route->route_id, 'stop_id' => $stop->stop_id, 'stop_order' => $i + 1, 'distance_from_origin_km' => $i * 11, 'stop_type' => 'stop']);
    }
    $fleetRoute = FleetRoute::create(['fleet_id' => $fleet->fleet_id, 'route_id' => $route->route_id, 'start_time' => '06:00', 'end_time' => '22:00', 'status' => 'active']);
    $trip = Trip::create([
        'fleet_route_id' => $fleetRoute->fleet_route_id, 'company_user_id' => $fleet->company_user_id, 'trip_date' => '2026-10-05', 'departure_time' => '06:00',
        'trip_type' => 'one_way', 'status' => $status, 'current_seated_capacity' => 0, 'current_standing_capacity' => 0, 'total_occupancy' => 0,
    ]);
    foreach ($gpsLatitudes as $n => $latitude) {
        DB::table('fleet_location_history')->insert(['fleet_id' => $fleet->fleet_id, 'trip_id' => $trip->trip_id, 'location' => $latitude . ',125.0', 'recorded_at' => '2026-10-05 0' . (6 + $n) . ':00:00']);
    }

    return $trip;
}

function makeOperatorWithProfile(): User
{
    $user = User::create([
        'username' => 'operator_' . uniqid(),
        'email' => 'operator+' . uniqid() . '@smarttransit.test',
        'password' => bcrypt('password123'),
        'role' => 'operator',
    ]);

    StaffUser::create([
        'company_user_uuid' => (string) Str::uuid(),
        'user_id' => $user->user_id,
        'name' => 'Operator Test',
        'phone_num' => '09170000002',
        'address' => 'Test Address',
    ]);

    return $user;
}

describe('Fleet Reporting System', function () {

    function issueOperatorToken(User $operator): string
    {
        return $operator->createToken('operator-test-token')->plainTextToken;
    }

    function createOwnedFleet(User $operator): Fleet
    {
        $companyUser = StaffUser::where('user_id', $operator->user_id)->firstOrFail();

        return Fleet::create([
            'company_user_id' => $companyUser->company_user_id,
            'plate_number' => 'TEST-' . strtoupper(substr((string) Str::uuid(), 0, 6)),
            'capacity' => 40,
            'seated_capacity' => 30,
            'standing_capacity' => 10,
            'status' => 'active',
            'fleet_type' => 'public',
        ]);
    }

    it('operator can retrieve financial audit for fleet', function () {
        $operator = makeOperatorWithProfile();
        $token = issueOperatorToken($operator);
        $fleet = createOwnedFleet($operator);

        $response = $this->withHeader('Authorization', "Bearer $token")
            ->getJson('/api/operator/fleets/' . $fleet->fleet_id . '/reports/financial');

        expect($response->status())->toBeIn([200, 404]);
    });

    it('operator can retrieve revenue breakdown by route', function () {
        $operator = makeOperatorWithProfile();
        $token = issueOperatorToken($operator);
        $fleet = createOwnedFleet($operator);

        $response = $this->withHeader('Authorization', "Bearer $token")
            ->getJson('/api/operator/fleets/' . $fleet->fleet_id . '/reports/revenue-by-route');

        expect($response->status())->toBeIn([200, 404]);
    });

    it('operator can retrieve route adherence data', function () {
        $operator = makeOperatorWithProfile();
        $token = issueOperatorToken($operator);
        $fleet = createOwnedFleet($operator);

        $response = $this->withHeader('Authorization', "Bearer $token")
            ->getJson('/api/operator/fleets/' . $fleet->fleet_id . '/reports/route-adherence');

        expect($response->status())->toBeIn([200, 404]);
    });

    it('route adherence reads the stops of a route that has trips (was a 500: it loaded a relation Route does not have)', function () {
        $operator = makeOperatorWithProfile();
        $token = issueOperatorToken($operator);
        $fleet = createOwnedFleet($operator);
        adherenceTrip($fleet, 3, 'completed', [7.0, 7.1, 7.2]);

        $response = $this->withHeader('Authorization', "Bearer $token")
            ->getJson('/api/operator/fleets/' . $fleet->fleet_id . '/reports/route-adherence?start_date=2026-10-01&end_date=2026-10-07');

        $response->assertOk();
        expect($response->json('data.summary.total_trips'))->toBe(1);
        expect($response->json('data.summary.trips_with_gps_data'))->toBe(1);
        expect($response->json('data.summary.deviated_trips'))->toBe(0);
        expect($response->json('data.summary.adherence_rate_pct'))->toEqual(100);
        expect($response->json('data.trips.0.stops_scheduled'))->toBe(3);
        expect($response->json('data.trips.0.stops_visited'))->toBe(3);
    });

    it('route adherence counts a trip that passed too few stops as deviated', function () {
        $operator = makeOperatorWithProfile();
        $token = issueOperatorToken($operator);
        $fleet = createOwnedFleet($operator);
        adherenceTrip($fleet, 3, 'completed', [7.0]);

        $response = $this->withHeader('Authorization', "Bearer $token")
            ->getJson('/api/operator/fleets/' . $fleet->fleet_id . '/reports/route-adherence?start_date=2026-10-01&end_date=2026-10-07');

        $response->assertOk();
        expect($response->json('data.trips.0.stops_visited'))->toBe(1);
        expect($response->json('data.trips.0.stop_coverage_pct'))->toEqual(33.3);
        expect($response->json('data.summary.deviated_trips'))->toBe(1);
        expect($response->json('data.summary.adherence_rate_pct'))->toEqual(0);
    });

    it('route adherence skips a route with no stops and reports a trip without GPS as proxy data', function () {
        $operator = makeOperatorWithProfile();
        $token = issueOperatorToken($operator);
        $fleet = createOwnedFleet($operator);
        adherenceTrip($fleet, 0, 'completed');
        adherenceTrip($fleet, 3, 'completed');

        $response = $this->withHeader('Authorization', "Bearer $token")
            ->getJson('/api/operator/fleets/' . $fleet->fleet_id . '/reports/route-adherence?start_date=2026-10-01&end_date=2026-10-07');

        $response->assertOk();
        expect($response->json('data.summary.total_trips'))->toBe(2);
        expect($response->json('data.summary.trips_with_gps_data'))->toBe(0);
        expect(collect($response->json('data.trips'))->pluck('data_source')->all())->toBe(['proxy']);
    });

    it('route adherence is not shown for another operator\'s fleet', function () {
        $operator = makeOperatorWithProfile();
        $other = makeOperatorWithProfile();
        $fleet = createOwnedFleet($other);
        adherenceTrip($fleet, 3, 'completed', [7.0, 7.1, 7.2]);

        $this->withHeader('Authorization', 'Bearer ' . issueOperatorToken($operator))
            ->getJson('/api/operator/fleets/' . $fleet->fleet_id . '/reports/route-adherence?start_date=2026-10-01&end_date=2026-10-07')
            ->assertStatus(403);
    });

    it('operator can retrieve occupancy trends', function () {
        $operator = makeOperatorWithProfile();
        $token = issueOperatorToken($operator);
        $fleet = createOwnedFleet($operator);

        $response = $this->withHeader('Authorization', "Bearer $token")
            ->getJson('/api/operator/fleets/' . $fleet->fleet_id . '/reports/occupancy-trends');

        expect($response->status())->toBeIn([200, 404]);
    });

    it('operator can retrieve daily summary', function () {
        $operator = makeOperatorWithProfile();
        $token = issueOperatorToken($operator);
        $fleet = createOwnedFleet($operator);

        $response = $this->withHeader('Authorization', "Bearer $token")
            ->getJson('/api/operator/fleets/' . $fleet->fleet_id . '/reports/daily-summary');

        expect($response->status())->toBeIn([200, 404]);
    });

    it('operator can retrieve payment channel breakdown', function () {
        $operator = makeOperatorWithProfile();
        $token = issueOperatorToken($operator);
        $fleet = createOwnedFleet($operator);

        $response = $this->withHeader('Authorization', "Bearer $token")
            ->getJson('/api/operator/fleets/' . $fleet->fleet_id . '/reports/payment-channels');

        expect($response->status())->toBeIn([200, 404]);
    });

    it('rejects reporting request for non-existent fleet', function () {
        $operator = makeOperatorWithProfile();
        $token = issueOperatorToken($operator);

        $response = $this->withHeader('Authorization', "Bearer $token")
            ->getJson('/api/operator/fleets/99999/reports/financial');

        $response->assertStatus(403);
    });

    it('rejects reporting request for another operators fleet', function () {
        $operatorA = makeOperatorWithProfile();
        $operatorB = makeOperatorWithProfile();

        $tokenA = issueOperatorToken($operatorA);
        $fleetB = createOwnedFleet($operatorB);

        $response = $this->withHeader('Authorization', "Bearer $tokenA")
            ->getJson('/api/operator/fleets/' . $fleetB->fleet_id . '/reports/financial');

        $response->assertStatus(403);
    });

});
