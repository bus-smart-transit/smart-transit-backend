<?php

namespace App\Console\Commands;

use App\Services\TicketResolutionService;
use Illuminate\Console\Command;

/**
 * 2026-11-04 (Batch 45, Todo R): asks PayMongo about gateway refunds still pending some minutes after they were
 * requested, so a refund event that never arrives cannot leave a ticket waiting for ever (config/refunds.php,
 * `reconcile`). Settles them by the same rules as the event; hands very old ones to the Operator's review queue.
 */
class ReconcileRefunds extends Command
{
    protected $signature = 'refunds:reconcile';

    protected $description = 'Asks PayMongo about refunds still pending and settles them.';

    public function handle(TicketResolutionService $resolutions): int
    {
        $result = $resolutions->reconcilePending();
        $this->info("Settled {$result['settled']} refund(s); sent {$result['review']} to review.");

        return self::SUCCESS;
    }
}
