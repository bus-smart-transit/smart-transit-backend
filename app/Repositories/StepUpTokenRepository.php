<?php

namespace App\Repositories;

use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

/**
 * step_up_tokens has no Eloquent model (a small, write-heavy, short-lived
 * token table) — accessed via the query builder here, kept behind a
 * Repository so callers (StepUpService, RequireStepUp middleware) never
 * touch DB::table() directly. Moved out of StaffAuthController and
 * RequireStepUp, which previously queried it inline.
 */
class StepUpTokenRepository
{
    public function purgeForUser(int $userId): int
    {
        return DB::table('step_up_tokens')
            ->where('user_id', $userId)
            ->delete();
    }

    public function create(int $userId, string $tokenHash, CarbonInterface $expiresAt): void
    {
        DB::table('step_up_tokens')->insert([
            'user_id' => $userId,
            'token_hash' => $tokenHash,
            'expires_at' => $expiresAt,
            'created_at' => now(),
        ]);
    }

    public function findValidByHash(string $tokenHash): ?object
    {
        return DB::table('step_up_tokens')
            ->where('token_hash', $tokenHash)
            ->where('expires_at', '>', now())
            ->first();
    }
}
