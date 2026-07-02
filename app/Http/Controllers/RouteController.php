<?php

namespace App\Http\Controllers;

use App\Services\RouteService;
use Illuminate\Http\Request;
use App\Traits\ApiResponse;

class RouteController extends Controller
{
    use ApiResponse;
    private RouteService $routeService;

    public function __construct(RouteService $routeService)
    {
        $this->routeService = $routeService;
    }

    public function index()
    {
        return $this->success($this->routeService->listRoutes(), 'Routes retrieved successfully');
    }

    public function show(int $routeId)
    {
        return $this->success($this->routeService->getRouteWithStops($routeId), 'Route retrieved successfully');
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'origin' => 'required|string',
            'destination' => 'required|string',
            'route_name' => 'required|string',
        ]);

        return $this->success($this->routeService->createRoute($validated), 'Route created successfully');
    }

    // route_stop_table endpoints — folded into RouteController, no standalone controller.
    public function addStop(Request $request, int $routeId)
    {
        $validated = $request->validate([
            'stop_id' => 'required|integer|exists:stops,stop_id',
            'stop_order' => 'required|integer',
            'distance_from_origin_km' => 'required|numeric',
        ]);

        $routeStop = $this->routeService->addStopToRoute(
            $routeId,
            $validated['stop_id'],
            $validated['stop_order'],
            $validated['distance_from_origin_km'],
        );

        return $this->success($routeStop, 'Stop added to route successfully');
    }

    public function removeStop(int $routeId, int $routeStopId)
    {
        $this->routeService->removeStopFromRoute($routeStopId);
        return $this->success(null, 'Stop removed from route successfully');
    }
}
