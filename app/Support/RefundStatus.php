<?php

namespace App\Support;

/**
 * 2026-11-14 (user): ONE owner for "what refund state is this sale in?". The Operator's Finance page (per ticket) and
 * the passenger's Transaction History (per payment) both ask here, so they can never disagree.
 *
 * Money is compared in centavos. A refund counts only once it SUCCEEDED; one still in progress (pending, waiting for
 * review, or failed and waiting for a retry) reads "refund pending".
 */
final class RefundStatus
{
    public const PAID = 'paid';

    public const REFUNDED = 'refunded';

    public const PARTIALLY_REFUNDED = 'partially_refunded';

    public const REFUND_PENDING = 'refund_pending';

    /** Ticket resolution statuses that mean "a refund is on its way but not done". */
    public const IN_PROGRESS = ['pending', 'needs_review', 'failed'];

    public static function of(int $paidCents, int $refundedCents, bool $inProgress): string
    {
        if ($refundedCents > 0 && ! $inProgress) {
            return $refundedCents >= $paidCents ? self::REFUNDED : self::PARTIALLY_REFUNDED;
        }
        if ($inProgress) {
            return self::REFUND_PENDING;
        }

        return self::PAID;
    }
}
