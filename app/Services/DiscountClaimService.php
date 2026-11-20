<?php

namespace App\Services;

use App\Models\DiscountClaim;
use App\Models\Payment;
use App\Models\Ticket;
use App\Repositories\DiscountClaimRepository;
use App\Repositories\RewardRepository;
use App\Repositories\TripRepository;
use App\Support\BoardingOpen;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

/**
 * Batch 75 (panel and user, 2026-11-15): the one owner of a discount claimed online ("claim online, verify on board").
 *
 *  - At checkout the passenger may claim one of the discounts the bus's Operator offers (Student, Senior citizen, PWD;
 *    the same types and rates as the cash discounts, OnsiteDiscountService) for each ticket. They pay the FULL fare.
 *  - On the bus the Chauffeur checks the valid ID and approves or rejects the claim, once, while boarding is open
 *    (BoardingOpen). Nothing about the ID is stored.
 *  - Approve: the ticket's fare becomes the discounted fare, recorded like a cash discount (type, label, rate, fare
 *    before it, discount amount, ID checked at), so Finance counts the discounted fare; the difference goes back to the
 *    passenger through PayMongo (a Maya partial refund waits for the next Manila midnight, as for trip refunds); the
 *    reward points earned on the refunded part are taken back. The transaction fee is not refunded.
 *  - Reject: the passenger keeps the full fare.
 *  - A claim nobody reviewed by the end of the trip lapses (full fare kept).
 *  - Only the signed PayMongo refund event decides the refund's final state (handleGatewayRefund), as for every refund.
 */
class DiscountClaimService
{
    public function __construct(
        private DiscountClaimRepository $claims,
        private TripRepository $trips,
        private OnsiteDiscountService $discounts,
        private FareCalculationService $fares,
        private RewardRepository $rewards,
    ) {}

    /**
     * The discount a checkout item claims, checked against what the trip's Operator offers; null when none is claimed.
     *
     * @return array{key: string, label: string, rate: float}|null
     *
     * @throws ValidationException
     */
    public function claimFor(int $tripId, ?string $key): ?array
    {
        if ($key === null || trim($key) === '') {
            return null;
        }
        $trip = $this->trips->findById($tripId);
        $type = $trip ? collect($this->discounts->activeFor((int) $trip->company_user_id))->firstWhere('key', trim($key)) : null;
        if (! $type) {
            throw ValidationException::withMessages(['items' => ['This discount is not offered on this bus.']]);
        }

        return ['key' => $type['key'], 'label' => $type['label'], 'rate' => (float) $type['rate']];
    }

    /**
     * The discounts a passenger may claim online for this trip (what its Operator offers on the bus).
     *
     * @return list<array{key: string, label: string, rate: float}>
     */
    public function offeredFor(int $tripId): array
    {
        $trip = $this->trips->findById($tripId);
        if (! $trip) {
            return [];
        }

        return array_map(
            fn (array $type) => ['key' => $type['key'], 'label' => $type['label'], 'rate' => (float) $type['rate']],
            $this->discounts->activeFor((int) $trip->company_user_id),
        );
    }

    /** Records the claim of a ticket that was just issued from a paid checkout item. */
    public function recordForTicket(Ticket $ticket, array $claim): DiscountClaim
    {
        return $this->claims->create([
            'ticket_id' => $ticket->ticket_id,
            'discount_type_key' => $claim['key'],
            'discount_label' => $claim['label'],
            'discount_rate' => $claim['rate'],
            'status' => DiscountClaim::CLAIMED,
            // Batch 76: the fare without any baggage fee (set at checkout), so the discount never applies to baggage.
            'fare' => (float) ($claim['fare'] ?? $ticket->amount),
        ]);
    }

