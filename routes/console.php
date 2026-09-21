<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Release held seats/standing for online checkouts whose payment never completed and whose hold has expired. Run every 5 minutes.
Schedule::command('payments:release-expired-holds')->everyFiveMinutes();

// Notifications audit follow-up: passengers of trips auto-marked 'delayed'
// (by any of the read-time TripRepository::markOverdueScheduledAsDelayed()
// call sites) get a push notification. Idempotent via delay_notified_at,
// so overlapping runs are safe.
Schedule::command('trips:notify-delayed')->everyMinute()->withoutOverlapping();

// Nightly fleet reports: generate daily_summary, financial, occupancy_trends, revenue_by_route for all fleets.
// Runs at 01:00 AM every day, covering the previous calendar day.
Schedule::command('reports:generate')->dailyAt('01:00')->withoutOverlapping();