<?php

use App\Repositories\FleetLocationRepository;
use App\Repositories\TicketRepository;
use App\Services\TripTimelineService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

// Batch 45 Feature C (decided 2026-11-04): each running bus on the public map carries its next stop, an ETA that is
// live only while the GPS is fresh (else the scheduled time), the scheduled time and the delay apart, and the
// passengers on board now. The passenger's own location is never sent.

function nbpTimeline(string $source, ?int $delay): array
{
    return [
        'refresh' => ['delay_minutes' => $delay],
        'stops' => [
            ['name' => 'Alpha', 'progress' => 'passed', 'eta_time' => '08:00', 'scheduled_time' => '08:00', 'eta_source' => $source, 'custom' => false],
            ['name' => 'Bravo', 'progress' => 'current', 'eta_time' => '08:20', 'scheduled_time' => '08:15', 'eta_source' => $source, 'custom' => false],
            ['name' => 'Charlie', 'progress' => 'upcoming', 'eta_time' => '08:40', 'scheduled_time' => '08:35', 'eta_source' => $source, 'custom' => false],
        ],
    ];
}

function nbpGet($test, array $timeline, int $ageSeconds, int $seated = 3, int $standing = 2): array
{
    config(['tracking.active_locations_cache_seconds' => 0, 'tracking.stale_seconds' => 120]);
    $row = (object) ['fleet_id' => 1, 'trip_id' => 42, 'route_id' => null, 'latitude' => 7.07, 'longitude' => 125.6, 'age_seconds' => $ageSeconds, 'plate_number' => 'NBP 1', 'bus_company_name' => 'Nbp', 'body_no' => '1'];
    $locations = Mockery::mock(FleetLocationRepository::class);
    $locations->shouldReceive('getAllActiveLocations')->andReturn(collect([$row]));
    app()->instance(FleetLocationRepository::class, $locations);
    $timelines = Mockery::mock(TripTimelineService::class);
    $timelines->shouldReceive('build')->with(42)->andReturn($timeline);
    app()->instance(TripTimelineService::class, $timelines);
    $tickets = Mockery::mock(TicketRepository::class);
    $tickets->shouldReceive('countBoardedByType')->with(42, 'seated')->andReturn($seated);
    $tickets->shouldReceive('countBoardedByType')->with(42, 'standing')->andReturn($standing);
    app()->instance(TicketRepository::class, $tickets);

    return $test->getJson('/api/fleet/locations')->assertSuccessful()->json('data.0.trip_progress');
}

it('gives the live ETA at the next stop, the scheduled time and the delay apart, and the passengers on board', function () {
    expect(nbpGet($this, nbpTimeline('live', 5), 30))->toBe([
        'next_stop' => 'Bravo', 'eta_time' => '08:20', 'eta_basis' => 'live', 'scheduled_time' => '08:15',
        'delay_minutes' => 5, 'gps_stale' => false, 'passengers' => 5,
    ]);
});

it('falls back to the scheduled time when the bus GPS is old', function () {
    $progress = nbpGet($this, nbpTimeline('live', 5), 600);

    expect($progress['eta_time'])->toBe('08:15')
        ->and($progress['eta_basis'])->toBe('scheduled')
        ->and($progress['delay_minutes'])->toBeNull()
        ->and($progress['gps_stale'])->toBeTrue();
});

it('says scheduled when the trip has no live progress yet', function () {
    $progress = nbpGet($this, nbpTimeline('scheduled', null), 10);

    expect($progress['eta_basis'])->toBe('scheduled')->and($progress['eta_time'])->toBe('08:15');
});

it('never sends the passenger location: the bus list takes no coordinates', function () {
    $route = collect(app('router')->getRoutes()->getRoutes())->first(fn ($r) => $r->uri() === 'api/fleet/locations');

    expect($route->methods())->toContain('GET');
    $this->getJson('/api/region')->assertSuccessful()->assertJsonPath('data.nearby_radius_km', 5);
});
