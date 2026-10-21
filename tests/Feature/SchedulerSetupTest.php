<?php

use Illuminate\Console\Scheduling\Schedule;

// The scheduled jobs (delayed-trip push, release of expired payment holds, deferred refunds, nightly reports) only run
// if the schedule is declared AND something calls it every minute. Both halves are checked here.

it('declares the jobs the system relies on', function () {
    $commands = collect(app(Schedule::class)->events())->map(fn ($event) => (string) $event->command)->implode("\n");

    foreach (['payments:release-expired-holds', 'trips:notify-delayed', 'reports:generate', 'refunds:release-deferred'] as $job) {
        expect($commands)->toContain($job);
    }
});

it('starts the scheduler in the container entrypoint', function () {
    $entrypoint = file_get_contents(base_path('docker-entrypoint.sh'));

    expect($entrypoint)->toContain('php artisan schedule:work');
    // It must run in the background, before the web server takes over the process.
    expect($entrypoint)->toMatch('/schedule:work\s*\n\s*echo [^\n]*\n\s*sleep \d+\s*\n\s*done\s*\n\) &\n/');
    expect(strpos($entrypoint, 'schedule:work'))->toBeLessThan(strpos($entrypoint, 'exec php artisan serve'));
});

it('keeps the queue worker and the scheduler running: each is restarted whenever it exits', function () {
    $entrypoint = file_get_contents(base_path('docker-entrypoint.sh'));

    // queue:work stops by design after --max-time; without a restart a queued route redraw would wait forever.
    foreach (['queue:work', 'schedule:work'] as $process) {
        expect($entrypoint)->toMatch('/while true; do\s*\n\s*php artisan '.preg_quote($process, '/').'[^\n]*\n/');
        expect(strpos($entrypoint, $process))->toBeLessThan(strpos($entrypoint, 'exec php artisan serve'));
    }
});
