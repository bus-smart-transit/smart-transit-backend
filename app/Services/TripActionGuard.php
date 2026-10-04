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
 * Transition table as built (Batch 27 Part 2, Task 1). "Shift" is one per block per day: pairing and the daily PIN
 * are made once for the block and hold for every leg; a standalone trip is its own one-leg shift.
 *
 * | Event                | From states                    | To        | Roles (guard)                                                                                                       |
 * |----------------------|--------------------------------|-----------|---------------------------------------------------------------------------------------------------------------------|
 * | boarding             | scheduled, delayed             | boarding  | driver (assigned, open shift covering this trip's block), operator (owns trip), admin. Leg N+1 only when leg N is   |
 * |                      |                                |           | completed/cancelled ("Finish leg N of this shift block first", 422); from the 2nd leg the bus must be at the        |
 * |                      |                                |           | leg's origin (Location Match Rule, geofence, config shift_blocks.leg_location_rule_enabled)                          |
 * | depart               | scheduled, delayed, boarding   | departed  | as boarding, plus crew paired (423) and both PINs verified once for the block (TripService)                          |
 * | acknowledge stop     | departed, in-progress          | (same)    | driver (assigned); repeats and older stops are ignored                                                              |
 * | complete (End Trip)  | departed, in-progress          | completed | driver (assigned), operator (owns trip), admin. Closes the leg only: the shift logs stay open for a block's next leg |
 * | End Shift            | n/a                            | n/a       | driver and conductor/Chauffeur; only when every leg of the shift block is completed or cancelled (a standalone trip  |
 * |                      |                                |           | closes the driver's log at End Trip, as before)                                                                     |
 *
 * A repeat of an event whose target state is already reached is a no-op that
 * succeeds (double taps, retries). Driver hand-off (Batch 18) is separate:
 * it is neither End Trip nor End Shift and is not touched here; the incoming
 * driver pairs once for the block they take over.
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
        private LegProgressionService $legs,
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

        // Batch 27 Part 2: a leg of a shift block starts only after the legs before it are completed or
        // cancelled (no out-of-order starts), and from the second leg on the bus must be at the leg's origin
        // terminal. The location rule applies to the driver; an Operator or Admin acts as the dispatcher override.
        if (in_array($event, ['boarding', 'depart'], true) && $trip->shift_block_id) {
            $blocked = $this->legs->startBlockedReason($trip, $role === 'driver');
            if ($blocked) {
                return [$this->deny($blocked[0], $blocked[1]), false];
            }
        }

        if ($role === 'driver' && in_array($event, ['boarding', 'depart'], true)) {
            $shift = $this->driverShiftService->getStatusForDriver($companyUser->company_user_id);
            $openShift = $shift['open_shift'] ?? null;
            if (! ($shift['has_open_shift'] ?? false) || ! $openShift) {
                return [$this->deny('Start your shift first.', 422), false];
            }
            // The shift covers this trip when it is the same trip, or another leg of the same shift block.
            if (! $this->legs->sameShift($openShift->trip, $trip)) {
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
