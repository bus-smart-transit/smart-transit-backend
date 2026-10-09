<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Paymongo\PaymongoClient;
use Paymongo\Exceptions\SignatureVerificationException;

class PayMongoService
{
    private string $secretKey;
    private PaymongoClient $client;

    /**
     * @param PaymongoClient|null $client  Inject a mock in tests; production
     *                                     receives null and auto-builds the real client.
     */
    public function __construct(?PaymongoClient $client = null)
    {
        $this->secretKey = config('services.paymongo.secret_key');

        if (empty($this->secretKey) || str_contains($this->secretKey, 'REPLACE_WITH')) {
            throw new \RuntimeException('PayMongo secret key is not configured. Set PAYMONGO_SECRET_KEY in your .env file.');
        }

        $this->client = $client ?? new PaymongoClient($this->secretKey);
    }

    /**
     * C4: PayMongo's Checkout Session only requires line_items and
     * payment_method_types; billing is optional (checked against the
     * PayMongo docs), and the hosted page collects whatever else it needs. So no
     * customer-information form exists in the app: an authenticated passenger's
     * account email is passed as optional billing.email, nothing else is.
     */
    public function createCheckoutSession(object $payment, array $lineItems, string $successUrl, string $cancelUrl, ?string $billingEmail = null): array
    {
        $verifyOption = $this->resolveVerifyOption();
        $methodType = ($payment->payment_channel ?? '') === 'maya' ? 'paymaya' : 'gcash';
        $attributes = [
            'send_email_receipt' => false,
            'show_description' => true,
            'show_line_items' => true,
            'line_items' => $lineItems,
            'payment_method_types' => [$methodType],
            'success_url' => $successUrl,
            'cancel_url' => $cancelUrl,
            'reference_number' => $payment->transaction_reference,
            'metadata' => [
                'payment_id' => (string) $payment->payment_id,
            ],
        ];
        if ($billingEmail) {
            $attributes['billing'] = ['email' => $billingEmail];
        }

        $response = Http::withBasicAuth($this->secretKey, '')
            ->withOptions(['verify' => $verifyOption])
            ->timeout(15)          // fail fast if PayMongo is unresponsive
            ->connectTimeout(5)    // TCP connect must succeed within 5s
            ->post('https://api.paymongo.com/v1/checkout_sessions', ['data' => ['attributes' => $attributes]]);

        $sessionData = $response->json('data') ?? [];
        Log::info('PayMongo checkout session created', [
            'session_id' => $sessionData['id'] ?? null,
            'checkout_url' => $sessionData['attributes']['checkout_url'] ?? null,
            'payment_intent_id' => $sessionData['attributes']['payment_intent']['id'] ?? null,
        ]);
        if ($response->failed()) {
            Log::error('PayMongo checkout session creation failed', ['body' => $response->body()]);
            throw new \RuntimeException('Unable to create PayMongo checkout session.');
        }

        return $response->json('data');
    }

    /**
     * Batch 28 Stage 3, item 6: refund all or part of ONE payment to the original payment method (GCash, Maya, card).
     * PayMongo's documented rules: the amount is in centavos, at most the payment's amount minus earlier refunds, and
     * several partial refunds may be issued on one payment. Maya accepts only a full refund on the day of the payment.
     * The refund's final state is NOT read from this answer: it comes from the signed refund webhook.
     *
     * @param  array<string, string>  $metadata  string values only (PayMongo's rule)
     * @return array<string, mixed>  the refund resource (`data` of the response)
     *
     * @throws \RuntimeException when PayMongo refuses or cannot be reached
     */
    public function createRefund(string $gatewayPaymentId, int $amountCentavos, string $reason, ?string $notes = null, array $metadata = []): array
    {
        $attributes = [
            'amount' => $amountCentavos,
            'payment_id' => $gatewayPaymentId,
            'reason' => $reason,
        ];
        if ($notes !== null && $notes !== '') {
            $attributes['notes'] = mb_substr($notes, 0, 255);
        }
        if ($metadata !== []) {
            $attributes['metadata'] = array_map('strval', $metadata);
        }

        try {
            $response = Http::withBasicAuth($this->secretKey, '')
                ->withOptions(['verify' => $this->resolveVerifyOption()])
                ->timeout(15)
                ->connectTimeout(5)
                ->post('https://api.paymongo.com/v1/refunds', ['data' => ['attributes' => $attributes]]);
        } catch (\Throwable $e) {
            Log::error('PayMongo refund request could not be sent', ['error' => $e->getMessage()]);
            throw new \RuntimeException('The payment provider could not be reached. Try the refund again shortly.');
        }

        if ($response->failed()) {
            Log::error('PayMongo refund refused', ['status' => $response->status(), 'body' => $response->body()]);
            throw new \RuntimeException((string) ($response->json('errors.0.detail') ?: 'The payment provider refused the refund.'));
        }

        return $response->json('data') ?? [];
    }

