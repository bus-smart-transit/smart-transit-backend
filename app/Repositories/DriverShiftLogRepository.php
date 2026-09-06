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
}
