<?php

namespace App\Repositories;

use App\Models\DriverShiftLog;

class DriverShiftLogRepository
{
    public function findOpenByDriver(int $driverCompanyUserId): ?DriverShiftLog
    {
        return DriverShiftLog::with(['trip.fleetRoute.route', 'fleet'])
            ->where('driver_company_user_id', $driverCompanyUserId)
            ->whereNull('ended_at')
            ->orderByDesc('started_at')
            ->first();
    }

    public function latestByDriver(int $driverCompanyUserId): ?DriverShiftLog
    {
        return DriverShiftLog::with(['trip.fleetRoute.route', 'fleet'])
            ->where('driver_company_user_id', $driverCompanyUserId)
            ->orderByDesc('started_at')
            ->first();
    }

    public function create(array $payload): DriverShiftLog
    {
        return DriverShiftLog::create($payload);
    }

    public function markEnded(DriverShiftLog $log, string $endSource = 'dashboard'): DriverShiftLog
    {
        DriverShiftLog::where('shift_log_id', $log->shift_log_id)->update([
            'ended_at' => now(),
            'end_source' => $endSource,
        ]);

        return $log->fresh(['trip.fleetRoute.route', 'fleet']);
    }

    /**
     * Batch 17, Issue #4 prevention: auto-close any still-open shift log
     * tied to a trip that has just been marked completed/cancelled, so it
     * can never again silently block a future shift start for this driver
     * (see the self-heal in DriverShiftService::startShiftForDriver() for
     * the reactive half of this fix). No-op if no open shift exists for
     * this trip.
     */
    public function endOpenForTrip(int $tripId, string $endSource = 'trip_status_change'): bool
    {
        return DriverShiftLog::where('trip_id', $tripId)
            ->whereNull('ended_at')
            ->update([
                'ended_at' => now(),
                'end_source' => $endSource,
            ]) > 0;
    }
}
