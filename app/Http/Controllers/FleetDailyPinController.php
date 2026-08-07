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
        $trip        = $this->resolveTripForDriver($companyUser->company_user_id);

        $pin = $this->pinService->getTodayPin($trip->trip_id);

        // Auto-generate if the trip has both staff but the PIN was never created
        // (e.g. operator assigned driver/conductor after trip creation).
        if (!$pin) {
            if ($trip->driver_id && $trip->conductor_id) {
                $pin = $this->pinService->generateOrGet($trip->trip_id, $trip->driver_id, $trip->conductor_id);
            } else {
                return $this->error('No PIN has been generated for your trip today. Ensure both driver and conductor are assigned.', 404);
            }
        }

        return $this->success([
            'pin_code'             => $pin->pin_code,
            'trip_id'              => $pin->trip_id,
            'trip_status'          => $trip->status,
            'trip_date'            => $trip->trip_date,
            'route_name'           => $trip->fleetRoute?->route?->route_name,
            'fleet_plate_number'   => $trip->fleetRoute?->fleet?->plate_number,
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
        $trip        = $this->resolveTripForConductor($companyUser->company_user_id);

        $pin = $this->pinService->getTodayPin($trip->trip_id);

        // Auto-generate if the trip has both staff but the PIN was never created.
        if (!$pin) {
            if ($trip->driver_id && $trip->conductor_id) {
                $pin = $this->pinService->generateOrGet($trip->trip_id, $trip->driver_id, $trip->conductor_id);
            } else {
                return $this->error('No PIN has been generated for your trip today. Ensure both driver and conductor are assigned.', 404);
            }
        }

        return $this->success([
            'pin_code'             => $pin->pin_code,
            'trip_id'              => $pin->trip_id,
            'trip_status'          => $trip->status,
            'trip_date'            => $trip->trip_date,
            'route_name'           => $trip->fleetRoute?->route?->route_name,
            'fleet_plate_number'   => $trip->fleetRoute?->fleet?->plate_number,
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
        $trip        = $this->resolveTripForDriver($companyUser->company_user_id);

        $pin = $this->pinService->verify(
            $trip->trip_id,
            $request->validated()['pin_code'],
            $companyUser->company_user_id,
            'driver'
        );

        return $this->success([
            'trip_id'            => $pin->trip_id,
            'trip_status'        => $trip->status,
            'route_name'         => $trip->fleetRoute?->route?->route_name,
            'fleet_plate_number' => $trip->fleetRoute?->fleet?->plate_number,
            'driver_verified_at' => $pin->driver_verified_at,
            'both_verified'      => $pin->isBothVerified(),
        ], 'PIN verified successfully. You are on the correct trip.');
    }

    /**
     * Conductor submits PIN to confirm they're on the correct fleet.
     * POST /conductor/pin/verify
     */
    public function verifyAsConductor(VerifyPinRequest $request)
    {
        $companyUser = $request->user()->companyProfile;
        $trip        = $this->resolveTripForConductor($companyUser->company_user_id);

        $pin = $this->pinService->verify(
            $trip->trip_id,
            $request->validated()['pin_code'],
            $companyUser->company_user_id,
            'conductor'
        );

        return $this->success([
            'trip_id'               => $pin->trip_id,
            'trip_status'           => $trip->status,
            'route_name'            => $trip->fleetRoute?->route?->route_name,
            'fleet_plate_number'    => $trip->fleetRoute?->fleet?->plate_number,
            'conductor_verified_at' => $pin->conductor_verified_at,
            'both_verified'         => $pin->isBothVerified(),
        ], 'PIN verified successfully. You are on the correct trip.');
    }

    // -------------------------------------------------------------------------
    // Helpers: resolve trip from today's assigned schedule/current run
    // -------------------------------------------------------------------------

    private function resolveTripForDriver(int $driverId): object
    {
        $trip = $this->tripService->getCurrentOrScheduledTripForDriver($driverId);

        if (!$trip) {
            abort(404, 'No trip assigned for today. Cannot resolve PIN context.');
        }

        return $trip;
    }

    private function resolveTripForConductor(int $conductorId): object
    {
        $trip = $this->tripService->getCurrentOrScheduledTripForConductor($conductorId);

        if (!$trip) {
            abort(404, 'No trip assigned for today. Cannot resolve PIN context.');
        }

        return $trip;
    }
}
