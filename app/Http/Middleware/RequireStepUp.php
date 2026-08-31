<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

/**
 * Enforce step-up re-authentication for sensitive endpoints.
 *
 * The client must first call POST /step-up/verify, which validates an OTP
 * and issues a short-lived step-up token (TTL: 15 minutes). Include that
 * token in the X-Step-Up-Token request header on any route protected here.
 */
class RequireStepUp
{
    public function handle(Request $request, Closure $next): Response
    {
        $rawToken = $request->header('X-Step-Up-Token');

        if (!$rawToken) {
            return response()->json([
                'message' => 'Step-up authentication required.',
                'step_up' => true,
            ], 403);
        }

        $hash   = hash('sha256', $rawToken);
        $record = DB::table('step_up_tokens')
            ->where('token_hash', $hash)
            ->where('expires_at', '>', now())
            ->first();

        if (!$record) {
            return response()->json([
                'message' => 'Step-up token is invalid or has expired. Please re-authenticate.',
                'step_up' => true,
            ], 403);
        }

        $request->attributes->set('step_up_user_id', $record->user_id);

        return $next($request);
    }
}

