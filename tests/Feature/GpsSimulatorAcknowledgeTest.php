<?php

use App\Models\Fleet;
use App\Models\FleetRoute;
use App\Models\GpsSimulation;
use App\Models\Route;
use App\Models\RouteStop;
use App\Models\StaffUser;
use App\Models\Stop;
use App\Models\Trip;
use App\Models\TripStopAcknowledgement;
use App\Models\User;
use App\Repositories\ReportingRepository;
use App\Repositories\RouteGeometryRepository;
use App\Services\DriverNavigationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

// 2026-11-21 (user, demo): the GPS simulator marks a stop as reached for the Driver. It follows the Driver's tap rule,
// shows on the Driver's screen (with "the simulator has control"), and counts in the route adherence report, labelled
// Simulated. SAMPLE coordinates (a straight line, stops about 10 km apart).

function saUser(string $role): User
{
    $user = User::create(['username' => 'sa'.Str::random(8), 'email' => Str::random(8).'@example.com', 'password' => bcrypt('password123'), 'role' => $role]);
    if ($role !== 'admin') {
        StaffUser::create(['user_id' => $user->user_id, 'company_user_uuid' => (string) Str::uuid(), 'name' => 'Sa '.$role, 'phone_num' => '09170000092', 'address' => 'x']);
    }

    return $user->fresh();
}

function saTrip(): array
{
    $operator = saUser('operator');
    $driver = saUser('driver');
    $route = Route::create(['route_name' => 'Sa Route', 'origin' => 'A', 'destination' => 'D']);
    $defs = [['Sa A', 7.00, 0], ['Sa B', 7.09, 10], ['Sa C', 7.18, 20], ['Sa D', 7.27, 30]];
    $stops = [];
    foreach ($defs as $i => [$name, $lat, $km]) {
        $stop = Stop::create(['stop_name' => $name, 'latitude' => $lat, 'longitude' => 125.0, 'municipality' => 'Sample City']);
        RouteStop::create(['route_id' => $route->route_id, 'stop_id' => $stop->stop_id, 'stop_order' => $i + 1, 'distance_from_origin_km' => $km, 'stop_type' => $i === 0 || $i === 3 ? 'terminal' : 'stop', 'scheduled_offset_min' => $km]);
        $stops[] = $stop;
    }
    app(RouteGeometryRepository::class)->save($route->route_id, ['type' => 'LineString', 'coordinates' => collect($defs)->map(fn ($d) => [125.0, $d[1]])->all()]);
    $fleet = Fleet::create(['company_user_id' => $operator->companyProfile->company_user_id, 'plate_number' => 'SA-'.fake()->unique()->numerify('####'), 'bus_company_name' => 'Co S', 'body_no' => '3', 'capacity' => 30, 'seated_capacity' => 30, 'standing_capacity' => 0, 'status' => 'active']);
    $fleetRoute = FleetRoute::create(['fleet_id' => $fleet->fleet_id, 'route_id' => $route->route_id, 'start_time' => '06:00', 'end_time' => '22:00', 'status' => 'active']);
    $trip = Trip::create([
        'fleet_route_id' => $fleetRoute->fleet_route_id, 'company_user_id' => $operator->companyProfile->company_user_id,
        'driver_id' => $driver->companyProfile->company_user_id, 'driver_accepted_at' => now(),
        'trip_date' => now('Asia/Manila')->toDateString(), 'departure_time' => '08:00', 'trip_type' => 'one_way', 'leg_direction' => 'outbound',
        'status' => 'departed', 'current_seated_capacity' => 0, 'current_standing_capacity' => 0, 'total_occupancy' => 0,
    ]);

    return compact('trip', 'fleet', 'stops', 'driver');
}

beforeEach(function () {
    Carbon::setTestNow(Carbon::parse('2026-10-05 08:30:00', 'Asia/Manila'));
    Cache::flush();
    config(['route_geometry.gps.simulator_enabled' => true]);
});
afterEach(fn () => Carbon::setTestNow());

