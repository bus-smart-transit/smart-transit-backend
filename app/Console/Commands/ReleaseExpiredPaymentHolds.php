<?php

namespace App\Console\Commands;

use App\Models\Payment;
use App\Services\TicketService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class ReleaseExpiredPaymentHolds extends Command
{
    protected $signature = 'payments:release-expired-holds';
    protected $description = 'Releases held seat/standing capacity for online checkouts whose payment never completed and whose hold has expired.';

    public function __construct(private TicketService $ticketService)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        $expired = Payment::where('status', 'pending')
            ->where('payment_method', 'online')
            ->where('hold_expires_at', '<=', now())
            ->doesntHave('tickets')
            ->get();

        foreach ($expired as $payment) {
            foreach ($payment->items_payload ?? [] as $reservedItem) {
                $this->ticketService->releaseTicketHold($reservedItem);
            }

            $payment->status = 'expired';
            $payment->is_valid = false;
            $payment->save();

            Log::info('Released expired payment hold.', ['payment_id' => $payment->payment_id]);
        }

        $this->info("Released {$expired->count()} expired payment hold(s).");
        return self::SUCCESS;
    }
}