<?php

namespace App\Console\Commands;

use App\Models\ShiftBlock;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class RepairShiftBlockLegDirections extends Command
{
    protected $signature = 'shift-blocks:repair-leg-directions
                            {--apply : Write the changes (default is a dry run)}
                            {--date= : Only inspect blocks for this scheduled date (Y-m-d)}
                            {--block-id=* : Only inspect specific shift_block_id values}';

    protected $description = 'B1 repair: enforce outbound/reverse alternating leg_direction values for shift-block legs. Dry run unless --apply is given; never touches started or finished legs.';

    public function handle(): int
    {
        $query = ShiftBlock::query()->with(['legs' => function ($q) {
            $q->orderBy('departure_time')->orderBy('trip_id');
        }]);

        if ($this->option('date')) {
            $query->whereDate('scheduled_date', $this->option('date'));
        }

        $blockIds = array_values(array_filter(array_map('intval', (array) $this->option('block-id'))));
        if ($blockIds !== []) {
            $query->whereIn('shift_block_id', $blockIds);
        }

        $blocks = $query->orderBy('shift_block_id')->get();

        $dryRun = ! (bool) $this->option('apply');
        $inspected = 0;
        $updated = 0;
        $unchanged = 0;
        $skipped = 0;

        foreach ($blocks as $block) {
            $inspected++;

            // Guard: do not mutate blocks that have already started/completed.
            if (in_array((string) $block->status, ['in_progress', 'completed'], true)) {
                $skipped++;
                $this->line("skip block {$block->shift_block_id} status={$block->status}");
                continue;
            }

            foreach ($block->legs as $index => $leg) {
                $expected = $this->expectedDirection($index);
                if ((string) $leg->leg_direction === $expected) {
                    $unchanged++;
                    continue;
                }

                // Leg-level guard: only legs that have not started may change.
                if (! in_array((string) $leg->status, ['scheduled', 'delayed'], true)) {
                    $skipped++;
                    $this->line("skip trip {$leg->trip_id} status={$leg->status}");
                    continue;
                }

                if ($dryRun) {
                    $this->line("would update trip {$leg->trip_id}: {$leg->leg_direction} -> {$expected}");
                    $updated++;
                    continue;
                }

                DB::table('trips')
                    ->where('trip_id', $leg->trip_id)
                    ->update(['leg_direction' => $expected, 'updated_at' => now()]);

                $updated++;
                $this->line("updated trip {$leg->trip_id}: {$leg->leg_direction} -> {$expected}");
            }
        }

        $this->newLine();
        $this->info('repair summary');
        $this->line("blocks_inspected={$inspected}");
        $this->line("legs_updated_or_would_update={$updated}");
        $this->line("legs_unchanged={$unchanged}");
        $this->line("blocks_skipped_started_or_completed={$skipped}");
        $this->line('mode='.($dryRun ? 'dry-run' : 'apply'));

        return self::SUCCESS;
    }

    private function expectedDirection(int $index): string
    {
        return $index % 2 === 0 ? 'outbound' : 'reverse';
    }
}
