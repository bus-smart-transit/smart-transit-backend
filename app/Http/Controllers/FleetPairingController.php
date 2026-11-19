<?php

namespace App\Http\Controllers;

use App\Models\Trip;
use App\Services\FleetDailyPinService;
use App\Services\FleetPairingService;
use App\Services\LegProgressionService;
use App\Services\TripService;
use App\Support\AssignmentAcceptance;
use App\Support\TripTiming;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class FleetPairingController extends Controller
{
    use ApiResponse;

    public function __construct(
        private FleetPairingService $pairingService,
        private FleetDailyPinService $pinService,
        private TripService $tripService,
        private LegProgressionService $legs,
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

    private function getToken(Request $request, string $role): JsonResponse
    {
        $companyUser = $request->user()->companyProfile;
        if (! $companyUser) {
            return $this->error('Staff profile not found.', 404);
        }

        $trip = $role === 'driver'
            ? $this->tripService->getTodayAssignedTripForDriver($companyUser->company_user_id)
            : $this->tripService->getTodayAssignedTripForConductor($companyUser->company_user_id);

        if (! $trip) {
            return $this->error('No assigned trip was found. Contact your operator.', 404);
        }

        // Batch 29: no pairing key until the assignment is accepted.
        if ($reason = AssignmentAcceptance::blockedReason($trip, $role)) {
            return $this->error($reason, 422, ['assignment' => [$reason]]);
        }

        if ($reason = $this->pairingTooEarly($trip)) {
            return $this->error($reason, 422, ['pairing' => [$reason]]);
        }

        $fleetId = (int) ($trip->fleetRoute?->fleet_id ?? 0);
        if (! $fleetId) {
            return $this->error('Fleet not assigned to this trip. Contact your operator.', 422);
        }

        $token = $this->pairingService->generateToken(
            $companyUser->company_user_id,
            $role,
            $trip->trip_id,
            $fleetId,
        );

        // The client draws the QR itself from `token`; no image URL (and so no third party) is involved.
        return $this->success([
            'token' => $token,
            'token_pin' => $this->pairingService->toReadablePin($token),
            'pin_code' => $this->pairingService->toReadablePin($token),
            'trip_id' => $trip->trip_id,
            'fleet_id' => $fleetId,
            'fleet_plate' => $trip->fleetRoute?->fleet?->plate_number,
            'route_name' => $trip->fleetRoute?->route?->route_name,
            'expires_at' => now()->endOfDay()->toIso8601String(),
        ], 'Pairing token generated');
    }

    private function pair(Request $request, string $role): JsonResponse
    {
        $request->validate([
            'token' => 'nullable|string|required_without:pin_code',
            'pin_code' => 'nullable|string|required_without:token',
        ]);

        $companyUser = $request->user()->companyProfile;
        if (! $companyUser) {
            return $this->error('Staff profile not found.', 404);
        }

        $trip = $role === 'driver'
            ? $this->tripService->getTodayAssignedTripForDriver($companyUser->company_user_id)
            : $this->tripService->getTodayAssignedTripForConductor($companyUser->company_user_id);

        if (! $trip) {
            return $this->error('No assigned trip was found.', 404);
        }

        if ($reason = AssignmentAcceptance::blockedReason($trip, $role)) {
            return $this->error($reason, 422, ['assignment' => [$reason]]);
        }

        if ($reason = $this->pairingTooEarly($trip)) {
            return $this->error($reason, 422, ['pairing' => [$reason]]);
        }

        $fleetId = (int) ($trip->fleetRoute?->fleet_id ?? 0);
        if (! $fleetId) {
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
                'paired' => true,
                'paired_at' => $pin->paired_at?->toIso8601String(),
                'trip_id' => $trip->trip_id,
                'fleet_id' => $fleetId,
                'fleet_plate' => $trip->fleetRoute?->fleet?->plate_number,
                'route_name' => $trip->fleetRoute?->route?->route_name,
            ], 'Pairing confirmed. You may now access your dashboard.');

        } catch (ValidationException $e) {
            $first = collect($e->errors())->flatten()->first() ?? 'Pairing failed.';

            return $this->error($first, 422, $e->errors());
        }
    }

    private function status(Request $request, string $role): JsonResponse
    {
        $companyUser = $request->user()->companyProfile;
        if (! $companyUser) {
            return $this->error('Staff profile not found.', 404);
        }

        $trip = $role === 'driver'
            ? $this->tripService->getCurrentOrScheduledTripForDriver($companyUser->company_user_id)
            : $this->tripService->getCurrentOrScheduledTripForConductor($companyUser->company_user_id);

        if (! $trip) {
            return $this->success([
                'paired' => false,
                'paired_at' => null,
                'expires_at' => now()->endOfDay()->toIso8601String(),
                'reason' => 'No active trip assigned for today.',
            ], 'Pairing status retrieved');
        }

        $status = $this->pairingService->getPairingStatus($trip->trip_id);
        // Phase A: when pairing opens, so the screens can show it next to a disabled pairing step.
        $opensAt = ($anchor = $this->pairingAnchor($trip)) ? TripTiming::pairingOpensAt($anchor) : null;
        $status['pairing_opens_at'] = $opensAt?->toIso8601String();
        $status['pairing_open'] = $anchor === null || TripTiming::pairingBlockedReason($anchor) === null;

        return $this->success($status, 'Pairing status retrieved');
    }

    /** Phase A (§4.11): pairing opens a set time before the departure; a shift block pairs once, on its first leg. */
    private function pairingTooEarly(object $trip): ?string
    {
        $anchor = $this->pairingAnchor($trip);

        return $anchor ? TripTiming::pairingBlockedReason($anchor) : null;
    }

    private function pairingAnchor(object $trip): ?Trip
    {
        $anchorId = $this->legs->pairingScopeTripId((int) $trip->trip_id);

        return $anchorId === (int) $trip->trip_id && $trip instanceof Trip ? $trip : Trip::find($anchorId);
    }
}
