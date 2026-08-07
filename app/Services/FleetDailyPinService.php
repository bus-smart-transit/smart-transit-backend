<?php

namespace App\Services;

use App\Models\FleetDailyPin;
use App\Repositories\FleetDailyPinRepository;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;

class FleetDailyPinService
{
    public function __construct(
        private FleetDailyPinRepository $pinRepository,
    ) {}

    /**
     * Called when both driver and conductor are assigned to a trip.
     * Generates a 6-digit PIN for the trip for today, or returns the
     * existing one if it was already created earlier in the day.
     */
    public function generateOrGet(int $tripId, int $driverId, int $conductorId): FleetDailyPin
    {
        $existing = $this->pinRepository->findTodayByTrip($tripId);

        if ($existing) {
            // Update staff IDs in case of re-assignment; reset verifications if pair changed.
            $pairChanged = $existing->driver_id !== $driverId || $existing->conductor_id !== $conductorId;

            if ($pairChanged) {
                $existing->driver_id            = $driverId;
                $existing->conductor_id         = $conductorId;
                $existing->driver_verified_at   = null;
                $existing->conductor_verified_at = null;
                $existing->save();
            }

            return $existing->fresh();
        }

        return $this->pinRepository->create([
            'trip_id'       => $tripId,
            'driver_id'     => $driverId,
            'conductor_id'  => $conductorId,
            'pin_code'      => $this->generateCode(),
            'pin_date'      => Carbon::today(),
        ]);
    }

    /**
     * Driver or conductor submits the PIN they see in their app.
     * Marks the appropriate verified_at timestamp.
     */
    public function verify(int $tripId, string $submittedPin, int $staffId, string $role): FleetDailyPin
    {
        $pin = $this->pinRepository->findTodayByTrip($tripId);

        if (!$pin) {
            throw ValidationException::withMessages([
                'pin_code' => ['No daily PIN has been generated for this trip yet.'],
            ]);
        }

        if ($pin->pin_code !== $submittedPin) {
            throw ValidationException::withMessages([
                'pin_code' => ['Incorrect PIN. You may be assigned to the wrong trip.'],
            ]);
        }

        if ($role === 'driver') {
            if ($pin->driver_id !== $staffId) {
                throw ValidationException::withMessages([
                    'pin_code' => ['You are not the assigned driver for this trip today.'],
                ]);
            }
            if ($pin->driver_verified_at !== null) {
                return $pin; // already verified, idempotent
            }
            return $this->pinRepository->markDriverVerified($pin);
        }

        if ($role === 'conductor') {
            if ($pin->conductor_id !== $staffId) {
                throw ValidationException::withMessages([
                    'pin_code' => ['You are not the assigned conductor for this trip today.'],
                ]);
            }
            if ($pin->conductor_verified_at !== null) {
                return $pin; // already verified, idempotent
            }
            return $this->pinRepository->markConductorVerified($pin);
        }

        throw ValidationException::withMessages([
            'role' => ['Invalid role for PIN verification.'],
        ]);
    }

    /**
     * Checks if both driver and conductor have verified the PIN for today.
     * Used as a gate before a trip can depart.
     */
    public function isBothVerified(int $tripId): bool
    {
        $pin = $this->pinRepository->findTodayByTrip($tripId);

        return $pin !== null && $pin->isBothVerified();
    }

    /**
    * Returns today's PIN record for a trip, or null.
     */
    public function getTodayPin(int $tripId): ?FleetDailyPin
    {
        return $this->pinRepository->findTodayByTrip($tripId);
    }

    private function generateCode(): string
    {
        return str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
    }
}
