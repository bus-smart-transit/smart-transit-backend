<?php

namespace App\Http\Controllers;

use App\Models\Payment;
use App\Repositories\PaymentRepository;
use App\Services\DiscountClaimService;
use App\Services\PaymentService;
use App\Services\TicketResolutionService;
use App\Services\UnfulfilledPaymentService;
use App\Services\XenditService;
use App\Support\Database\RlsContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * 2026-11-26 (user): Xendit's webhook (POST /api/webhooks/xendit). It is the only thing that confirms an online payment
 * or settles a refund when Xendit is the provider, as PayMongoController is for PayMongo; it calls the same code.
 * A call is accepted only with the dashboard's verification token in `x-callback-token`.
 *
 *  - payment_session.completed: the passenger paid; the tickets are issued (finalizeOnlinePayment, idempotent).
 *  - payment_session.expired: the page expired unpaid; the payment fails and its hold is released.
 *  - refund.succeeded / refund.failed: a refund is settled (ticket, discount, or a payment that made no ticket).
 */
class XenditController extends Controller
{
    public function __construct(
        private PaymentService $payments,
        private PaymentRepository $paymentRepository,
        private TicketResolutionService $ticketResolutions,
        private UnfulfilledPaymentService $unfulfilledPayments,
        private XenditService $xendit,
    ) {}

    public function handle(Request $request): JsonResponse
    {
        if (! XenditService::webhookTokenValid($request->header('x-callback-token'))) {
            return response()->json(['message' => 'Invalid token'], 401);
        }

        $event = (string) $request->input('event', '');
        $data = (array) $request->input('data', []);

        RlsContext::withBypass(function () use ($event, $data) {
            Log::info('Xendit webhook received', ['event' => $event]);

            match ($event) {
                'payment_session.completed' => $this->sessionCompleted($data),
                'payment_session.expired' => $this->sessionExpired($data),
                'refund.succeeded', 'refund.failed' => $this->settleRefund($this->xendit->refundShape($data)),
                default => Log::info('Unhandled Xendit event', ['event' => $event]),
            };
        });

        return response()->json(['message' => 'Webhook processed'], 200);
    }

    /** The payment this session belongs to: by the session id stored at checkout, else by our TXN reference. */
    private function paymentFor(array $data): ?Payment
    {
        $sessionId = (string) ($data['payment_session_id'] ?? '');
        $payment = $sessionId !== '' ? $this->paymentRepository->findByGatewayReference($sessionId) : null;
        if (! $payment && ! empty($data['reference_id'])) {
            $payment = $this->paymentRepository->findByTransactionReference((string) $data['reference_id']);
            // A session we never stored must still carry this payment's own reference (it does: reference_id).
            if ($payment && $payment->gateway_reference && $sessionId !== '' && $payment->gateway_reference !== $sessionId) {
                Log::warning('Xendit webhook: the session does not match the payment; ignored.', ['payment_id' => $payment->payment_id]);

                return null;
            }
        }
        if (! $payment) {
            Log::warning('Xendit webhook: no matching payment.', ['session_id' => $sessionId]);
        }

        return $payment;
    }

    private function sessionCompleted(array $data): void
    {
        $payment = $this->paymentFor($data);
        if (! $payment) {
            return;
        }

        $fill = [];
        if (empty($payment->gateway_reference) && ! empty($data['payment_session_id'])) {
            $fill['gateway_reference'] = (string) $data['payment_session_id'];
        }
        // A refund needs Xendit's own payment id; the completed session carries it.
        if (empty($payment->gateway_payment_id) && ! empty($data['payment_id'])) {
            $fill['gateway_payment_id'] = (string) $data['payment_id'];
        }
        if ($fill !== []) {
            $payment->forceFill($fill)->save();
        }

        $this->payments->finalizeOnlinePayment($payment);
        Log::info('Payment confirmed via Xendit webhook, tickets issued.', ['payment_id' => $payment->payment_id]);
    }

    private function sessionExpired(array $data): void
    {
        $payment = $this->paymentFor($data);
        if (! $payment || $payment->status !== 'pending') {
            return;
        }

        $this->payments->failOnlinePaymentWithReleases($payment);
        Log::info('Payment page expired unpaid (Xendit); hold released.', ['payment_id' => $payment->payment_id]);
    }

    /** A ticket refund (trip cancelled), a discount refund, else the refund of a payment that made no ticket. */
    private function settleRefund(array $refund): void
    {
        if (! $this->ticketResolutions->handleGatewayRefund($refund)
            && ! app(DiscountClaimService::class)->handleGatewayRefund($refund)) {
            $this->unfulfilledPayments->handleGatewayRefund($refund);
        }
    }
}
