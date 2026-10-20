<?php

use App\Jobs\GenerateRouteGeometry;
use App\Models\Fleet;
use App\Models\FleetRoute;
use App\Models\Route;
use App\Models\RouteStop;
use App\Models\StaffUser;
use App\Models\Stop;
use App\Models\Trip;
use App\Models\User;
use App\Repositories\FleetLocationRepository;
use App\Repositories\RouteGeometryRepository;
use App\Repositories\RouteStopRepository;
use App\Repositories\TripRepository;
use App\Services\FleetLocationService;
use App\Services\RouteGeometryGenerator;
use App\Services\RouteService;
use App\Services\RouteSnapService;
use App\Services\StopCoordinateValidator;
use App\Services\StopService;
use App\Support\Geo\GeoLine;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

// Data-driven fixture: any route, any stops. Coordinates are test data only.
function makeRouteWithStops(array $points): Route
{
    $route = Route::create(['origin' => 'A', 'destination' => 'B', 'route_name' => 'Fixture '.Str::random(4)]);
    foreach ($points as $i => [$name, $lat, $lng, $km]) {
        $stop = Stop::create(['stop_name' => $name, 'latitude' => $lat, 'longitude' => $lng]);
        RouteStop::create([
            'route_id' => $route->route_id,
            'stop_id' => $stop->stop_id,
            'stop_order' => $i + 1,
            'distance_from_origin_km' => $km,
        ]);
    }

    return $route;
}

function fixtureLine(): array
{
    return ['type' => 'LineString', 'coordinates' => [[125.0, 7.0], [125.0, 7.1], [125.0, 7.2]]];
}

it('projects a point onto a line with distance to and along the line', function () {
    $projection = GeoLine::project(fixtureLine()['coordinates'], 7.05, 125.001);

    expect($projection['distance_to_line_m'])->toBeGreaterThan(100)->toBeLessThan(120);
    expect($projection['distance_along_m'])->toBeGreaterThan(5500)->toBeLessThan(5600);
    expect($projection['total_length_m'])->toBeGreaterThan(22000)->toBeLessThan(22500);
});

it('mirrors distance along the route for the reverse direction', function () {
    $repo = app(RouteGeometryRepository::class);
    $route = makeRouteWithStops([['A', 7.0, 125.0, 0], ['B', 7.2, 125.0, 22]]);
    $repo->save($route->route_id, fixtureLine());

    $snap = app(RouteSnapService::class);
    $out = $snap->snap($route->route_id, 7.05, 125.0, 'outbound');
    $back = $snap->snap($route->route_id, 7.05, 125.0, 'reverse');

    expect($out['on_route'])->toBeTrue();
    expect(round($out['distance_along_route_m'] + $back['distance_along_route_m']))->toBe(round($out['total_length_m']));
});

it('reverses stop order, sequence and distances for reverse legs', function () {
    $route = makeRouteWithStops([['A', 7.0, 125.0, 0], ['B', 7.1, 125.0, 10], ['C', 7.2, 125.0, 25]]);

    $stops = app(RouteStopRepository::class)->getOrderedStopsForDirection($route->route_id, 'reverse');

    expect($stops->map(fn ($s) => $s->stop->stop_name)->all())->toBe(['C', 'B', 'A']);
    expect($stops->pluck('stop_order')->all())->toBe([1, 2, 3]);
    expect($stops->map(fn ($s) => (float) $s->distance_from_origin_km)->all())->toBe([0.0, 15.0, 25.0]);

    // Stored rows are untouched.
    expect(RouteStop::where('route_id', $route->route_id)->orderBy('stop_order')->first()->stop->stop_name)->toBe('A');
});

it('serves canonical geometry per direction and never per-request routes', function () {
    Http::fake();
    $route = makeRouteWithStops([['A', 7.0, 125.0, 0], ['B', 7.2, 125.0, 22]]);
    app(RouteGeometryRepository::class)->save($route->route_id, fixtureLine());

    $forward = $this->getJson("/api/routes/{$route->route_id}/geometry")->assertSuccessful();
    expect($forward->json('data.geometry.coordinates.0'))->toEqual([125.0, 7.0]);

    $reverse = $this->getJson("/api/routes/{$route->route_id}/geometry?direction=reverse")
        ->assertSuccessful()
        ->assertJsonPath('data.direction', 'reverse');
    expect($reverse->json('data.geometry.coordinates.0'))->toEqual([125.0, 7.2]);

    Http::assertNothingSent();
});

