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

        $body = $response->json() ?? [];
        $messageId = $body['message_id'] ?? null;
        $bodyStatus = $body['status'] ?? null;
        $ok = $response->successful() && ($messageId !== null || (int) $bodyStatus === 200);

        return [
            'ok' => $ok,
            'message_id' => $messageId !== null ? (string) $messageId : null,
            'error' => $ok ? null : mb_substr('IPROG: '.($body['message'] ?? $response->body() ?: 'HTTP '.$response->status()), 0, 255),
        ];
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