    /**
     * The Chauffeur's decision. The ticket must be on their running trip and boarding must be open.
     *
     * @throws ValidationException
     */
    public function review(Ticket $ticket, bool $approve, object $activeTrip, object $user): DiscountClaim
    {
        if ((int) $ticket->trip_id !== (int) $activeTrip->trip_id) {
            throw ValidationException::withMessages(['ticket' => ['This ticket is for a different trip.']]);
        }
        if (! BoardingOpen::allows($activeTrip->status)) {
            throw ValidationException::withMessages(['trip' => [BoardingOpen::MESSAGE]]);
        }

        $claim = DB::transaction(function () use ($ticket, $approve, $user) {
            $found = $this->claims->findForTicket((int) $ticket->ticket_id);
            $claim = $found ? $this->claims->findForUpdate((int) $found->id) : null;
            if (! $claim) {
                throw ValidationException::withMessages(['ticket' => ['This passenger did not claim a discount.']]);
            }
            if ($claim->status !== DiscountClaim::CLAIMED) {
                throw ValidationException::withMessages(['ticket' => ['This discount was already reviewed.']]);
            }

            $reviewed = ['reviewed_by_user_id' => $user->user_id ?? null, 'reviewed_at' => Carbon::now('UTC')];
            if (! $approve) {
                $claim->update($reviewed + ['status' => DiscountClaim::REJECTED]);
                AuditLogger::log('discount_claim.rejected', 'Ticket', (int) $ticket->ticket_id, ['claim_id' => $claim->id]);

                return $claim;
            }

            $locked = Ticket::with('payment')->whereKey($ticket->ticket_id)->lockForUpdate()->first();
            $fare = (float) $claim->fare;
            $discounted = $this->fares->roundAmount($fare * (1 - (float) $claim->discount_rate));
            $refund = round(min($fare, max(0.0, $fare - $discounted)), 2);

            $claim->update($reviewed + [
                'status' => DiscountClaim::APPROVED,
                'refund_amount' => $refund,
                'refund_status' => $refund > 0 ? DiscountClaim::REFUND_PENDING : DiscountClaim::REFUND_SUCCEEDED,
                'not_before' => $refund > 0 ? $this->waitUntil($locked->payment, $refund) : null,
                'completed_at' => $refund > 0 ? null : Carbon::now('UTC'),
            ]);
            // Recorded like a cash discount, so every report counts the discounted fare. Batch 76: the refund comes off
            // the ticket's amount, so a baggage fee in it stays paid (the discount is only on the fare).
            $locked->update([
                'amount' => round(max(0.0, (float) $locked->amount - $refund), 2),
                'original_amount' => $fare,
                'discount_amount' => $refund,
                'discount_type_key' => $claim->discount_type_key,
                'discount_label' => $claim->discount_label,
                'discount_rate' => $claim->discount_rate,
                'discount_id_checked_at' => Carbon::now(),
            ]);
            $this->takeBackPoints($locked, $fare, $refund);
            AuditLogger::log('discount_claim.approved', 'Ticket', (int) $ticket->ticket_id, ['claim_id' => $claim->id, 'refund' => $refund]);

            return $claim;
        });

        if ($claim->status === DiscountClaim::APPROVED && $claim->refund_status === DiscountClaim::REFUND_PENDING) {
            $this->submitToGateway($claim);
        }

        return $claim->fresh();
    }

    /** The trip ended: claims nobody reviewed lapse (the passenger keeps the full fare). */
    public function lapseForTrip(int $tripId): int
    {
        return $this->claims->lapseOpenForTrip($tripId);
    }

    public function submitToGateway(DiscountClaim $claim): void
    {
        $lock = Cache::lock('discount-refund-submit-'.$claim->id, 60);
        if (! $lock->get()) {
            return;
        }

        try {
            $this->sendToGateway($claim);
        } finally {
            $lock->release();
        }
    }

    /** Approved Maya refunds whose waiting time is over (run with `refunds:release-deferred`). */
    public function releaseDeferred(): int
    {
        $due = $this->claims->deferredDue();
        $due->each(fn (DiscountClaim $claim) => $this->submitToGateway($claim));

        return $due->count();
    }

    /**
     * The signed PayMongo refund event for a discount refund, matched by the refund id stored when it was requested
     * (or, when that answer was lost, by the claim id in its metadata for the same PayMongo payment). Idempotent.
     */
    public function handleGatewayRefund(array $refund): bool
    {
        $refundId = $refund['id'] ?? null;
        $attributes = $refund['attributes'] ?? [];
        $status = strtolower((string) ($attributes['status'] ?? ''));

        $claim = is_string($refundId) && $refundId !== '' ? $this->claims->findByGatewayRefundId($refundId) : null;
        $claimId = $attributes['metadata']['discount_claim_id'] ?? null;
        if (! $claim && $claimId) {
            $candidate = $this->claims->find((int) $claimId);
            $paymentId = $attributes['payment_id'] ?? null;
            if ($candidate && $candidate->gateway_refund_id === null && is_string($paymentId) && $paymentId !== '' && $candidate->gateway_payment_id === $paymentId) {
                $claim = $candidate;
            }
        }
        if (! $claim) {
            return false;
        }

        $notify = false;
        DB::transaction(function () use ($claim, $refundId, $status, $attributes, &$notify) {
            $locked = $this->claims->findForUpdate((int) $claim->id);
            if ($locked->refund_status === DiscountClaim::REFUND_SUCCEEDED) {
                return;
            }
            $fill = ['gateway_status' => $status ?: $locked->gateway_status];
            if ($refundId && $locked->gateway_refund_id === null) {
                $fill['gateway_refund_id'] = $refundId;
            }
            if ($status === 'succeeded') {
                $locked->update($fill + ['refund_status' => DiscountClaim::REFUND_SUCCEEDED, 'failure_reason' => null, 'completed_at' => Carbon::now('UTC')]);
                AuditLogger::log('discount_claim.refund_succeeded', 'Ticket', (int) $locked->ticket_id, ['claim_id' => $locked->id]);
                $notify = true;
            } elseif ($status === 'failed') {
                $locked->update($fill + ['refund_status' => DiscountClaim::REFUND_FAILED, 'failure_reason' => mb_substr((string) ($attributes['failed_message'] ?? 'The payment provider could not complete the refund.'), 0, 255)]);
                AuditLogger::log('discount_claim.refund_failed', 'Ticket', (int) $locked->ticket_id, ['claim_id' => $locked->id, 'source' => 'webhook']);
            } else {
                $locked->update($fill);
            }
        });

        if ($notify) {
            $ticket = Ticket::with('payment')->find($claim->ticket_id);
            if ($ticket?->payment) {
                app(RefundCompletedMailer::class)->send($ticket->payment, (float) $claim->refund_amount, $ticket);
            }
        }

        return true;
    }

