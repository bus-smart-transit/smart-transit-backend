<?php

namespace App\Http\Middleware;

use App\Services\TripService;
use App\Support\BoardingOpen;
use Closure;
use Illuminate\Http\Request;

/**
 * Batch 74: refuses a Chauffeur's scan, cash sale or check-in while their current trip has not started boarding
 * (BoardingOpen owns the rule). With no current trip it lets the request through, so the endpoint keeps its own reply.
 */
class EnsureBoardingOpen
{
    public function __construct(private TripService $tripService) {}

    public function handle(Request $request, Closure $next): mixed
    {
        $companyUser = $request->user()?->companyProfile;
        $trip = $companyUser ? $this->tripService->getCurrentTripForConductor((int) $companyUser->company_user_id) : null;

        if ($trip && ! BoardingOpen::allows($trip->status)) {
            return response()->json([
                'status' => 'error',
                'message' => BoardingOpen::MESSAGE,
                'errors' => ['trip' => [BoardingOpen::MESSAGE]],
            ], 422);
        }

        return $next($request);
    }
}
