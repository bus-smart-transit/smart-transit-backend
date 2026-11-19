<?php

namespace App\Http\Middleware;

use App\Services\AuditLogger;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Batch 48 Phase 1: every change a Super Admin makes through /api/admin is recorded in the audit log, even where a
 * controller writes no entry of its own (Phase 4: and only then). It records the method, the route pattern and its ids, and the result; never
 * the request body (it may hold passwords, emails or phones). Reads are not recorded here.
 */
class AuditAdminWrites
{
    public function handle(Request $request, Closure $next): Response
    {
        $before = AuditLogger::writes();
        $response = $next($request);

        // Batch 48 Phase 4: only when the action wrote no entry of its own, so one change is one line in the log.
        if (! $request->isMethodSafe() && $response->getStatusCode() < 400 && AuditLogger::writes() === $before) {
            $route = $request->route();
            AuditLogger::log('admin.'.strtolower($request->method()), 'AdminRoute', null, [
                'route' => $route?->uri(),
                'ids' => array_filter((array) $route?->parameters(), fn ($value) => is_scalar($value)),
                'status' => $response->getStatusCode(),
            ]);
        }

        return $response;
    }
}
