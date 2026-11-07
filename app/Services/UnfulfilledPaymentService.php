<?php

namespace App\Services;

use App\Models\PassengerUser;
use App\Models\Payment;
use App\Models\Trip;
use App\Models\User;
use App\Support\Database\RlsContext;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

/**
 * Online payments the system took money for but could not turn into tickets (a payment that arrived after its seats
 * were released and could not be honoured: see PaymentService::finalizeOnlinePayment). They are marked `needs_refund`.
 *
 * Batch 49 item 1 (user approved): the full amount is first sent back through PayMongo automatically
 * (submitAutomaticRefund, run by the RefundUnfulfilledPayment job); the signed refund event then marks the payment
 * refunded (handleGatewayRefund). While that refund is in progress nobody can record a refund by hand. When sending fails
 * or PayMongo reports the refund failed, the payment waits here as before: the Operator refunds the money in PayMongo
 * and records it with a note, normally the PayMongo refund id (recordRefund does NOT call PayMongo), kept with who
 * recorded it and when. An Operator sees only payments for trips of their own; another Operator's
 * payment is the same answer as a missing one.
 */
class UnfulfilledPaymentService
{
    public const NEEDS_REFUND = 'needs_refund';

    public const REFUNDED = 'refunded';

    public const OUTCOME_UNKNOWN = 'outcome_unknown';

    public function __construct(private PaymentService $paymentService) {}

    /**
     * @param  array{status?: string, page?: int, per_page?: int}  $filters
     * @return array{data: array<int, array<string, mixed>>, meta: array<string, mixed>}
     */
    /** $operatorCompanyUserId null = every company (the Super Admin, read only; Batch 48 Phase 5). */
    public function paginate(?int $operatorCompanyUserId, array $filters): array
    {
        $status = in_array($filters['status'] ?? null, [self::NEEDS_REFUND, self::REFUNDED, self::OUTCOME_UNKNOWN, 'all'], true) ? $filters['status'] : self::NEEDS_REFUND;
        $page = max(1, (int) ($filters['page'] ?? 1));
        $perPage = max(1, min(50, (int) ($filters['per_page'] ?? 20)));

        return RlsContext::withBypass(function () use ($operatorCompanyUserId, $status, $page, $perPage) {
            $owned = $this->ownedPayments($operatorCompanyUserId);

            $counts = [
                self::NEEDS_REFUND => $owned->where('status', self::NEEDS_REFUND)->count(),
                self::REFUNDED => $owned->where('status', self::REFUNDED)->count(),
                self::OUTCOME_UNKNOWN => $owned->where('idempotency_state', 'unknown')->count(),
            ];

            $rows = match ($status) {
                'all' => $owned,
                self::OUTCOME_UNKNOWN => $owned->where('idempotency_state', 'unknown'),
                default => $owned->where('status', $status),
            };
            $total = $rows->count();
            $pageRows = $rows->forPage($page, $perPage)->values();

            return [
                'data' => $this->present($pageRows),
                'meta' => [
                    'page' => $page, 'per_page' => $perPage, 'total' => $total,
                    'last_page' => max(1, (int) ceil($total / $perPage)), 'status' => $status,
                    'counts' => $counts,
                ],
            ];
        });
    }

