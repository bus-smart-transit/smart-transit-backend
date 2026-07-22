<?php

namespace App\Http\Controllers;

use App\Services\FleetDailyPinService;
use App\Services\TripService;
use App\Http\Requests\VerifyPinRequest;
use App\Traits\ApiResponse;
use Illuminate\Http\Request;

class FleetDailyPinController extends Controller
{
    use ApiResponse;

    public function __construct(
        private FleetDailyPinService $pinService,
        private TripService $tripService,
    ) {}

    /**
     * Driver views today's PIN for their assigned fleet.
     * GET /driver/pin
     */
    public function showForDriver(Request $request)
    {
        $companyUser = $request->user()->companyProfile;
        $fleetId     = $this->resolveFleetForDriver($companyUser->company_user_id);

        $pin = $this->pinService->getTodayPin($fleetId);

        if (!$pin) {
            return $this->error('No PIN has been generated for your fleet today. Ensure both driver and conductor are assigned.', 404);
        }

        return $this->success([
            'pin_code'             => $pin->pin_code,
            'fleet_id'             => $pin->fleet_id,
            'pin_date'             => $pin->pin_date,
            'driver_verified_at'   => $pin->driver_verified_at,
            'conductor_verified_at' => $pin->conductor_verified_at,
            'both_verified'        => $pin->isBothVerified(),
        ], 'Daily PIN retrieved');
    }

    /**
     * Conductor views today's PIN for their assigned fleet.
     * GET /conductor/pin
     */
    public function showForConductor(Request $request)
    {
        $companyUser = $request->user()->companyProfile;
        $fleetId     = $this->resolveFleetForConductor($companyUser->company_user_id);

        $pin = $this->pinService->getTodayPin($fleetId);

        if (!$pin) {
            return $this->error('No PIN has been generated for your fleet today. Ensure both driver and conductor are assigned.', 404);
        }

        return $this->success([
            'pin_code'             => $pin->pin_code,
            'fleet_id'             => $pin->fleet_id,
            'pin_date'             => $pin->pin_date,
            'driver_verified_at'   => $pin->driver_verified_at,
            'conductor_verified_at' => $pin->conductor_verified_at,
            'both_verified'        => $pin->isBothVerified(),
        ], 'Daily PIN retrieved');
    }

    /**
     * Driver submits PIN to confirm they're on the correct fleet.
     * POST /driver/pin/verify
     */
    public function verifyAsDriver(VerifyPinRequest $request)
    {
        $companyUser = $request->user()->companyProfile;
        $fleetId     = $this->resolveFleetForDriver($companyUser->company_user_id);

        $pin = $this->pinService->verify(
            $fleetId,
            $request->validated()['pin_code'],
            $companyUser->company_user_id,
            'driver'
        );

        return $this->success([
            'fleet_id'           => $pin->fleet_id,
            'driver_verified_at' => $pin->driver_verified_at,
            'both_verified'      => $pin->isBothVerified(),
        ], 'PIN verified successfully. You are on the correct fleet.');
    }

    /**
     * Conductor submits PIN to confirm they're on the correct fleet.
     * POST /conductor/pin/verify
     */
    public function verifyAsConductor(VerifyPinRequest $request)
    {
        $companyUser = $request->user()->companyProfile;
        $fleetId     = $this->resolveFleetForConductor($companyUser->company_user_id);

        $pin = $this->pinService->verify(
            $fleetId,
            $request->validated()['pin_code'],
            $companyUser->company_user_id,
            'conductor'
        );

        return $this->success([
            'fleet_id'              => $pin->fleet_id,
            'conductor_verified_at' => $pin->conductor_verified_at,
            'both_verified'         => $pin->isBothVerified(),
        ], 'PIN verified successfully. You are on the correct fleet.');
    }

    // -------------------------------------------------------------------------
    // Helpers: resolve fleet_id from today's assigned trip
    // -------------------------------------------------------------------------

    private function resolveFleetForDriver(int $driverId): int
    {
        $trip = $this->tripService->getCurrentOrScheduledTripForDriver($driverId);

        if (!$trip) {
            abort(404, 'No trip assigned for today. Cannot resolve fleet.');
        }

        return $trip->fleetRoute->fleet_id;
    }

    private function resolveFleetForConductor(int $conductorId): int
    {
        $trip = $this->tripService->getCurrentOrScheduledTripForConductor($conductorId);

        if (!$trip) {
            abort(404, 'No trip assigned for today. Cannot resolve fleet.');
        }

        return $trip->fleetRoute->fleet_id;
    }
}
