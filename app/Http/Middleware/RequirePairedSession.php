<?php

namespace App\Http\Middleware;

use App\Services\FleetPairingService;
use App\Services\TripService;
use Closure;
use Illuminate\Http\Request;

class RequirePairedSession
{
    public function __construct(
        private TripService $tripService,
        private FleetPairingService $pairingService,
    ) {}

    public function handle(Request $request, Closure $next, string $role): mixed
    {
        $companyUser = $request->user()?->companyProfile;
        if (!$companyUser) {
            return response()->json([
                'status' => 'error',
                'message' => 'Staff profile not found.',
            ], 404);
        }

        $tripId = (int) $request->route('tripId');

        if ($tripId <= 0) {
            $trip = $role === 'driver'
                ? $this->tripService->getCurrentTripForDriver($companyUser->company_user_id)
                : $this->tripService->getCurrentTripForConductor($companyUser->company_user_id);

            if (!$trip) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'No active trip found.',
                ], 404);
            }

            $tripId = (int) $trip->trip_id;
        }

        $pairingStatus = $this->pairingService->getPairingStatus($tripId);
        if (!($pairingStatus['paired'] ?? false)) {
            return response()->json([
                'status' => 'error',
                'message' => $pairingStatus['reason'] ?? 'Pairing required before accessing this feature.',
            ], 423);
        }

        return $next($request);
    }
}
