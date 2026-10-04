<?php

namespace App\Support\RateLimiting;

use Illuminate\Cache\RateLimiter;

/**
 * Rate limiter that can be switched off with RATE_LIMITING_ENABLED=false
 * (config 'app.rate_limiting_enabled'). Dev/test sessions only.
 *
 * Both the route throttles (`throttle:N,M`) and the manual limiters in the
 * controllers (login, OTP, tracking misses) ask this class whether a caller
 * has made "too many attempts", so one override covers all of them. When the
 * switch is off nothing is ever reported as too many; hits are still counted,
 * so turning the switch back on starts from the real counters.
 */
class ToggleableRateLimiter extends RateLimiter
{
    public function tooManyAttempts($key, $maxAttempts)
    {
        if (! config('app.rate_limiting_enabled', true)) {
            return false;
        }

        return parent::tooManyAttempts($key, $maxAttempts);
    }
}
