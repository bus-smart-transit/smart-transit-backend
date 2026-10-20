<?php
namespace App\Repositories;
use App\Models\Stop;
use App\Models\Payment;
use App\Models\Ticket;
use App\Models\RewardTransaction;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class PaymentRepository
{
    public function create(array $payload): Payment
    {
        $payload['payment_uuid'] = $payload['payment_uuid'] ?? (string) Str::uuid();
        $payload['payment_created'] = $payload['payment_created'] ?? now();
        return Payment::create($payload);
    }

    public function findById(int $paymentId): ?Payment
    {
        return Payment::with('tickets')->find($paymentId);
    }

    public function findByTransactionReference(string $transactionReference): ?Payment
    {
        return Payment::where('transaction_reference', $transactionReference)->first();
    }

    public function findByIdempotencyKey(string $scopeHash, string $keyHash, bool $lockForUpdate = false): ?Payment
    {
        $query = Payment::with(['tickets', 'onsitePayment'])
            ->where('idempotency_scope_hash', $scopeHash)
            ->where('idempotency_key_hash', $keyHash);

        if ($lockForUpdate) {
            $query->lockForUpdate();
        }

        return $query->first();
    }

    public function findByGatewayReference(string $gatewayReference): ?Payment
    {
        return Payment::where('gateway_reference', $gatewayReference)->first();
    }

    public function findByPaymentIntentId(string $paymentIntentId): ?Payment
    {
        return Payment::where('payment_intent_id', $paymentIntentId)->first();
    }

    public function markStatus(int $paymentId, string $status, bool $isValid = true): bool
    {
        return Payment::where('payment_id', $paymentId)->update([
            'status' => $status,
            'is_valid' => $isValid,
        ]) > 0;
    }

    public function findPassengerHistoryFromPayments(int $passengerId, int $perPage = 50, int $page = 1): LengthAwarePaginator
    {
        $ticketPaymentIds = DB::table('tickets')
            ->select('payment_id')
            ->where('passenger_id', $passengerId)
            ->whereNotNull('payment_id')
            ->distinct();
        $rewardPaymentIds = DB::table('reward_transactions')
            ->select('payment_id')
            ->where('passenger_id', $passengerId)
            ->whereNotNull('payment_id')
            ->distinct();
        $resolvedPaymentIds = $ticketPaymentIds->union($rewardPaymentIds);

        $payments = Payment::query()
            ->with(['tickets.originStop', 'tickets.destinationStop'])
            ->whereIn('payment_id', $resolvedPaymentIds)
            ->orderByDesc('payment_created')
            ->orderByDesc('created_at')
            ->orderByDesc('payment_id')
            ->paginate($perPage, ['*'], 'page', $page);
        $paymentRows = $payments->getCollection();

        $paymentIds = $paymentRows->pluck('payment_id')->filter()->map(fn ($id) => (int) $id)->values();

        $rewardByPayment = RewardTransaction::query()
            ->where('passenger_id', $passengerId)
            ->whereIn('payment_id', $paymentIds)
            ->orderBy('created_at', 'desc')
            ->get()
            ->groupBy('payment_id');

        $stopIds = [];
        foreach ($paymentRows as $payment) {
            foreach (($payment->items_payload ?? []) as $item) {
                if (isset($item['origin_stop_id'])) {
                    $stopIds[] = (int) $item['origin_stop_id'];
                }
                if (isset($item['destination_stop_id'])) {
                    $stopIds[] = (int) $item['destination_stop_id'];
                }
            }
        }

        $stopNames = Stop::query()
            ->whereIn('stop_id', array_values(array_unique(array_filter($stopIds))))
            ->pluck('stop_name', 'stop_id');

        $payments->setCollection($paymentRows->map(function (Payment $payment) use ($stopNames, $passengerId, $rewardByPayment) {
            $matchedItems = collect($payment->items_payload ?? [])
                ->filter(function ($item) use ($passengerId) {
                    return (int) ($item['passenger_id'] ?? 0) === $passengerId;
                })
                ->values();

            if ($matchedItems->isEmpty()) {
                $matchedItems = $payment->tickets
                    ->where('passenger_id', $passengerId)
                    ->map(function ($ticket) {
                        return [
                            'trip_id' => $ticket->trip_id,
                            'seat_type' => $ticket->seat_type,
                            'amount' => (float) $ticket->amount,
                            'origin_stop_id' => $ticket->origin_stop_id,
                            'destination_stop_id' => $ticket->destination_stop_id,
                        ];
                    })
                    ->values();
            }

            $items = $matchedItems->map(function ($item) use ($stopNames) {
                $originId = isset($item['origin_stop_id']) ? (int) $item['origin_stop_id'] : null;
                $destinationId = isset($item['destination_stop_id']) ? (int) $item['destination_stop_id'] : null;

                $originName = $originId ? ($stopNames[$originId] ?? "Stop {$originId}") : null;
                $destinationName = $destinationId ? ($stopNames[$destinationId] ?? "Stop {$destinationId}") : null;

                if (!$originName && isset($item['origin_label'])) {
                    $originName = trim((string) $item['origin_label']) ?: null;
                }

                if (!$destinationName && isset($item['destination_label'])) {
                    $destinationName = trim((string) $item['destination_label']) ?: null;
                }

                if (!$originName && isset($item['origin_lat'], $item['origin_lng'])) {
                    $originName = \App\Support\TicketBoarding::BETWEEN_STOPS;
                }

                if (!$destinationName && isset($item['destination_lat'], $item['destination_lng'])) {
                    $destinationName = 'Custom drop-off point';
                }

                return [
                    'trip_id' => $item['trip_id'] ?? null,
                    'seat_type' => $item['seat_type'] ?? null,
                    'amount' => isset($item['amount']) ? (float) $item['amount'] : null,
                    'origin_stop_id' => $originId,
                    'destination_stop_id' => $destinationId,
                    'origin_stop_name' => $originName,
                    'destination_stop_name' => $destinationName,
                    'route_label' => ($originName && $destinationName) ? ($originName . ' to ' . $destinationName) : 'Route unavailable',
                ];
            })->values();

            $rewardRows = $rewardByPayment->get($payment->payment_id, collect());
            $redeemedPoints = (int) abs($rewardRows->where('type', 'redeemed')->sum('points'));
            $earnedPoints = (int) $rewardRows->where('type', 'earned')->sum('points');
            $rewardDiscount = (float) $redeemedPoints;
            $grossAmount = (float) $payment->amount + $rewardDiscount;

            return [
                'payment_id' => $payment->payment_id,
                'payment_uuid' => $payment->payment_uuid,
                'transaction_reference' => $payment->transaction_reference,
                'payment_method' => $payment->payment_method,
                'payment_channel' => $payment->payment_channel,
                'status' => $payment->status,
                'is_valid' => (bool) $payment->is_valid,
                'amount' => (float) $payment->amount,
                'gross_amount' => $grossAmount,
                'reward_points_redeemed' => $redeemedPoints,
                'reward_discount' => $rewardDiscount,
                'reward_points_earned' => $earnedPoints,
                'paid_at' => $payment->payment_created ?? $payment->created_at,
                'item_count' => $items->count(),
                'route_summary' => $items->pluck('route_label')->filter()->unique()->implode('; '),
                'items' => $items,
            ];
        })->values());

        return $payments;
    }
}