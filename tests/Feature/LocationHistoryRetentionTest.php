<?php

use App\Models\Fleet;
use App\Models\FleetRoute;
use App\Models\Route;
use App\Models\StaffUser;
use App\Models\Trip;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

// Batch 28 Stage 3, item 9: bus position history is kept 30 days (adviser decision); the latest position and the
// trip's last acknowledged stop are different data and are never touched.

function retentionSetup(): array
{
    $user = User::create(['username' => 'ret'.Str::random(6), 'email' => Str::random(8).'@example.com', 'password' => bcrypt('password123'), 'role' => 'operator']);
    $operator = StaffUser::create(['user_id' => $user->user_id, 'company_user_uuid' => (string) Str::uuid(), 'name' => 'Ret Op', 'phone_num' => '09170000051', 'address' => 'x']);
    $route = Route::create(['route_name' => 'Ret Route', 'origin' => 'A', 'destination' => 'B']);
    $fleet = Fleet::create(['company_user_id' => $operator->company_user_id, 'plate_number' => 'RET-1', 'capacity' => 40, 'seated_capacity' => 30, 'standing_capacity' => 10, 'status' => 'active']);
    $fleetRoute = FleetRoute::create(['fleet_id' => $fleet->fleet_id, 'route_id' => $route->route_id, 'start_time' => '06:00', 'end_time' => '22:00', 'status' => 'active']);
    $trip = Trip::create(['fleet_route_id' => $fleetRoute->fleet_route_id, 'company_user_id' => $operator->company_user_id, 'trip_date' => '2026-10-04', 'departure_time' => '06:00', 'trip_type' => 'one_way', 'status' => 'departed', 'current_seated_capacity' => 0, 'current_standing_capacity' => 0, 'total_occupancy' => 0]);
    $trip->forceFill(['last_acknowledged_stop_id' => null, 'last_acknowledged_at' => '2026-09-01 08:00:00'])->save();

    return [$fleet, $trip];
}

function retentionRow(int $fleetId, int $tripId, string $recordedAtUtc): int
{
    return DB::table('fleet_location_history')->insertGetId(['fleet_id' => $fleetId, 'trip_id' => $tripId, 'location' => '7.1,125.6', 'recorded_at' => $recordedAtUtc]);
}

beforeEach(fn () => Carbon::setTestNow(Carbon::parse('2026-10-04 12:00:00', 'UTC')));
afterEach(fn () => Carbon::setTestNow());

it('deletes only history rows older than the window and leaves the newer ones, the latest position and the acknowledgement', function () {
    [$fleet, $trip] = retentionSetup();
    $old = retentionRow($fleet->fleet_id, $trip->trip_id, '2026-08-01 10:00:00');       // 64 days
    $edgeOld = retentionRow($fleet->fleet_id, $trip->trip_id, '2026-09-03 11:59:59');   // just over 30 days
    $edgeNew = retentionRow($fleet->fleet_id, $trip->trip_id, '2026-09-04 12:00:01');   // just under 30 days
    $recent = retentionRow($fleet->fleet_id, $trip->trip_id, '2026-10-03 09:00:00');
    DB::table('fleet_locations')->insert(['fleet_id' => $fleet->fleet_id, 'trip_id' => $trip->trip_id, 'location' => '7.1,125.6', 'recorded_at' => '2026-07-01 00:00:00']); // an OLD latest position: still kept

    $this->artisan('fleet:prune-location-history')->assertSuccessful();

    expect(DB::table('fleet_location_history')->pluck('history_id')->all())->toEqualCanonicalizing([$edgeNew, $recent]);
    expect(DB::table('fleet_locations')->count())->toBe(1);
    expect($trip->fresh()->last_acknowledged_at)->not->toBeNull();
    expect([$old, $edgeOld])->each->toBeInt();
});

it('does nothing in a dry run except report how many rows would be deleted', function () {
    [$fleet, $trip] = retentionSetup();
    foreach (['2026-07-01 00:00:00', '2026-08-15 00:00:00', '2026-10-01 00:00:00'] as $at) {
        retentionRow($fleet->fleet_id, $trip->trip_id, $at);
    }

    $this->artisan('fleet:prune-location-history --dry-run')->expectsOutputToContain('2 of 3 history row(s)')->assertSuccessful();

    expect(DB::table('fleet_location_history')->count())->toBe(3);
});

it('keeps 30 days by default, takes the window from config or --days, and refuses a window under a day', function () {
    expect(config('retention.location_history_days'))->toBe(30);
    [$fleet, $trip] = retentionSetup();
    retentionRow($fleet->fleet_id, $trip->trip_id, '2026-09-25 00:00:00'); // 9 days old
    retentionRow($fleet->fleet_id, $trip->trip_id, '2026-10-03 00:00:00');

    config(['retention.location_history_days' => 7]);
    $this->artisan('fleet:prune-location-history')->assertSuccessful();
    expect(DB::table('fleet_location_history')->count())->toBe(1);

    $this->artisan('fleet:prune-location-history --days=0')->assertFailed();
    expect(DB::table('fleet_location_history')->count())->toBe(1);
});

it('is scheduled only when the switch is on, so nothing is deleted before the dry-run report has been seen', function () {
    expect(config('retention.location_history_schedule_enabled'))->toBeFalse();
    $events = collect(app(\Illuminate\Console\Scheduling\Schedule::class)->events())->map(fn ($e) => $e->command);
    expect($events->contains(fn ($c) => str_contains((string) $c, 'fleet:prune-location-history')))->toBeFalse();
    expect($events->contains(fn ($c) => str_contains((string) $c, 'refunds:release-deferred')))->toBeTrue();
});