it('reports each source stop offset from the stored line without replacing its coordinates', function () {
    $route = makeRouteWithStops([['A', 7.0, 125.001, 0], ['B', 7.2, 125.001, 22]]);
    app(RouteGeometryRepository::class)->save($route->route_id, fixtureLine());

    $stops = $this->getJson("/api/routes/{$route->route_id}/stops")
        ->assertSuccessful()
        ->json('data');

    expect((float) $stops[0]['latitude'])->toBe(7.0)
        ->and((float) $stops[0]['longitude'])->toBe(125.001)
        ->and($stops[0]['distance_to_line_m'])->toBeGreaterThan(100)
        ->and($stops[0]['distance_to_line_m'])->toBeLessThan(120);
});

it('exposes stop_type and geometry_stale without leaking the raw column', function () {
    $route = makeRouteWithStops([['A', 7.0, 125.0, 0], ['B', 7.1, 125.0, 10], ['C', 7.2, 125.0, 22]]);
    $firstId = RouteStop::where('route_id', $route->route_id)->orderBy('stop_order')->value('id');
    expect(app(RouteStopRepository::class)->updateStopType($route->route_id, $firstId, 'terminal'))->toBeTrue();

    $this->getJson("/api/routes/{$route->route_id}/stops")
        ->assertSuccessful()
        ->assertJsonPath('data.0.stop_type', 'terminal')
        ->assertJsonPath('data.1.stop_type', 'stop');

    expect($route->fresh()->toArray())->not->toHaveKey('route_geometry');
});

it('regenerates geometry and cumulative stop offsets via ORS', function () {
    config(['route_geometry.ors.api_key' => 'test-key']);
    $route = makeRouteWithStops([
        ['A', 7.0, 125.0, 0],
        ['B', 7.1, 125.0, 10],
        ['C', 7.2, 125.0, 20],
    ]);

    Http::fake([
        '*/v2/snap/*' => Http::response(['locations' => [
            ['location' => [125.0002, 7.0001]],
            ['location' => [125.00025, 7.1001]],
            ['location' => [125.0003, 7.2001]],
        ]]),
        '*/v2/directions/*' => Http::response(['features' => [['geometry' => ['coordinates' => [
            [125.0002, 7.0001], [125.00025, 7.1001], [125.0003, 7.2001],
        ]], 'properties' => ['segments' => [
            ['distance' => 5000, 'duration' => 600],
            ['distance' => 8000, 'duration' => 900],
        ]]]]]),
    ]);

    $result = app(RouteGeometryGenerator::class)->generate($route->route_id);

    expect($result['ok'])->toBeTrue();
    expect(app(RouteGeometryRepository::class)->get($route->route_id)['coordinates'])->toHaveCount(3);
    expect(app(RouteGeometryRepository::class)->status($route->route_id)['geometry_stale'])->toBeFalse();
    expect(app(RouteGeometryRepository::class)->status($route->route_id)['geometry_generated_at'])->not->toBeNull();
    $stops = RouteStop::where('route_id', $route->route_id)->orderBy('stop_order')->get();
    expect($stops->pluck('scheduled_offset_min')->map(fn ($minutes) => (int) $minutes)->all())->toBe([0, 10, 25]);
    expect($stops->pluck('distance_from_origin_km')->map(fn ($distance) => (float) $distance)->all())->toBe([0.0, 5.0, 13.0]);
    expect((float) $stops->first()->snapped_latitude)->toBe(7.0001);

    Http::assertSent(fn ($request) => str_contains($request->url(), '/v2/directions/')
        && $request['continue_straight'] === true
        && $request['coordinates'][0] === [125.0002, 7.0001]);
});

