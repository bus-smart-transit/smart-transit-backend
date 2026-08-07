<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

class CheckRole
{
    public function handle(Request $request, Closure $next, string ...$roles): mixed
    {
        if (!$request->user() || !in_array($request->user()->role, $roles)) {
            return response()->json([
                'status' => 'error',
                'message' => 'Access denied. Insufficient permissions.',
            ], 403);
        }

        return $next($request);
    }
}