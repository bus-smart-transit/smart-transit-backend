<?php

use App\Models\Route;
use App\Models\RouteStop;
use App\Models\Stop;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

// php artisan traffic:check: read-only evidence of live traffic per stretch, with the apps' own labels.

function tcRoute(int $stops = 4): Route
{
    $route = Route::create(['route_name' => 'Tc Route', 'origin' => 'A', 'destination' => 'D']);
    foreach (range(0, $stops - 1) as $i) {
        $stop = Stop::create(['stop_name' => 'Tc stop '.($i + 1), 'latitude' => 7.0 + $i * 0.05, 'longitude' => 125.5 + $i * 0.01, 'municipality' => 'Mati City']);
        RouteStop::create(['route_id' => $route->route_id, 'stop_id' => $stop->stop_id, 'stop_order' => $i + 1, 'distance_from_origin_km' => $i * 6, 'stop_type' => $i === 0 || $i === $stops - 1 ? 'terminal' : 'stop', 'scheduled_offset_min' => $i * 10]);
    }

    return $route;
}

function tcAnswer(int $live, int $free): array
{
    return ['routes' => [['summary' => ['lengthInMeters' => 6000, 'travelTimeInSeconds' => $live, 'noTrafficTravelTimeInSeconds' => $free, 'trafficDelayInSeconds' => max(0, $live - $free)]]]];
}

beforeEach(function () {
    Cache::flush();
    config(['traffic.tomtom.key' => 'secret-test-key', 'traffic.monthly_budget' => 1000, 'traffic.cache_ttl_s' => 150]);
});

it('labels each stretch with the apps\' own thresholds and totals them', function () {
    $route = tcRoute(4);   // 3 stretches
    $answers = [tcAnswer(600, 600), tcAnswer(840, 600), tcAnswer(1200, 600)];   // no delay, +40% (Moderate), +100% (Heavy)
    Http::fake(['api.tomtom.com/*' => Http::sequence()->push($answers[0])->push($answers[1])->push($answers[2])]);

    $this->artisan('traffic:check', ['route' => $route->route_id])
        ->expectsOutputToContain('Normal flow: 1, Moderate: 1, Heavy: 1, Unavailable: 0. Total extra time over the route: 14 min.')
        ->assertSuccessful();
});

it('treats less than a minute of extra time as noise', function () {
    $route = tcRoute(2);
    Http::fake(['api.tomtom.com/*' => Http::response(tcAnswer(630, 600))]);   // 30 s extra on 10 min: 5%, and under a minute

    $this->artisan('traffic:check', ['route' => $route->route_id])
        ->expectsOutputToContain('Normal flow: 1, Moderate: 0, Heavy: 0, Unavailable: 0')
        ->assertSuccessful();
});

it('says Unavailable, with the reason, when the provider cannot answer, and invents nothing', function () {
    $route = tcRoute(2);
    Http::fake(['api.tomtom.com/*' => Http::response('', 429)]);

    $this->artisan('traffic:check', ['route' => $route->route_id])
        ->expectsOutputToContain('Unavailable (provider_limit)')
        ->expectsOutputToContain('Normal flow: 0, Moderate: 0, Heavy: 0, Unavailable: 1')
        ->assertSuccessful();
});

it('only counts the requests on a dry run, and calls nobody', function () {
    $route = tcRoute(5);
    Http::fake();

    $this->artisan('traffic:check', ['route' => $route->route_id, '--dry' => true])
        ->expectsOutputToContain('this would use up to 4 provider requests')
        ->assertSuccessful();

    Http::assertNothingSent();
});

it('refuses a route that does not exist, and a route with fewer than two stops needs no requests', function () {
    Http::fake();
    $this->artisan('traffic:check', ['route' => 99999])->expectsOutputToContain('No such route')->assertFailed();

    $one = tcRoute(1);
    $this->artisan('traffic:check', ['route' => $one->route_id])->expectsOutputToContain('nothing to check')->assertSuccessful();

    Http::assertNothingSent();
});

it('prints the time now in Manila so the output can be kept as evidence of when it was run', function () {
    $route = tcRoute(2);
    Http::fake(['api.tomtom.com/*' => Http::response(tcAnswer(600, 600))]);

    $this->artisan('traffic:check', ['route' => $route->route_id])->expectsOutputToContain('Manila time now:')->assertSuccessful();
});
