<?php

namespace App\Services;

use App\Repositories\TripRepository;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Single place for who may do what to a trip, and from which state (Batch 24,
 * B5). Replaces the ownership / shift / pairing checks that used to be
 * scattered through TripController.
 *
 * Transition table (state x event x role x guard):
 *
 * | Event                | From states                    | To        | Roles (guard)                                                                                   |
 * |----------------------|--------------------------------|-----------|-------------------------------------------------------------------------------------------------|
 * | boarding             | scheduled, delayed             | boarding  | driver (assigned + open shift for this trip), operator (owns trip), admin                       |
 * | depart               | scheduled, delayed, boarding   | departed  | as boarding, plus crew paired (423) and both PINs verified (TripService)                        |
 * | acknowledge stop     | departed, in-progress          | (same)    | driver (assigned); repeats and older stops are ignored                                          |
 * | complete (End Trip)  | departed, in-progress          | completed | driver (assigned), operator (owns trip), admin                                                  |
 * | End Shift            | n/a                            | n/a       | conductor/Chauffeur only, and only when every leg of the shift block is completed or cancelled |
 *
 * A repeat of an event whose target state is already reached is a no-op that
 * succeeds (double taps, retries). Driver hand-off (Batch 18) is separate:
 * it is neither End Trip nor End Shift and is not touched here.
 */
class TripActionGuard
{
    public const TRANSITIONS = [
        'boarding' => ['from' => ['scheduled', 'delayed'], 'to' => 'boarding'],
        'depart' => ['from' => ['scheduled', 'delayed', 'boarding'], 'to' => 'departed'],
        'complete' => ['from' => ['departed', 'in-progress'], 'to' => 'completed'],
    ];

    public function __construct(
        private TripRepository $tripRepository,
        private TripService $tripService,
        private FleetPairingService $pairingService,
        private DriverShiftService $driverShiftService,
    ) {
    }

    /**
     * Operator ownership check shared by every operator-facing trip action.
     * Non-operators pass (their own rules live in evaluate()).
     */
    public function assertOperatorOwnsTrip(Request $request, int $tripId): ?JsonResponse
    {
        $user = $request->user();
        if (! $user || $user->role !== 'operator') {
            return null;
        }

        $companyUserId = $user->companyProfile?->company_user_id;
        if (! $companyUserId) {
            return $this->deny('Staff profile not found.', 404);
        }

        if (! $this->tripService->isOwnedByOperator($tripId, $companyUserId)) {
            return $this->deny('You do not have permission to modify this trip.', 403);
        }

        return null;
    }

    /**
     * @return array{0: ?JsonResponse, 1: bool} [denial response or null, whether the event's target state is already reached]
     */
    public function evaluate(Request $request, int $tripId, string $event): array
    {
        if ($denied = $this->assertOperatorOwnsTrip($request, $tripId)) {
            return [$denied, false];
        }

        $trip = $this->tripRepository->findById($tripId);
        if (! $trip) {
            return [$this->deny('Trip not found.', 404), false];
        }

        $role = strtolower((string) ($request->user()->role ?? ''));
        $companyUser = $request->user()->companyProfile;

        if ($role === 'driver') {
            if (! $companyUser) {
                return [$this->deny('Staff profile not found.', 404), false];
            }
            if ((int) $trip->driver_id !== (int) $companyUser->company_user_id) {
                return [$this->deny('You are not the driver assigned to this trip.', 403), false];
            }
        }

        $rule = self::TRANSITIONS[$event];
        if ($trip->status === $rule['to']) {
            return [null, true];
        }

        if ($event === 'depart') {
            $pairing = $this->pairingService->getPairingStatus($tripId);
            if (! ($pairing['paired'] ?? false)) {
                return [$this->deny($pairing['reason'] ?? 'Pairing required before departing the trip.', 423), false];
            }
        }

        if ($role === 'driver' && in_array($event, ['boarding', 'depart'], true)) {
            $shift = $this->driverShiftService->getStatusForDriver($companyUser->company_user_id);
            $openShift = $shift['open_shift'] ?? null;
            if (! ($shift['has_open_shift'] ?? false) || ! $openShift) {
                return [$this->deny('Start your shift first.', 422), false];
            }
            if ((int) ($openShift->trip_id ?? 0) !== $tripId) {
                return [$this->deny('Your active shift is assigned to a different trip.', 422), false];
            }
        }

        if (! in_array($trip->status, $rule['from'], true)) {
            return [$this->deny("Cannot {$event} a trip that is {$trip->status}.", 422), false];
        }

        return [null, false];
    }

    private function deny(string $message, int $code): JsonResponse
    {
        return response()->json(['status' => 'error', 'message' => $message, 'errors' => null], $code);
    }
}
