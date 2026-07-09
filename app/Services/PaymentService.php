<?php
namespace App\Services;
use App\Repositories\PaymentRepository;
use App\Repositories\OnlinePaymentRepository;
use App\Repositories\OnsitePaymentRepository;
use Illuminate\Support\Facades\DB;
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
     * $payload['items'][*] = ['trip_id','seat_type','origin_stop_id','destination_stop_id']
     *
     * IMPORTANT: no ticket rows are created here anymore. Each item's
     * seat/standing capacity is reserved and its fare locked in via
     * TicketService::reserveAndPrice(), and that reservation is stored as
     * JSON on payments.items_payload. Actual `tickets` rows are only created
     * once the webhook confirms payment — see
     * PayMongoController::handleCheckoutPaid -> PaymentService::finalizeTicket().
     *
     * online_payments is created immediately (it just records WHICH passenger
     * made the online payment, not whether it succeeded). Success/failure is
     * tracked solely via payments.status + payments.is_valid — see
     * confirmOnlinePayment()/failOnlinePayment() below and
     * TicketService::validateScan(), which checks payment status before
     * allowing a scan.
     */
    /**
     * $payload     = ['items' => [...]]
     * $payload['items'][*] = ['trip_id','seat_type','origin_stop_id','destination_stop_id','passenger_id' nullable]
     * 
     * Onsite/cash sales are confirmed instantly, so tickets are still
     * created immediately here (no pending window to defer for).
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
            //IGNORE - Intelephense auto correct but does not affect workflow.
            $payment->items_payload = $reservedItems;
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