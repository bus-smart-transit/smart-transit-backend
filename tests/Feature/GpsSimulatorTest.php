<?php

use App\Models\Fleet;
use App\Models\FleetRoute;
use App\Models\GpsSimulation;
use App\Models\Route;
use App\Models\RouteStop;
use App\Models\StaffUser;
use App\Models\Stop;
use App\Models\Trip;
use App\Models\User;
use App\Repositories\FleetLocationRepository;
use App\Repositories\RouteGeometryRepository;
use App\Services\FleetLocationService;
use App\Services\GpsSimulationService;
use App\Services\TripProgressService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

// Batch 49 item 4 (user, 2026-11-07): the Super Admin "Take control of GPS location" for a departed trip. The simulated
// position is marked and labelled; nothing that decides uses it; it never goes to the history; the Driver phone is set
// aside while it runs; it stops by itself. Coordinates are SAMPLE test values (a straight line, stops ~10 km apart).

function gsUser(string $role): User
{
    $user = User::create(['username' => 'gs'.Str::random(8), 'email' => Str::random(8).'@example.com', 'password' => bcrypt('password123'), 'role' => $role]);
    if ($role !== 'admin') {
        StaffUser::create(['user_id' => $user->user_id, 'company_user_uuid' => (string) Str::uuid(), 'name' => 'Gs '.$role, 'phone_num' => '09170000091', 'address' => 'x']);
    }

    return $user->fresh();
}

function gsTrip(string $status = 'departed'): array
{
    $operator = gsUser('operator');
    $driver = gsUser('driver');
    $route = Route::create(['route_name' => 'Gs Route', 'origin' => 'A', 'destination' => 'D']);
    $defs = [['Gs A', 7.00, 0], ['Gs B', 7.09, 10], ['Gs C', 7.18, 20], ['Gs D', 7.27, 30]];
    $stops = [];
    foreach ($defs as $i => [$name, $lat, $km]) {
        $stop = Stop::create(['stop_name' => $name, 'latitude' => $lat, 'longitude' => 125.0, 'municipality' => 'Sample City']);
        RouteStop::create(['route_id' => $route->route_id, 'stop_id' => $stop->stop_id, 'stop_order' => $i + 1, 'distance_from_origin_km' => $km, 'stop_type' => $i === 0 || $i === 3 ? 'terminal' : 'stop', 'scheduled_offset_min' => $km]);
        $stops[] = $stop;
    }
    app(RouteGeometryRepository::class)->save($route->route_id, ['type' => 'LineString', 'coordinates' => collect($defs)->map(fn ($d) => [125.0, $d[1]])->all()]);
    $fleet = Fleet::create(['company_user_id' => $operator->companyProfile->company_user_id, 'plate_number' => 'GS-'.fake()->unique()->numerify('####'), 'bus_company_name' => 'Co G', 'body_no' => '2', 'capacity' => 30, 'seated_capacity' => 30, 'standing_capacity' => 0, 'status' => 'active']);
    $fleetRoute = FleetRoute::create(['fleet_id' => $fleet->fleet_id, 'route_id' => $route->route_id, 'start_time' => '06:00', 'end_time' => '22:00', 'status' => 'active']);
    $trip = Trip::create([
        'fleet_route_id' => $fleetRoute->fleet_route_id, 'company_user_id' => $operator->companyProfile->company_user_id,
        'driver_id' => $driver->companyProfile->company_user_id, 'driver_accepted_at' => now(),
        'trip_date' => now('Asia/Manila')->toDateString(), 'departure_time' => '08:00', 'trip_type' => 'one_way', 'leg_direction' => 'outbound',
        'status' => $status, 'current_seated_capacity' => 0, 'current_standing_capacity' => 0, 'total_occupancy' => 0,
    ]);

    return compact('trip', 'fleet', 'stops', 'driver');
}

function gsStart($test, array $f, int $speed = 10)
{
    return $test->actingAs(gsUser('admin'), 'sanctum')->postJson('/api/admin/gps-simulations', ['trip_id' => $f['trip']->trip_id, 'speed' => $speed]);
}

