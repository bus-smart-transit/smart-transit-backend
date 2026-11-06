<?php

use App\Models\StaffUser;
use App\Models\User;
use App\Repositories\FleetLocationRepository;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

// Batch 45 Todo 2 (approved 2026-11-04): 1) limits on the public routes that had none and on Operator actions that
// use outside services; 2) forgot-password, verify-email resend and guest ticket lookup counted per email, account
// or reference + IP with a wider per-IP cap; 3) the public map's bus list allowed 300 a minute and cached briefly.

beforeEach(fn () => config(['app.rate_limiting_enabled' => true]));

function rlaStatuses($test, string $method, string $url, int $times, array $data = [], string $ip = '10.1.0.1'): array
{
    $statuses = [];
    for ($i = 0; $i < $times; $i++) {
        $statuses[] = $test->withServerVariables(['REMOTE_ADDR' => $ip])->json($method, $url, $data)->status();
    }

    return $statuses;
}

function rlaOperator(): User
{
    $user = User::create(['username' => 'rlaop', 'email' => 'rla.operator@example.com', 'password' => 'right-password-1', 'role' => 'operator']);
    StaffUser::create(['user_id' => $user->user_id, 'company_user_uuid' => (string) Str::uuid(), 'name' => 'Rla Operator', 'phone_num' => '09170000095', 'address' => 'x']);

    return $user;
}

function rlaMiddleware(string $method, string $uri): array
{
    return collect(app('router')->getRoutes()->getRoutes())
        ->first(fn ($route) => $route->uri() === $uri && in_array($method, $route->methods(), true))
        ->gatherMiddleware();
}

// 2026-11-06: public read pages allow 240 a minute per IP (was 60), so a group on one Wi-Fi is not refused; each route
// still has its own counter, and another IP is never affected.
it('stops a public read page after 240 calls a minute from one IP, without touching another route or IP', function () {
    $statuses = rlaStatuses($this, 'GET', '/api/fare/options', 241);

    expect(array_slice($statuses, 0, 240))->not->toContain(429)
        ->and(end($statuses))->toBe(429)
        ->and(rlaStatuses($this, 'GET', '/api/fare/options', 1, [], '10.1.0.2')[0])->not->toBe(429)
        ->and(rlaStatuses($this, 'GET', '/api/region', 1)[0])->not->toBe(429);
});

it('gives every public read page the shared-network limit', function (string $method, string $uri) {
    expect(rlaMiddleware($method, $uri))->toContain('throttle:public-read');
})->with([
    ['POST', 'api/fare/quote'],
    ['POST', 'api/fare/quote-fleets-by-location'],
    ['GET', 'api/routes/{routeId}/fares'],
    ['GET', 'api/routes/{routeId}/stops'],
    ['GET', 'api/trips'],
]);

it('lets many people on one network sign up, while one email is still limited', function () {
    $body = fn (string $email) => ['email' => $email, 'password' => 'password123', 'password_confirmation' => 'password123', 'name' => 'Group Tester', 'phone_num' => '09171234567', 'address' => 'Tagum', 'birthdate' => '2000-01-01'];

    $group = collect(range(1, 8))->map(fn ($i) => $this->withServerVariables(['REMOTE_ADDR' => '10.9.0.1'])->postJson('/api/passengers/register', $body("group{$i}@example.com"))->status());
    $same = collect(range(1, 4))->map(fn () => $this->withServerVariables(['REMOTE_ADDR' => '10.9.0.1'])->postJson('/api/passengers/register', $body('group1@example.com'))->status());

    expect($group->all())->not->toContain(429)
        ->and($same->last())->toBe(429);
});

it('gives the Admin the same 10-a-minute limits on accounts, stops and routes', function (string $method, string $uri, string $throttle) {
    expect(rlaMiddleware($method, $uri))->toContain($throttle);
})->with([
    ['POST', 'api/admin/accounts', 'throttle:10,1,mgmt-accounts-post'],
    ['POST', 'api/admin/stops', 'throttle:10,1,mgmt-stops-post'],
    ['PUT', 'api/admin/stops/{stop}', 'throttle:10,1,mgmt-stops-put'],
    ['POST', 'api/admin/routes', 'throttle:10,1,mgmt-routes-post'],
]);

