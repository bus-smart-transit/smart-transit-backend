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

    public function __construct()
    {
        $this->secretKey = config('services.paymongo.secret_key');
        $this->client = new PaymongoClient($this->secretKey);
    }

    public function createCheckoutSession(object $payment, array $lineItems, string $successUrl, string $cancelUrl): array
    {
        $response = Http::withBasicAuth($this->secretKey, '')
            ->post('https://api.paymongo.com/v1/checkout_sessions', [
                'data' => [
                    'attributes' => [
                        'send_email_receipt' => false,
                        'show_description' => true,
                        'show_line_items' => true,
                        'line_items' => $lineItems,
                        'payment_method_types' => ['gcash', 'card', 'paymaya'],
                        'success_url' => $successUrl,
                        'cancel_url' => $cancelUrl,
                        'reference_number' => $payment->transaction_reference,
                        'metadata' => [
                            'payment_id' => (string) $payment->payment_id,
                        ],
                    ],
                ],
            ]);

        Log::info('PayMongo checkout session created', ['data' => $response->json('data')]);
        if ($response->failed()) {
            Log::error('PayMongo checkout session creation failed', ['body' => $response->body()]);
            throw new \RuntimeException('Unable to create PayMongo checkout session.');
        }

        return $response->json('data');
    }

    /**
     * Returns the verified event object on success, or null on failure.
     * Uses PayMongo's own SDK verification rather than a hand-rolled HMAC check.
     */

    public function verifyAndConstructEvent(string $payload, ?string $signatureHeader): ?object
    {
        if (!$signatureHeader) {
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
        }
    }
}