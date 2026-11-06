<?php

namespace App\Http\Middleware;

use App\Services\AuditLogger;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Batch 48 Phase 1: every change a Super Admin makes through /api/admin is recorded in the audit log, even where a
 * controller writes no entry of its own. It records the method, the route pattern and its ids, and the result; never
 * the request body (it may hold passwords, emails or phones). Reads are not recorded here.
 */
class AuditAdminWrites
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        if (! $request->isMethodSafe() && $response->getStatusCode() < 400) {
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
