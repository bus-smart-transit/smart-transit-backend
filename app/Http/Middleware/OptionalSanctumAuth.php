<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Laravel\Sanctum\PersonalAccessToken;

/**
 * Lets a route accept BOTH guests and authenticated passengers.
 *
 * - If a valid Sanctum bearer token is present, the user is attached via auth()->setUser()
 *   so $request->user() works normally downstream.
 * - If no token, or an invalid/expired token, the request simply proceeds as a guest.
 *   It does NOT reject the request the way the standard 'auth:sanctum' middleware would.
 */
class OptionalSanctumAuth
{
    public function handle(Request $request, Closure $next)
    {
        $token = $request->bearerToken();

        if ($token) {
            $accessToken = PersonalAccessToken::findToken($token);

            if ($accessToken && $accessToken->tokenable) {
                auth()->setUser($accessToken->tokenable);
            }
        }

        return $next($request);
    }
}