it('keeps the previous geometry and flags geometry_stale when generation fails', function () {
    config(['route_geometry.ors.api_key' => 'test-key']);
    $route = makeRouteWithStops([['A', 7.0, 125.0, 0], ['B', 7.2, 125.0, 22]]);
    $repo = app(RouteGeometryRepository::class);
    $repo->save($route->route_id, fixtureLine());

    Http::fake(['*' => Http::response(['error' => 'down'], 500)]);

    $result = app(RouteGeometryGenerator::class)->generate($route->route_id);

    expect($result['ok'])->toBeFalse();
    expect($repo->get($route->route_id))->not->toBeNull();
    expect($repo->status($route->route_id)['geometry_stale'])->toBeTrue();
});

it('flags stale and queues regeneration when a stop is added to a route', function () {
    Queue::fake();
    $route = makeRouteWithStops([['A', 7.0, 125.0, 0], ['B', 7.2, 125.0, 22]]);
    $extra = Stop::create(['stop_name' => 'Mid', 'latitude' => 7.1, 'longitude' => 125.0]);

    $user = User::create(['username' => 'op_'.Str::random(5), 'email' => Str::random(6).'@example.com', 'password' => bcrypt('password'), 'role' => 'operator']);
    StaffUser::create(['user_id' => $user->user_id, 'company_user_uuid' => (string) Str::uuid(), 'name' => 'Op', 'phone_num' => '09123456789', 'address' => 'X']);

    $this->actingAs($user->fresh())
        ->postJson("/api/operator/routes/{$route->route_id}/stops", ['stop_id' => $extra->stop_id, 'stop_order' => 3, 'distance_from_origin_km' => 23])
        ->assertSuccessful();

    Queue::assertPushed(GenerateRouteGeometry::class, fn ($job) => $job->routeId === $route->route_id);
    expect(app(RouteGeometryRepository::class)->status($route->route_id)['geometry_stale'])->toBeTrue();
});

it('rejects invalid stop coordinates on create', function (float $lat, float $lng) {
    $user = User::create(['username' => 'op_'.Str::random(5), 'email' => Str::random(6).'@example.com', 'password' => bcrypt('password'), 'role' => 'operator']);
    StaffUser::create(['user_id' => $user->user_id, 'company_user_uuid' => (string) Str::uuid(), 'name' => 'Op', 'phone_num' => '09123456789', 'address' => 'X']);

    $this->actingAs($user->fresh())
        ->postJson('/api/operator/stops', ['stop_name' => 'Bad', 'latitude' => $lat, 'longitude' => $lng])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['latitude']);

    $this->assertDatabaseMissing('stops', ['stop_name' => 'Bad']);
})->with([
    'zero' => [0.0, 0.0],
    'outside area' => [52.02, 4.26],
    'swapped' => [125.6, 7.07],
]);

it('accepts a valid in-area stop coordinate', function () {
    expect(app(StopCoordinateValidator::class)->problems(7.07, 125.6))->toBe([]);
});

it('rejects a stop update when the routing provider cannot snap it near a road', function () {
    config(['route_geometry.ors.api_key' => 'test-key']);
    Http::fake(['*/v2/snap/*' => Http::response(['locations' => [null]])]);
    $operator = makeRouteRedrawStaff('operator');
    $stop = Stop::create(['stop_name' => 'Existing stop', 'latitude' => 7.07, 'longitude' => 125.6]);

    $this->actingAs($operator)
        ->putJson("/api/operator/stops/{$stop->stop_id}", ['latitude' => 7.071, 'longitude' => 125.601])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['latitude']);

    expect((float) $stop->fresh()->latitude)->toBe(7.07)
        ->and((float) $stop->fresh()->longitude)->toBe(125.6);
    Http::assertSent(fn ($request) => str_contains($request->url(), '/v2/snap/'));
});

it('reports invalid stops without modifying them', function () {
    Stop::create(['stop_name' => 'Zero', 'latitude' => 0, 'longitude' => 0]);
    Stop::create(['stop_name' => 'Swapped', 'latitude' => 125.6, 'longitude' => 7.07]);
    Stop::create(['stop_name' => 'Far', 'latitude' => 52.02, 'longitude' => 4.26]);
    Stop::create(['stop_name' => 'Ok', 'latitude' => 7.07, 'longitude' => 125.6]);

    Artisan::call('stops:report-invalid');
    $output = Artisan::output();

    expect($output)->toContain('null_or_zero')->toContain('swapped')->toContain('out_of_area');

    expect(Stop::where('stop_name', 'Zero')->first()->latitude)->toEqual(0);
});