function gsTick(GpsSimulation $simulation): bool
{
    return app(GpsSimulationService::class)->tick((int) $simulation->id, (string) Cache::get('gps-simulation-chain:'.$simulation->id));
}

beforeEach(function () {
    Carbon::setTestNow(Carbon::parse('2026-10-05 08:30:00', 'Asia/Manila'));
    Cache::flush();
    config(['route_geometry.gps.simulator_enabled' => true]);
});
afterEach(fn () => Carbon::setTestNow());

it('is refused while the master switch is off', function () {
    config(['route_geometry.gps.simulator_enabled' => false]);
    $f = gsTrip();

    gsStart($this, $f)->assertStatus(422)->assertJsonPath('errors.simulator.0', 'The GPS simulator is turned off. It is turned on in the server settings (GPS_SIMULATOR_ENABLED).');
    $this->getJson('/api/admin/gps-simulations')->assertOk()->assertJsonPath('data.enabled', false);
});

it('is for the Super Admin only, with its own limits', function () {
    $f = gsTrip();
    $this->actingAs(gsUser('operator'), 'sanctum')->postJson('/api/admin/gps-simulations', ['trip_id' => $f['trip']->trip_id, 'speed' => 1])->assertForbidden();

    $middleware = fn (string $uri, string $method) => collect(app('router')->getRoutes()->getRoutes())
        ->first(fn ($r) => $r->uri() === $uri && in_array($method, $r->methods(), true))->gatherMiddleware();
    expect($middleware('api/admin/gps-simulations', 'POST'))->toContain('throttle:10,1,admin-gps-sim-start')->toContain('admin.audit')
        ->and($middleware('api/admin/gps-simulations/{simulationId}', 'PATCH'))->toContain('throttle:30,1,admin-gps-sim-control');
});

it('only takes control of a trip that has departed, once', function () {
    gsStart($this, gsTrip('scheduled'))->assertStatus(422)->assertJsonPath('errors.trip_id.0', 'Only a trip that has departed can be simulated.');

    $f = gsTrip();
    gsStart($this, $f)->assertCreated();
    gsStart($this, $f)->assertStatus(422)->assertJsonPath('errors.trip_id.0', 'This trip is already simulated. Stop it first.');
});

it('writes a marked position that is shown as simulated but used by nothing that decides, and never history', function () {
    $f = gsTrip();
    gsStart($this, $f)->assertCreated();

    $repo = app(FleetLocationRepository::class);
    expect($repo->getCurrentForTrip($f['fleet']->fleet_id, $f['trip']->trip_id))->toBeNull()
        ->and($repo->getCurrentForTrip($f['fleet']->fleet_id, $f['trip']->trip_id, includeSimulated: true)['simulated'])->toBeTrue()
        ->and(DB::table('fleet_location_history')->count())->toBe(0)
        ->and(DB::table('audit_logs')->where('action', 'gps_simulation.start')->count())->toBe(1);

    // Batch 50 (user, 2026-11-08): while the simulator is switched on, a simulated bus counts for booking a stop ahead of
    // it (the demo); with the switch off its point decides nothing.
    expect(app(TripProgressService::class)->stopIsAhead($f['trip']->fresh(), $f['stops'][2]->stop_id))->toBeTrue();
    config(['route_geometry.gps.simulator_enabled' => false]);
    expect(app(TripProgressService::class)->stopIsAhead($f['trip']->fresh(), $f['stops'][2]->stop_id))->toBeFalse();
});

it('moves the bus along the route at the chosen speed', function () {
    $f = gsTrip();
    gsStart($this, $f, speed: 10)->assertCreated();
    $simulation = GpsSimulation::firstOrFail();

    Carbon::setTestNow(now()->addSeconds(3));
    expect(gsTick($simulation))->toBeTrue();

    // 30 km/h x 10 = 83.3 m a second, 3 seconds.
    expect($simulation->fresh()->along_m)->toEqualWithDelta(250.0, 1.0);
    $lat = app(FleetLocationRepository::class)->getCurrentForTrip($f['fleet']->fleet_id, $f['trip']->trip_id, includeSimulated: true)['latitude'];
    expect($lat)->toBeGreaterThan(7.0);
});

