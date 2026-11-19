<?php

namespace App\Support\RateLimiting;

use Illuminate\Support\Facades\RateLimiter;

/**
 * Owns the sign-in limit for POST /auth/login (decided 2026-11-04, Batch 45 Todo 2; numbers in config auth.sign_in_limits).
 *
 * - Per email + IP: after 5 failed sign-ins within the failure window, that email is locked on that IP for 5 minutes,
 *   counted from the fifth failure (not from the first one).
 * - Per IP: a wider cap on all failures from one address, so one address cannot try many emails. People behind one
 *   carrier or office address each keep their own per-email count below that cap.
 * - A successful sign-in clears only that email's count; the IP count keeps running out its window.
 *
 * Only failures are counted. Counting uses RateLimiter::attempts (not affected by RATE_LIMITING_ENABLED), and the
 * checks use tooManyAttempts (switched off by it), so turning the switch back on applies the failures kept meanwhile.
 */
class SignInThrottle
{
    /**
     * Seconds the caller must still wait, or null when this email may try to sign in from this IP.
     */
    public static function secondsLocked(string $portal, string $email, ?string $ip): ?int
    {
        $limits = [
            self::lockKey($portal, $email, $ip) => 1,
            self::ipKey($portal, $ip) => self::limit('ip_failures'),
        ];

        foreach ($limits as $key => $maxAttempts) {
            if (RateLimiter::tooManyAttempts($key, $maxAttempts)) {
                return max(1, RateLimiter::availableIn($key));
            }
        }

        return null;
    }

    /**
     * Counts one failed sign-in; the fifth failure in a row for this email on this IP starts the lock.
     */
    public static function recordFailure(string $portal, string $email, ?string $ip): void
    {
        RateLimiter::hit(self::ipKey($portal, $ip), self::limit('ip_window_minutes') * 60);

        $failuresKey = self::failuresKey($portal, $email, $ip);
        RateLimiter::hit($failuresKey, self::limit('failure_window_minutes') * 60);

        if (RateLimiter::attempts($failuresKey) >= self::limit('failures')) {
            RateLimiter::clear($failuresKey);
            RateLimiter::hit(self::lockKey($portal, $email, $ip), self::limit('lock_minutes') * 60);
        }
    }

    /**
     * A successful sign-in: this email starts again from zero on this IP. Other emails and the IP cap are untouched.
     */
    public static function recordSuccess(string $portal, string $email, ?string $ip): void
    {
        RateLimiter::clear(self::failuresKey($portal, $email, $ip));
    }

    /**
     * "about 5 minutes" from 300, "45 seconds" from 45 (same wording as the 429 handler in bootstrap/app.php).
     */
    public static function waitText(int $seconds): string
    {
        return $seconds >= 90 ? 'about '.(int) ceil($seconds / 60).' minutes' : $seconds.' seconds';
    }

    private static function failuresKey(string $portal, string $email, ?string $ip): string
    {
        return $portal.'-login-failures:'.self::emailHash($email).'|'.$ip;
    }

    private static function lockKey(string $portal, string $email, ?string $ip): string
    {
        return $portal.'-login-lock:'.self::emailHash($email).'|'.$ip;
    }

    private static function ipKey(string $portal, ?string $ip): string
    {
        return $portal.'-login-ip:'.$ip;
    }

    /**
     * Hashed so no email address is stored in the cache keys.
     */
    private static function emailHash(string $email): string
    {
        return sha1(mb_strtolower(trim($email)));
    }

    private static function limit(string $name): int
    {
        return (int) config('auth.sign_in_limits.'.$name);
    }
}
