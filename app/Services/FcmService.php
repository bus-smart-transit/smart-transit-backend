<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Facades\Log;
use Kreait\Firebase\Factory;
use Kreait\Firebase\Contract\Messaging;
use Kreait\Firebase\Messaging\CloudMessage;
use Kreait\Firebase\Exception\MessagingException;

/**
 * Batch 15, Items 2/3: Firebase Cloud Messaging.
 *
 * Deliberately fail-soft, not fail-hard: unlike PayMongoService (a payment
 * provider — a missing/broken config there should stop checkout), a push
 * notification is a "nice to have" side effect of trip assignment / payment
 * confirmation. Missing config, an invalid/expired device token, or a
 * network error must never break the actual business operation that
 * triggered the notification attempt. Every public method here catches its
 * own exceptions and logs a warning instead of throwing.
 */
class FcmService
{
    private ?Messaging $messaging = null;

    public function __construct(?Messaging $messaging = null)
    {
        if ($messaging) {
            $this->messaging = $messaging;
            return;
        }

        try {
            $this->messaging = $this->buildMessaging();
        } catch (\Throwable $e) {
            Log::warning('FcmService: not configured, push notifications disabled — ' . $e->getMessage());
            $this->messaging = null;
        }
    }

    private function buildMessaging(): Messaging
    {
        $factory = new Factory();

        $credentialsJson = config('services.firebase.credentials_json');
        if (! empty($credentialsJson)) {
            $decoded = json_decode($credentialsJson, true);
            if (json_last_error() !== JSON_ERROR_NONE) {
                throw new \RuntimeException('FIREBASE_CREDENTIALS_JSON is not valid JSON.');
            }
            return $factory->withServiceAccount($decoded)->createMessaging();
        }

        $credentialsPath = config('services.firebase.credentials');
        if (empty($credentialsPath) || ! file_exists($credentialsPath)) {
            throw new \RuntimeException('Firebase service-account credentials not found. Set FIREBASE_CREDENTIALS_JSON or place the JSON file at the configured path.');
        }

        return $factory->withServiceAccount($credentialsPath)->createMessaging();
    }

    public function isEnabled(): bool
    {
        return $this->messaging !== null;
    }

    /**
     * Registers (or overwrites) the calling user's single active device
     * token — one active token per user for this phase, per the batch spec.
     */
    public function registerToken(User $user, string $token): void
    {
        $user->forceFill(['fcm_token' => $token])->save();
    }

    public function clearToken(User $user): void
    {
        $user->forceFill(['fcm_token' => null])->save();
    }

    /**
     * Sends a push notification to a specific user's registered device.
     * No-ops (logs, doesn't throw) if FCM isn't configured, the user has no
     * token, or the send fails for any reason (expired token, network,
     * etc.) — callers (TripService, PaymentService) must never have their
     * own operation fail because of this.
     */
    public function sendToUser(User $user, string $title, string $body, array $data = []): bool
    {
        if (! $this->isEnabled()) {
            return false;
        }

        $token = $user->fcm_token;
        if (empty($token)) {
            return false;
        }

        try {
            // v8.5.0 of kreait/firebase-php builds targeted messages via
            // CloudMessage::fromArray() — there is no CloudMessage::
            // withTarget() static factory in this version (that API existed
            // in older releases; confirmed against the installed source,
            // not assumed from possibly-outdated docs).
            $message = CloudMessage::fromArray([
                'token' => $token,
                'notification' => ['title' => $title, 'body' => $body],
                'data' => array_map('strval', $data),
            ]);

            $this->messaging->send($message);
            return true;
        } catch (MessagingException $e) {
            Log::warning("FcmService: send failed for user {$user->user_id} — " . $e->getMessage());
            return false;
        } catch (\Throwable $e) {
            Log::warning("FcmService: unexpected error sending to user {$user->user_id} — " . $e->getMessage());
            return false;
        }
    }
}
