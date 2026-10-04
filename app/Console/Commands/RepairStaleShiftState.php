<?php

namespace App\Console\Commands;

use App\Models\ConductorShiftLog;
use App\Models\DriverShiftLog;
use App\Models\ShiftBlock;
use App\Models\Ticket;
use App\Models\Trip;
use App\Services\AuditLogger;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Batch 27 Part 2, Task 1c: repairs stale shift state left by earlier days (the dev data had a Chauffeur shift
 * open on yesterday's first leg and a block whose later legs stayed "delayed" forever).
 *
 * DRY RUN BY DEFAULT: without --apply it only prints what it would do and changes nothing.
 *
 * Rules (it never touches a leg that has started or finished):
 *  - Only blocks dated before today (Manila) are looked at.
 *  - A block with any leg that is departed or in-progress is skipped whole and reported.
 *  - A leg still scheduled, delayed or boarding is cancelled, unless it has a valid ticket: then it is
 *    left alone and listed for the Operator (a person must decide about passengers).
 *  - When no live leg is left the block becomes completed (if a leg completed) or cancelled.
 *  - An open driver or Chauffeur shift log whose block (or trip) is finished once this runs is closed
 *    with end_source "repair_stale".
 */
class RepairStaleShiftState extends Command
{
    protected $signature = 'shift:repair-stale {--apply : Write the changes. Without it nothing is changed (dry run).}';

    protected $description = 'Repair stale shift blocks and open shift logs from earlier days (dry run unless --apply)';

    public function handle(): int
    {
        $apply = (bool) $this->option('apply');
        $today = Carbon::now(config('app.timezone', 'Asia/Manila'))->toDateString();
        $this->info(($apply ? 'APPLYING' : 'DRY RUN (nothing is changed; add --apply to write)')." - Manila today is {$today}");

        $legsToCancel = [];
        $blockUpdates = [];
        $leftAlone = [];
        $finishedBlocks = [];

        foreach (ShiftBlock::whereDate('scheduled_date', '<', $today)->whereIn('status', ['scheduled', 'in_progress'])->get() as $block) {
            $legs = Trip::where('shift_block_id', $block->shift_block_id)->orderBy('departure_time')->orderBy('trip_id')->get();

            if ($legs->contains(fn (Trip $l) => in_array($l->status, ['departed', 'in-progress'], true))) {
                $leftAlone[] = ["block {$block->shift_block_id}", 'a leg has started (departed or in-progress): not touched'];

                continue;
            }

            $live = $legs->filter(fn (Trip $l) => in_array($l->status, ['scheduled', 'delayed', 'boarding'], true));
            foreach ($live as $leg) {
                $hasTickets = Ticket::where('trip_id', $leg->trip_id)->where('status', 'valid')->exists();
                if ($hasTickets) {
                    $leftAlone[] = ["trip {$leg->trip_id} (block {$block->shift_block_id})", 'has valid tickets: the Operator must decide'];

                    continue;
                }
                $legsToCancel[] = [$leg->trip_id, $block->shift_block_id, $leg->status];
            }

            $remaining = $legs->filter(fn (Trip $l) => in_array($l->status, ['scheduled', 'delayed', 'boarding'], true)
                && ! in_array($l->trip_id, array_column($legsToCancel, 0), true));
            if ($remaining->isEmpty()) {
                $newStatus = $legs->contains(fn (Trip $l) => $l->status === 'completed') ? 'completed' : 'cancelled';
                $blockUpdates[] = [$block->shift_block_id, $block->status, $newStatus];
                $finishedBlocks[] = $block->shift_block_id;
            }
        }

        // Open shift logs whose shift is finished (or becomes finished by this run).
        $logsToClose = [];
        foreach ([['driver', DriverShiftLog::whereNull('ended_at')->get()], ['conductor', ConductorShiftLog::whereNull('ended_at')->get()]] as [$role, $logs]) {
            foreach ($logs as $log) {
                $trip = Trip::find($log->trip_id);
                if (! $trip || $trip->trip_date?->toDateString() >= $today) {
                    continue;
                }
                $finished = $trip->shift_block_id
                    ? in_array((int) $trip->shift_block_id, $finishedBlocks, true) || ! Trip::where('shift_block_id', $trip->shift_block_id)->whereNotIn('status', ['completed', 'cancelled'])->exists()
                    : in_array($trip->status, ['completed', 'cancelled'], true);
                if ($finished) {
                    $logsToClose[] = [$role, $log->shift_log_id, $log->trip_id];
                } else {
                    $leftAlone[] = ["{$role} shift log {$log->shift_log_id}", 'its trip or block is not finished: not touched'];
                }
            }
        }

        $this->table(['Legs to cancel', 'block', 'was'], $legsToCancel);
        $this->table(['Blocks to close', 'was', 'becomes'], $blockUpdates);
        $this->table(['Open shift logs to close', 'log id', 'trip'], $logsToClose);
        $this->table(['Left alone', 'why'], $leftAlone);

        if (! $apply) {
            $this->comment('Dry run: no changes written.');

            return self::SUCCESS;
        }

        DB::transaction(function () use ($legsToCancel, $blockUpdates, $logsToClose) {
            foreach ($legsToCancel as [$tripId]) {
                Trip::where('trip_id', $tripId)->whereIn('status', ['scheduled', 'delayed', 'boarding'])->update(['status' => 'cancelled']);
            }
            foreach ($blockUpdates as [$blockId, , $newStatus]) {
                ShiftBlock::where('shift_block_id', $blockId)->update(['status' => $newStatus]);
            }
            foreach ($logsToClose as [$role, $logId]) {
                ($role === 'driver' ? DriverShiftLog::class : ConductorShiftLog::class)::where('shift_log_id', $logId)
                    ->whereNull('ended_at')->update(['ended_at' => now(), 'end_source' => 'repair_stale']);
            }
            AuditLogger::logAs('system', null, 'shift.repair_stale', null, null, [
                'legs_cancelled' => array_column($legsToCancel, 0),
                'blocks' => array_column($blockUpdates, 0),
                'shift_logs_closed' => array_column($logsToClose, 1),
            ]);
        });

        $this->info('Applied.');

        return self::SUCCESS;
    }
}
