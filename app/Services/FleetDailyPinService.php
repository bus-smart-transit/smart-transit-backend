<?php

namespace App\Services;

use App\Models\FleetDailyPin;
use App\Repositories\FleetDailyPinRepository;
use App\Repositories\StaffRepository;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;

class FleetDailyPinService
{
    public function __construct(
        private FleetDailyPinRepository $pinRepository,
        private StaffRepository $staffRepository,
    ) {}

    /**
     * Called when both driver and conductor are assigned to a trip.
     * Generates a 6-digit PIN for the trip for today, or returns the
     * existing one if it was already created earlier in the day.
     *
     * Hard guard: refuses to generate/return a PIN unless both IDs
     * reference currently valid, active staff accounts with the correct
     * role (driver_id -> role 'driver', conductor_id -> role 'conductor').
     * This is the last line of defense — even if a trip somehow ends up
     * with driver_id/conductor_id that were never validated as a real
     * assignment (e.g. set directly at trip creation), no PIN/QR will be
     * issued for it.
     */
    public function generateOrGet(int $tripId, int $driverId, int $conductorId): FleetDailyPin
    {
        $this->assertValidAssignment($driverId, $conductorId);

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
     * Refuses to proceed unless both IDs reference existing staff users
     * with the correct role. This is what "a valid assignment record"
     * means in this codebase's data model (driver_id/conductor_id on the
     * trip, backed by a role-checked company_users record) — there is no
     * separate assignment table.
     */
    private function assertValidAssignment(int $driverId, int $conductorId): void
    {
        $driver = $this->staffRepository->findById($driverId);
        if (! $driver || $driver->user?->role !== 'driver') {
            throw ValidationException::withMessages([
                'driver_id' => ['No valid driver assignment found for this trip. PIN/QR cannot be generated.'],
            ]);
        }

        $conductor = $this->staffRepository->findById($conductorId);
        if (! $conductor || $conductor->user?->role !== 'conductor') {
            throw ValidationException::withMessages([
                'conductor_id' => ['No valid conductor assignment found for this trip. PIN/QR cannot be generated.'],
            ]);
        }
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
