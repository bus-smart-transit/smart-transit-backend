<?php

namespace App\Http\Controllers;

use App\Http\Requests\AssignFleetRouteRequest;
use App\Http\Requests\StoreFleetRequest;
use App\Http\Requests\UpdateFleetRequest;
use App\Services\AuditLogger;
use App\Services\FleetService;
use App\Services\StaffService;
use App\Traits\ApiResponse;
use Illuminate\Http\Request;

class FleetController extends Controller
{
    use ApiResponse;

    public function __construct(
        private FleetService $fleetService,
        private StaffService $staffService,
    ) {}

    /** Batch 46 step 2: an Operator sees only their company's buses; the Admin sees all. */
    public function index(Request $request)
    {
        return $this->success($this->fleetService->listFleets($this->operatorScope($request)), 'Fleets retrieved successfully');
    }

    public function store(StoreFleetRequest $request)
    {
        $staffProfile = $this->staffService->getStaffProfile($request->user());

        if (! $staffProfile) {
            return $this->error('Staff profile not found for this account.');
        }

        $fleet = $this->fleetService->registerFleet($request->validated(), $staffProfile->company_user_id);

        return $this->success($fleet, 'Fleet registered successfully');
    }

    public function update(UpdateFleetRequest $request, int $fleetId)
    {
        // 2026-11-06: an Operator edits only a bus of their own company; its company label stays the company name.
        $scope = $this->operatorScope($request);
        $changes = $request->validated();
        if ($scope !== null) {
            if (! $this->fleetService->isOwnedByOperator($fleetId, $scope)) {
                return $this->error('Fleet not found.', 404);
            }
            unset($changes['bus_company_name']);
        }

        $fleet = $this->fleetService->updateFleet($fleetId, $changes);
        if ($fleet) {
            AuditLogger::log('fleet.update', 'Fleet', $fleetId, ['changed' => array_keys($changes)]);
        }
        if (! $fleet) {
            return $this->error('Fleet not found.', 404);
        }

        return $this->success($fleet, 'Fleet updated successfully');
    }

    public function assignRoute(AssignFleetRouteRequest $request, int $fleetId)
    {
        // Batch 46 step 2: only a bus of the Operator's own company (another company's bus reads as not found).
        $scope = $this->operatorScope($request);
        if ($scope !== null && ! $this->fleetService->isOwnedByOperator($fleetId, $scope)) {
            return $this->error('Fleet not found.', 404);
        }

        $fleetRoute = $this->fleetService->assignRouteToFleet($fleetId, $request->validated());

        // No recalculation step needed — fares for this fleet+route are
        // computed on demand at quote/booking time (see FareRuleService),
        // not precomputed and stored.
        return $this->success($fleetRoute, 'Route assigned successfully');
    }

    public function fleetRoutes(Request $request)
    {
        // Batch 46 step 2 (least privilege): an Operator schedules only on their company's buses (any Operator of the
        // company may); the Admin sees every company's.
        $scope = $this->operatorScope($request);

        return $this->success(
            $scope === null ? $this->fleetService->listAllFleetRoutes() : $this->fleetService->listFleetRoutesByOperator($scope),
            'Fleet routes retrieved successfully'
        );
    }

    /** The signed-in Operator's company_users id (0 when they have no staff profile); null for the Admin. */
    private function operatorScope(Request $request): ?int
    {
        if ($request->user()?->role === 'admin') {
            return null;
        }

        return (int) ($request->user()?->companyProfile?->company_user_id ?? 0);
    }
}
