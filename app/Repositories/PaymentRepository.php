<?php
namespace App\Repositories;
use App\Models\Stop;
use App\Models\Payment;
use App\Models\Ticket;
use App\Models\RewardTransaction;
use Illuminate\Support\Collection;
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

    public function findPassengerHistoryFromPayments(int $passengerId): Collection
    {
        // Fast path: resolve payment ids through indexed relations first.
        $ticketPaymentIds = Ticket::query()
            ->where('passenger_id', $passengerId)
            ->whereNotNull('payment_id')
            ->pluck('payment_id')
            ->map(fn ($id) => (int) $id)
            ->values();

        $rewardPaymentIds = RewardTransaction::query()
            ->where('passenger_id', $passengerId)
            ->whereNotNull('payment_id')
            ->pluck('payment_id')
            ->map(fn ($id) => (int) $id)
            ->values();

        $resolvedPaymentIds = $ticketPaymentIds
            ->merge($rewardPaymentIds)
            ->unique()
            ->values();

        if ($resolvedPaymentIds->isEmpty()) {
            return collect();
        }

        $payments = Payment::query()
            ->with(['tickets.originStop', 'tickets.destinationStop'])
            ->whereIn('payment_id', $resolvedPaymentIds)
            ->orderByDesc('payment_created')
            ->orderByDesc('created_at')
            ->get();

        $paymentIds = $payments->pluck('payment_id')->filter()->map(fn ($id) => (int) $id)->values();

        $rewardByPayment = RewardTransaction::query()
            ->where('passenger_id', $passengerId)
            ->whereIn('payment_id', $paymentIds)
            ->orderBy('created_at', 'desc')
            ->get()
            ->groupBy('payment_id');

        $stopIds = [];
        foreach ($payments as $payment) {
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

        return $payments->map(function (Payment $payment) use ($stopNames, $passengerId, $rewardByPayment) {
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
        })->values();
    }
}