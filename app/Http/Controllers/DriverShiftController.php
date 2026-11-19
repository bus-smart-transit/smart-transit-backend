<?php

namespace App\Http\Controllers;

use App\Services\DriverShiftService;
use App\Traits\ApiResponse;
use Illuminate\Http\Request;

class DriverShiftController extends Controller
{
    use ApiResponse;

    public function __construct(private DriverShiftService $driverShiftService) {}

    public function status(Request $request)
    {
        $companyUser = $request->user()?->companyProfile;
        if (!$companyUser) return $this->error('Staff profile not found.', 404);

        $status = $this->driverShiftService->getStatusForDriver($companyUser->company_user_id);

        return $this->success([
            'open_shift' => $this->formatShift($status['open_shift']),
            'latest_shift' => $this->formatShift($status['latest_shift']),
            'has_open_shift' => (bool) $status['has_open_shift'],
            'is_block_shift' => (bool) $status['is_block_shift'],
            'end_blocked_reason' => $status['end_blocked_reason'],
        ], 'Driver shift status retrieved');
    }

    public function start(Request $request)
    {
        $companyUser = $request->user()?->companyProfile;
        if (!$companyUser) return $this->error('Staff profile not found.', 404);

        $shift = $this->driverShiftService->startShiftForDriver($companyUser->company_user_id);

        return $this->success([
            'shift' => $this->formatShift($shift),
        ], 'Driver shift started');
    }

    // B5: End Shift belongs to the Chauffeur for a standalone trip: a driver ends the trip
    // (PATCH trips/{id}/complete), which also closes the driver's shift log.
    // Batch 27 Part 2: on a shift block the driver's log stays open across the legs and the driver
    // ends the shift here, once every leg is completed or cancelled.
    public function end(Request $request)
    {
        $companyUser = $request->user()?->companyProfile;
        if (!$companyUser) return $this->error('Staff profile not found.', 404);

        $open = $this->driverShiftService->getStatusForDriver($companyUser->company_user_id)['open_shift'];
        if (! $open?->trip?->shift_block_id) {
            return $this->error('Only the Chauffeur can end a shift. Drivers end the trip instead.', 403);
        }

        $shift = $this->driverShiftService->endShiftForDriver($companyUser->company_user_id);

        return $this->success(['shift' => $this->formatShift($shift)], 'Driver shift ended');
    }

    private function formatShift(?object $shift): ?array
    {
        if (!$shift) return null;

        return [
            'shift_log_id' => $shift->shift_log_id,
            'driver_company_user_id' => $shift->driver_company_user_id,
            'trip_id' => $shift->trip_id,
            'fleet_id' => $shift->fleet_id,
            'shift_date' => $shift->shift_date,
            'started_at' => $shift->started_at,
            'ended_at' => $shift->ended_at,
            'start_source' => $shift->start_source,
            'end_source' => $shift->end_source,
            'trip_status' => $shift->trip?->status,
            'route_name' => $shift->trip?->fleetRoute?->route?->route_name,
            'fleet_plate_number' => $shift->fleet?->plate_number,
        ];
    }
}
