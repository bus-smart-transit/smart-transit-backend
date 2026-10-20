<?php

use App\Http\Middleware\CheckRole;
use App\Http\Middleware\ApiRequestMetricsMiddleware;
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
