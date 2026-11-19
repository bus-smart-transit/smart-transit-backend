<?php

namespace App\Console\Commands;

use App\Jobs\FillStopTown;
use App\Models\Stop;
use Illuminate\Console\Command;

/**
 * Batch 52 item 1: fills the town of every stop that has none (one lookup a second, the Nominatim limit). Run once after
 * the deploy; new and moved stops are filled by FillStopTown on their own.
 */
class FillStopTowns extends Command
{
    protected $signature = 'stops:fill-towns';

    protected $description = 'Fill the town of stops that have none, from their position (one a second)';

    public function handle(): int
    {
        $ids = Stop::query()->where(fn ($q) => $q->whereNull('municipality')->orWhere('municipality', ''))
            ->whereNotNull('latitude')->whereNotNull('longitude')->pluck('stop_id');
        foreach ($ids as $i => $id) {
            if ($i > 0) {
                usleep(1_100_000);
            }
            FillStopTown::dispatchSync((int) $id);
            $town = Stop::query()->whereKey($id)->value('municipality');
            $this->line("stop {$id}: ".($town ?: 'no town found'));
        }
        $this->info("{$ids->count()} stops checked.");

        return self::SUCCESS;
    }
}
