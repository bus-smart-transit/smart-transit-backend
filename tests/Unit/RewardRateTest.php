<?php

use App\Services\RewardService;

// 2026-11-20 (user): points are earned at DECIMAL_RATE (0.1) per PESOS_PER_POINT (PHP 20) paid, kept to two decimals.

it('earns 0.1 point for every PHP 20 paid, kept to two decimals', function () {
    expect(RewardService::pointsFor(130))->toBe(0.65)  // the user's example: (130 / 20) * 0.1
        ->and(RewardService::pointsFor(152))->toBe(0.76)
        ->and(RewardService::pointsFor(20))->toBe(0.1)
        ->and(RewardService::pointsFor(0))->toBe(0.0)
        ->and(RewardService::pointsFor(-5))->toBe(0.0);
});

it('takes back on a partial refund exactly the points the refunded part earned', function () {
    // A PHP 47 fare with a PHP 9 discount refunded: 0.24 earned, 0.19 kept, 0.05 taken back.
    $takenBack = round(RewardService::pointsFor(47) - RewardService::pointsFor(47 - 9), 2);

    expect($takenBack)->toBe(0.05);
});
