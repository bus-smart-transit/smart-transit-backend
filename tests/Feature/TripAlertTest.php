<?php

use App\Models\Company;
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
use App\Models\TripAlert;
use App\Models\User;
use App\Models\UserNotification;
use App\Repositories\FleetLocationRepository;
use Illuminate\Broadcasting\AnonymousEvent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

// Batch 58 (user, 2026-11-10): crew alerts ("Alert operator"). Types Breakdown, Accident, Road blocked; every Operator
// and the Company Admin are told; passengers only after an Operator presses "Tell passengers". SAMPLE names and places.

function taStaff(string $role, Company $company, bool $admin = false, string $name = ''): User
{
    $user = User::create(['username' => 'ta'.Str::random(8), 'email' => Str::random(8).'@example.com', 'password' => bcrypt('password123'), 'role' => $role]);
    StaffUser::create(['user_id' => $user->user_id, 'company_user_uuid' => (string) Str::uuid(), 'name' => $name ?: ucfirst($role).' '.Str::random(3), 'phone_num' => '0917000'.random_int(1000, 9999), 'address' => 'x', 'company_id' => $company->company_id, 'is_company_admin' => $admin]);

    return $user->fresh();
}

/** A company with an admin, a second Operator, a Driver and a Chauffeur, and one trip of theirs in $status. */
function taSetup(string $status = 'departed'): array
{
    $company = Company::create(['company_name' => 'Ta Bus Co '.Str::random(5)]);
    $admin = taStaff('operator', $company, true, 'Ana Admin');
    $operator = taStaff('operator', $company, false, 'Oscar Operator');
    $driver = taStaff('driver', $company, false, 'Dan Driver');
    $chauffeur = taStaff('conductor', $company, false, 'Cora Chauffeur');
    $route = Route::create(['route_name' => 'Ta Route', 'origin' => 'A', 'destination' => 'B']);
    foreach ([[7.0, 0], [7.1, 10]] as $i => [$lat, $km]) {
        $stop = Stop::create(['stop_name' => 'Ta Stop '.$i, 'latitude' => $lat, 'longitude' => 125.0]);
        RouteStop::create(['route_id' => $route->route_id, 'stop_id' => $stop->stop_id, 'stop_order' => $i + 1, 'distance_from_origin_km' => $km, 'stop_type' => 'stop', 'scheduled_offset_min' => $i * 15]);
    }
    $fleet = Fleet::create(['company_user_id' => $admin->companyProfile->company_user_id, 'plate_number' => 'TA-'.fake()->unique()->numerify('####'), 'body_no' => 'TA-01', 'capacity' => 30, 'seated_capacity' => 30, 'standing_capacity' => 0, 'status' => 'active']);
    $fr = FleetRoute::create(['fleet_id' => $fleet->fleet_id, 'route_id' => $route->route_id, 'start_time' => '05:00', 'end_time' => '22:00', 'status' => 'active']);
    $trip = Trip::create(['fleet_route_id' => $fr->fleet_route_id, 'company_user_id' => $operator->companyProfile->company_user_id, 'driver_id' => $driver->companyProfile->company_user_id, 'conductor_id' => $chauffeur->companyProfile->company_user_id, 'trip_date' => now()->toDateString(), 'departure_time' => '10:00', 'trip_type' => 'one_way', 'status' => $status, 'current_seated_capacity' => 0, 'current_standing_capacity' => 0, 'total_occupancy' => 0]);

    return compact('company', 'admin', 'operator', 'driver', 'chauffeur', 'trip', 'fleet');
}

function taPassenger(Trip $trip, bool $withAccount = true): void
{
    $passengerId = null;
    if ($withAccount) {
        $user = User::create(['username' => 'tapax'.Str::random(6), 'email' => Str::random(8).'@example.com', 'password' => 'secret-pass-1', 'role' => 'passenger']);
        $passengerId = PassengerUser::create(['passenger_uuid' => (string) Str::uuid(), 'user_id' => $user->user_id, 'name' => 'Pax Sample', 'phone_num' => '09171234567', 'address' => 'x', 'birthdate' => '1999-05-05', 'gender' => 'female'])->passenger_id;
    }
    $payment = Payment::create(['amount' => 50, 'payment_created' => now(), 'transaction_reference' => 'TXN-TA-'.Str::random(6), 'payment_method' => 'online', 'payment_channel' => 'gcash', 'status' => 'paid', 'payment_uuid' => (string) Str::uuid(), 'is_valid' => true, 'items_payload' => []]);
    Ticket::create(['fleet_route_id' => $trip->fleet_route_id, 'trip_id' => $trip->trip_id, 'payment_id' => $payment->payment_id, 'passenger_id' => $passengerId, 'ticket_uuid' => (string) Str::uuid(), 'status' => 'valid', 'amount' => 50, 'distance_km' => 10, 'seat_type' => 'seated']);
}

