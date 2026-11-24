<?php

use App\Services\FleetPairingService;

// 2026-11-24 (production test): the pairing reply said "14:02:58+00:00" for a pairing made at 14:02 Manila time.
// paired_at keeps Manila wall time; whatever offset the database returns, it is read as Manila time.

it('reads the PostgreSQL value (wall time labelled UTC) as Manila time', function () {
    config(['app.timezone' => 'Asia/Manila']);

    expect(FleetPairingService::pairedAtLocal('2026-11-24 14:02:58+00')->toIso8601String())->toBe('2026-11-24T14:02:58+08:00');
});

it('reads the SQLite value (no offset) as Manila time', function () {
    config(['app.timezone' => 'Asia/Manila']);

    expect(FleetPairingService::pairedAtLocal('2026-11-24 14:02:58')->toIso8601String())->toBe('2026-11-24T14:02:58+08:00');
});
