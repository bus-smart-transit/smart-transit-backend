<?php

namespace App\Console\Commands;

use App\Services\DiscountClaimService;
use App\Services\TicketResolutionService;
use Illuminate\Console\Command;

/**
 * 2026-11-04 (Batch 45, Todo R): asks PayMongo about gateway refunds still pending some minutes after they were
 * requested, so a refund event that never arrives cannot leave a ticket waiting for ever (config/refunds.php,
 * `reconcile`). Settles them by the same rules as the event; hands very old ones to the Operator's review queue.
 * 2026-11-19 (audit): online discount refunds too (DiscountClaimService::reconcilePending).
 */
class ReconcileRefunds extends Command
{
    protected $signature = 'refunds:reconcile';

    protected $description = 'Asks PayMongo about refunds still pending and settles them.';

    public function handle(TicketResolutionService $resolutions, DiscountClaimService $discountClaims): int
    {
        $result = $resolutions->reconcilePending();
        $claims = $discountClaims->reconcilePending();
        $this->info("Settled {$result['settled']} refund(s); sent {$result['review']} to review. Discount refunds: settled {$claims['settled']}, failed {$claims['failed']}.");

        return self::SUCCESS;
    }
}
