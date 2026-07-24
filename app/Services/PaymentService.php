<?php
namespace App\Services;
use App\Models\Payment;
use App\Repositories\PaymentRepository;
use App\Repositories\OnlinePaymentRepository;
use App\Repositories\OnsitePaymentRepository;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class PaymentService
{
    public function __construct(
        private PaymentRepository $paymentRepository,
        private OnlinePaymentRepository $onlinePaymentRepository,
        private OnsitePaymentRepository $onsitePaymentRepository,
        private TicketService $ticketService,
        private PayMongoService $payMongoService,
        private RewardService $rewardService,
    ) {
    }

    /**
     * $payload = ['items' => [...], 'payment_channel']
     * Each item is EITHER:
     *   stop-based:   ['trip_id','seat_type','origin_stop_id','destination_stop_id']
     *   custom-point: ['trip_id','seat_type','origin_lat','origin_lng','destination_lat','destination_lng']
     *
     * No ticket rows are created here. Each item's seat/standing capacity
     * is reserved and its fare computed via TicketService::reserveAndPrice(),
     * and that reservation is stored as JSON on payments.items_payload.
     * Actual `tickets` rows are only created once the webhook confirms
     * payment — see PaymentService::finalizeOnlinePayment().
     */
    public function checkoutOnline(?int $passengerId, ?string $guestEmail, array $payload): array
    {
        if (function_exists('set_time_limit')) {
            @set_time_limit(120);
        }

        $startedAt = microtime(true);

        $prepared = DB::transaction(function () use ($passengerId, $guestEmail, $payload, $startedAt) {
            DB::statement("SET LOCAL lock_timeout TO '5s'");
            DB::statement("SET LOCAL statement_timeout TO '12s'");

            $pointsRequested = max(0, (int) ($payload['reward_points_to_use'] ?? 0));

            if ($pointsRequested > 0 && !$passengerId) {
                throw ValidationException::withMessages([
                    'reward_points_to_use' => ['Reward points can only be used by signed-in passengers.'],
                ]);
            }

            $payment = $this->paymentRepository->create([
                'amount' => 0,
                'transaction_reference' => 'TXN-' . strtoupper(uniqid()),
                'payment_method' => 'online',
                'payment_channel' => $payload['payment_channel'],
                'guest_email' => $guestEmail,
                'status' => 'pending',
                'is_valid' => true,
            ]);

            // Keep one online_payment row per online transaction.
            // Passenger ownership is persisted in items_payload, not
            // in online_payment, so the table can stay minimal.
            $this->onlinePaymentRepository->create([
                'payment_id' => $payment->payment_id,
            ]);

            $total = 0;
            $reservedItems = [];
            $lineItems = [];
            $groupedItems = [];

            foreach ($payload['items'] as $item) {
                $itemKey = json_encode([
                    'trip_id' => (int) ($item['trip_id'] ?? 0),
                    'seat_type' => (string) ($item['seat_type'] ?? ''),
                    'origin_stop_id' => isset($item['origin_stop_id']) ? (int) $item['origin_stop_id'] : null,
                    'destination_stop_id' => isset($item['destination_stop_id']) ? (int) $item['destination_stop_id'] : null,
                    'origin_lat' => isset($item['origin_lat']) ? (float) $item['origin_lat'] : null,
                    'origin_lng' => isset($item['origin_lng']) ? (float) $item['origin_lng'] : null,
                    'destination_lat' => isset($item['destination_lat']) ? (float) $item['destination_lat'] : null,
                    'destination_lng' => isset($item['destination_lng']) ? (float) $item['destination_lng'] : null,
                ]);

                if (!isset($groupedItems[$itemKey])) {
                    $groupedItems[$itemKey] = [
                        'item' => $item,
                        'count' => 0,
                    ];
                }

                $groupedItems[$itemKey]['count']++;
            }

            foreach ($groupedItems as $group) {
                // Compute fare once per unique item and reserve remaining
                // repeated quantity as one batched capacity operation.
                $template = $this->ticketService->reserveAndPrice($group['item']);

                $extraCount = max(0, ((int) $group['count']) - 1);
                if ($extraCount > 0) {
                    $this->ticketService->reserveFromTemplate($template, $extraCount);
                }

                for ($i = 0; $i < (int) $group['count']; $i++) {
                    $reserved = $template;
                    $total += $reserved['amount'];
                    $reserved['passenger_id'] = $passengerId;
                    $reservedItems[] = $reserved;
                }
            }

            Log::info('Checkout reservation stage completed', [
                'item_count' => count($payload['items'] ?? []),
                'payment_id' => $payment->payment_id,
                'elapsed_ms' => (int) round((microtime(true) - $startedAt) * 1000),
            ]);

            $rewardPointsApplied = 0;
            $rewardDiscount = 0.0;

            if ($passengerId && $pointsRequested > 0) {
                $currentPoints = $this->rewardService->getPoints($passengerId);
                if ($currentPoints <= 0) {
                    throw ValidationException::withMessages([
                        'reward_points_to_use' => ['No reward points are available to redeem.'],
                    ]);
                }

                $maxRedeemableByAmount = max(0, (int) floor($total - 1));
                $rewardPointsApplied = min($pointsRequested, $currentPoints, $maxRedeemableByAmount);
                $rewardDiscount = (float) $rewardPointsApplied;

                if ($rewardPointsApplied <= 0) {
                    throw ValidationException::withMessages([
                        'reward_points_to_use' => ['Requested reward points cannot be applied. Minimum payable amount is PHP 1.00 for online checkout.'],
                    ]);
                }
            }

            $netTotal = max(0.0, round($total - $rewardDiscount, 2));

            $remainingDiscount = $rewardDiscount;
            foreach ($reservedItems as $idx => $reserved) {
                $grossAmount = (float) ($reserved['amount'] ?? 0);
                $itemDiscount = min($grossAmount, $remainingDiscount);
                $netAmount = max(0.0, round($grossAmount - $itemDiscount, 2));
                $remainingDiscount = max(0.0, round($remainingDiscount - $itemDiscount, 2));

                $reservedItems[$idx]['reward_earn_amount'] = $netAmount;
            }

            $targetCentavos = max(100, (int) round($netTotal * 100));
            $lineItems = [[
                'name' => 'Smart Transit Ticket Purchase',
                'amount' => $targetCentavos,
                'currency' => 'PHP',
                'quantity' => 1,
            ]];

            $payment->amount = $netTotal;
            $payment->items_payload = $reservedItems;
            // Matches PayMongo's default checkout session expiry window.
            // Confirm against your PayMongo dashboard settings if you've
            // customized session expiry.
            $payment->hold_expires_at = Carbon::now()->addMinutes(15);
            $payment->save();

            return [
                'payment' => $payment,
                'line_items' => $lineItems,
                'gross_amount' => (float) $total,
                'reward_points_applied' => $rewardPointsApplied,
                'reward_discount' => $rewardDiscount,
                'net_amount' => (float) $payment->amount,
            ];
        });

        /** @var Payment $payment */
        $payment = $prepared['payment'];
        $returnBaseUrl = $this->resolveCheckoutReturnBaseUrl($payload);

        try {
            $session = $this->payMongoService->createCheckoutSession(
                $payment,
                $prepared['line_items'],
                $returnBaseUrl . '/checkout/success',
                $returnBaseUrl . '/checkout/cancel',
            );

            Log::info('Checkout gateway session created', [
                'payment_id' => $payment->payment_id,
                'elapsed_ms' => (int) round((microtime(true) - $startedAt) * 1000),
            ]);
        } catch (\Throwable $e) {
            try {
                $this->failOnlinePaymentWithReleases($payment);
            } catch (\Throwable $releaseError) {
                Log::error('Checkout release failed after gateway error', [
                    'payment_id' => $payment->payment_id,
                    'error' => $releaseError->getMessage(),
                ]);
            }
            throw $e;
        }

        DB::transaction(function () use ($session, $payment, $passengerId, $prepared) {
            $freshPayment = Payment::query()->find($payment->payment_id);
            if (!$freshPayment) {
                throw new \RuntimeException('Payment record no longer exists.');
            }

            $freshPayment->gateway_reference = $session['id'];
            $freshPayment->payment_intent_id = $session['attributes']['payment_intent']['id'] ?? null;
            $freshPayment->save();

            if ($passengerId && ((int) $prepared['reward_points_applied']) > 0) {
                $this->rewardService->redeemPoints(
                    $passengerId,
                    (int) $prepared['reward_points_applied'],
                    'Redeemed during checkout: ' . $freshPayment->transaction_reference,
                    $freshPayment->payment_id,
                );
            }
        });

        Log::info('Checkout finalized for redirect', [
            'payment_id' => $payment->payment_id,
            'elapsed_ms' => (int) round((microtime(true) - $startedAt) * 1000),
        ]);

        return [
            'payment' => Payment::query()->find($payment->payment_id),
            'gross_amount' => $prepared['gross_amount'],
            'reward_points_applied' => $prepared['reward_points_applied'],
            'reward_discount' => $prepared['reward_discount'],
            'net_amount' => $prepared['net_amount'],
            'checkout_url' => $session['attributes']['checkout_url'],
        ];
    }

    private function resolveCheckoutReturnBaseUrl(array $payload): string
    {
        $candidate = $payload['return_base_url'] ?? null;

        if (is_string($candidate) && filter_var($candidate, FILTER_VALIDATE_URL)) {
            $scheme = parse_url($candidate, PHP_URL_SCHEME);
            if (is_string($scheme) && in_array(Str::lower($scheme), ['http', 'https'], true)) {
                return rtrim($candidate, '/');
            }
        }

        return rtrim((string) config('app.frontend_url', 'http://localhost:5173'), '/');
    }

    /**
     * $payload = ['items' => [...]]
     * $payload['items'][*] = [...same shapes as above, plus 'passenger_id' nullable]
     *
     * Onsite/cash sales are confirmed instantly, so tickets are still
     * created immediately here (no pending window to defer for).
     */
    public function checkoutOnsite(int $conductorCompanyUserId, array $payload): array
    {
        return DB::transaction(function () use ($conductorCompanyUserId, $payload) {
            $payment = $this->paymentRepository->create([
                'amount' => 0,
                'transaction_reference' => 'TXN-' . strtoupper(uniqid()),
                'payment_method' => 'cash',
                'payment_channel' => 'onsite',
                'status' => 'paid',
                'is_valid' => true,
            ]);

            $this->onsitePaymentRepository->create([
                'payment_id' => $payment->payment_id,
                'conductor_id' => $conductorCompanyUserId,
            ]);

            $total = 0;
            $tickets = [];

            foreach ($payload['items'] as $item) {
                $passengerId = $item['passenger_id'] ?? null;
                $ticket = $this->ticketService->issueTicket($item, $passengerId, $payment->payment_id);
                $total += $ticket->amount;
                $tickets[] = $ticket;
            }

            $payment->amount = $total;
            $payment->save();

            return ['payment' => $payment, 'tickets' => $tickets];
        });
    }

    /**
     * Called by PayMongoController::handleCheckoutPaid. Confirms the payment
     * and finalizes every reserved item in items_payload into a real ticket
     * row, atomically — if any item fails to finalize, the whole thing rolls
     * back (including the status change), so the webhook can be safely
     * retried by PayMongo instead of leaving a half-issued purchase.
     *
     * Idempotent: safe to call again on webhook redelivery.
     */
    public function finalizeOnlinePayment(Payment $payment): void
    {
        if ($payment->tickets()->exists()) {
            Log::info('PaymentService: tickets already issued for this payment, skipping.', ['payment_id' => $payment->payment_id]);
            $this->confirmOnlinePayment($payment->payment_id);
            return;
        }

        DB::transaction(function () use ($payment) {
            $this->confirmOnlinePayment($payment->payment_id);

            foreach ($payment->items_payload ?? [] as $reservedItem) {
                $passengerId = $reservedItem['passenger_id'] ?? null;
                $this->ticketService->finalizeTicket($reservedItem, $passengerId, $payment->payment_id);
            }
        });
    }

    /**
     * Called by PayMongoController::handlePaymentFailed. Marks the payment
     * failed and releases every reserved item's held seat/standing capacity
     * back to the trip. Idempotent.
     */
    public function failOnlinePaymentWithReleases(Payment $payment): void
    {
        if ($payment->status === 'failed') {
            Log::info('PaymentService: payment already marked failed, skipping duplicate release.', ['payment_id' => $payment->payment_id]);
            return;
        }

        DB::transaction(function () use ($payment) {
            $this->failOnlinePayment($payment->payment_id);

            foreach ($payment->items_payload ?? [] as $reservedItem) {
                $this->ticketService->releaseTicketHold($reservedItem);
            }
        });
    }

    public function findById(int $paymentId)
    {
        return $this->paymentRepository->findById($paymentId);
    }

    public function findByGatewayReference(string $gatewayReference)
    {
        return $this->paymentRepository->findByGatewayReference($gatewayReference);
    }

    public function findByPaymentIntentId(string $paymentIntentId)
    {
        return $this->paymentRepository->findByPaymentIntentId($paymentIntentId);
    }

    public function confirmOnlinePayment(int $paymentId): bool
    {
        return $this->paymentRepository->markStatus($paymentId, 'paid', true);
    }

    public function failOnlinePayment(int $paymentId): bool
    {
        return $this->paymentRepository->markStatus($paymentId, 'failed', false);
    }

    public function getPassengerHistoryFromPayments(int $passengerId): object
    {
        return $this->paymentRepository->findPassengerHistoryFromPayments($passengerId);
    }
}