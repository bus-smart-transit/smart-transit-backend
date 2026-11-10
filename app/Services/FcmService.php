<?php

namespace App\Services;

use App\Models\User;
use App\Repositories\UserNotificationRepository;
use App\Support\FirebaseCredentials;
use App\Support\HealthBeat;
use App\Support\LiveUpdates;
use Illuminate\Support\Facades\Log;
use Kreait\Firebase\Contract\Messaging;
use Kreait\Firebase\Exception\MessagingException;
use Kreait\Firebase\Factory;
use Kreait\Firebase\Messaging\CloudMessage;

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

    private UserNotificationRepository $notificationRepository;

    public function __construct(?Messaging $messaging = null, ?UserNotificationRepository $notificationRepository = null)
    {
        $this->notificationRepository = $notificationRepository ?? app(UserNotificationRepository::class);

        if ($messaging) {
            $this->messaging = $messaging;

            return;
        }

        try {
            $this->messaging = $this->buildMessaging();
        } catch (\Throwable $e) {
            Log::warning('FcmService: not configured, push notifications disabled — '.$e->getMessage());
            $this->messaging = null;
        }
    }

    private function buildMessaging(): Messaging
    {
        // 2026-11-07: shared with Google sign-in (App\Support\FirebaseCredentials).
        return FirebaseCredentials::factory()->createMessaging();
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
     *
     * S1 (Batch 17): this is the single choke point every trip/payment
     * notification already flows through, so it's also where the in-app
     * notification-bell history is written — regardless of whether the
     * push itself succeeds (no token registered / device offline / FCM
     * misconfigured), the user should still see it next time they open the
     * app. This guarantees the bell and the push always describe the same
     * event; there is no second, separately-maintained notification list.
     */
    public function sendToUser(User $user, string $title, string $body, array $data = []): bool
    {
        $this->notificationRepository->createForUser(
            $user->user_id,
            (string) ($data['type'] ?? 'general'),
            $title,
            $body,
            $data,
        );
        // Batch 58: the person's open screens reload their bell at once (LiveUpdates; never fails this send).
        LiveUpdates::notification((int) $user->user_id, (string) ($data['type'] ?? 'general'), $data);

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
            HealthBeat::record('push', true);

            return true;
        } catch (MessagingException $e) {
            Log::warning("FcmService: send failed for user {$user->user_id} — ".$e->getMessage());
            HealthBeat::record('push', false, 'refused');

            return false;
        } catch (\Throwable $e) {
            Log::warning("FcmService: unexpected error sending to user {$user->user_id} — ".$e->getMessage());
            HealthBeat::record('push', false, 'error');

            return false;
        }
    }

    /**
     * In-app notification-bell reads (S1, Batch 17) — thin passthroughs to
     * the repository so controllers never touch it directly.
     */
    public function listRecentForUser(int $userId): array
    {
        return $this->notificationRepository->listRecentForUser($userId)->values()->all();
    }

    public function countUnreadForUser(int $userId): int
    {
        return $this->notificationRepository->countUnreadForUser($userId);
    }

    public function markReadForUser(int $notificationId, int $userId): bool
    {
        return $this->notificationRepository->markReadForUser($notificationId, $userId);
    }

    /** @param list<int> $notificationIds */
    public function markManyReadForUser(array $notificationIds, int $userId): int
    {
        return $this->notificationRepository->markManyReadForUser($notificationIds, $userId);
    }

    public function markAllReadForUser(int $userId): int
    {
        return $this->notificationRepository->markAllReadForUser($userId);
    }
}
