<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * 2026-11-06 (decided by the user): only a company admin (company_users.is_company_admin) changes the company's buses,
 * fares, staff, routes and stops. Other Operators of the company dispatch and only view those. The platform Admin is
 * not limited. Reads never use this middleware.
 */
class EnsureCompanyAdmin
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        if ($user?->role === 'admin' || ($user?->role === 'operator' && (bool) $user->companyProfile?->is_company_admin)) {
            return $next($request);
        }

        return response()->json([
            'status' => 'error',
            'message' => 'Only your company admin can change this. Ask them, or the platform Admin.',
        ], 403);
    }
}
