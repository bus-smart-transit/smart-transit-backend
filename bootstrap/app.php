<?php

use App\Http\Middleware\ApiRequestMetricsMiddleware;
use App\Http\Middleware\CheckRole;
use App\Http\Middleware\OptionalSanctumAuth;
use App\Http\Middleware\RequirePairedSession;
use App\Http\Middleware\RequireStepUp;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Exceptions\ThrottleRequestsException;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // 2026-10-30: behind a reverse proxy (Caddy on the VPS, ngrok on a laptop) every request arrives from the
        // proxy, so each client's own address (rate limits, audit log) is read from X-Forwarded-For. TRUSTED_PROXIES
        // says which proxy may set it: "*" only where the backend port is reachable through the proxy alone.
        $trustedProxies = trim((string) env('TRUSTED_PROXIES', ''));
        if ($trustedProxies !== '') {
            $middleware->trustProxies(at: $trustedProxies === '*' ? '*' : array_map('trim', explode(',', $trustedProxies)));
        }

        $middleware->append('App\\Http\\Middleware\\ApplyDatabaseRlsContext');
        $middleware->appendToGroup('api', ApiRequestMetricsMiddleware::class);

        $middleware->alias([
            'role' => CheckRole::class,
            'auth.optional' => OptionalSanctumAuth::class,
            'paired' => RequirePairedSession::class,
            'step-up' => RequireStepUp::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->render(function (ThrottleRequestsException $e, Request $request) {
            if ($request->is('api/*') || $request->expectsJson()) {
                // Says how long to wait, and no longer claims every limit is about logging in (a map, a scan or a
                // PIN try that is slowed down is not a "login attempt").
                $seconds = (int) ($e->getHeaders()['Retry-After'] ?? 0);
                $wait = $seconds >= 90
                    ? 'about '.(int) ceil($seconds / 60).' minutes'
                    : ($seconds > 0 ? $seconds.' seconds' : 'a moment');

                return response()->json([
                    'success' => false,
                    'message' => "Too many attempts. Please wait {$wait} and try again.",
                ], 429, array_intersect_key($e->getHeaders(), ['Retry-After' => true]));
            }

            return null;
        });
    })->create();