it('compacts stop sequence and queues geometry when a stop is removed from one route', function () {
    Queue::fake();
    $route = makeRouteWithStops([
        ['A', 7.0, 125.0, 0],
        ['B', 7.1, 125.0, 10],
        ['C', 7.2, 125.0, 20],
        ['D', 7.3, 125.0, 30],
    ]);
    $removedRouteStopId = RouteStop::where('route_id', $route->route_id)->where('stop_order', 2)->value('id');

    expect(app(RouteService::class)->removeStopFromRoute($route->route_id, (int) $removedRouteStopId))->toBeTrue();
    expect(RouteStop::where('route_id', $route->route_id)->orderBy('stop_order')->pluck('stop_order')->map(fn ($order) => (int) $order)->all())->toBe([1, 2, 3]);
    expect(RouteStop::where('route_id', $route->route_id)->orderBy('stop_order')->with('stop')->get()->pluck('stop.stop_name')->all())->toBe(['A', 'C', 'D']);
    Queue::assertPushed(GenerateRouteGeometry::class, fn ($job) => $job->routeId === $route->route_id);
});

it('compacts and redraws every affected route when a global stop is deleted', function () {
    Queue::fake();
    $routeOne = makeRouteWithStops([['A', 7.0, 125.0, 0], ['Shared', 7.1, 125.0, 10], ['C', 7.2, 125.0, 20]]);
    $shared = Stop::where('stop_name', 'Shared')->firstOrFail();
    $routeTwo = makeRouteWithStops([['D', 7.3, 125.0, 0], ['E', 7.4, 125.0, 10], ['Shared', 7.1, 125.0, 20], ['F', 7.5, 125.0, 30]]);
    RouteStop::where('route_id', $routeTwo->route_id)->where('stop_order', 3)->update(['stop_id' => $shared->stop_id]);

    expect(app(StopService::class)->deleteStop($shared->stop_id))->toBeTrue();
    expect(RouteStop::where('route_id', $routeOne->route_id)->orderBy('stop_order')->pluck('stop_order')->map(fn ($order) => (int) $order)->all())->toBe([1, 2]);
    expect(RouteStop::where('route_id', $routeTwo->route_id)->orderBy('stop_order')->pluck('stop_order')->map(fn ($order) => (int) $order)->all())->toBe([1, 2, 3]);
    Queue::assertPushed(GenerateRouteGeometry::class, 2);
});

it('refuses to run the GPS simulator unless explicitly enabled', function () {
    config(['route_geometry.gps.simulator_enabled' => false]);

    $this->artisan('gps:simulate 1')->assertFailed();
});

it('ignores GPS fixes whose accuracy is worse than the configured threshold', function () {
    config(['route_geometry.gps.max_accuracy_m' => 100]);

    $trip = new Trip;
    $trip->trip_id = 9;
    $trip->setRelation('fleetRoute', new FleetRoute(['fleet_id' => 4]));
    $trips = Mockery::mock(TripRepository::class);
    $trips->shouldReceive('findCurrentByDriver')->andReturn($trip);
    $locations = Mockery::mock(FleetLocationRepository::class);
    $locations->shouldReceive('upsertLocation')->twice();

    $service = new FleetLocationService($locations, $trips, app(RouteSnapService::class));

    expect($service->updateLocation(1, ['latitude' => 7.07, 'longitude' => 125.6, 'accuracy' => 850]))->toBeFalse();
    expect($service->updateLocation(1, ['latitude' => 7.07, 'longitude' => 125.6, 'accuracy' => 12]))->toBeTrue();
    expect($service->updateLocation(1, ['latitude' => 7.07, 'longitude' => 125.6]))->toBeTrue();
});

