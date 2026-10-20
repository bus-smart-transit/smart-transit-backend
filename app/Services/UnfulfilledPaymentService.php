<?php

namespace App\Services;

use App\Models\PassengerUser;
use App\Models\Payment;
use App\Models\Trip;
use App\Models\User;
use App\Support\Database\RlsContext;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Online payments the system took money for but could not turn into tickets (a payment that arrived after its seats
 * were released and could not be honoured: see PaymentService::finalizeOnlinePayment). They are marked `needs_refund`.
 *
 * The Operator refunds the money in PayMongo (the dashboard, or whichever way the company refunds) and records it here
 * with a note, normally the PayMongo refund id. This does NOT call PayMongo: it is the record of a refund a person made,
 * kept with who recorded it and when. An Operator sees only payments for trips of their own; another Operator's
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
    public function paginate(int $operatorCompanyUserId, array $filters): array
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
     * @throws ValidationException    the payment is not waiting for a refund
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

                $payment->status = self::REFUNDED;
                $payment->is_valid = false;
                $payment->refunded_at = now();
                $payment->refund_note = mb_substr(trim($note), 0, 255);
                $payment->refunded_by_user_id = $user->user_id;
                $payment->save();

                AuditLogger::log('payment.refund_recorded', 'Payment', (int) $payment->payment_id, [
                    'transaction_reference' => $payment->transaction_reference,
                    'amount' => (float) $payment->amount,
                    'note' => $payment->refund_note,
                ]);

                return (int) $payment->payment_id;
            });

            return $this->present(collect([Payment::findOrFail($paymentId)]))[0];
        });
    }

    /**
     * Releases an unknown checkout only after the Operator verifies in PayMongo that it was not charged.
     * The signed webhook remains the only authority that can mark a payment paid.
     */
    public function recordCheckoutNotCharged(int $paymentId, int $operatorCompanyUserId, User $user, string $note): Payment
    {
        return RlsContext::withBypass(function () use ($paymentId, $operatorCompanyUserId, $user, $note) {
            $resolvedId = DB::transaction(function () use ($paymentId, $operatorCompanyUserId, $user, $note) {
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
    private function ownedPayments(int $operatorCompanyUserId)
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
                    : 'The payment arrived after the seats were released and no ticket could be issued.',
                'refunded_at' => $payment->refunded_at?->toIso8601String(),
                'refund_note' => $payment->refund_note,
                'refunded_by_name' => $payment->refunded_by_user_id ? ($actors[$payment->refunded_by_user_id] ?? null) : null,
            ];
        })->all();
    }
}
