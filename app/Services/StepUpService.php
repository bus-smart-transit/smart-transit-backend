<?php

namespace App\Services;

use App\Repositories\StepUpTokenRepository;

/**
 * Step-up re-authentication tokens (short-lived, issued after OTP
 * verification, required on sensitive endpoints like fare-rule changes).
 * Moved data access out of StaffAuthController::stepUpVerify() and the
 * RequireStepUp middleware, which previously queried step_up_tokens
 * directly via DB::table().
 */
class StepUpService
{
    private const TTL_MINUTES = 15;

    public function __construct(
        private StepUpTokenRepository $stepUpTokenRepository,
    ) {}

    /**
     * Purges any existing step-up tokens for the user and issues a new one.
     * Returns the RAW token (not the hash) to send to the client.
     */
    public function issueToken(int $userId): string
    {
        $this->stepUpTokenRepository->purgeForUser($userId);

        $rawToken = bin2hex(random_bytes(32));

        $this->stepUpTokenRepository->create(
            $userId,
            hash('sha256', $rawToken),
            now()->addMinutes(self::TTL_MINUTES),
        );

        return $rawToken;
    }

    public function ttlSeconds(): int
    {
        return self::TTL_MINUTES * 60;
    }

    /**
     * Resolves a raw step-up token to the user_id it was issued for, only
     * if it exists and has not expired. Returns null if invalid/expired.
     */
    public function resolveUserIdForToken(string $rawToken): ?int
    {
        $record = $this->stepUpTokenRepository->findValidByHash(hash('sha256', $rawToken));

        return $record ? (int) $record->user_id : null;
    }
}
