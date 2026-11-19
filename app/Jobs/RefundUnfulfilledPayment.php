<?php

namespace App\Jobs;

use App\Services\UnfulfilledPaymentService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Batch 49 item 1 (user approved): a payment that was confirmed after its bus filled up made no ticket. Its full amount
 * is sent back through PayMongo automatically (UnfulfilledPaymentService::submitAutomaticRefund). Queued so the webhook
 * answers at once; safe to run twice (a payment with a refund already sent is skipped). When sending fails, the payment
 * stays in the Operator's refund list to be refunded by hand.
 */
class RefundUnfulfilledPayment implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public function __construct(public int $paymentId) {}

    public function handle(UnfulfilledPaymentService $refunds): void
    {
        $refunds->submitAutomaticRefund($this->paymentId);
    }
}
