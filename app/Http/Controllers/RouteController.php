<?php
namespace App\Http\Controllers;
use App\Services\RouteService;
use App\Http\Requests\StoreRouteRequest;
use App\Http\Requests\AddRouteStopRequest;
use App\Traits\ApiResponse;

class RouteController extends Controller
{
    use ApiResponse;
    public function __construct(private RouteService $routeService)
    {
    }

    public function index()
    {
        return $this->success($this->routeService->listRoutes(), 'Routes retrieved successfully');
    }

    public function show(int $routeId)
    {
        return $this->success($this->routeService->getRouteWithStops($routeId), 'Route retrieved successfully');
    }

    public function store(StoreRouteRequest $request)
    {
        return $this->success($this->routeService->createRoute($request->validated()), 'Route created successfully');
    }

    // route_stop_table management — folded into RouteController, no separate controller
    public function addStop(AddRouteStopRequest $request, int $routeId)
    {
        return $this->success(
            $this->routeService->addStopToRoute($routeId, $request->validated()),
            'Stop added to route successfully'
        );
    }

    public function removeStop(int $routeId, int $routeStopId)
    {
        $this->routeService->removeStopFromRoute($routeStopId);
        return $this->success(null, 'Stop removed from route successfully');
    }
}
