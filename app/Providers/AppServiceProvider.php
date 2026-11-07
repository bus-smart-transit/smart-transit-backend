<?php

namespace App\Providers;

use App\Support\ApiRequestMetrics;
use App\Support\Database\RlsContext;
use App\Support\HealthBeat;
use App\Support\PlaceGroups;
use App\Support\RateLimiting\ToggleableRateLimiter;
use Illuminate\Cache\RateLimiter;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Http\Client\Events\ConnectionFailed;
use Illuminate\Http\Client\Events\ResponseReceived;
use Illuminate\Http\Request;
use Illuminate\Mail\Events\MessageSent;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\ServiceProvider;
use Laravel\Sanctum\Sanctum;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->scoped(ApiRequestMetrics::class);
        // Batch 49 item 6: the stops are grouped into places once per request or job.
        $this->app->scoped(PlaceGroups::class);

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
        $this->configureRateLimiters();
        $this->recordHealthBeats();
    }

    /**
     * Batch 48 Phase 6: the last result of the queue, email and outside services, for the System health page
     * (App\Support\HealthBeat). Only a status code is kept, never the URL (it can hold an API key).
     */
    private function recordHealthBeats(): void
    {
        Event::listen(ResponseReceived::class, function (ResponseReceived $event) {
            $part = HealthBeat::partForHost(parse_url((string) $event->request->url(), PHP_URL_HOST) ?: null);
            if ($part !== null) {
                HealthBeat::record($part, $event->response->successful(), 'HTTP '.$event->response->status());
            }
        });
        Event::listen(ConnectionFailed::class, function (ConnectionFailed $event) {
            $part = HealthBeat::partForHost(parse_url((string) $event->request->url(), PHP_URL_HOST) ?: null);
            if ($part !== null) {
                HealthBeat::record($part, false, 'could not connect');
            }
        });
        Event::listen(MessageSent::class, fn () => HealthBeat::record('mail', true));
        Queue::after(fn (JobProcessed $event) => HealthBeat::record('queue', true));
        Queue::failing(fn (JobFailed $event) => HealthBeat::record('queue', false, 'a job failed'));
    }

    /**
     * 2026-10-27: the checkout return page asks for one payment's status every few seconds. It is counted per payment
     * reference and IP, so two passengers behind one network (or the map polling in another tab) never use up each
     * other's checks. Same limit as before: 30 a minute.
     */
    private function configureRateLimiters(): void
    {
        $limiter = $this->app->make(RateLimiter::class);

        $limiter->for('payment-status', fn (Request $request) => Limit::perMinute(30)
            ->by('payment-status|'.(string) $request->query('ref', '').'|'.$request->ip()));

        // 2026-11-04 (Batch 45 Todo 2): counted per email, account or reference + IP, so people behind one carrier or
        // office address no longer use up each other's tries; a wider per-IP cap still stops one address from going
        // through many emails or references. Passenger and staff forgot-password pages have their own counters.
        $bySubjectAndIp = fn (string $name, int $perSubject, int $perIp, callable $subject) => $limiter->for(
            $name,
            fn (Request $request) => [
                Limit::perMinutes(15, $perSubject)->by($name.'|subject|'.sha1(mb_strtolower(trim((string) $subject($request)))).'|'.$request->ip()),
                Limit::perMinutes(15, $perIp)->by($name.'|ip|'.$request->ip()),
            ],
        );

        $bySubjectAndIp('pw-forgot-passenger', 3, 20, fn (Request $request) => $request->input('email', ''));
        $bySubjectAndIp('pw-forgot-staff', 3, 20, fn (Request $request) => $request->input('email', ''));
        $bySubjectAndIp('verify-email-resend', 3, 20, fn (Request $request) => $request->input('account_uuid', ''));
        $bySubjectAndIp('tickets-lookup', 10, 40, fn (Request $request) => $request->input('transaction_reference', ''));

        // 2026-11-06 (before group testing on one Wi-Fi): sign-up, login codes and password resets were counted per IP
        // only, so the 6th person on a school or event network was refused. Now per email or account + IP, with a
        // wider per-IP cap that still stops one address from trying many accounts.
        $bySubjectAndIp('passenger-register', 3, 60, fn (Request $request) => $request->input('email', ''));
        $bySubjectAndIp('passengers-verify-otp', 10, 100, fn (Request $request) => $request->input('account_uuid', ''));
        $bySubjectAndIp('staff-verify-otp', 5, 30, fn (Request $request) => $request->input('account_uuid', ''));
        $bySubjectAndIp('pw-reset', 6, 30, fn (Request $request) => $request->input('email', ''));

        // Public read pages (fares, region, routes, trips, booking lookups, tracking) a group on one network loads at the
        // same time: 240 a minute per IP, each route counted on its own (was 60).
        $limiter->for('public-read', fn (Request $request) => Limit::perMinute(240)
            ->by('public-read|'.($request->route()?->uri() ?? $request->path()).'|'.$request->ip()));
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