    /**
     * Records that the Operator refunded this payment. Only a `needs_refund` payment can be recorded, once.
     *
     * @return array<string, mixed>
     *
     * @throws ModelNotFoundException another Operator's payment, or no such payment
     * @throws ValidationException the payment is not waiting for a refund
     */
    public function recordRefund(int $paymentId, int $operatorCompanyUserId, object $user, string $note): array
    {
        return RlsContext::withBypass(function () use ($paymentId, $operatorCompanyUserId, $user, $note) {
            $paymentId = DB::transaction(function () use ($paymentId, $operatorCompanyUserId, $user, $note) {
                $payment = Payment::whereKey($paymentId)->lockForUpdate()->first();
                if (! $payment || ! $this->isOwnedBy($payment, $operatorCompanyUserId)) {
                    throw new ModelNotFoundException;
                }

                if ($payment->status !== self::NEEDS_REFUND) {
                    throw ValidationException::withMessages(['status' => ['This payment is not waiting for a refund.']]);
                }

                if ($payment->gateway_refund_id !== null) {
                    throw ValidationException::withMessages(['status' => ['An automatic refund for this payment is already in progress. Wait for PayMongo to finish it.']]);
                }

                $payment->status = self::REFUNDED;
                $payment->is_valid = false;
                $payment->refunded_at = now();
                $payment->refund_note = mb_substr(trim($note), 0, 255);
                $payment->refunded_by_user_id = $user->user_id;
                $payment->save();

                AuditLogger::log('payment.refund_recorded', 'Payment', (int) $payment->payment_id, [
                    'transaction_reference' => $payment->transaction_reference,
                    'amount' => (float) $payment->amount,
                    'fee_amount' => (float) $payment->fee_amount,
                    'note' => $payment->refund_note,
                ]);

                return (int) $payment->payment_id;
            });

            // 2026-11-04 (Batch 45, Todo R): the buyer is told once the Operator records that the money went back.
            $refunded = Payment::findOrFail($paymentId);
            // Everything the buyer paid comes back: the fares and the booking fee (Batch 45 Feature A).
            app(RefundCompletedMailer::class)->send($refunded, (float) $refunded->amount + (float) $refunded->fee_amount);

            return $this->present(collect([$refunded]))[0];
        });
    }

    /**
     * Batch 49 item 1: sends the whole amount the buyer paid (fares and booking fee) back through PayMongo. Only a
     * `needs_refund` payment with no refund in progress; safe to call twice. A failure leaves it for a refund by hand.
     */
    public function submitAutomaticRefund(int $paymentId): void
    {
        RlsContext::withBypass(function () use ($paymentId) {
            $payment = Payment::find($paymentId);
            if (! $payment || $payment->status !== self::NEEDS_REFUND || $payment->gateway_refund_id !== null || $payment->payment_method !== 'online') {
                return;
            }

            try {
                $payMongo = app(PayMongoService::class);
                $payId = $payment->gateway_payment_id ?: ($payment->gateway_reference ? $payMongo->findPaymentIdForCheckoutSession($payment->gateway_reference) : null);
                if (! $payId) {
                    throw new \RuntimeException('The payment could not be found at the payment provider.');
                }

                // What PayMongo charged: the fares (never under PHP 1, as at checkout) plus the booking fee.
                $centavos = max(100, (int) round((float) $payment->amount * 100)) + (int) round((float) $payment->fee_amount * 100);
                $refund = $payMongo->createRefund(
                    $payId,
                    $centavos,
                    (string) config('refunds.gateway_reason'),
                    'The bus filled up before payment '.$payment->transaction_reference.' was confirmed',
                    ['payment_id' => (string) $payment->payment_id, 'kind' => 'unfulfilled_payment'],
                );

                $payment->forceFill([
                    'gateway_payment_id' => $payId,
                    'gateway_refund_id' => $refund['id'] ?? null,
                    'refund_note' => 'Automatic refund sent to PayMongo.',
                ])->save();
                AuditLogger::log('payment.refund_submitted', 'Payment', (int) $payment->payment_id, [
                    'transaction_reference' => $payment->transaction_reference,
                    'amount' => $centavos / 100,
                    'gateway_refund_id' => $refund['id'] ?? null,
                ]);
            } catch (\Throwable $e) {
                Log::warning('Automatic refund of an unfulfilled payment could not be sent.', ['payment_id' => $payment->payment_id, 'error' => $e->getMessage()]);
                $payment->forceFill(['refund_note' => 'Automatic refund failed. Refund it by hand.'])->save();
                AuditLogger::log('payment.refund_failed', 'Payment', (int) $payment->payment_id, ['reason' => mb_substr($e->getMessage(), 0, 255)]);
            }
        });
    }