    /** What the passenger, the Chauffeur and the receipts show about a claim. @return array<string, mixed>|null */
    public function present(?DiscountClaim $claim): ?array
    {
        if (! $claim) {
            return null;
        }

        return [
            'type' => $claim->discount_type_key,
            'label' => $claim->discount_label,
            'rate' => (float) $claim->discount_rate,
            'status' => $claim->status,
            'fare' => (float) $claim->fare,
            'refund_amount' => $claim->refund_amount !== null ? (float) $claim->refund_amount : null,
            'refund_status' => $claim->refund_status,
            'message' => $this->message($claim),
        ];
    }

    /** @return array<int, array<string, mixed>> ticket_id => present() */
    public function presentForTickets(array $ticketIds): array
    {
        return $this->claims->forTickets($ticketIds)->map(fn (DiscountClaim $claim) => $this->present($claim))->all();
    }

    private function message(DiscountClaim $claim): string
    {
        $peso = 'PHP '.number_format((float) $claim->refund_amount, 2);

        return match (true) {
            $claim->status === DiscountClaim::CLAIMED => "{$claim->discount_label} discount claimed. Show your valid ID to the Chauffeur on the bus.",
            $claim->status === DiscountClaim::REJECTED => "{$claim->discount_label} discount not approved. The full fare stays.",
            $claim->status === DiscountClaim::LAPSED => "{$claim->discount_label} discount not reviewed on the bus. The full fare stays. Contact the bus company if this is wrong.",
            $claim->refund_status === DiscountClaim::REFUND_SUCCEEDED => "{$claim->discount_label} discount approved. {$peso} was sent back to your e-wallet.",
            $claim->refund_status === DiscountClaim::REFUND_FAILED => "{$claim->discount_label} discount approved, but sending back {$peso} failed. Contact the bus company.",
            default => "{$claim->discount_label} discount approved. {$peso} is on its way back to your e-wallet.",
        };
    }

    private function sendToGateway(DiscountClaim $claim): void
    {
        $claim = $claim->fresh();
        if ($claim->refund_status !== DiscountClaim::REFUND_PENDING || $claim->gateway_refund_id !== null) {
            return;
        }
        if ($claim->not_before && $claim->not_before->isFuture()) {
            return;
        }

        $ticket = Ticket::with('payment')->findOrFail($claim->ticket_id);
        $payment = $ticket->payment;
        try {
            $payMongo = app(PayMongoService::class);
            $payId = $payment?->gateway_payment_id ?: ($payment?->gateway_reference ? $payMongo->findPaymentIdForCheckoutSession($payment->gateway_reference) : null);
            if (! $payId) {
                throw new \RuntimeException('The payment could not be found at the payment provider.');
            }
            if ($payment->gateway_payment_id !== $payId) {
                $payment->forceFill(['gateway_payment_id' => $payId])->save();
            }
            // 2026-11-19 (audit): saved BEFORE asking for the refund. A refund event can arrive before the answer below
            // is stored; handleGatewayRefund() then finds this claim by its metadata only if the payment id is known.
            $claim->update(['gateway_payment_id' => $payId]);
            $refund = $payMongo->createRefund(
                $payId,
                (int) round((float) $claim->refund_amount * 100),
                (string) config('refunds.gateway_reason'),
                'Discount approved on the bus, ticket '.$ticket->ticket_uuid,
                ['discount_claim_id' => (string) $claim->id, 'ticket_uuid' => (string) $ticket->ticket_uuid],
            );
            $claim->update([
                'gateway_payment_id' => $payId,
                'gateway_refund_id' => $refund['id'] ?? null,
                'gateway_status' => $refund['attributes']['status'] ?? 'pending',
            ]);
            AuditLogger::log('discount_claim.refund_submitted', 'Ticket', (int) $ticket->ticket_id, ['claim_id' => $claim->id, 'gateway_refund_id' => $refund['id'] ?? null]);
        } catch (\Throwable $e) {
            Log::warning('Discount refund could not be submitted', ['claim_id' => $claim->id, 'error' => $e->getMessage()]);
            $claim->update(['refund_status' => DiscountClaim::REFUND_FAILED, 'failure_reason' => mb_substr($e->getMessage(), 0, 255)]);
            AuditLogger::log('discount_claim.refund_failed', 'Ticket', (int) $ticket->ticket_id, ['claim_id' => $claim->id, 'reason' => $e->getMessage()]);
        }
    }

