<?php

use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

// 2026-10-27: every throttle counts on its own. Before, a throttle with no name (throttle:30,1) shared ONE counter per
// IP or user with every other unnamed throttle, so the passenger map polling fleet/locations used up the checkout
// return page's payments/status checks, and a 15-minute OTP limit could keep region or tracking blocked for 15
// minutes. No limit changed; only the counters were separated.

beforeEach(fn () => config(['app.rate_limiting_enabled' => true]));

function rlbCall($test, string $url, int $times): array
{
    $statuses = [];
    for ($i = 0; $i < $times; $i++) {
        $statuses[] = $test->getJson($url)->status();
    }

    return $statuses;
}

it('keeps the payment status checks free while the map polls the bus positions', function () {
    rlbCall($this, '/api/fleet/locations', 40);

    expect(rlbCall($this, '/api/payments/status?ref=TXN-RLB-1', 1)[0])->not->toBe(429);
});

it('still stops payment status checks at 30 a minute, counted per payment reference', function () {
    $first = rlbCall($this, '/api/payments/status?ref=TXN-RLB-A', 31);

    expect(array_slice($first, 0, 30))->not->toContain(429)
        ->and(end($first))->toBe(429)
        ->and(rlbCall($this, '/api/payments/status?ref=TXN-RLB-B', 1)[0])->not->toBe(429);
});

it('does not let a 15-minute OTP limit lock the region page', function () {
    for ($i = 0; $i < 6; $i++) {
        $this->postJson('/api/staff/verify-otp', []);
    }

    $region = rlbCall($this, '/api/region', 61);

    expect(array_slice($region, 0, 60))->not->toContain(429)
        ->and(end($region))->toBe(429);
});