    /**
     * Batch 49 item 1: the signed PayMongo refund event for an automatic refund of an unfulfilled payment, matched by
     * the refund id stored when it was sent. Succeeded: the payment is refunded and the buyer is emailed once. Failed:
     * the refund id is cleared so the Operator can refund it by hand. False when the refund is not one of these.
     *
     * @param  array<string, mixed>  $refund  the refund resource
     */
    public function handleGatewayRefund(array $refund): bool
    {
        $refundId = $refund['id'] ?? null;
        if (! is_string($refundId) || $refundId === '') {
            return false;
        }
        $status = strtolower((string) ($refund['attributes']['status'] ?? ''));

        return RlsContext::withBypass(function () use ($refundId, $status) {
            $outcome = DB::transaction(function () use ($refundId, $status) {
                $payment = Payment::where('gateway_refund_id', $refundId)->lockForUpdate()->first();
                if (! $payment) {
                    return null;
                }
                if ($payment->status !== self::NEEDS_REFUND) {
                    return ['payment' => $payment, 'emailed' => false]; // already settled: a replay changes nothing
                }

                if (in_array($status, ['succeeded', 'paid'], true)) {
                    $payment->forceFill([
                        'status' => self::REFUNDED,
                        'is_valid' => false,
                        'refunded_at' => now(),
                        'refund_note' => 'Refunded automatically through PayMongo ('.$refundId.').',
                    ])->save();
                    AuditLogger::log('payment.refund_completed', 'Payment', (int) $payment->payment_id, ['gateway_refund_id' => $refundId]);

                    return ['payment' => $payment, 'emailed' => true];
                }

                if ($status === 'failed') {
                    $payment->forceFill(['gateway_refund_id' => null, 'refund_note' => 'Automatic refund failed at PayMongo. Refund it by hand.'])->save();
                    AuditLogger::log('payment.refund_failed', 'Payment', (int) $payment->payment_id, ['gateway_refund_id' => $refundId]);
                }

                return ['payment' => $payment, 'emailed' => false];
            });

            if ($outcome === null) {
                return false;
            }
            if ($outcome['emailed']) {
                $refunded = $outcome['payment'];
                app(RefundCompletedMailer::class)->send($refunded, (float) $refunded->amount + (float) $refunded->fee_amount);
            }

            return true;
        });
    }

    /**
     * Releases an unknown checkout only after the Operator verifies in PayMongo that it was not charged.
     * The signed webhook remains the only authority that can mark a payment paid.
     */
    public function recordCheckoutNotCharged(int $paymentId, int $operatorCompanyUserId, User $user, string $note): Payment
    {
        return RlsContext::withBypass(function () use ($paymentId, $operatorCompanyUserId, $note) {
            $resolvedId = DB::transaction(function () use ($paymentId, $operatorCompanyUserId, $note) {
                $payment = Payment::whereKey($paymentId)->lockForUpdate()->first();
                if (! $payment || ! $this->isOwnedBy($payment, $operatorCompanyUserId)) {
                    throw new ModelNotFoundException;
                }
                if ($payment->payment_method !== 'online' || $payment->status !== 'pending' || $payment->idempotency_state !== 'unknown') {
                    throw ValidationException::withMessages(['status' => ['This checkout is not awaiting outcome reconciliation.']]);
                }

                $this->paymentService->failOnlinePaymentWithReleases($payment);
                $payment->refresh();
                if ($payment->status !== 'failed') {
                    throw ValidationException::withMessages(['status' => ['The checkout changed state and cannot be reconciled as unpaid.']]);
                }

                $payment->idempotency_state = 'failed';
                $payment->save();
                AuditLogger::log('payment.checkout_reconciled_not_charged', 'Payment', (int) $payment->payment_id, [
                    'transaction_reference' => $payment->transaction_reference,
                    'note' => mb_substr(trim($note), 0, 255),
                ]);

                return (int) $payment->payment_id;
            });

            return Payment::findOrFail($resolvedId);
        });
    }

    /** Online refund and unknown-outcome payments for this Operator's trips, newest first. */
    private function ownedPayments(?int $operatorCompanyUserId)
    {
        $payments = Payment::where(function ($query) {
            $query->whereIn('status', [self::NEEDS_REFUND, self::REFUNDED])
                ->orWhere(function ($unknown) {
                    $unknown->where('status', 'pending')->where('idempotency_state', 'unknown');
                });
        })
            ->where('payment_method', 'online')
            ->orderByDesc('payment_created')
            ->orderByDesc('payment_id')
            ->get();

        $owners = $this->tripOwners($payments);

        if ($operatorCompanyUserId === null) {
            return $payments;
        }

        return $payments->filter(fn (Payment $p) => $this->ownsAny($p, $operatorCompanyUserId, $owners))->values();
    }

    private function isOwnedBy(Payment $payment, int $operatorCompanyUserId): bool
    {
        return $this->ownsAny($payment, $operatorCompanyUserId, $this->tripOwners(collect([$payment])));
    }

