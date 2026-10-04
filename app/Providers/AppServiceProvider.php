<?php

namespace App\Providers;

use App\Support\Database\RlsContext;
use App\Support\RateLimiting\ToggleableRateLimiter;
use Illuminate\Cache\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
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
        if ($this->app->runningInConsole()) {
            RlsContext::enableBypass();
        }

        $this->guardStepUpBypassIsDevOnly();
        $this->guardStaffOtpBypassIsDevOnly();
        $this->guardRateLimitingOffIsDevOnly();
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
