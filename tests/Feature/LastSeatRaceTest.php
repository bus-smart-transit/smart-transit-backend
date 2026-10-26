<?php

use App\Models\Payment;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

require_once __DIR__.'/../Support/last_seat_fixture.php';

// smarttransit_prompt.md §12 / §8: one seat left, two passengers reserve it AT THE SAME MOMENT, from two separate
// processes with their own database connections. Exactly one gets it; the other is refused and leaves nothing behind
// (no payment, no held seat). The seat counter is taken under a row lock (TripService::recordBoardingMultiple) inside
// the transaction that creates the payment. No RefreshDatabase: the workers must see committed rows.
//
// Opt-in, on an isolated PostgreSQL test database (never the app database), for example:
//   CHECKOUT_CONCURRENCY_TESTS=1 DB_CONNECTION=pgsql DB_DATABASE=smart_transit_test php vendor/bin/pest tests/Feature/LastSeatRaceTest.php

it('gives the last seat to exactly one of two passengers checking out at the same moment', function () {
    if (getenv('CHECKOUT_CONCURRENCY_TESTS') !== '1') {
        $this->markTestSkipped('Set CHECKOUT_CONCURRENCY_TESTS=1 only for an isolated, pre-migrated PostgreSQL test database.');
    }
    if (DB::getDriverName() !== 'pgsql' || ! Schema::hasTable('payments') || ! function_exists('proc_open')) {
        $this->markTestSkipped('This cross-process race needs a migrated PostgreSQL test database and proc_open.');
    }

    [$trip, $payload] = lsOneSeatTrip();
    $passengers = [lsPassenger('Racer One'), lsPassenger('Racer Two')];
    $paymentsBefore = Payment::count();

    $barrier = sys_get_temp_dir().DIRECTORY_SEPARATOR.'smart-transit-race-'.Str::random(12);
    mkdir($barrier);
    $goPath = $barrier.DIRECTORY_SEPARATOR.'go';
    $workers = [];

    try {
        foreach ($passengers as $index => $passenger) {
            $spec = [
                'ready_path' => $barrier.DIRECTORY_SEPARATOR.'ready-'.$index, 'go_path' => $goPath,
                'passenger_id' => $passenger->passenger_id, 'idempotency_key' => 'race-'.$index.'-'.Str::random(20), 'payload' => $payload,
            ];
            $pipes = [];
            $process = proc_open([PHP_BINARY, base_path('tests/Support/checkout_race_worker.php')], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, base_path());
            fwrite($pipes[0], json_encode($spec, JSON_THROW_ON_ERROR));
            fclose($pipes[0]);
            $workers[] = ['process' => $process, 'pipes' => $pipes, 'ready_path' => $spec['ready_path']];
        }

        $deadline = microtime(true) + 30;
        while (microtime(true) < $deadline && count(array_filter($workers, fn ($w) => is_file($w['ready_path']))) < 2) {
            usleep(10_000);
        }
        expect(array_filter($workers, fn ($w) => is_file($w['ready_path'])))->toHaveCount(2);
        file_put_contents($goPath, 'go'); // both start at the same moment

        $results = [];
        foreach ($workers as $worker) {
            $stdout = stream_get_contents($worker['pipes'][1]);
            $stderr = stream_get_contents($worker['pipes'][2]);
            fclose($worker['pipes'][1]);
            fclose($worker['pipes'][2]);
            expect(proc_close($worker['process']), $stderr)->toBe(0);
            $results[] = json_decode(trim($stdout), true, flags: JSON_THROW_ON_ERROR);
        }
    } finally {
        foreach ($workers as $worker) {
            if (is_resource($worker['process'])) {
                proc_terminate($worker['process']);
                proc_close($worker['process']);
            }
        }
        foreach (glob($barrier.DIRECTORY_SEPARATOR.'*') ?: [] as $path) {
            @unlink($path);
        }
        @rmdir($barrier);
    }

    expect(collect($results)->pluck('outcome')->sort()->values()->all())->toBe(['refused', 'reserved']);
    expect(collect($results)->firstWhere('outcome', 'refused')['message'])->toBe('This trip is full.');
    expect((int) $trip->fresh()->current_seated_capacity)->toBe(1); // never 2: not oversold
    expect(Payment::count() - $paymentsBefore)->toBe(1);           // the loser left no payment
});
