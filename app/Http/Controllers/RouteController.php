<?php
namespace App\Http\Controllers;
use App\Services\RouteService;
use App\Http\Requests\StoreRouteRequest;
use App\Http\Requests\AddRouteStopRequest;
use App\Http\Requests\UpdateRouteCustomDropoffRequest;
use App\Http\Requests\UpdateRouteStopTypeRequest;
use App\Traits\ApiResponse;
use Illuminate\Http\Request;

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
    public function publicStops(Request $request, int $routeId)
    {
        return $this->success(
            $this->routeService->getPublicStopsForRoute($routeId, $this->direction($request)),
            'Route stops retrieved successfully'
        );
    }

    /**
     * Public endpoint: canonical route line as GeoJSON, per direction.
     * GET /routes/{routeId}/geometry?direction=outbound|reverse
     */
    public function geometry(Request $request, int $routeId)
    {
        return $this->success(
            $this->routeService->getRouteGeometry($routeId, $this->direction($request)),
            'Route geometry retrieved successfully'
        );
    }

    // Operator: queue a background regeneration of the canonical geometry.
    public function regenerateGeometry(int $routeId)
    {
        $this->routeService->queueGeometryRegeneration($routeId);

        return $this->success(null, 'Route geometry regeneration queued');
    }

    // Operator/admin: stop_type is editable per route stop.
    public function updateStopType(UpdateRouteStopTypeRequest $request, int $routeId, int $routeStopId)
    {
        $updated = $this->routeService->updateStopType($routeId, $routeStopId, $request->validated('stop_type'));

        return $updated
            ? $this->success(null, 'Stop type updated successfully')
            : $this->error('Route stop not found.', 404);
    }

    // Operator/admin: D5 per-route switch for custom drop-off (default off).
    public function updateCustomDropoff(UpdateRouteCustomDropoffRequest $request, int $routeId)
    {
        $updated = $this->routeService->setAllowCustomDropoff($routeId, (bool) $request->validated('allow_custom_dropoff'));

        return $updated
            ? $this->success(['allow_custom_dropoff' => (bool) $request->validated('allow_custom_dropoff')], 'Custom drop-off setting updated')
            : $this->error('Route not found.', 404);
    }

    private function direction(Request $request): string
    {
        return $request->query('direction') === 'reverse' ? 'reverse' : 'outbound';
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
        $this->routeService->removeStopFromRoute($routeId, $routeStopId);
        return $this->success(null, 'Stop removed from route successfully');
    }
}
