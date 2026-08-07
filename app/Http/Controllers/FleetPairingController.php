<?php

namespace App\Http\Controllers;

use App\Services\FleetDailyPinService;
use App\Services\FleetPairingService;
use App\Services\TripService;
use App\Traits\ApiResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class FleetPairingController extends Controller
{
    use ApiResponse;

    public function __construct(
        private FleetPairingService  $pairingService,
        private FleetDailyPinService $pinService,
        private TripService          $tripService,
    ) {}

    // ── Driver endpoints ─────────────────────────────────────────────────────

    /** GET /api/driver/pairing-token */
    public function getDriverToken(Request $request)
    {
        return $this->getToken($request, 'driver');
    }

    /** POST /api/driver/pair  body: { token: "..." } */
    public function pairAsDriver(Request $request)
    {
        return $this->pair($request, 'driver');
    }

    /** GET /api/driver/pairing-status */
    public function driverPairingStatus(Request $request)
    {
        return $this->status($request, 'driver');
    }

    // ── Conductor endpoints ──────────────────────────────────────────────────

    /** GET /api/conductor/pairing-token */
    public function getConductorToken(Request $request)
    {
        return $this->getToken($request, 'conductor');
    }

    /** POST /api/conductor/pair  body: { token: "..." } */
    public function pairAsConductor(Request $request)
    {
        return $this->pair($request, 'conductor');
    }

    /** GET /api/conductor/pairing-status */
    public function conductorPairingStatus(Request $request)
    {
        return $this->status($request, 'conductor');
    }

    // ── Shared logic ─────────────────────────────────────────────────────────

    private function getToken(Request $request, string $role): \Illuminate\Http\JsonResponse
    {
        $companyUser = $request->user()->companyProfile;
        if (!$companyUser) {
            return $this->error('Staff profile not found.', 404);
        }

        $trip = $role === 'driver'
            ? $this->tripService->getCurrentOrScheduledTripForDriver($companyUser->company_user_id)
            : $this->tripService->getCurrentOrScheduledTripForConductor($companyUser->company_user_id);

        if (!$trip) {
            return $this->error('No trip assigned for today. Contact your operator.', 404);
        }

        $fleetId = (int) ($trip->fleetRoute?->fleet_id ?? 0);
        if (!$fleetId) {
            return $this->error('Fleet not assigned to this trip. Contact your operator.', 422);
        }

        $token = $this->pairingService->generateToken(
            $companyUser->company_user_id,
            $role,
            $trip->trip_id,
            $fleetId,
        );

        $qrUrl = 'https://api.qrserver.com/v1/create-qr-code/?size=300x300&data=' . urlencode($token);

        return $this->success([
            'token'       => $token,
            'token_pin'   => $this->pairingService->toReadablePin($token),
            'pin_code'    => $this->pairingService->toReadablePin($token),
            'qr_url'      => $qrUrl,
            'trip_id'     => $trip->trip_id,
            'fleet_id'    => $fleetId,
            'fleet_plate' => $trip->fleetRoute?->fleet?->plate_number,
            'route_name'  => $trip->fleetRoute?->route?->route_name,
            'expires_at'  => now()->endOfDay()->toIso8601String(),
        ], 'Pairing token generated');
    }

    private function pair(Request $request, string $role): \Illuminate\Http\JsonResponse
    {
        $request->validate([
            'token'    => 'nullable|string|required_without:pin_code',
            'pin_code' => 'nullable|string|required_without:token',
        ]);

        $companyUser = $request->user()->companyProfile;
        if (!$companyUser) {
            return $this->error('Staff profile not found.', 404);
        }

        $trip = $role === 'driver'
            ? $this->tripService->getCurrentOrScheduledTripForDriver($companyUser->company_user_id)
            : $this->tripService->getCurrentOrScheduledTripForConductor($companyUser->company_user_id);

        if (!$trip) {
            return $this->error('No trip assigned for today.', 404);
        }

        $fleetId = (int) ($trip->fleetRoute?->fleet_id ?? 0);
        if (!$fleetId) {
            return $this->error('Fleet not assigned to this trip.', 422);
        }

        try {
            // Ensure the daily-pin record exists before PIN-based pairing.
            // FleetDailyPinController lazy-creates it when staff view their PIN
            // page — but if they skip that screen and try to pair directly,
            // resolvePartnerTokenFromPin would fail with "No pairing record found".
            if ($trip->driver_id && $trip->conductor_id) {
                $this->pinService->generateOrGet(
                    $trip->trip_id,
                    (int) $trip->driver_id,
                    (int) $trip->conductor_id,
                );
            }

            $partnerToken = $request->filled('token')
                ? (string) $request->input('token')
                : $this->pairingService->resolvePartnerTokenFromPin(
                    (string) $request->input('pin_code'),
                    $role,
                    $trip->trip_id,
                    $fleetId,
                );

            $pin = $this->pairingService->validateAndPair(
                $partnerToken,
                $companyUser->company_user_id,
                $role,
                $trip->trip_id,
                $fleetId,
            );

            return $this->success([
                'paired'      => true,
                'paired_at'   => $pin->paired_at?->toIso8601String(),
                'trip_id'     => $trip->trip_id,
                'fleet_id'    => $fleetId,
                'fleet_plate' => $trip->fleetRoute?->fleet?->plate_number,
                'route_name'  => $trip->fleetRoute?->route?->route_name,
            ], 'Pairing confirmed. You may now access your dashboard.');

        } catch (ValidationException $e) {
            $first = collect($e->errors())->flatten()->first() ?? 'Pairing failed.';
            return $this->error($first, 422, $e->errors());
        }
    }

    private function status(Request $request, string $role): \Illuminate\Http\JsonResponse
    {
        $companyUser = $request->user()->companyProfile;
        if (!$companyUser) {
            return $this->error('Staff profile not found.', 404);
        }

        $trip = $role === 'driver'
            ? $this->tripService->getCurrentOrScheduledTripForDriver($companyUser->company_user_id)
            : $this->tripService->getCurrentOrScheduledTripForConductor($companyUser->company_user_id);

        if (!$trip) {
            return $this->success([
                'paired'     => false,
                'paired_at'  => null,
                'expires_at' => now()->endOfDay()->toIso8601String(),
                'reason'     => 'No active trip assigned for today.',
            ], 'Pairing status retrieved');
        }

        $status = $this->pairingService->getPairingStatus($trip->trip_id);
        return $this->success($status, 'Pairing status retrieved');
    }
}
