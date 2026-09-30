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
            return $this->client->webhooks->constructEvent([
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
    }
}