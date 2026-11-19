<?php

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

// Road-congestion map tiles for the driver map. The browser asks GET /api/traffic/flow/{z}/{x}/{y}.png; the key stays here.

// A real 1x1 transparent PNG, so the test also proves binary pictures survive the cache.
const FLOW_PNG_BASE64 = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==';

// Zoom 12 tile over Davao City (7.07 N, 125.6 E).
const FLOW_URL = '/api/traffic/flow/12/3477/1967.png';

function flowUser(string $role): User
{
    return User::create(['username' => $role.uniqid(), 'email' => uniqid().'@t.test', 'password' => bcrypt('password123'), 'role' => $role]);
}

beforeEach(function () {
    Cache::flush();
    config([
        'traffic.tomtom.key' => 'secret-test-key',
        'traffic.flow_tiles.monthly_budget' => 1000,
        'traffic.flow_tiles.cache_ttl_s' => 120,
        'traffic.failure_cache_ttl_s' => 45,
    ]);
});

it('gives a signed-in driver the congestion picture and never the provider key', function () {
    Http::fake(['api.tomtom.com/*' => Http::response(base64_decode(FLOW_PNG_BASE64), 200, ['Content-Type' => 'image/png'])]);

    $response = $this->actingAs(flowUser('driver'))->get(FLOW_URL)->assertOk();

    expect($response->headers->get('Content-Type'))->toBe('image/png');
    expect($response->getContent())->toBe(base64_decode(FLOW_PNG_BASE64));
});

it('asks TomTom for the relative0 flow tile of the same coordinates', function () {
    Http::fake(['api.tomtom.com/*' => Http::response(base64_decode(FLOW_PNG_BASE64))]);

    $this->actingAs(flowUser('driver'))->get(FLOW_URL)->assertOk();

    Http::assertSent(function (Request $request) {
        parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);

        return str_contains($request->url(), '/traffic/map/4/tile/flow/relative0/12/3477/1967.png')
            && ($query['key'] ?? null) === 'secret-test-key';
    });
});

it('shares one provider request between drivers looking at the same tile', function () {
    Http::fake(['api.tomtom.com/*' => Http::response(base64_decode(FLOW_PNG_BASE64))]);

    $this->actingAs(flowUser('driver'))->get(FLOW_URL)->assertOk();
    $this->actingAs(flowUser('driver'))->get(FLOW_URL)->assertOk();

    Http::assertSentCount(1);
});

it('is for drivers only', function () {
    Http::fake();

    $this->getJson(FLOW_URL)->assertUnauthorized();
    $this->actingAs(flowUser('passenger'))->get(FLOW_URL)->assertForbidden();
    $this->actingAs(flowUser('operator'))->get(FLOW_URL)->assertForbidden();

    Http::assertNothingSent();
});

it('refuses a tile outside the service region or the zoom range without calling the provider', function () {
    Http::fake();
    $driver = flowUser('driver');

    // Zoom 12 tile over Europe, and zoom 3 and 20 (outside the allowed range).
    $this->actingAs($driver)->getJson('/api/traffic/flow/12/2048/1362.png')->assertStatus(422)->assertJsonPath('errors.reason', 'outside_region');
    $this->actingAs($driver)->getJson('/api/traffic/flow/3/6/3.png')->assertStatus(422)->assertJsonPath('errors.reason', 'zoom_out_of_range');
    $this->actingAs($driver)->getJson('/api/traffic/flow/20/859000/492000.png')->assertStatus(422)->assertJsonPath('errors.reason', 'zoom_out_of_range');

    Http::assertNothingSent();
});

it('says so instead of drawing anything when the key is missing', function () {
    config(['traffic.tomtom.key' => '']);
    Http::fake();

    $this->actingAs(flowUser('driver'))->getJson(FLOW_URL)->assertStatus(503)->assertJsonPath('errors.reason', 'not_configured');

    Http::assertNothingSent();
});

it('stops calling TomTom once the monthly tile budget is used', function () {
    config(['traffic.flow_tiles.monthly_budget' => 1]);
    Http::fake(['api.tomtom.com/*' => Http::response(base64_decode(FLOW_PNG_BASE64))]);
    $driver = flowUser('driver');

    $this->actingAs($driver)->get(FLOW_URL)->assertOk();
    $this->actingAs($driver)->getJson('/api/traffic/flow/12/3478/1967.png')->assertStatus(503)->assertJsonPath('errors.reason', 'budget_reached');

    Http::assertSentCount(1);
});

it('reports a provider limit and remembers the failure briefly', function () {
    Http::fake(['api.tomtom.com/*' => Http::response('', 429)]);
    $driver = flowUser('driver');

    $this->actingAs($driver)->getJson(FLOW_URL)->assertStatus(503)->assertJsonPath('errors.reason', 'provider_limit');
    $this->actingAs($driver)->getJson(FLOW_URL)->assertStatus(503)->assertJsonPath('errors.reason', 'provider_limit');

    Http::assertSentCount(1);
});
