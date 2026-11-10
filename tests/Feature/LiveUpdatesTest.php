<?php

use App\Models\Company;
use App\Models\Fleet;
use App\Models\FleetRoute;
use App\Models\Route;
use App\Models\RouteStop;
use App\Models\StaffUser;
use App\Models\Stop;
use App\Models\Trip;
use App\Models\User;
use App\Repositories\FleetLocationRepository;
use App\Repositories\TripRepository;
use App\Services\FcmService;
use App\Support\HealthBeat;
use App\Support\LiveUpdates;
use Illuminate\Broadcasting\AnonymousEvent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

// Batch 58 (user, 2026-11-10): live updates over WebSocket (Laravel Reverb), sent from one place (LiveUpdates).
// Coordinates are SAMPLE test values.

function lvStaff(string $role, ?int $companyId = null): User
{
    $user = User::create(['username' => 'lv'.Str::random(8), 'email' => Str::random(8).'@example.com', 'password' => bcrypt('password123'), 'role' => $role]);
    StaffUser::create(['user_id' => $user->user_id, 'company_user_uuid' => (string) Str::uuid(), 'name' => ucfirst($role).' Lv', 'phone_num' => '09170000095', 'address' => 'x', 'company_id' => $companyId]);

    return $user->fresh();
}

function lvTrip(string $status): Trip
{
    $operator = lvStaff('operator');
    $route = Route::create(['route_name' => 'Lv Route', 'origin' => 'A', 'destination' => 'B']);
    foreach ([[7.0, 0], [7.1, 10]] as $i => [$lat, $km]) {
        $stop = Stop::create(['stop_name' => 'Lv Stop '.$i, 'latitude' => $lat, 'longitude' => 125.0]);
        RouteStop::create(['route_id' => $route->route_id, 'stop_id' => $stop->stop_id, 'stop_order' => $i + 1, 'distance_from_origin_km' => $km, 'stop_type' => 'stop', 'scheduled_offset_min' => $i * 15]);
    }
    $fleet = Fleet::create(['company_user_id' => $operator->companyProfile->company_user_id, 'plate_number' => 'LV-'.fake()->unique()->numerify('####'), 'capacity' => 30, 'seated_capacity' => 30, 'standing_capacity' => 0, 'status' => 'active']);
    $fr = FleetRoute::create(['fleet_id' => $fleet->fleet_id, 'route_id' => $route->route_id, 'start_time' => '05:00', 'end_time' => '22:00', 'status' => 'active']);

    return Trip::create(['fleet_route_id' => $fr->fleet_route_id, 'company_user_id' => $operator->companyProfile->company_user_id, 'trip_date' => now()->toDateString(), 'departure_time' => '10:00', 'trip_type' => 'one_way', 'status' => $status, 'current_seated_capacity' => 0, 'current_standing_capacity' => 0, 'total_occupancy' => 0]);
}

/** @return list<AnonymousEvent> */
function lvSent(string $name): array
{
    return Event::dispatched(AnonymousEvent::class)
        ->map(fn (array $args) => $args[0])
        ->filter(fn (AnonymousEvent $event) => $event->broadcastAs() === $name)
        ->values()
        ->all();
}

function lvChannelNames(AnonymousEvent $event): array
{
    return array_map(fn ($channel) => (string) $channel, (array) $event->broadcastOn());
}

beforeEach(function () {
    config(['broadcasting.default' => 'reverb']);
    $this->realEvents = Event::getFacadeRoot();
    Event::fake([AnonymousEvent::class]);
});

it('announces a trip status change on the public trip-status channel with the id and status only', function () {
    $trip = lvTrip('scheduled');

    expect(app(TripRepository::class)->updateStatus($trip->trip_id, 'boarding'))->toBeTrue();

    $sent = lvSent('trip.status');
    expect($sent)->toHaveCount(1)
        ->and(lvChannelNames($sent[0]))->toBe([LiveUpdates::TRIP_STATUS])
        ->and($sent[0]->broadcastWith())->toBe(['trip_id' => $trip->trip_id, 'status' => 'boarding']);
});

it('announces a running bus position with its place on the route, and no plate number', function () {
    $trip = lvTrip('departed');

    app(FleetLocationRepository::class)->upsertLocation(['fleet_id' => $trip->fleetRoute->fleet_id, 'trip_id' => $trip->trip_id, 'latitude' => 7.05, 'longitude' => 125.0, 'heading' => 10, 'speed_kmh' => 30]);

    $sent = lvSent('bus.moved');
    expect($sent)->toHaveCount(1)
        ->and(lvChannelNames($sent[0]))->toBe([LiveUpdates::POSITIONS]);
    $payload = $sent[0]->broadcastWith();
    expect($payload)->toMatchArray(['fleet_id' => $trip->fleetRoute->fleet_id, 'trip_id' => $trip->trip_id, 'trip_status' => 'departed', 'latitude' => 7.05])
        ->and($payload)->toHaveKey('route_position')
        ->and(json_encode($payload))->not->toContain($trip->fleetRoute->fleet->plate_number);
});

