<?php
namespace App\Http\Controllers;
use App\Services\FleetService;
use App\Services\FareCalculationService;
use App\Services\StaffService;
use App\Http\Requests\StoreFleetRequest;
use App\Http\Requests\AssignFleetRouteRequest;
use App\Traits\ApiResponse;

class FleetController extends Controller
{
    use ApiResponse;
    public function __construct(
        private FleetService $fleetService,
        private FareCalculationService $fareCalculationService,
        private StaffService $staffService,
    ) {
    }

    public function index()
    {
        return $this->success($this->fleetService->listFleets(), 'Fleets retrieved successfully');
    }

    public function store(StoreFleetRequest $request)
    {
        $staffProfile = $this->staffService->getStaffProfile($request->user());

        if (!$staffProfile) {
            return $this->error('Staff profile not found for this account.');
        }

        $fleet = $this->fleetService->registerFleet($request->validated(), $staffProfile->company_user_id);
        return $this->success($fleet, 'Fleet registered successfully');
    }

    public function assignRoute(AssignFleetRouteRequest $request, int $fleetId)
    {
        $fleetRoute = $this->fleetService->assignRouteToFleet($fleetId, $request->validated());
        // Precompute all stop-pair fares immediately after assignment
        $this->fareCalculationService->recalculateForFleetRoute($fleetRoute->fleet_route_id);
        return $this->success($fleetRoute, 'Route assigned and fares calculated successfully');
    }
}