    /** Maya accepts only a FULL refund on the day of the payment, so this partial one waits for the next Manila midnight. */
    private function waitUntil(?Payment $payment, float $refund): ?Carbon
    {
        if (! $payment || strtolower((string) $payment->payment_channel) !== 'maya') {
            return null;
        }
        $tz = config('app.timezone', 'Asia/Manila');
        $paidOn = Carbon::parse($payment->payment_created ?? $payment->created_at, $tz);
        if (! $paidOn->isSameDay(Carbon::now($tz))) {
            return null;
        }

        return $refund >= (float) $payment->amount - 0.005 ? null : Carbon::now($tz)->addDay()->startOfDay()->utc();
    }

    /** The reward points earned on the refunded part come back off the passenger's balance (never below zero). */
    private function takeBackPoints(Ticket $ticket, float $fare, float $refund): void
    {
        if (! $ticket->passenger_id || $refund <= 0) {
            return;
        }
        // 2026-11-20: the same earning rule as RewardService::awardPoints (points have two decimals).
        $points = round(RewardService::pointsFor($fare) - RewardService::pointsFor(max(0.0, $fare - $refund)), 2);
        if ($points <= 0) {
            return;
        }
        if ($this->rewards->decrementPointsIfEnough((int) $ticket->passenger_id, $points)) {
            $this->rewards->createTransaction([
                'passenger_id' => $ticket->passenger_id,
                'payment_id' => $ticket->payment_id,
                'points' => -$points, // a correction of points earned (the type column allows earned or redeemed)
                'type' => 'earned',
                'description' => 'Discount approved: points on the refunded part',
            ]);
        }
    }

    /**
     * 2026-11-19 (audit): the same safety net as trip refunds (TicketResolutionService::reconcilePending): asks PayMongo
     * about discount refunds still pending some minutes after approval, settles them by the same rules as the event, and
     * marks very old ones failed so the bus company can check them in PayMongo.
     *
     * @return array{settled: int, failed: int}
     */
    public function reconcilePending(): array
    {
        $now = Carbon::now('UTC');
        $after = $now->copy()->subMinutes(max(0, (int) config('refunds.reconcile.after_minutes', 10)));
        $giveUp = $now->copy()->subHours(max(1, (int) config('refunds.reconcile.give_up_hours', 72)));
        $settled = 0;
        $failed = 0;

        foreach ($this->claims->pendingGatewayRefunds($after) as $claim) {
            $refund = app(PayMongoService::class)->retrieveRefund((string) $claim->gateway_refund_id);
            $status = strtolower((string) ($refund['attributes']['status'] ?? ''));
            if ($refund && in_array($status, ['succeeded', 'failed'], true) && ($refund['id'] ?? null) === $claim->gateway_refund_id) {
                $this->handleGatewayRefund($refund);
                $settled++;

                continue;
            }
            if ($claim->reviewed_at && $claim->reviewed_at->lte($giveUp)) {
                $reason = 'The payment provider has not finished this refund after '.(int) config('refunds.reconcile.give_up_hours', 72).' hours. Check it in PayMongo.';
                DB::transaction(function () use ($claim, $reason, &$failed) {
                    $locked = $this->claims->findForUpdate((int) $claim->id);
                    if ($locked->refund_status !== DiscountClaim::REFUND_PENDING) {
                        return;
                    }
                    $locked->update(['refund_status' => DiscountClaim::REFUND_FAILED, 'failure_reason' => $reason]);
                    AuditLogger::log('discount_claim.refund_failed', 'Ticket', (int) $locked->ticket_id, ['claim_id' => $locked->id, 'source' => 'reconcile_timeout']);
                    $failed++;
                });
            }
        }

        return ['settled' => $settled, 'failed' => $failed];
    }
}