it('does not announce a bus that is only boarding (the map shows it waiting at its first stop)', function () {
    $trip = lvTrip('boarding');

    app(FleetLocationRepository::class)->upsertLocation(['fleet_id' => $trip->fleetRoute->fleet_id, 'trip_id' => $trip->trip_id, 'latitude' => 7.05, 'longitude' => 125.0]);

    expect(lvSent('bus.moved'))->toBe([]);
});

it('tells a person\'s screens about a new notification on their own private channel', function () {
    $driver = lvStaff('driver');

    app(FcmService::class)->sendToUser($driver, 'Message from your Operator', 'Please call the office.', ['type' => 'crew_message', 'trip_id' => 5]);

    $sent = lvSent('notification');
    expect($sent)->toHaveCount(1)
        ->and(lvChannelNames($sent[0]))->toBe(['private-user.'.$driver->user_id])
        ->and($sent[0]->broadcastWith())->toBe(['type' => 'crew_message', 'trip_id' => 5, 'alert_id' => null]);
});

it('sends nothing when broadcasting is not set to Reverb', function () {
    config(['broadcasting.default' => 'null']);
    $trip = lvTrip('scheduled');

    app(TripRepository::class)->updateStatus($trip->trip_id, 'boarding');

    Event::assertNotDispatched(AnonymousEvent::class);
});

it('never fails the action when the live server cannot be reached, and records it for System Health', function () {
    Event::swap($this->realEvents); // real broadcasting, to a server that is not there
    config(['broadcasting.connections.reverb' => array_merge(config('broadcasting.connections.reverb'), [
        'key' => 'test-key', 'secret' => 'test-secret', 'app_id' => '1',
        'options' => ['host' => '127.0.0.1', 'port' => 1, 'scheme' => 'http', 'useTLS' => false],
    ])]);
    $trip = lvTrip('scheduled');

    expect(app(TripRepository::class)->updateStatus($trip->trip_id, 'boarding'))->toBeTrue()
        ->and($trip->fresh()->status)->toBe('boarding')
        ->and(HealthBeat::last('reverb')['ok'] ?? null)->toBeFalse();
});

it('lets only Operators of the same company listen to the company channel', function () {
    $company = Company::create(['company_name' => 'Lv Co']);
    $other = Company::create(['company_name' => 'Other Lv Co']);
    $operator = lvStaff('operator', $company->company_id);
    $colleague = lvStaff('operator', $company->company_id);
    $outsider = lvStaff('operator', $other->company_id);
    $driver = lvStaff('driver', $company->company_id);
    $key = LiveUpdates::companyKey($operator->companyProfile->company_user_id);

    expect($key)->toBe((string) $company->company_id)
        ->and(LiveUpdates::mayListenToCompany($operator, $key))->toBeTrue()
        ->and(LiveUpdates::mayListenToCompany($colleague, $key))->toBeTrue()
        ->and(LiveUpdates::mayListenToCompany($outsider, $key))->toBeFalse()
        ->and(LiveUpdates::mayListenToCompany($driver, $key))->toBeFalse()
        ->and(LiveUpdates::mayListenToCompany(null, $key))->toBeFalse();
});

it('signs private channels in through the API at /api/broadcasting/auth', function () {
    expect(collect(app('router')->getRoutes())->contains(fn ($route) => $route->uri() === 'api/broadcasting/auth'))->toBeTrue();
});

it('tells each visitor which live channels are theirs (guests: the public ones), and that live updates are off when not set up', function () {
    $company = Company::create(['company_name' => 'Lv Channels Co']);
    $operator = lvStaff('operator', $company->company_id);
    $driver = lvStaff('driver', $company->company_id);

    $this->actingAs($operator)->getJson('/api/live/channels')->assertOk()
        ->assertJsonPath('data.enabled', true)
        ->assertJsonPath('data.positions', LiveUpdates::POSITIONS)
        ->assertJsonPath('data.user', 'user.'.$operator->user_id)
        ->assertJsonPath('data.company', 'company.'.$company->company_id);

    app('auth')->forgetGuards();
    config(['broadcasting.default' => 'log']);
    $this->actingAs($driver)->getJson('/api/live/channels')->assertOk()
        ->assertJsonPath('data.enabled', false)
        ->assertJsonPath('data.company', null);

    app('auth')->forgetGuards();
    $this->getJson('/api/live/channels')->assertOk()->assertJsonPath('data.user', null)->assertJsonPath('data.positions', LiveUpdates::POSITIONS);
});
