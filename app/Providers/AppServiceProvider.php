<?php

namespace App\Providers;

use App\Support\Database\RlsContext;
use App\Support\ApiRequestMetrics;
use App\Support\RateLimiting\ToggleableRateLimiter;
use Illuminate\Cache\RateLimiter;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\ServiceProvider;
use Laravel\Sanctum\Sanctum;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->scoped(ApiRequestMetrics::class);

        // Laravel's CacheServiceProvider is deferred and (re)binds the limiter
        // when it loads, so a plain singleton() here would be overwritten.
        // extend() runs after that binding and swaps in a limiter that honours
        // RATE_LIMITING_ENABLED (see ToggleableRateLimiter).
        $this->app->extend(RateLimiter::class, function ($original, $app) {
            return new ToggleableRateLimiter($app->make('cache')->driver(
                $app['config']->get('cache.limiter')
            ));
        });
    }

    public function boot(): void
    {
        if (config('api_instrumentation.enabled', true)) {
            DB::listen(fn (QueryExecuted $query) => app(ApiRequestMetrics::class)->recordQuery((float) $query->time));
        }

        if ($this->app->runningInConsole()) {
            RlsContext::enableBypass();
        }

        $this->guardStepUpBypassIsDevOnly();
        $this->guardStaffOtpBypassIsDevOnly();
        $this->guardRateLimitingOffIsDevOnly();
        $this->endIdleStaffSessions();
    }

    /**
     * An Operator's token that has not been used for sanctum.idle_minutes.operator minutes stops working and is deleted,
     * so a forgotten or stolen office session does not stay open for the full 24 hours. Sanctum calls this BEFORE it
     * records the new last_used_at, so the gap it sees is the real idle time. Roles that are not listed are untouched:
     * a Driver's phone sends GPS with nobody touching it, and a Chauffeur's shift runs the same way, so a time limit
     * there would end a trip's tracking.
     */
    private function endIdleStaffSessions(): void
    {
        Sanctum::authenticateAccessTokensUsing(function ($accessToken, bool $isValid) {
            if (! $isValid) {
                return false;
            }

            $limits = array_filter((array) config('sanctum.idle_minutes', []));
            $lastUsed = $accessToken->last_used_at ?? $accessToken->created_at;
            if ($limits === [] || ! $lastUsed || $lastUsed->gt(now()->subMinutes(min($limits)))) {
                return true; // nothing to limit, or used recently enough that the role does not matter
            }

            $limit = $limits[$accessToken->tokenable?->role] ?? null;
            if ($limit && $lastUsed->lt(now()->subMinutes((int) $limit))) {
                $accessToken->delete();

                return false;
            }

            return true;
        });
    }

    /**
     * Same fail-fast pattern as the other dev switches: rate limiting may be
     * turned off only when APP_ENV=local. Anywhere else the app refuses to boot.
     */
    private function guardRateLimitingOffIsDevOnly(): void
    {
        if (! config('app.rate_limiting_enabled', true) && ! $this->app->environment('local', 'testing')) {
            throw new \RuntimeException(
                'RATE_LIMITING_ENABLED is false outside a local environment (APP_ENV='
                    .config('app.env').'). Refusing to boot — rate limiting must be on in '
                    .'staging/production.'
            );
        }
    }

    /**
     * Fail-fast safeguard for STEPUP-01 (see SECURITY_AUDIT.md): this
     * project has no CI/CD pipeline to run an automated pre-deploy check,
     * so this runtime guard is the substitute. If DISABLE_STEP_UP is ever
     * true outside a local environment — e.g. an env value copied into a
     * staging/production config by mistake — refuse to boot rather than
     * silently allow step-up auth to be bypassed.
     */
    private function guardStepUpBypassIsDevOnly(): void
    {
        if (config('app.disable_step_up') && ! $this->app->environment('local')) {
            throw new \RuntimeException(
                'DISABLE_STEP_UP is enabled outside a local environment (APP_ENV='
                    .config('app.env').'). Refusing to boot — this flag must never '
                    .'be true in staging/production. See SECURITY_AUDIT.md (STEPUP-01).'
            );
        }
    }

    /**
     * Same fail-fast pattern as guardStepUpBypassIsDevOnly(), for the staff
     * login OTP dev-convenience bypass. Refuses to boot if DISABLE_STAFF_OTP
     * is ever true outside a local environment.
     */
    private function guardStaffOtpBypassIsDevOnly(): void
    {
        if (config('app.disable_staff_otp') && ! $this->app->environment('local')) {
            throw new \RuntimeException(
                'DISABLE_STAFF_OTP is enabled outside a local environment (APP_ENV='
                    .config('app.env').'). Refusing to boot — this flag must never '
                    .'be true in staging/production. See SECURITY_AUDIT.md.'
            );
        }
    }
}
