<?php

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
        $middleware->append('App\\Http\\Middleware\\ApplyDatabaseRlsContext');

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
                return response()->json([
                    'success' => false,
                    'message' => 'Too many login attempts. Please wait 15 minutes and try again.',
                ], 429);
            }

            return null;
        });
    })->create();
