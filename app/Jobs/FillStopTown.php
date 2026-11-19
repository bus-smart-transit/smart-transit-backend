<?php

namespace App\Jobs;

use App\Repositories\StopRepository;
use App\Services\RegionPlaceService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Batch 52 item 1 (user, 2026-11-09): a stop without a town gets the region town its position is in (RegionPlaceService,
 * the same lookup as "Are you in {place}?"), so the homepage can list the trips you can board in your town. A town
 * already set (by the Company Admin) is never changed; no answer leaves it empty.
 */
class FillStopTown implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $backoff = 10;

    public function __construct(public int $stopId) {}

    public function handle(StopRepository $stops, RegionPlaceService $places): void
    {
        $stop = $stops->findById($this->stopId);
        if (! $stop || ($stop->municipality ?? '') !== '' || $stop->latitude === null || $stop->longitude === null) {
            return;
        }
        $found = $places->placeAt((float) $stop->latitude, (float) $stop->longitude);
        if ($found) {
            $stops->update($this->stopId, ['municipality' => $found['place']]);
        }
    }
}
