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

    /**
     * Ends the shift. Batch 29: every ended shift gets a pending remittance batch, with the closing cash the Conductor
     * declared when there is one (null for an automatic close: the batch then records that nobody gave a count).
     */
    public function markEnded(ConductorShiftLog $log, string $endSource = 'dashboard', ?float $closingCash = null): ConductorShiftLog
    {
        ConductorShiftLog::where('shift_log_id', $log->shift_log_id)->update([
            'ended_at' => now(),
            'end_source' => $endSource,
        ]);

        $fresh = $log->fresh(['trip.fleetRoute.route', 'fleet']);
        app(\App\Services\CashRemittanceService::class)->openForShift($fresh, $closingCash);

        return $fresh;
    }

    /**
     * Batch 17, Issue #4 prevention: see DriverShiftLogRepository::
     * endOpenForTrip() for the full explanation.
     */
    public function endOpenForTrip(int $tripId, string $endSource = 'trip_status_change'): bool
    {
        $open = ConductorShiftLog::where('trip_id', $tripId)->whereNull('ended_at')->get();
        if ($open->isEmpty()) {
            return false;
        }

        ConductorShiftLog::whereIn('shift_log_id', $open->pluck('shift_log_id'))->update([
            'ended_at' => now(),
            'end_source' => $endSource,
        ]);
        // Batch 29: closed by the trip, not by the Conductor: a pending batch with no declared count.
        foreach (ConductorShiftLog::whereIn('shift_log_id', $open->pluck('shift_log_id'))->get() as $closed) {
            app(\App\Services\CashRemittanceService::class)->openForShift($closed, null);
        }

        return true;
    }
}
