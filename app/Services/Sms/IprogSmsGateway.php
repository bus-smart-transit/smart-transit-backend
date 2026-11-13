<?php

namespace App\Services\Sms;

use Illuminate\Support\Facades\Http;

/**
 * Batch 64: the IPROG SMS API (https://www.iprogsms.com/api/v1/documentation). Only this class talks to it.
 *  - POST /sms_messages: api_token, phone_number (09XXXXXXXXX), message, sms_provider -> { status, message, message_id }
 *  - GET /account/sms_credits?api_token= -> { load_balance }
 * IPROG answers "status": 200 in the body on success; an invalid token is "status": 500 with "Invalid Token".
 * A successful answer means IPROG accepted the text, not that it reached the phone.
 */
class IprogSmsGateway
{
    /** @return array{ok: bool, message_id: string|null, error: string|null} */
    public function send(string $e164Phone, string $message): array
    {
        $token = (string) config('sms.api_token');
        if ($token === '') {
            return ['ok' => false, 'message_id' => null, 'error' => 'IPROGSMS_API_TOKEN is not set.'];
        }

        try {
            $response = Http::timeout((int) config('sms.timeout_seconds'))
                ->acceptJson()
                ->asJson()
                ->post(config('sms.base_url').'/sms_messages', [
                    'api_token' => $token,
                    'phone_number' => self::localNumber($e164Phone),
                    'message' => $message,
                    'sms_provider' => (int) config('sms.sms_provider'),
                ]);
        } catch (\Throwable $e) {
            return ['ok' => false, 'message_id' => null, 'error' => 'IPROG could not be reached: '.mb_substr($e->getMessage(), 0, 180)];
        }

        $body = $response->json();
        $body = is_array($body) ? $body : [];
        $messageId = is_scalar($body['message_id'] ?? null) ? (string) $body['message_id'] : null;
        $bodyStatus = $body['status'] ?? null;
        $ok = $response->successful() && ($messageId !== null || (is_numeric($bodyStatus) && (int) $bodyStatus === 200));

        return [
            'ok' => $ok,
            'message_id' => $messageId,
            'error' => $ok ? null : mb_substr('IPROG: '.self::answerText($body['message'] ?? null, $response->body(), $response->status()), 0, 255),
        ];
    }

    /**
     * IPROG's refusal as one line of text. Its "message" can be a sentence or a list (for example field errors), so a
     * list is flattened; with no message, the raw body or the HTTP status is used.
     */
    public static function answerText(mixed $message, string $rawBody, int $httpStatus): string
    {
        if (is_array($message)) {
            $parts = [];
            array_walk_recursive($message, function ($value, $key) use (&$parts) {
                $parts[] = is_int($key) ? (string) $value : "{$key}: {$value}";
            });

            return $parts === [] ? 'HTTP '.$httpStatus : implode('; ', $parts);
        }
        if (is_scalar($message) && (string) $message !== '') {
            return (string) $message;
        }

        return $rawBody !== '' ? $rawBody : 'HTTP '.$httpStatus;
    }

    /** The credits left, or null when IPROG cannot be asked. */
    public function credits(): ?float
    {
        $token = (string) config('sms.api_token');
        if ($token === '') {
            return null;
        }
        try {
            $response = Http::timeout((int) config('sms.timeout_seconds'))->acceptJson()->get(config('sms.base_url').'/account/sms_credits', ['api_token' => $token]);
        } catch (\Throwable) {
            return null;
        }
        $balance = $response->json('data.load_balance') ?? $response->json('load_balance');

        return $response->successful() && is_numeric($balance) ? (float) $balance : null;
    }

    /** +639171234567 -> 09171234567 (the form IPROG documents). */
    public static function localNumber(string $e164Phone): string
    {
        return str_starts_with($e164Phone, '+63') ? '0'.substr($e164Phone, 3) : $e164Phone;
    }
}
