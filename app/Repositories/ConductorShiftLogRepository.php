<?php

namespace App\Repositories;

use App\Models\ConductorShiftLog;

class ConductorShiftLogRepository
{
    public function findOpenByConductor(int $conductorCompanyUserId): ?ConductorShiftLog
    {
        return ConductorShiftLog::with(['trip.fleetRoute.route', 'fleet'])
            ->where('conductor_company_user_id', $conductorCompanyUserId)
            ->whereNull('ended_at')
            ->orderByDesc('started_at')
            ->first();
    }

    public function latestByConductor(int $conductorCompanyUserId): ?ConductorShiftLog
    {
        return ConductorShiftLog::with(['trip.fleetRoute.route', 'fleet'])
            ->where('conductor_company_user_id', $conductorCompanyUserId)
            ->orderByDesc('started_at')
            ->first();
    }

    public function create(array $payload): ConductorShiftLog
    {
        return ConductorShiftLog::create($payload);
    }

    public function markEnded(ConductorShiftLog $log, string $endSource = 'dashboard'): ConductorShiftLog
    {
        ConductorShiftLog::where('shift_log_id', $log->shift_log_id)->update([
            'ended_at' => now(),
            'end_source' => $endSource,
        ]);

        return $log->fresh(['trip.fleetRoute.route', 'fleet']);
    }

    /**
     * Batch 17, Issue #4 prevention: see DriverShiftLogRepository::
     * endOpenForTrip() for the full explanation.
     */
    public function endOpenForTrip(int $tripId, string $endSource = 'trip_status_change'): bool
    {
        return ConductorShiftLog::where('trip_id', $tripId)
            ->whereNull('ended_at')
            ->update([
                'ended_at' => now(),
                'end_source' => $endSource,
            ]) > 0;
    }
}
