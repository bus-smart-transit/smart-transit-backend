<?php

namespace App\Http\Controllers;

use App\Services\PaymentService;
use App\Services\PayMongoService;
use App\Services\TicketResolutionService;
use App\Services\UnfulfilledPaymentService;
use App\Support\Database\RlsContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class PayMongoController extends Controller
{
    public function __construct(
        private PayMongoService $payMongoService,
        private PaymentService $paymentService,
        private TicketResolutionService $ticketResolutions,
        private UnfulfilledPaymentService $unfulfilledPayments,
    ) {}

    public function handle(Request $request)
    {
        $payload = $request->getContent();
        $signature = $request->header('Paymongo-Signature');

        $event = $this->payMongoService->verifyAndConstructEvent($payload, $signature);

        if (! $event) {
            return response()->json(['message' => 'Invalid signature'], 401);
        }

        RlsContext::withBypass(function () use ($event) {
            Log::info('PayMongo webhook received', ['type' => $event->type]);

            match ($event->type) {
                'checkout_session.payment.paid' => $this->handleCheckoutPaid($event),
                'payment.paid' => $this->handlePaymentPaid($event),
                'payment.failed' => $this->handlePaymentFailed($event),
                // 2026-11-04 (Batch 45, Todo R): PayMongo sends payment.refunded / payment.refund.updated (its Events
                // page); refund.* is kept for any older payload shape. All of them settle through handleRefund().
                'payment.refunded', 'payment.refund.updated', 'refund.succeeded', 'refund.failed', 'refund.updated' => $this->handleRefund($event),
                default => Log::info('Unhandled PayMongo event type', ['type' => $event->type]),
            };
        });

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
     *
     * 2026-11-04 (Batch 45, Todo W): PayMongo sends every event of the account to every webhook, and `payment_id` in
     * the metadata is a plain number another app on the same account could also send. A payment found by that number
     * is used only when the event also carries something that is that payment's own: its TXN reference (the checkout
     * session's reference_number) or its payment intent.
     */
    private function resolvePaymentFromMetadata(array $resource): ?object
    {
        $paymentId = $resource['attributes']['metadata']['payment_id'] ?? null;

        if ($paymentId) {
            $payment = $this->paymentService->findById((int) $paymentId);
            if ($payment && $this->eventBelongsTo($payment, $resource)) {
                return $payment;
            }
            if ($payment) {
                Log::warning('PayMongo webhook: metadata payment_id does not belong to this payment; ignored.', [
                    'resource_id' => $resource['id'] ?? null,
                ]);
            }
        }

        $paymentIntentId = $this->eventPaymentIntentId($resource);

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

    /** The event carries this payment's own TXN reference or payment intent (see resolvePaymentFromMetadata). */
    private function eventBelongsTo(object $payment, array $resource): bool
    {
        $reference = $resource['attributes']['reference_number'] ?? null;
        if (is_string($reference) && $reference !== '' && hash_equals((string) $payment->transaction_reference, $reference)) {
            return true;
        }

        $intent = $this->eventPaymentIntentId($resource);

        return is_string($intent) && $intent !== '' && ! empty($payment->payment_intent_id)
            && hash_equals((string) $payment->payment_intent_id, $intent);
    }

    private function eventPaymentIntentId(array $resource): ?string
    {
        $intent = $resource['attributes']['payment_intent_id'] ?? $resource['attributes']['payment_intent']['id'] ?? null;

        return is_string($intent) && $intent !== '' ? $intent : null;
    }

    private function handleCheckoutPaid(object $event): void
    {
        $resource = $this->resourceToArray($event->resource);
        $sessionId = $resource['id'] ?? null;

        $payment = $sessionId ? $this->paymentService->findByGatewayReference($sessionId) : null;
        if (! $payment) {
            $payment = $this->resolvePaymentFromMetadata($resource);
        }

        if (! $payment) {
            Log::warning('PayMongo webhook: no matching payment for checkout session.', ['session_id' => $sessionId]);

            return;
        }

        if ($payment->idempotency_state === 'unknown') {
            $payment->forceFill([
                'gateway_reference' => $payment->gateway_reference ?: $sessionId,
                'payment_intent_id' => $payment->payment_intent_id ?: ($resource['attributes']['payment_intent']['id'] ?? $resource['attributes']['payment_intent_id'] ?? null),
            ])->save();
        }

        // A refund needs PayMongo's own payment id (pay_...); the paid event carries it.
        $gatewayPaymentId = $resource['attributes']['payments'][0]['id'] ?? null;
        if (is_string($gatewayPaymentId) && $gatewayPaymentId !== '' && empty($payment->gateway_payment_id)) {
            $payment->forceFill(['gateway_payment_id' => $gatewayPaymentId])->save();
        }

        $this->paymentService->finalizeOnlinePayment($payment);

        Log::info('Payment confirmed via webhook, tickets issued.', [
            'payment_id' => $payment->payment_id,
            'session_id' => $sessionId,
        ]);
    }

    /**
     * Batch 28 Stage 3, item 6: the signed refund event is the only thing that settles a gateway refund (never the
     * client, never the refund request's own answer). Idempotent: see TicketResolutionService::handleGatewayRefund().
     *
     * 2026-11-04 (Batch 45, Todo R): payment.refunded carries the PAYMENT, with its refunds in `attributes.refunds`;
     * each one is settled on its own. A refund resource (payment.refund.updated, or the older refund.* shape) is
     * settled directly.
     */
    private function handleRefund(object $event): void
    {
        $resource = $this->resourceToArray($event->resource);

        if (($resource['type'] ?? null) === 'payment' || str_starts_with((string) ($resource['id'] ?? ''), 'pay_')) {
            $paymentId = $resource['id'] ?? null;
            foreach ((array) ($resource['attributes']['refunds'] ?? []) as $refund) {
                if (! is_array($refund) || empty($refund['id'])) {
                    continue;
                }
                $refund['attributes'] = (array) ($refund['attributes'] ?? []);
                $refund['attributes']['payment_id'] ??= $paymentId;
                $this->settleRefund($refund);
            }

            return;
        }

        $this->settleRefund($resource);
    }

    /** A ticket refund (trip cancelled), else (Batch 49 item 1) the automatic refund of a payment that made no ticket. */
    private function settleRefund(array $refund): void
    {
        if (! $this->ticketResolutions->handleGatewayRefund($refund)) {
            $this->unfulfilledPayments->handleGatewayRefund($refund);
        }
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

        if (! $payment) {
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
