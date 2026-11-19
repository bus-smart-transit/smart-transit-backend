<?php

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

// Live traffic through the server (TomTom). The browser asks GET /api/traffic/segment; the key stays here.

const TRAFFIC_URL = '/api/traffic/segment?from_lat=7.0701&from_lng=125.6001&to_lat=7.1002&to_lng=125.6203';

function tomtomAnswer(array $summary = []): array
{
    return ['routes' => [['summary' => array_merge([
        'lengthInMeters' => 6600,
        'travelTimeInSeconds' => 840,
        'trafficDelayInSeconds' => 120,
        'noTrafficTravelTimeInSeconds' => 720,
        'historicTrafficTravelTimeInSeconds' => 780,
    ], $summary)]]];
}

beforeEach(function () {
    Cache::flush();
    config(['traffic.tomtom.key' => 'secret-test-key', 'traffic.monthly_budget' => 1000, 'traffic.cache_ttl_s' => 150, 'traffic.failure_cache_ttl_s' => 45]);
});

it('returns the live time, the no-traffic time and the delay, and never the provider key', function () {
    Http::fake(['api.tomtom.com/*' => Http::response(tomtomAnswer())]);

    $response = $this->getJson(TRAFFIC_URL)->assertOk();

    expect($response->json('data'))->toMatchArray([
        'available' => true, 'provider' => 'tomtom', 'travel_time_s' => 840, 'no_traffic_s' => 720, 'historic_s' => 780, 'delay_s' => 120, 'length_m' => 6600, 'cached' => false,
    ]);
    expect($response->getContent())->not->toContain('secret-test-key');
});

it('asks TomTom for a bus with traffic on and both travel times', function () {
    Http::fake(['api.tomtom.com/*' => Http::response(tomtomAnswer())]);

    $this->getJson(TRAFFIC_URL)->assertOk();

    Http::assertSent(function (Request $request) {
        parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);

        return str_contains($request->url(), '/routing/1/calculateRoute/7.0701,125.6001:7.1002,125.6203/json')
            && ($query['travelMode'] ?? null) === 'bus'
            && ($query['traffic'] ?? null) === 'true'
            && ($query['computeTravelTimeFor'] ?? null) === 'all'
            && ($query['key'] ?? null) === 'secret-test-key';
    });
});

it('shares one answer between drivers in the same 100 m cells', function () {
    Http::fake(['api.tomtom.com/*' => Http::response(tomtomAnswer())]);

    $first = $this->getJson('/api/traffic/segment?from_lat=7.0701&from_lng=125.6001&to_lat=7.1002&to_lng=125.6203')->assertOk();
    $second = $this->getJson('/api/traffic/segment?from_lat=7.0704&from_lng=125.5998&to_lat=7.1001&to_lng=125.6204')->assertOk();

    Http::assertSentCount(1);
    expect($first->json('data.cached'))->toBeFalse();
    expect($second->json('data.cached'))->toBeTrue();
    expect($second->json('data.travel_time_s'))->toBe(840);
});

it('asks again for a different place', function () {
    Http::fake(['api.tomtom.com/*' => Http::response(tomtomAnswer())]);

    $this->getJson(TRAFFIC_URL)->assertOk();
    $this->getJson('/api/traffic/segment?from_lat=7.2001&from_lng=125.7001&to_lat=7.2502&to_lng=125.7203')->assertOk();

    Http::assertSentCount(2);
});

it('says unavailable, without calling anyone, when no key is configured', function () {
    config(['traffic.tomtom.key' => '']);
    Http::fake();

    $this->getJson(TRAFFIC_URL)->assertOk()->assertJsonPath('data.available', false)->assertJsonPath('data.reason', 'not_configured');

    Http::assertNothingSent();
});

it('reports a limit reached and remembers it briefly instead of asking again', function () {
    Http::fake(['api.tomtom.com/*' => Http::response(['error' => 'quota'], 429)]);

    $this->getJson(TRAFFIC_URL)->assertOk()->assertJsonPath('data.available', false)->assertJsonPath('data.reason', 'provider_limit');
    $this->getJson(TRAFFIC_URL)->assertOk()->assertJsonPath('data.reason', 'provider_limit');

    Http::assertSentCount(1);
});