function taAs($test, User $user): mixed
{
    app('auth')->forgetGuards();

    return $test->actingAs($user);
}

beforeEach(fn () => Carbon::setTestNow(Carbon::parse('2026-11-10 10:30', 'Asia/Manila')));
afterEach(fn () => Carbon::setTestNow());

it('lets the Driver alert every Operator and the Company Admin, with where the bus was, and not twice', function () {
    $s = taSetup();

    $first = taAs($this, $s['driver'])->postJson("/api/crew/trips/{$s['trip']->trip_id}/alerts", ['type' => 'breakdown', 'note' => 'Flat tyre', 'latitude' => 7.05, 'longitude' => 125.01])
        ->assertCreated()
        ->assertJsonPath('data.type_label', 'Breakdown')
        ->assertJsonPath('data.raised_role', 'driver')
        ->assertJsonPath('data.location_source', 'phone')
        ->assertJsonPath('data.can_cancel', true)
        ->assertJsonPath('data.trip.body_no', 'TA-01')
        ->assertJsonPath('data.trip.fleet_id', $s['fleet']->fleet_id)
        ->json('data');

    foreach (['admin', 'operator'] as $who) {
        $note = UserNotification::where('user_id', $s[$who]->user_id)->where('type', 'crew_alert')->first();
        expect($note)->not->toBeNull()
            ->and($note->title)->toBe('Crew alert: Breakdown')
            ->and($note->body)->toContain('Dan Driver (Driver) reported a breakdown on TA-01 (Ta Route). Note: Flat tyre');
    }
    expect(UserNotification::where('user_id', $s['driver']->user_id)->exists())->toBeFalse();

    // A second press of the same type returns the open alert.
    taAs($this, $s['driver'])->postJson("/api/crew/trips/{$s['trip']->trip_id}/alerts", ['type' => 'breakdown'])
        ->assertOk()->assertJsonPath('data.alert_id', $first['alert_id'])->assertJsonPath('data.repeated', true);
    expect(TripAlert::count())->toBe(1);
});

it('uses the bus\'s recent GPS report as the place when the phone sends none, for the Chauffeur too', function () {
    $s = taSetup();
    app(FleetLocationRepository::class)->upsertLocation(['fleet_id' => $s['fleet']->fleet_id, 'trip_id' => $s['trip']->trip_id, 'latitude' => 7.07, 'longitude' => 125.02]);

    taAs($this, $s['chauffeur'])->postJson("/api/crew/trips/{$s['trip']->trip_id}/alerts", ['type' => 'road_blocked'])
        ->assertCreated()
        ->assertJsonPath('data.raised_role', 'conductor')
        ->assertJsonPath('data.location_source', 'gps')
        ->assertJsonPath('data.latitude', 7.07);
});

it('refuses someone who is not the trip\'s crew, a trip that has not started boarding, and an unknown type', function () {
    $s = taSetup();
    $other = taSetup();

    taAs($this, $other['driver'])->postJson("/api/crew/trips/{$s['trip']->trip_id}/alerts", ['type' => 'breakdown'])->assertForbidden();
    taAs($this, $s['operator'])->postJson("/api/crew/trips/{$s['trip']->trip_id}/alerts", ['type' => 'breakdown'])->assertForbidden();
    taAs($this, $s['driver'])->postJson("/api/crew/trips/{$s['trip']->trip_id}/alerts", ['type' => 'medical'])->assertUnprocessable()->assertJsonValidationErrors('type');

    $s['trip']->update(['status' => 'scheduled']);
    taAs($this, $s['driver'])->postJson("/api/crew/trips/{$s['trip']->trip_id}/alerts", ['type' => 'breakdown'])->assertUnprocessable();
    expect(TripAlert::count())->toBe(0);
});

it('lets the sender take an alert back within the window only, and tells the Operators', function () {
    $s = taSetup();
    $id = taAs($this, $s['driver'])->postJson("/api/crew/trips/{$s['trip']->trip_id}/alerts", ['type' => 'accident'])->json('data.alert_id');

    taAs($this, $s['chauffeur'])->patchJson("/api/crew/alerts/{$id}/cancel")->assertForbidden();
    taAs($this, $s['driver'])->patchJson("/api/crew/alerts/{$id}/cancel")->assertOk()->assertJsonPath('data.status', 'cancelled');
    expect(UserNotification::where('user_id', $s['operator']->user_id)->where('title', 'Crew alert taken back')->exists())->toBeTrue();

    $late = taAs($this, $s['driver'])->postJson("/api/crew/trips/{$s['trip']->trip_id}/alerts", ['type' => 'breakdown'])->json('data.alert_id');
    Carbon::setTestNow(now()->addSeconds((int) config('alerts.cancel_window_seconds') + 1));
    taAs($this, $s['driver'])->patchJson("/api/crew/alerts/{$late}/cancel")->assertUnprocessable();
});

