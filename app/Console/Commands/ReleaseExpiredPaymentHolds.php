<?php

namespace App\Console\Commands;

use App\Models\Payment;
use App\Services\RewardService;
use App\Services\TicketService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class ReleaseExpiredPaymentHolds extends Command
{
    protected $signature = 'payments:release-expired-holds';
    protected $description = 'Releases held seat/standing capacity for online checkouts whose payment never completed and whose hold has expired, and gives back the reward points they used.';

    public function __construct(private TicketService $ticketService, private RewardService $rewardService)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        $candidates = Payment::where('status', 'pending')
            ->where('payment_method', 'online')
            ->where(function ($query) {
                $query->whereNull('idempotency_state')->orWhere('idempotency_state', '!=', 'unknown');
            })
            ->where('hold_expires_at', '<=', now())
            ->doesntHave('tickets')
            ->pluck('payment_id');

        $released = 0;

        foreach ($candidates as $paymentId) {
            $done = DB::transaction(function () use ($paymentId) {
                // Locked and re-read: a paid webhook arriving at this very moment must win, not be released under it.
                $payment = Payment::whereKey($paymentId)->lockForUpdate()->first();
                if (! $payment || $payment->status !== 'pending' || $payment->idempotency_state === 'unknown' || $payment->tickets()->exists()) {
                    return false;
                }

                foreach ($payment->items_payload ?? [] as $reservedItem) {
                    $this->ticketService->releaseTicketHold($reservedItem);
                }

                $payment->status = 'expired';
                $payment->is_valid = false;
                $payment->save();

                // The points this checkout took come back with the seats.
                $this->rewardService->returnRedeemedPoints($payment->payment_id);

                Log::info('Released expired payment hold.', ['payment_id' => $payment->payment_id]);

                return true;
            });

            $released += $done ? 1 : 0;
        }

        $this->info("Released {$released} expired payment hold(s).");

        return self::SUCCESS;
    }
}