    /**
     * The pay_... id of the payment behind a checkout session. Payments made before the id was stored have only the
     * session id; a refund needs the payment id, so it is read from the session when it is first needed.
     */
    public function findPaymentIdForCheckoutSession(string $sessionId): ?string
    {
        try {
            $response = Http::withBasicAuth($this->secretKey, '')
                ->withOptions(['verify' => $this->resolveVerifyOption()])
                ->timeout(15)
                ->connectTimeout(5)
                ->get('https://api.paymongo.com/v1/checkout_sessions/'.urlencode($sessionId));
        } catch (\Throwable $e) {
            Log::warning('PayMongo checkout session lookup failed', ['error' => $e->getMessage()]);

            return null;
        }

        $id = $response->successful() ? $response->json('data.attributes.payments.0.id') : null;

        return is_string($id) && $id !== '' ? $id : null;
    }

    private function resolveVerifyOption(): bool|string
    {
        $verify = (bool) config('services.paymongo.verify_ssl', true);
        if (!$verify) {
            return false;
        }

        $configuredBundle = trim((string) config('services.paymongo.ca_bundle', ''));
        $candidates = [];

        if ($configuredBundle !== '') {
            $candidates[] = $configuredBundle;
            $candidates[] = base_path($configuredBundle);
        }

        $candidates[] = base_path('storage/certs/cacert.pem');

        foreach ($candidates as $candidate) {
            if (is_string($candidate) && $candidate !== '' && is_file($candidate)) {
                return $candidate;
            }
        }

        return true;
    }

    /**
     * Returns the verified event object on success, or null on failure.
     * Uses PayMongo's own SDK verification rather than a hand-rolled HMAC check.
     */

    public function verifyAndConstructEvent(string $payload, ?string $signatureHeader): ?object
    {
        // An unset webhook secret would make the HMAC key empty and forgeable.
        if (!$signatureHeader || trim((string) config('services.paymongo.webhook_secret')) === '') {
            return null;
        }

        try {
            $event = $this->client->webhooks->constructEvent([
                'payload' => $payload,
                'signature_header' => $signatureHeader,
                'webhook_secret_key' => config('services.paymongo.webhook_secret'),
            ]);
        } catch (SignatureVerificationException $e) {
            Log::warning('PayMongo webhook signature verification failed.', ['error' => $e->getMessage()]);
            return null;
        } catch (\Throwable $e) {
            // Malformed header/payload: reject as unauthenticated rather than 500.
            Log::warning('PayMongo webhook rejected: malformed signature or payload.', ['error' => $e->getMessage()]);
            return null;
        }

        // The signature proves PayMongo sent it, not WHEN. The SDK does not look at the age, so a captured delivery
        // could be replayed forever. The timestamp is part of what was signed (it cannot be changed without breaking
        // the signature), so it is checked here against a tolerance wide enough for PayMongo's own retries.
        $tolerance = (int) config('services.paymongo.webhook_tolerance_s', 86400);
        if ($tolerance > 0 && preg_match('/(?:^|,)\s*t=(\d+)/', $signatureHeader, $match)) {
            if (abs(time() - (int) $match[1]) > $tolerance) {
                Log::warning('PayMongo webhook rejected: signature timestamp outside the allowed window.');
                return null;
            }
        }

        return $event;
    }
}