it('shows the company\'s alerts to its Operators only, and an Operator marks one handled once', function () {
    $s = taSetup();
    $other = taSetup();
    $id = taAs($this, $s['driver'])->postJson("/api/crew/trips/{$s['trip']->trip_id}/alerts", ['type' => 'breakdown'])->json('data.alert_id');

    taAs($this, $s['admin'])->getJson('/api/operator/alerts')->assertOk()->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.trip.driver.name', 'Dan Driver')
        ->assertJsonPath('data.0.trip.conductor.name', 'Cora Chauffeur');
    taAs($this, $other['operator'])->getJson('/api/operator/alerts')->assertOk()->assertJsonCount(0, 'data');
    taAs($this, $other['operator'])->patchJson("/api/operator/alerts/{$id}/handle")->assertNotFound();

    taAs($this, $s['operator'])->patchJson("/api/operator/alerts/{$id}/handle", ['note' => 'Spare bus on the way'])
        ->assertOk()->assertJsonPath('data.status', 'handled')->assertJsonPath('data.handled_by', 'Oscar Operator');
    expect(UserNotification::where('user_id', $s['driver']->user_id)->where('type', 'crew_alert_handled')->value('body'))->toContain('Spare bus on the way');
    taAs($this, $s['admin'])->patchJson("/api/operator/alerts/{$id}/handle")->assertUnprocessable();

    taAs($this, $s['driver'])->getJson("/api/crew/trips/{$s['trip']->trip_id}/alerts")->assertOk()
        ->assertJsonPath('data.alerts.0.status', 'handled')
        ->assertJsonPath('data.types', collect(config('alerts.types'))->map(fn ($label, $key) => ['key' => $key, 'label' => $label])->values()->all());
});

it('tells passengers only when an Operator presses it, once, and never for an alert taken back', function () {
    $s = taSetup();
    taPassenger($s['trip']);
    taPassenger($s['trip']);
    taPassenger($s['trip'], false); // a guest: no account to tell
    $id = taAs($this, $s['driver'])->postJson("/api/crew/trips/{$s['trip']->trip_id}/alerts", ['type' => 'breakdown'])->json('data.alert_id');
    expect(UserNotification::where('type', 'trip_alert_notice')->count())->toBe(0); // never automatic

    taAs($this, $s['operator'])->postJson("/api/operator/alerts/{$id}/tell-passengers")
        ->assertOk()->assertJsonPath('data.passengers_told_count', 2)->assertJsonPath('message', '2 passengers were told.');
    $notice = UserNotification::where('type', 'trip_alert_notice')->first();
    expect(UserNotification::where('type', 'trip_alert_notice')->count())->toBe(2)
        ->and($notice->title)->toBe('Your bus is delayed')
        ->and($notice->body)->toStartWith('Ta Route: your bus has a breakdown');
    taAs($this, $s['admin'])->postJson("/api/operator/alerts/{$id}/tell-passengers")->assertUnprocessable();

    $taken = taAs($this, $s['driver'])->postJson("/api/crew/trips/{$s['trip']->trip_id}/alerts", ['type' => 'accident'])->json('data.alert_id');
    taAs($this, $s['driver'])->patchJson("/api/crew/alerts/{$taken}/cancel")->assertOk();
    taAs($this, $s['operator'])->postJson("/api/operator/alerts/{$taken}/tell-passengers")->assertUnprocessable();
});

it('announces each change to the company\'s Operators live', function () {
    config(['broadcasting.default' => 'reverb']);
    Event::fake([AnonymousEvent::class]);
    $s = taSetup();

    taAs($this, $s['driver'])->postJson("/api/crew/trips/{$s['trip']->trip_id}/alerts", ['type' => 'breakdown'])->assertCreated();

    $alerts = Event::dispatched(AnonymousEvent::class)->map(fn ($args) => $args[0])->filter(fn ($e) => $e->broadcastAs() === 'alert')->values();
    expect($alerts)->toHaveCount(1)
        ->and(array_map('strval', (array) $alerts[0]->broadcastOn()))->toBe(['private-company.'.$s['company']->company_id])
        ->and($alerts[0]->broadcastWith()['type'])->toBe('breakdown');
});
