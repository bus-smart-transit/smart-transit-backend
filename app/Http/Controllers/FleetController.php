<?php
namespace App\Http\Controllers;
use App\Services\FleetService;
use App\Services\StaffService;
use App\Http\Requests\StoreFleetRequest;
use App\Http\Requests\AssignFleetRouteRequest;
use App\Traits\ApiResponse;
use Illuminate\Http\Request;

class FleetController extends Controller
{
    use ApiResponse;
    public function __construct(
        private FleetService $fleetService,
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
        // No recalculation step needed — fares for this fleet+route are
        // computed on demand at quote/booking time (see FareRuleService),
        // not precomputed and stored.
        return $this->success($fleetRoute, 'Route assigned successfully');
    }

    public function fleetRoutes(Request $request)
    {
        // Return ALL active fleet routes so operators can schedule trips on any
        // fleet, not just fleets they personally created.
        return $this->success(
            $this->fleetService->listAllFleetRoutes(),
            'Fleet routes retrieved successfully'
        );
    }
}