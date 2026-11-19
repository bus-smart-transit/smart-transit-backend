<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Batch 28 Stage 3, item 9: keep bus position history for a limited time.
 *
 * Deletes ONLY fleet_location_history rows recorded more than N days ago (N from config/retention.php, default 30,
 * or --days). It never touches fleet_locations (the latest position of each bus) or the trips' last acknowledged stop,
 * which are different tables. --dry-run counts and reports what would go and deletes nothing. Rows go in batches so
 * a large backlog does not hold a long lock.
 */
class PruneFleetLocationHistory extends Command
{
    protected $signature = 'fleet:prune-location-history {--days= : Keep this many days (default: config retention.location_history_days)} {--dry-run : Only count what would be deleted} {--batch=5000 : Rows per delete}';

    protected $description = 'Deletes bus position history older than the retention window (never the latest positions or stop acknowledgements).';

    public function handle(): int
    {
        $days = $this->option('days') !== null ? (int) $this->option('days') : (int) config('retention.location_history_days');
        if ($days < 1) {
            $this->error('The retention window must be at least 1 day.');

            return self::FAILURE;
        }

        $cutoff = now('UTC')->subDays($days);
        $base = fn () => DB::table('fleet_location_history')->where('recorded_at', '<', $this->cutoffValue($cutoff));

        $total = (int) DB::table('fleet_location_history')->count();
        $old = (int) $base()->count();

        if ($this->option('dry-run')) {
            $this->info("DRY RUN: {$old} of {$total} history row(s) are older than {$days} day(s) (before {$cutoff->toDateTimeString()} UTC) and would be deleted. Nothing was deleted.");

            return self::SUCCESS;
        }

        $batch = max(100, (int) $this->option('batch'));
        $deleted = 0;
        do {
            $ids = $base()->orderBy('history_id')->limit($batch)->pluck('history_id');
            if ($ids->isEmpty()) {
                break;
            }
            $deleted += DB::table('fleet_location_history')->whereIn('history_id', $ids)->delete();
        } while ($ids->count() === $batch);

        Log::info('Pruned bus position history.', ['deleted' => $deleted, 'older_than_days' => $days]);
        $this->info("Deleted {$deleted} history row(s) older than {$days} day(s). {$total} row(s) were there before.");

        return self::SUCCESS;
    }

    /** PostgreSQL compares a timestamptz with an ISO string that carries its zone; SQLite compares plain UTC text. */
    private function cutoffValue(\Illuminate\Support\Carbon $cutoff): string
    {
        return DB::getDriverName() === 'sqlite' ? $cutoff->format('Y-m-d H:i:s') : $cutoff->format('Y-m-d H:i:s').'+00';
    }
}
