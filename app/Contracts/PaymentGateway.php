<?php

namespace App\Contracts;

use App\Exceptions\PayMongoCheckoutOutcomeUnknown;

/**
 * 2026-11-26 (user): the online payment provider. PayMongo closed the test account, so Xendit is added beside it and
 * config('services.payments.provider') chooses one (AppServiceProvider binds it). Both answer in the same shapes, the
 * ones the rest of the system already reads (PayMongo's), so confirming payments and settling refunds is unchanged:
 *
 *  - a checkout session: ['id' => session id, 'attributes' => ['checkout_url' => ..., 'payment_intent' => ['id' => ...]]]
 *  - a refund: ['id' => refund id, 'attributes' => ['status' => 'pending'|'succeeded'|'failed', 'amount' => centavos,
 *    'payment_id' => the provider's payment id, 'metadata' => [...]]]
 *
 * Only the provider's signed webhook confirms a payment or a refund; these answers never do.
 */
interface PaymentGateway
{
    /**
     * The hosted payment page for one online payment.
     *
     * @param  array<int, array{name: string, amount: int, currency: string, quantity: int}>  $lineItems  amounts in centavos
     * @return array<string, mixed>
     *
     * @throws PayMongoCheckoutOutcomeUnknown when the provider could not be reached or failed on its side
     * @throws \RuntimeException when the provider refused the request
     */
    public function createCheckoutSession(object $payment, array $lineItems, string $successUrl, string $cancelUrl, ?string $billingEmail = null): array;

    /**
     * @param  array<string, string>  $metadata
     * @return array<string, mixed>
     *
     * @throws \RuntimeException when the provider refuses or cannot be reached
     */
    public function createRefund(string $gatewayPaymentId, int $amountCentavos, string $reason, ?string $notes = null, array $metadata = []): array;

    /** @return array<string, mixed>|null */
    public function retrieveRefund(string $refundId): ?array;

    public function findPaymentIdForCheckoutSession(string $sessionId): ?string;

    /** @return array{paid: bool, gateway_payment_id: string|null, payment_intent_id: string|null}|null */
    public function lookupCheckoutSession(string $sessionId): ?array;
}
