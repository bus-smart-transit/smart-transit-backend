<?php

namespace App\Services;

use App\Contracts\PaymentGateway;
use App\Exceptions\PayMongoCheckoutOutcomeUnknown;
use App\Exceptions\PayMongoNotConfigured;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * 2026-11-26 (user): Xendit as the online payment provider (PayMongo closed the test account). The passenger pays on
 * Xendit's hosted page (a Payment Session in PAYMENT_LINK mode, GCash or Maya); Xendit's webhook (XenditController,
 * checked with the dashboard's verification token) confirms it. Refunds use Xendit's Refunds API (v3), keyed by the
 * payment id the completed session carries. Answers are given in the PayMongo shapes (see PaymentGateway).
 *
 * Docs: https://docs.xendit.co/apidocs/create-session.md and https://docs.xendit.co/apidocs/create-a-refund.md
 */
class XenditService implements PaymentGateway
{
    private const REFUND_API_VERSION = '2024-11-11';

    /** A Payment Session must expire at least 10 minutes after it is made. */
    private const MIN_SESSION_MINUTES = 11;

    public function createCheckoutSession(object $payment, array $lineItems, string $successUrl, string $cancelUrl, ?string $billingEmail = null): array
    {
        $centavos = (int) collect($lineItems)->sum(fn (array $item) => (int) $item['amount'] * max(1, (int) ($item['quantity'] ?? 1)));
        $holdEnds = $payment->hold_expires_at ? Carbon::parse($payment->hold_expires_at) : null;
        $earliest = now()->addMinutes(self::MIN_SESSION_MINUTES);
        $expiresAt = $holdEnds && $holdEnds->greaterThan($earliest) ? $holdEnds : $earliest;

        $body = [
            'reference_id' => (string) $payment->transaction_reference,
            'session_type' => 'PAY',
            'mode' => 'PAYMENT_LINK',
            'amount' => round($centavos / 100, 2),
            'currency' => 'PHP',
            'country' => 'PH',
            'allowed_payment_channels' => [($payment->payment_channel ?? '') === 'maya' ? 'PAYMAYA' : 'GCASH'],
            'description' => 'SmartTransit bus ticket '.$payment->transaction_reference,
            'success_return_url' => $successUrl,
            'cancel_return_url' => $cancelUrl,
            'expires_at' => $expiresAt->copy()->utc()->format('Y-m-d\TH:i:s.v\Z'),
            'metadata' => ['payment_id' => (string) $payment->payment_id],
        ];

        $startedAt = hrtime(true);
        try {
            $response = $this->client()->post($this->url('/sessions'), $body);
        } catch (\Throwable $exception) {
            $this->record('payment_session.create', $startedAt, 'network_error');
            throw new PayMongoCheckoutOutcomeUnknown((string) $payment->transaction_reference, $exception);
        }

        if ($response->serverError()) {
            $this->record('payment_session.create', $startedAt, 'provider_error', $response->status());
            throw new PayMongoCheckoutOutcomeUnknown((string) $payment->transaction_reference);
        }
        if ($response->failed() || ! $response->json('payment_link_url')) {
            $this->record('payment_session.create', $startedAt, 'rejected', $response->status());
            Log::warning('Xendit refused the payment session.', ['error_code' => $response->json('error_code'), 'message' => $response->json('message')]);
            throw new \RuntimeException('Unable to create the payment page. Please try again.');
        }
        $this->record('payment_session.create', $startedAt, 'success', $response->status());

        return [
            'id' => (string) $response->json('payment_session_id'),
            'attributes' => [
                'checkout_url' => (string) $response->json('payment_link_url'),
                'payment_intent' => ['id' => null],
            ],
        ];
    }

    public function createRefund(string $gatewayPaymentId, int $amountCentavos, string $reason, ?string $notes = null, array $metadata = []): array
    {
        $body = [
            // A new reference for every refund (Xendit refuses a repeated one as a duplicate).
            'reference_id' => 'RFD-'.Str::upper(Str::random(20)),
            'payment_id' => $gatewayPaymentId,
            'amount' => round($amountCentavos / 100, 2),
            'reason' => $this->reason($reason),
            'metadata' => array_map('strval', $metadata + ($notes ? ['notes' => mb_substr($notes, 0, 255)] : [])),
        ];

        $startedAt = hrtime(true);
        try {
            $response = $this->client()->withHeaders(['api-version' => self::REFUND_API_VERSION])->post($this->url('/v3/refunds'), $body);
        } catch (\Throwable $e) {
            $this->record('refund.create', $startedAt, 'network_error');
            throw new \RuntimeException('The payment provider could not be reached. Try the refund again shortly.');
        }

        if ($response->failed()) {
            $this->record('refund.create', $startedAt, $response->serverError() ? 'provider_error' : 'rejected', $response->status());
            throw new \RuntimeException((string) ($response->json('message') ?: 'The payment provider refused the refund.'));
        }
        $this->record('refund.create', $startedAt, 'success', $response->status());

        return $this->refundShape((array) $response->json());
    }

    public function retrieveRefund(string $refundId): ?array
    {
        $startedAt = hrtime(true);
        try {
            $response = $this->client()->withHeaders(['api-version' => self::REFUND_API_VERSION])->timeout(10)->get($this->url('/v3/refunds/'.urlencode($refundId)));
        } catch (\Throwable $e) {
            $this->record('refund.retrieve', $startedAt, 'network_error');

            return null;
        }
        $this->record('refund.retrieve', $startedAt, $this->outcome($response), $response->status());

        return $response->successful() ? $this->refundShape((array) $response->json()) : null;
    }

    public function findPaymentIdForCheckoutSession(string $sessionId): ?string
    {
        $session = $this->session($sessionId, 'payment_session.lookup');
        $id = $session['payment_id'] ?? null;

        return is_string($id) && $id !== '' ? $id : null;
    }

    public function lookupCheckoutSession(string $sessionId): ?array
    {
        $session = $this->session($sessionId, 'payment_session.status');
        if ($session === null) {
            return null;
        }
        $paymentId = $session['payment_id'] ?? null;

        return [
            'paid' => ($session['status'] ?? null) === 'COMPLETED',
            'gateway_payment_id' => is_string($paymentId) && $paymentId !== '' ? $paymentId : null,
            'payment_intent_id' => null,
        ];
    }

    /** True when the webhook carries the dashboard's verification token (x-callback-token). */
    public static function webhookTokenValid(?string $token): bool
    {
        $expected = trim((string) config('services.xendit.webhook_token'));

        return $expected !== '' && is_string($token) && hash_equals($expected, $token);
    }

    /**
     * A Xendit refund object in the PayMongo shape the refund code reads (status lower case, amount in centavos).
     *
     * @param  array<string, mixed>  $refund
     * @return array<string, mixed>
     */
    public function refundShape(array $refund): array
    {
        return [
            'id' => $refund['refund_id'] ?? $refund['id'] ?? null,
            'attributes' => [
                'status' => strtolower((string) ($refund['status'] ?? 'pending')),
                'amount' => isset($refund['amount']) ? (int) round(((float) $refund['amount']) * 100) : null,
                'payment_id' => $refund['payment_id'] ?? null,
                'metadata' => is_array($refund['metadata'] ?? null) ? $refund['metadata'] : [],
                'failed_message' => $refund['failure_code'] ?? null,
            ],
        ];
    }

    /** @return array<string, mixed>|null */
    private function session(string $sessionId, string $operation): ?array
    {
        $startedAt = hrtime(true);
        try {
            $response = $this->client()->timeout(10)->get($this->url('/sessions/'.urlencode($sessionId)));
        } catch (\Throwable $e) {
            $this->record($operation, $startedAt, 'network_error');

            return null;
        }
        $this->record($operation, $startedAt, $this->outcome($response), $response->status());

        return $response->successful() ? (array) $response->json() : null;
    }

    /** PayMongo-style reasons (config refunds.gateway_reason) to Xendit's: CANCELLATION, REQUESTED_BY_CUSTOMER, ... */
    private function reason(string $reason): string
    {
        $reason = strtolower($reason);

        return match (true) {
            str_contains($reason, 'requested') => 'REQUESTED_BY_CUSTOMER',
            str_contains($reason, 'duplicate') => 'DUPLICATE',
            str_contains($reason, 'fraud') => 'FRAUDULENT',
            str_contains($reason, 'cancel') => 'CANCELLATION',
            default => 'OTHERS',
        };
    }

    private function client(): PendingRequest
    {
        $key = trim((string) config('services.xendit.secret_key'));
        if ($key === '') {
            throw new PayMongoNotConfigured;
        }

        return Http::withBasicAuth($key, '')->acceptJson()->asJson()->timeout(15)->connectTimeout(5);
    }

    private function url(string $path): string
    {
        return rtrim((string) config('services.xendit.base_url', 'https://api.xendit.co'), '/').$path;
    }

    private function outcome(Response $response): string
    {
        return $response->successful() ? 'success' : ($response->serverError() ? 'provider_error' : 'rejected');
    }

    private function record(string $operation, int $startedAt, string $outcome, ?int $status = null): void
    {
        Log::info('payment.provider_request', [
            'provider' => 'xendit',
            'operation' => $operation,
            'outcome' => $outcome,
            'status' => $status,
            'duration_ms' => round((hrtime(true) - $startedAt) / 1_000_000, 2),
        ]);
    }
}