it('limits Operator actions that send email or call map services to 10 a minute', function (string $method, string $url) {
    $this->actingAs(rlaOperator(), 'sanctum');
    $statuses = rlaStatuses($this, $method, $url, 11);

    expect(array_slice($statuses, 0, 10))->not->toContain(429)
        ->and(end($statuses))->toBe(429);
})->with([
    ['POST', '/api/operator/accounts'],
    ['POST', '/api/operator/stops'],
    ['PUT', '/api/operator/stops/999999'],
    ['POST', '/api/operator/routes'],
]);

it('counts forgot-password per email + IP, keeps passenger and staff pages apart, and caps one IP at 20', function () {
    $mine = rlaStatuses($this, 'POST', '/api/passengers/forgot-password', 4, ['email' => 'rla.one@example.com']);
    expect(array_slice($mine, 0, 3))->not->toContain(429)
        ->and(end($mine))->toBe(429);

    // Someone else on the same address, and the staff page, still work.
    expect(rlaStatuses($this, 'POST', '/api/passengers/forgot-password', 1, ['email' => 'rla.two@example.com'])[0])->not->toBe(429)
        ->and(rlaStatuses($this, 'POST', '/api/staff/forgot-password', 1, ['email' => 'rla.one@example.com'])[0])->not->toBe(429);

    // Letter case does not make a new email.
    expect(rlaStatuses($this, 'POST', '/api/passengers/forgot-password', 1, ['email' => 'RLA.One@Example.com'])[0])->toBe(429);

    // 20 requests from one address in all. A refused request is not counted, so 4 so far on the passenger page
    // (3 for rla.one, 1 for rla.two); the staff page counts apart.
    for ($i = 1; $i <= 16; $i++) {
        expect(rlaStatuses($this, 'POST', '/api/passengers/forgot-password', 1, ['email' => "rla.many{$i}@example.com"])[0])->not->toBe(429);
    }
    expect(rlaStatuses($this, 'POST', '/api/passengers/forgot-password', 1, ['email' => 'rla.last@example.com'])[0])->toBe(429)
        ->and(rlaStatuses($this, 'POST', '/api/passengers/forgot-password', 1, ['email' => 'rla.last@example.com'], '10.1.0.2')[0])->not->toBe(429);
});

it('counts the verification email resend per account + IP', function () {
    $first = (string) Str::uuid();
    $mine = rlaStatuses($this, 'POST', '/api/passengers/verify-email/resend', 4, ['account_uuid' => $first]);

    expect(array_slice($mine, 0, 3))->not->toContain(429)
        ->and(end($mine))->toBe(429)
        ->and(rlaStatuses($this, 'POST', '/api/passengers/verify-email/resend', 1, ['account_uuid' => (string) Str::uuid()])[0])->not->toBe(429);
});

it('counts guest ticket lookups per reference + IP', function () {
    $mine = rlaStatuses($this, 'GET', '/api/tickets/lookup?transaction_reference=TXN-RLA-A&email=a@example.com', 11);

    expect(array_slice($mine, 0, 10))->not->toContain(429)
        ->and(end($mine))->toBe(429)
        ->and(rlaStatuses($this, 'GET', '/api/tickets/lookup?transaction_reference=TXN-RLA-B&email=a@example.com', 1)[0])->not->toBe(429);
});

it('shares the bus list for a few seconds and still hides the plate from passengers', function () {
    config(['tracking.active_locations_cache_seconds' => 5]);
    $row = (object) ['fleet_id' => 1, 'route_id' => null, 'latitude' => 7.07, 'longitude' => 125.6, 'plate_number' => 'RLA 123', 'bus_company_name' => 'Rla Lines', 'body_no' => '7'];
    $repository = Mockery::mock(FleetLocationRepository::class);
    $repository->shouldReceive('getAllActiveLocations')->once()->andReturn(collect([$row]));
    $this->app->instance(FleetLocationRepository::class, $repository);

    $public = $this->getJson('/api/fleet/locations')->assertSuccessful()->json('data.0');

    $this->actingAs(rlaOperator(), 'sanctum');
    $staff = $this->getJson('/api/fleet/locations')->assertSuccessful()->json('data.0'); // from the cache (once())

    expect($public)->not->toHaveKey('plate_number')
        ->and($staff['plate_number'])->toBe('RLA 123')
        ->and($staff['bus_label'])->toBe($public['bus_label']);
});
