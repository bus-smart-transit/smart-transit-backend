<?php
namespace App\Services;
use App\Models\Payment;
use App\Repositories\PaymentRepository;
use App\Repositories\OnlinePaymentRepository;
use App\Repositories\OnsitePaymentRepository;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Carbon;

class PaymentService
{
    public function __construct(
        private PaymentRepository $paymentRepository,
        private OnlinePaymentRepository $onlinePaymentRepository,
        private OnsitePaymentRepository $onsitePaymentRepository,
        private TicketService $ticketService,
        private PayMongoService $payMongoService,
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
        return DB::transaction(function () use ($passengerId, $guestEmail, $payload) {
            $payment = $this->paymentRepository->create([
                'amount' => 0,
                'transaction_reference' => 'TXN-' . strtoupper(uniqid()),
                'payment_method' => 'online',
                'payment_channel' => $payload['payment_channel'],
                'guest_email' => $guestEmail,
                'status' => 'pending',
                'is_valid' => true,
            ]);

            if ($passengerId) {
                $this->onlinePaymentRepository->create([
                    'passenger_id' => $passengerId,
                    'payment_id' => $payment->payment_id,
                ]);
            }

            $total = 0;
            $reservedItems = [];
            $lineItems = [];

            foreach ($payload['items'] as $item) {
                // Holds seat/standing capacity now and locks in the fare —
                // no ticket row is created yet.
                $reserved = $this->ticketService->reserveAndPrice($item);
                $total += $reserved['amount'];
                $reservedItems[] = $reserved;

                $lineItems[] = [
                    'name' => "Trip #{$reserved['trip_id']} — {$reserved['seat_type']}",
                    'amount' => (int) round($reserved['amount'] * 100), // PayMongo expects centavos
                    'currency' => 'PHP',
                    'quantity' => 1,
                ];
            }

            $payment->amount = $total;
            $payment->items_payload = $reservedItems;
            // Matches PayMongo's default checkout session expiry window.
            // Confirm against your PayMongo dashboard settings if you've
            // customized session expiry.
            $payment->hold_expires_at = Carbon::now()->addMinutes(15);
            $payment->save();

            $session = $this->payMongoService->createCheckoutSession(
                $payment,
                $lineItems,
                config('app.frontend_url') . '/checkout/success',
                config('app.frontend_url') . '/checkout/cancel',
            );

            $payment->gateway_reference = $session['id'];
            $payment->payment_intent_id = $session['attributes']['payment_intent']['id'] ?? null;
            $payment->save();

            return [
                'payment' => $payment,
                'checkout_url' => $session['attributes']['checkout_url'],
            ];
        });
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

            $passengerId = $payment->onlinePayment?->passenger_id;

            foreach ($payment->items_payload ?? [] as $reservedItem) {
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
}