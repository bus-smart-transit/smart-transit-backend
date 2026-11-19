<?php

namespace App\Services;

use App\Models\ShiftBlock;
use App\Models\Trip;
use App\Repositories\ShiftBlockRepository;

/**
 * 2026-10-27: a shift block (the morning shift or the afternoon shift of one bus) starts and finishes by itself, from
 * its own legs. This replaces the Driver's "Confirm Takeover" and the Chauffeur's hand-off buttons:
 *
 *  - started: the first time one of its legs starts boarding (or departs), the block becomes in progress.
 *  - legEnded: when its last open leg ends (completed or cancelled), the block is finished. With at least one leg run
 *    it is completed and pending the Operator's review (as the hand-off did); with every leg cancelled it is cancelled.
 *
 * The crew are not signed out here: they finish with End Shift (cash count and hand-over). Two crews cannot run one
 * bus at once: LegProgressionService::startBlockedReason refuses a block's first leg while another block on the
 * same bus is still running. The Operator can still close or start a block by hand (ShiftBlockController overrides).
 */
class ShiftBlockLifecycleService
{
    private const ENDED = ['completed', 'cancelled'];

    public function __construct(private ShiftBlockRepository $blocks) {}

    public function started(int $tripId): void
    {
        $block = $this->blockOf($tripId);
        if ($block && $block->status === 'scheduled') {
            $this->blocks->updateStatus((int) $block->shift_block_id, 'in_progress');
        }
    }

    public function legEnded(int $tripId): void
    {
        $block = $this->blockOf($tripId);
        if (! $block || in_array($block->status, self::ENDED, true)) {
            return;
        }

        $statuses = Trip::where('shift_block_id', $block->shift_block_id)->pluck('status');
        if ($statuses->isEmpty() || $statuses->contains(fn ($status) => ! in_array($status, self::ENDED, true))) {
            return;
        }

        $this->blocks->update((int) $block->shift_block_id, $statuses->contains('completed')
            ? ['status' => 'completed', 'audit_status' => 'pending_review']
            : ['status' => 'cancelled']);
    }

    private function blockOf(int $tripId): ?ShiftBlock
    {
        $blockId = Trip::whereKey($tripId)->value('shift_block_id');

        return $blockId ? ShiftBlock::find($blockId) : null;
    }
}
