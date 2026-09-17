<?php

namespace App\Repositories;

use App\Models\FleetDailyPin;
use Illuminate\Support\Carbon;

class FleetDailyPinRepository
{
    public function findTodayByTrip(int $tripId): ?FleetDailyPin
    {
        return FleetDailyPin::where('trip_id', $tripId)
            ->whereDate('pin_date', Carbon::today())
            ->first();
    }

    public function create(array $payload): FleetDailyPin
    {
        return FleetDailyPin::create($payload);
    }

    public function markDriverVerified(FleetDailyPin $pin): FleetDailyPin
    {
        $pin->driver_verified_at = now();
        $pin->save();
        return $pin->fresh();
    }

    public function markConductorVerified(FleetDailyPin $pin): FleetDailyPin
    {
        $pin->conductor_verified_at = now();
        $pin->save();
        return $pin->fresh();
    }

    public function updateStaff(FleetDailyPin $pin, ?int $driverId, ?int $conductorId): FleetDailyPin
    {
        $pin->driver_id    = $driverId ?? $pin->driver_id;
        $pin->conductor_id = $conductorId ?? $pin->conductor_id;
        $pin->save();
        return $pin->fresh();
    }

    /**
     * S2 (Batch 12): a decline invalidates today's PIN — it was generated
     * for a driver+conductor pair that no longer both hold their
     * assignment, so it must not remain usable by the staff member who
     * stayed on the trip.
     */
    public function deleteTodayByTrip(int $tripId): bool
    {
        return FleetDailyPin::where('trip_id', $tripId)
            ->whereDate('pin_date', Carbon::today())
            ->delete() > 0;
    }
}