    /** @return array<int, int|null>  trip id => the Operator who owns the trip */
    private function tripOwners($payments): array
    {
        $tripIds = $payments->flatMap(fn (Payment $p) => collect($p->items_payload ?? [])->pluck('trip_id'))->filter()->unique()->values();

        return $tripIds->isEmpty() ? [] : Trip::whereIn('trip_id', $tripIds)->pluck('company_user_id', 'trip_id')->all();
    }

    private function ownsAny(Payment $payment, int $operatorCompanyUserId, array $owners): bool
    {
        foreach ($payment->items_payload ?? [] as $item) {
            if ((int) ($owners[(int) ($item['trip_id'] ?? 0)] ?? 0) === $operatorCompanyUserId) {
                return true;
            }
        }

        return false;
    }

    /** @return array<int, array<string, mixed>> */
    private function present($payments): array
    {
        $tripIds = $payments->flatMap(fn (Payment $p) => collect($p->items_payload ?? [])->pluck('trip_id'))->filter()->unique()->values();
        $trips = $tripIds->isEmpty() ? collect() : Trip::with('fleetRoute.route')->whereIn('trip_id', $tripIds)->get()->keyBy('trip_id');

        $passengerIds = $payments->flatMap(fn (Payment $p) => collect($p->items_payload ?? [])->pluck('passenger_id'))->filter()->unique()->values();
        $passengers = $passengerIds->isEmpty() ? collect() : PassengerUser::with('user')->whereIn('passenger_id', $passengerIds)->get()->keyBy('passenger_id');

        $actorIds = $payments->pluck('refunded_by_user_id')->filter()->unique()->values();
        $actors = $actorIds->isEmpty() ? collect() : DB::table('company_users')->whereIn('user_id', $actorIds)->pluck('name', 'user_id');

        return $payments->map(function (Payment $payment) use ($trips, $passengers, $actors) {
            $items = collect($payment->items_payload ?? []);
            $passenger = $passengers->get((int) ($items->pluck('passenger_id')->filter()->first() ?? 0));

            return [
                'payment_id' => (int) $payment->payment_id,
                'transaction_reference' => $payment->transaction_reference,
                'status' => $payment->status,
                'reconciliation_status' => $payment->idempotency_state === 'unknown' ? self::OUTCOME_UNKNOWN : null,
                'amount' => (float) $payment->amount,
                'fee_amount' => (float) $payment->fee_amount,
                'total_paid' => round((float) $payment->amount + (float) $payment->fee_amount, 2),
                'payment_channel' => $payment->payment_channel,
                'paid_at' => $payment->payment_created?->toIso8601String(),
                'payer' => [
                    'is_guest' => $payment->guest_email !== null && $passenger === null,
                    'name' => $passenger?->name,
                    'email' => $passenger?->user?->email ?? $payment->guest_email,
                ],
                'trips' => $items->groupBy('trip_id')->map(function ($group, $tripId) use ($trips) {
                    $trip = $trips->get((int) $tripId);

                    return [
                        'trip_id' => (int) $tripId,
                        'trip_date' => $trip?->trip_date?->toDateString(),
                        'departure_time' => $trip?->departure_time,
                        'trip_status' => $trip?->status,
                        'route_name' => $trip?->fleetRoute?->route?->route_name,
                        'tickets' => $group->count(),
                    ];
                })->values()->all(),
                'gateway_reference' => $payment->gateway_reference,
                'gateway_payment_id' => $payment->gateway_payment_id,
                'reason' => $payment->idempotency_state === 'unknown'
                    ? 'PayMongo did not return a definitive checkout-session response. Verify the transaction reference in PayMongo before releasing the hold.'
                    : 'The payment was confirmed after the seats were gone, so no ticket could be issued.',
                // Batch 49 item 1: true while the automatic refund is with PayMongo (nothing to do by hand).
                'automatic_refund_pending' => $payment->status === self::NEEDS_REFUND && $payment->gateway_refund_id !== null,
                'refunded_automatically' => $payment->status === self::REFUNDED && $payment->gateway_refund_id !== null && $payment->refunded_by_user_id === null,
                'refunded_at' => $payment->refunded_at?->toIso8601String(),
                'refund_note' => $payment->refund_note,
                'refunded_by_name' => $payment->refunded_by_user_id ? ($actors[$payment->refunded_by_user_id] ?? null) : null,
            ];
        })->all();
    }
}
