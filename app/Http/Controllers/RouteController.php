<?php
namespace App\Http\Controllers;
use App\Models\RouteStop;
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

    /**
     * Public endpoint: ordered stops with coordinates for a route.
     * Used by passenger map (route polyline) and driver navigation map.
     * GET /routes/{routeId}/stops
     */
    public function publicStops(int $routeId)
    {
        $stops = RouteStop::with('stop')
            ->where('route_id', $routeId)
            ->orderBy('stop_order')
            ->get()
            ->map(fn ($rs) => [
                'stop_id'                 => $rs->stop_id,
                'stop_name'               => $rs->stop?->stop_name,
                'latitude'                => $rs->stop?->latitude !== null ? (float) $rs->stop->latitude : null,
                'longitude'               => $rs->stop?->longitude !== null ? (float) $rs->stop->longitude : null,
                'stop_order'              => $rs->stop_order,
                'distance_from_origin_km' => (float) $rs->distance_from_origin_km,
            ]);

        return $this->success($stops, 'Route stops retrieved successfully');
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