it('pauses, jumps to a stop, resumes and stops when told, and each is audit-logged', function () {
    $f = gsTrip();
    $id = gsStart($this, $f)->assertCreated()->json('data.id');

    $this->patchJson("/api/admin/gps-simulations/{$id}", ['action' => 'pause'])->assertOk()->assertJsonPath('data.status', 'paused');
    Carbon::setTestNow(now()->addSeconds(3));
    expect(gsTick(GpsSimulation::findOrFail($id)))->toBeFalse();

    $this->patchJson("/api/admin/gps-simulations/{$id}", ['action' => 'jump', 'stop_id' => $f['stops'][2]->stop_id])->assertOk();
    $lat = app(FleetLocationRepository::class)->getCurrentForTrip($f['fleet']->fleet_id, $f['trip']->trip_id, includeSimulated: true)['latitude'];
    expect($lat)->toEqualWithDelta(7.18, 0.001);

    $this->patchJson("/api/admin/gps-simulations/{$id}", ['action' => 'resume'])->assertOk()->assertJsonPath('data.status', 'running');
    $this->patchJson("/api/admin/gps-simulations/{$id}", ['action' => 'stop'])->assertOk()->assertJsonPath('data.status', 'stopped');
    $this->patchJson("/api/admin/gps-simulations/{$id}", ['action' => 'pause'])->assertStatus(422);

    expect(DB::table('audit_logs')->whereIn('action', ['gps_simulation.pause', 'gps_simulation.jump', 'gps_simulation.resume', 'gps_simulation.stop'])->count())->toBe(4);
});

it('sets the Driver phone aside while it has control, and lets it back after a stop', function () {
    $f = gsTrip();
    $id = gsStart($this, $f)->assertCreated()->json('data.id');
    $service = app(FleetLocationService::class);
    $driverCompanyUser = (int) $f['driver']->companyProfile->company_user_id;

    expect($service->updateLocation($driverCompanyUser, ['latitude' => 7.05, 'longitude' => 125.0, 'accuracy' => 8]))->toBeTrue();
    expect(app(FleetLocationRepository::class)->getCurrentForTrip($f['fleet']->fleet_id, $f['trip']->trip_id))->toBeNull(); // still the simulated one

    $this->patchJson("/api/admin/gps-simulations/{$id}", ['action' => 'stop'])->assertOk();
    $service->updateLocation($driverCompanyUser, ['latitude' => 7.05, 'longitude' => 125.0, 'accuracy' => 8]);
    expect(app(FleetLocationRepository::class)->getCurrentForTrip($f['fleet']->fleet_id, $f['trip']->trip_id)['simulated'])->toBeFalse();
});

it('stops by itself after the time limit, and when the trip ends', function () {
    $f = gsTrip();
    gsStart($this, $f)->assertCreated();
    $simulation = GpsSimulation::firstOrFail();

    Carbon::setTestNow(now()->addMinutes((int) config('route_geometry.gps.simulator_max_minutes') + 1));
    expect(gsTick($simulation))->toBeFalse()
        ->and($simulation->fresh()->status)->toBe('stopped')
        ->and($simulation->fresh()->stop_reason)->toBe('time_limit');

    $g = gsTrip();
    gsStart($this, $g)->assertCreated();
    $second = GpsSimulation::latest('id')->firstOrFail();
    $g['trip']->update(['status' => 'completed']);
    expect(gsTick($second))->toBeFalse()->and($second->fresh()->stop_reason)->toBe('trip_ended');
});

it('shows the tracking page bus as simulated', function () {
    $f = gsTrip();
    gsStart($this, $f)->assertCreated();

    $overview = $this->getJson('/api/admin/gps-simulations')->assertOk()->json('data');
    expect($overview['enabled'])->toBeTrue()
        ->and($overview['simulations'][0]['status'])->toBe('running')
        ->and(collect($overview['trips'])->firstWhere('trip_id', $f['trip']->trip_id)['simulated'])->toBeTrue();
});