it('marks a stop as reached for the Driver, who is told the simulator has control', function () {
    $f = saTrip();
    $admin = saUser('admin');
    $this->actingAs($admin, 'sanctum');
    $id = $this->postJson('/api/admin/gps-simulations', ['trip_id' => $f['trip']->trip_id, 'speed' => 10])->assertCreated()->json('data.id');

    $this->patchJson("/api/admin/gps-simulations/{$id}", ['action' => 'acknowledge', 'stop_id' => $f['stops'][1]->stop_id])
        ->assertOk()->assertJsonPath('data.last_acknowledged_stop_id', $f['stops'][1]->stop_id);

    expect($f['trip']->fresh()->last_acknowledged_stop_id)->toBe($f['stops'][1]->stop_id);
    $log = TripStopAcknowledgement::sole();
    expect($log->only(['trip_id', 'stop_id', 'source', 'acknowledged_by_user_id']))->toBe([
        'trip_id' => $f['trip']->trip_id, 'stop_id' => $f['stops'][1]->stop_id, 'source' => 'simulator', 'acknowledged_by_user_id' => $admin->user_id,
    ]);

    $driverView = app(DriverNavigationService::class)->getCurrentTripWithStops($f['driver']->companyProfile->company_user_id);
    expect($driverView['simulated_control'])->toBeTrue()
        ->and(collect($driverView['stops'])->pluck('is_acknowledged')->all())->toBe([true, true, false, false]);

    // Once the simulation stops, the Driver no longer sees it.
    $this->patchJson("/api/admin/gps-simulations/{$id}", ['action' => 'stop'])->assertOk();
    expect(app(DriverNavigationService::class)->getCurrentTripWithStops($f['driver']->companyProfile->company_user_id)['simulated_control'])->toBeFalse();
});

it('refuses a stop that is not on the trip\'s route, and needs a stop', function () {
    $f = saTrip();
    $this->actingAs(saUser('admin'), 'sanctum');
    $id = $this->postJson('/api/admin/gps-simulations', ['trip_id' => $f['trip']->trip_id, 'speed' => 10])->assertCreated()->json('data.id');
    $other = Stop::create(['stop_name' => 'Elsewhere', 'latitude' => 8.0, 'longitude' => 126.0]);

    $this->patchJson("/api/admin/gps-simulations/{$id}", ['action' => 'acknowledge', 'stop_id' => $other->stop_id])
        ->assertStatus(422)->assertJsonPath('errors.stop_id.0', 'This stop cannot be marked as reached: the trip is not on the road, or the stop is not on its route.');
    $this->patchJson("/api/admin/gps-simulations/{$id}", ['action' => 'acknowledge'])->assertStatus(422)->assertJsonValidationErrors('stop_id');
    expect(TripStopAcknowledgement::count())->toBe(0);
});

it('logs the Driver\'s own taps too', function () {
    $f = saTrip();
    expect(app(DriverNavigationService::class)->acknowledgeStop($f['driver']->companyProfile->company_user_id, $f['stops'][2]->stop_id, $f['driver']->user_id))->toBeTrue();

    expect(TripStopAcknowledgement::sole()->only(['stop_id', 'source']))->toBe(['stop_id' => $f['stops'][2]->stop_id, 'source' => 'driver'])
        ->and(app(DriverNavigationService::class)->getCurrentTripWithStops($f['driver']->companyProfile->company_user_id)['simulated_control'])->toBeFalse();
});

it('counts acknowledged stops in the route adherence report and labels simulated ones', function () {
    $f = saTrip();
    $this->actingAs(saUser('admin'), 'sanctum');
    $id = $this->postJson('/api/admin/gps-simulations', ['trip_id' => $f['trip']->trip_id, 'speed' => 10])->assertCreated()->json('data.id');
    foreach ([0, 1, 2] as $i) {
        $this->patchJson("/api/admin/gps-simulations/{$id}", ['action' => 'acknowledge', 'stop_id' => $f['stops'][$i]->stop_id])->assertOk();
    }
    expect(GpsSimulation::find($id)->status)->not->toBe(GpsSimulation::STOPPED);

    $date = now('Asia/Manila')->toDateString();
    $report = app(ReportingRepository::class)->getRouteAdherence($f['fleet']->fleet_id, $date, $date);
    $row = $report['trips'][0];

    expect($row['data_source'])->toBe('acknowledged')
        ->and($row['stops_scheduled'])->toBe(4)
        ->and($row['stops_acknowledged'])->toBe(3)
        ->and($row['stops_visited'])->toBe(3)
        ->and($row['stop_coverage_pct'])->toBe(75.0)
        ->and($row['simulated'])->toBeTrue()
        ->and($row['simulated_acknowledgements'])->toBe(3)
        ->and($report['summary']['deviated_trips'])->toBe(1);
});
