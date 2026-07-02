<?php

namespace App\Http\Controllers;

use App\Services\FleetService;
use App\Services\FareCalculationService;
use Illuminate\Http\Request;
use App\Traits\ApiResponse;

class FleetController extends Controller
{
    use ApiResponse;

    private FleetService $fleetService;
    private FareCalculationService $fareCalculationService;
    public function __construct(
        FleetService $fleetService,
        FareCalculationService $fareCalculationService,
    ) {
        $this->fleetService = $fleetService;
        $this->fareCalculationService = $fareCalculationService;
    }

    public function index()
    {
        return $this->success($this->fleetService->listFleets(), 'Fleets retrieved successfully');
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'company_user_id' => 'required|integer|exists:company_users,company_user_id',
            'plate_number' => 'required|string',
            'capacity' => 'required|integer',
            'seated_capacity' => 'required|integer',
            'standing_capacity' => 'required|integer',
        ]);

        return $this->success($this->fleetService->registerFleet($validated), 'Fleet registered successfully');
    }

    public function assignRoute(Request $request, int $fleetId)
    {
        $validated = $request->validate([
            'route_id' => 'required|integer|exists:routes,route_id',
            'start_time' => 'required|date_format:H:i',
            'end_time' => 'required|date_format:H:i',
        ]);

        $fleetRoute = $this->fleetService->assignRouteToFleet(
            $fleetId,
            $validated['route_id'],
            $validated['start_time'],
            $validated['end_time'],
        );

        // Precompute fares for this new fleet-route immediately.
        $this->fareCalculationService->recalculateForFleetRoute($fleetRoute->fleet_route_id);

        return $this->success($fleetRoute, 'Route assigned to fleet successfully');
    }
}
