<?php

namespace App\Http\Controllers;

use App\Services\PaymentService;
use App\Services\PayMongoService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class PayMongoController extends Controller
{
    public function __construct(
        private PayMongoService $payMongoService,
        private PaymentService $paymentService,
    ) {
    }

    public function handle(Request $request)
    {
        $payload = $request->getContent();
        $signature = $request->header('Paymongo-Signature');

        $event = $this->payMongoService->verifyAndConstructEvent($payload, $signature);

        if (!$event) {
            return response()->json(['message' => 'Invalid signature'], 401);
        }

        Log::info('PayMongo webhook received', ['type' => $event->type]);

        match ($event->type) {
            'checkout_session.payment.paid' => $this->handleCheckoutPaid($event),
            'payment.paid' => $this->handlePaymentPaid($event),
            'payment.failed' => $this->handlePaymentFailed($event),
            default => Log::info('Unhandled PayMongo event type', ['type' => $event->type]),
        };

        return response()->json(['message' => 'Webhook processed'], 200);
    }

    private function resourceToArray(mixed $resource): array
    {
        if (is_array($resource)) {
            return $resource;
        }

        if (is_object($resource)) {
            return json_decode(json_encode($resource), true) ?? [];
        }

        return [];
    }

    /**
     * payment.paid / payment.failed resources carry a pay_... id, which we
     * never store, and don't reliably carry back the metadata attached at
     * checkout-session creation (observed: payment.failed often has
     * metadata === null). Tries metadata first, then falls back to matching
     * on payment_intent_id, which IS reliably present on both event types.
     */
    private function resolvePaymentFromMetadata(array $resource): ?object
    {
        $paymentId = $resource['attributes']['metadata']['payment_id'] ?? null;

        if ($paymentId) {
            return $this->paymentService->findById((int) $paymentId);
        }

        $paymentIntentId = $resource['attributes']['payment_intent_id'] ?? null;

        if ($paymentIntentId) {
            $payment = $this->paymentService->findByPaymentIntentId($paymentIntentId);
            if ($payment) {
                return $payment;
            }
        }

        Log::warning('PayMongo webhook: no payment_id metadata or payment_intent_id match on resource.', [
            'resource_id' => $resource['id'] ?? null,
        ]);

        return null;
    }

    private function handleCheckoutPaid(object $event): void
    {
        $resource = $this->resourceToArray($event->resource);
        $sessionId = $resource['id'] ?? null;

        $payment = $sessionId ? $this->paymentService->findByGatewayReference($sessionId) : null;

        if (!$payment) {
            Log::warning('PayMongo webhook: no matching payment for checkout session.', ['session_id' => $sessionId]);
            return;
        }

        $this->paymentService->finalizeOnlinePayment($payment);

        Log::info('Payment confirmed via webhook, tickets issued.', [
            'payment_id' => $payment->payment_id,
            'session_id' => $sessionId,
        ]);
    }

    private function handlePaymentPaid(object $event): void
    {
        $resource = $this->resourceToArray($event->resource);
        Log::info('payment.paid received (informational only)', ['payment_id' => $resource['id'] ?? null]);
    }

    private function handlePaymentFailed(object $event): void
    {
        $resource = $this->resourceToArray($event->resource);

        $payment = $this->resolvePaymentFromMetadata($resource);

        if (!$payment) {
            Log::warning('PayMongo webhook: no matching payment for failed payment.', ['paymongo_payment_id' => $resource['id'] ?? null]);
            return;
        }

        $this->paymentService->failOnlinePaymentWithReleases($payment);

        Log::info('Payment failed via webhook, held seats released.', [
            'payment_id' => $payment->payment_id,
            'paymongo_payment_id' => $resource['id'] ?? null,
        ]);
    }
}