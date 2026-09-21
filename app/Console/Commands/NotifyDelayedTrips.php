<?php

namespace App\Console\Commands;

use App\Services\TripService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class NotifyDelayedTrips extends Command
{
    protected $signature = 'trips:notify-delayed';
    protected $description = 'Sends a push notification to passengers of trips that have been auto-marked as delayed (idempotent — safe to run every minute).';

    public function __construct(private TripService $tripService)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        $notified = $this->tripService->notifyDelayedTrips();

        if ($notified > 0) {
            Log::info('Notified passengers of delayed trips.', ['trip_count' => $notified]);
        }

        $this->info("Notified passengers for {$notified} delayed trip(s).");

        return self::SUCCESS;
    }
}
