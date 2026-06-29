<?php

namespace App\Http\Controllers;

use App\Services\FleetRouteService;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;

class FleetRouteController extends Controller
{
    private FleetRouteService $fleetRouteService;

    public function __construct(FleetRouteService $fleetRouteService)
    {
        $this->fleetRouteService = $fleetRouteService;
    }

    public function index(Request $request)
    {
        return $this->fleetRouteService->listFleetRoute($request->input('per_page', 15));
    }

    public function store(Request $request)
    {
        return $this->fleetRouteService->createFleetRoute($request->all());
    }

    public function show(string $uuid)
    {
        return $this->fleetRouteService->getFleetRoute($uuid);
    }

    public function update(Request $request, string $uuid)
    {
        return $this->fleetRouteService->updateFleetRoute($uuid, $request->all());
    }

    public function destroy(string $uuid)
    {
        $this->fleetRouteService->deleteFleetRoute($uuid);
        return response()->json(['message' => 'Deleted successfully'], 200);
    }
    
    public function restore(string $uuid)
    {
        return $this->fleetRouteService->restoreFleetRoute($uuid);
    }
}