it('reports a provider error and a connection failure as unavailable, never as a time', function () {
    Http::fake(['api.tomtom.com/*' => Http::response('boom', 500)]);
    $this->getJson(TRAFFIC_URL)->assertOk()->assertJsonPath('data.available', false)->assertJsonPath('data.reason', 'provider_error');

    Cache::flush();
    Http::fake(fn () => throw new ConnectionException('timed out'));
    $this->getJson(TRAFFIC_URL)->assertOk()->assertJsonPath('data.available', false)->assertJsonPath('data.reason', 'provider_error')->assertJsonMissingPath('data.travel_time_s');
});

it('does not invent a time when the provider answers without one', function () {
    Http::fake(['api.tomtom.com/*' => Http::response(['routes' => [['summary' => ['lengthInMeters' => 1000]]]])]);

    $this->getJson(TRAFFIC_URL)->assertOk()->assertJsonPath('data.available', false)->assertJsonPath('data.reason', 'no_travel_time');
});

it('keeps the no-traffic time empty rather than guessing it when the provider omits it', function () {
    Http::fake(['api.tomtom.com/*' => Http::response(tomtomAnswer(['noTrafficTravelTimeInSeconds' => 0, 'trafficDelayInSeconds' => null]))]);

    $response = $this->getJson(TRAFFIC_URL)->assertOk();

    expect($response->json('data.available'))->toBeTrue();
    expect($response->json('data.no_traffic_s'))->toBeNull();
    expect($response->json('data.delay_s'))->toBeNull();
});

it('stops asking the provider once the monthly budget is used and says so', function () {
    config(['traffic.monthly_budget' => 2]);
    Http::fake(['api.tomtom.com/*' => Http::response(tomtomAnswer())]);

    $this->getJson('/api/traffic/segment?from_lat=7.1001&from_lng=125.6001&to_lat=7.1502&to_lng=125.6203')->assertOk()->assertJsonPath('data.available', true);
    $this->getJson('/api/traffic/segment?from_lat=7.2001&from_lng=125.7001&to_lat=7.2502&to_lng=125.7203')->assertOk()->assertJsonPath('data.available', true);
    $this->getJson('/api/traffic/segment?from_lat=7.3001&from_lng=125.8001&to_lat=7.3502&to_lng=125.8203')
        ->assertOk()->assertJsonPath('data.available', false)->assertJsonPath('data.reason', 'budget_reached');

    Http::assertSentCount(2);
});

it('does not charge the budget for an answer served from the cache', function () {
    config(['traffic.monthly_budget' => 1]);
    Http::fake(['api.tomtom.com/*' => Http::response(tomtomAnswer())]);

    $this->getJson(TRAFFIC_URL)->assertOk();
    $this->getJson(TRAFFIC_URL)->assertOk()->assertJsonPath('data.available', true)->assertJsonPath('data.cached', true);

    Http::assertSentCount(1);
});

it('refuses points outside the service region, missing values and points too far apart', function () {
    Http::fake();

    $this->getJson('/api/traffic/segment?from_lat=14.6&from_lng=121.0&to_lat=14.7&to_lng=121.1')->assertStatus(422);
    $this->getJson('/api/traffic/segment?from_lat=7.07&from_lng=125.6')->assertStatus(422);
    $this->getJson('/api/traffic/segment?from_lat=5.4&from_lng=125.0&to_lat=8.0&to_lng=126.6')->assertStatus(422);

    Http::assertNothingSent();
});

it('works for a guest and for a signed-in user alike', function () {
    Http::fake(['api.tomtom.com/*' => Http::response(tomtomAnswer())]);

    $this->getJson(TRAFFIC_URL)->assertOk()->assertJsonPath('data.available', true);

    $user = App\Models\User::create(['username' => 'traffic'.uniqid(), 'email' => 'traffic'.uniqid().'@example.com', 'password' => bcrypt('password123'), 'role' => 'operator']);
    $token = $user->createToken('t')->plainTextToken;
    $this->withHeader('Authorization', "Bearer $token")->getJson(TRAFFIC_URL)->assertOk()->assertJsonPath('data.available', true);
});