function makeRouteRedrawStaff(string $role): User
{
    $user = User::create([
        'username' => 'route_redraw_'.Str::random(8),
        'email' => Str::random(8).'@example.com',
        'password' => bcrypt('password'),
        'role' => $role,
    ]);
    StaffUser::create([
        'user_id' => $user->user_id,
        'company_user_uuid' => (string) Str::uuid(),
        'name' => 'Route Redraw '.ucfirst($role),
        'phone_num' => '09123456789',
        'address' => 'Test Address',
    ]);

    return $user->fresh();
}

function assignRouteRedrawFleet(Route $route, User $operator): void
{
    $fleet = Fleet::create([
        'company_user_id' => $operator->companyProfile->company_user_id,
        'plate_number' => 'RR-'.Str::upper(Str::random(6)),
        'capacity' => 40,
        'seated_capacity' => 30,
        'standing_capacity' => 10,
        'status' => 'active',
    ]);
    FleetRoute::create([
        'fleet_id' => $fleet->fleet_id,
        'route_id' => $route->route_id,
        'start_time' => '06:00',
        'end_time' => '22:00',
        'status' => 'active',
    ]);
}

it('allows an Operator to queue and audit a redraw for a route assigned to their own fleet', function () {
    config(['cache.default' => 'array', 'route_geometry.redraw_cooldown_minutes' => 15]);
    Cache::flush();
    Queue::fake();
    $operator = makeRouteRedrawStaff('operator');
    $route = makeRouteWithStops([['A', 7.0, 125.0, 0], ['B', 7.2, 125.0, 22]]);
    assignRouteRedrawFleet($route, $operator);

    $this->actingAs($operator)
        ->postJson("/api/operator/routes/{$route->route_id}/geometry/regenerate")
        ->assertSuccessful()
        ->assertJsonPath('data.queued', true);

    Queue::assertPushed(GenerateRouteGeometry::class, fn ($job) => $job->routeId === $route->route_id);
    $this->assertDatabaseHas('audit_logs', [
        'actor_id' => $operator->user_id,
        'action' => 'route.geometry_redraw_queued',
        'subject_type' => 'Route',
        'subject_id' => $route->route_id,
    ]);
    $this->actingAs($operator)->getJson("/api/operator/routes/{$route->route_id}")
        ->assertSuccessful()
        ->assertJsonPath('data.can_regenerate_geometry', true)
        ->assertJsonPath('data.geometry_regeneration_pending', true);
});

it('refuses an Operator redraw for a route assigned only to another company', function () {
    config(['cache.default' => 'array']);
    Cache::flush();
    Queue::fake();
    $operator = makeRouteRedrawStaff('operator');
    $otherOperator = makeRouteRedrawStaff('operator');
    $route = makeRouteWithStops([['A', 7.0, 125.0, 0], ['B', 7.2, 125.0, 22]]);
    assignRouteRedrawFleet($route, $otherOperator);

    $this->actingAs($operator)
        ->postJson("/api/operator/routes/{$route->route_id}/geometry/regenerate")
        ->assertNotFound();
    $this->actingAs($operator)->getJson("/api/operator/routes/{$route->route_id}")
        ->assertSuccessful()
        ->assertJsonPath('data.can_regenerate_geometry', false);
    Queue::assertNothingPushed();
});

it('allows Admin redraws and blocks another redraw during the configured per-route cooldown', function () {
    config(['cache.default' => 'array', 'route_geometry.redraw_cooldown_minutes' => 15]);
    Cache::flush();
    Queue::fake();
    $admin = makeRouteRedrawStaff('admin');
    $route = makeRouteWithStops([['A', 7.0, 125.0, 0], ['B', 7.2, 125.0, 22]]);

    $this->actingAs($admin)
        ->postJson("/api/admin/routes/{$route->route_id}/geometry/regenerate")
        ->assertSuccessful();
    $this->actingAs($admin)->getJson("/api/admin/routes/{$route->route_id}")
        ->assertSuccessful()
        ->assertJsonPath('data.can_regenerate_geometry', true);

    Cache::forget(RouteGeometryGenerator::pendingCacheKey($route->route_id));
    $this->actingAs($admin)
        ->postJson("/api/admin/routes/{$route->route_id}/geometry/regenerate")
        ->assertStatus(429);
    Queue::assertPushed(GenerateRouteGeometry::class, 1);
});
