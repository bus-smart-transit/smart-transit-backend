<?php

namespace App\Console\Commands;

use App\Services\DiscountClaimService;
use App\Services\TicketResolutionService;
use Illuminate\Console\Command;

/**
 * Batch 28 Stage 3, item 6: sends the gateway refunds that had to wait (Maya accepts only a full refund on the day of
 * the payment, so a partial one waits for the next Manila midnight) once their time has come.
 */
class ReleaseDeferredRefunds extends Command
{
    protected $signature = 'refunds:release-deferred';

    protected $description = 'Sends refunds that were waiting for the payment provider\'s same-day rule to pass.';

    public function handle(TicketResolutionService $resolutions, DiscountClaimService $discountClaims): int
    {
        // Batch 75: approved online discounts paid with Maya wait the same way.
        $count = $resolutions->releaseDeferred() + $discountClaims->releaseDeferred();
        $this->info("Released {$count} deferred refund(s).");

        return self::SUCCESS;
    }
}
