<?php

namespace App\Repositories;

use App\Models\FleetDailyPin;
use App\Models\Trip;
use Illuminate\Support\Carbon;

class FleetDailyPinRepository
{
    /**
     * Batch 27 Part 2: a leg of a shift block is found through its block, so the pairing and PIN made
     * once for the block serve every leg that day. A standalone trip (or an older per-leg row) is
     * found by trip_id as before.
     */
    public function findTodayByTrip(int $tripId): ?FleetDailyPin
    {
        $blockId = Trip::where('trip_id', $tripId)->value('shift_block_id');
        if ($blockId) {
            $blockPin = FleetDailyPin::where('shift_block_id', $blockId)->whereDate('pin_date', Carbon::today())->first();
            if ($blockPin) {
                return $blockPin;
            }
        }

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
        $blockId = Trip::where('trip_id', $tripId)->value('shift_block_id');
        $query = FleetDailyPin::whereDate('pin_date', Carbon::today());
        $blockId ? $query->where('shift_block_id', $blockId) : $query->where('trip_id', $tripId);

        return $query->delete() > 0;
    }
}
