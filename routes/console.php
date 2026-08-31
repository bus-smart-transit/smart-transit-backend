<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Release held seats/standing for online checkouts whose payment never completed and whose hold has expired. Run every 5 minutes.
Schedule::command('payments:release-expired-holds')->everyFiveMinutes();

// Nightly fleet reports: generate daily_summary, financial, occupancy_trends, revenue_by_route for all fleets.
// Runs at 01:00 AM every day, covering the previous calendar day.
Schedule::command('reports:generate')->dailyAt('01:00')->withoutOverlapping();