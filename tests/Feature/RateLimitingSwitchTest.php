<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\RateLimiter;

uses(RefreshDatabase::class);

// RATE_LIMITING_ENABLED (config app.rate_limiting_enabled) is a dev switch that
// turns every limiter off. Default is on; these tests pin both states.

it('reports too many attempts when rate limiting is on (default)', function () {
    config(['app.rate_limiting_enabled' => true]);

    RateLimiter::hit('switch-test', 60);
    RateLimiter::hit('switch-test', 60);

    expect(RateLimiter::tooManyAttempts('switch-test', 1))->toBeTrue();
});

it('never reports too many attempts when rate limiting is switched off, and keeps counting', function () {
    config(['app.rate_limiting_enabled' => false]);

    RateLimiter::hit('switch-test-off', 60);
    RateLimiter::hit('switch-test-off', 60);

    expect(RateLimiter::tooManyAttempts('switch-test-off', 1))->toBeFalse();

    // Switching it back on applies the counters that were kept meanwhile.
    config(['app.rate_limiting_enabled' => true]);
    expect(RateLimiter::tooManyAttempts('switch-test-off', 1))->toBeTrue();
});

it('lets a throttled route be called past its limit only when rate limiting is off', function () {
    // passengers/verify-otp is throttle:10,15
    config(['app.rate_limiting_enabled' => true]);
    $statuses = [];
    for ($i = 0; $i < 11; $i++) {
        $statuses[] = $this->postJson('/api/passengers/verify-otp', [])->status();
    }
    expect(end($statuses))->toBe(429);

    $this->app['cache']->flush();

    config(['app.rate_limiting_enabled' => false]);
    $statuses = [];
    for ($i = 0; $i < 11; $i++) {
        $statuses[] = $this->postJson('/api/passengers/verify-otp', [])->status();
    }
    expect($statuses)->not->toContain(429);
});

it('allows thirty checkout attempts per minute and throttles the next attempt', function () {
    config(['app.rate_limiting_enabled' => true]);
    $statuses = [];
    for ($attempt = 0; $attempt < 30; $attempt++) {
        $statuses[] = $this->postJson('/api/checkout', [])->status();
    }

    expect($statuses)->not->toContain(429);
    $this->postJson('/api/checkout', [])->assertStatus(429);
});

it('lets staff login keep failing past 5 attempts only when rate limiting is off', function () {
    $payload = ['portal' => 'staff', 'email' => 'nobody@example.com', 'password' => 'wrong-password', 'role' => 'operator'];

    config(['app.rate_limiting_enabled' => false]);
    for ($i = 0; $i < 7; $i++) {
        $response = $this->postJson('/api/auth/login', $payload);
    }
    expect($response->json('errors.email.0') ?? $response->json('message') ?? '')
        ->not->toContain('Too many failed login attempts');

    config(['app.rate_limiting_enabled' => true]);
    $response = $this->postJson('/api/auth/login', $payload);
    expect(json_encode($response->json()))->toContain('Too many failed login attempts');
});
