<?php

namespace App\Http\Controllers;

use App\Http\Requests\AddRouteStopRequest;
use App\Http\Requests\StoreRouteRequest;
use App\Http\Requests\UpdateRouteCustomDropoffRequest;
use App\Http\Requests\UpdateRouteStopExpressRequest;
use App\Http\Requests\UpdateRouteStopTypeRequest;
use App\Models\Route;
use App\Services\RouteGeometryGenerator;
use App\Services\RouteService;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class RouteController extends Controller
{
    use ApiResponse;

    public function __construct(private RouteService $routeService) {}

    public function index(Request $request)
    {
        return $this->success($this->routeService->listRoutes($request->user()), 'Routes retrieved successfully');
    }

    public function show(Request $request, int $routeId)
    {
        // 2026-11-06: another company's route reads as not found.
        $route = $this->routeService->getRouteWithStops($routeId, $request->user());

        return $route ? $this->success($route, 'Route retrieved successfully') : $this->error('Route not found.', 404);
    }

    public function store(StoreRouteRequest $request)
    {
        // 2026-11-06: a route belongs to the creating company admin's company (the platform Admin's has none).
        $payload = $request->validated();
        if ($request->user()?->role !== 'admin') {
            $payload['company_id'] = $request->user()?->companyProfile?->company_id;
        }

        return $this->success($this->routeService->createRoute($payload), 'Route created successfully');
    }

    /** 2026-11-06: every change to a route's stops, line or drop-off rule needs the route to be the company's own. */
    private function ownRouteOr404(Request $request, int $routeId): ?JsonResponse
    {
        return $this->routeService->userOwnsRoute($routeId, $request->user()) ? null : $this->error('Route not found.', 404);
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
    public function regenerateGeometry(Request $request, int $routeId)
    {
        if (! $this->routeService->canRegenerateGeometry($routeId, $request->user())) {
            return $this->error('Route not found.', 404);
        }

        $this->routeService->queueManualGeometryRegeneration($routeId);

        return $this->success(['queued' => true, 'redraw' => RouteGeometryGenerator::redrawStatus($routeId)], 'Route line redraw queued');
    }

    // Operator/admin: stop_type is editable per route stop.
    public function updateStopType(UpdateRouteStopTypeRequest $request, int $routeId, int $routeStopId)
    {
        if ($denied = $this->ownRouteOr404($request, $routeId)) {
            return $denied;
        }

        $updated = $this->routeService->updateStopType($routeId, $routeStopId, $request->validated('stop_type'));

        return $updated
            ? $this->success(null, 'Stop type updated successfully')
            : $this->error('Route stop not found.', 404);
    }

    // Operator/admin: which stops an express trip serves (and optional express timing).
    public function updateStopExpress(UpdateRouteStopExpressRequest $request, int $routeId, int $routeStopId)
    {
        if ($denied = $this->ownRouteOr404($request, $routeId)) {
            return $denied;
        }

        $updated = $this->routeService->updateStopExpress($routeId, $routeStopId, $request->validated());

        return $updated
            ? $this->success(null, 'Express stop setting updated successfully')
            : $this->error('Route stop not found.', 404);
    }

    // Operator/admin: D5 per-route switch for custom drop-off (default off).
    public function updateCustomDropoff(UpdateRouteCustomDropoffRequest $request, int $routeId)
    {
        if ($denied = $this->ownRouteOr404($request, $routeId)) {
            return $denied;
        }

        $mode = $request->validated('custom_dropoff_mode');
        $updated = $mode !== null
            ? $this->routeService->setCustomDropoffMode($routeId, (string) $mode)
            : $this->routeService->setAllowCustomDropoff($routeId, (bool) $request->validated('allow_custom_dropoff'));
        $route = $updated ? Route::where('route_id', $routeId)->first(['route_id', 'allow_custom_dropoff', 'custom_dropoff_mode']) : null;

        return $route
            ? $this->success(['allow_custom_dropoff' => (bool) $route->allow_custom_dropoff, 'custom_dropoff_mode' => $route->custom_dropoff_mode], 'Custom drop-off setting updated')
            : $this->error('Route not found.', 404);
    }

    private function direction(Request $request): string
    {
        return $request->query('direction') === 'reverse' ? 'reverse' : 'outbound';
    }

    // route_stop_table management — folded into RouteController, no separate controller
    public function addStop(AddRouteStopRequest $request, int $routeId)
    {
        if ($denied = $this->ownRouteOr404($request, $routeId)) {
            return $denied;
        }
        // 2026-11-06: only the company's own stops go on its routes (so a stop is never shared between companies).
        if (! $this->routeService->userOwnsStop((int) $request->validated('stop_id'), $request->user())) {
            return $this->error('Stop not found.', 404);
        }

        return $this->success(
            $this->routeService->addStopToRoute($routeId, $request->validated()),
            'Stop added to route successfully'
        );
    }

    public function removeStop(Request $request, int $routeId, int $routeStopId)
    {
        if ($denied = $this->ownRouteOr404($request, $routeId)) {
            return $denied;
        }

        $this->routeService->removeStopFromRoute($routeId, $routeStopId);

        return $this->success(null, 'Stop removed from route successfully');
    }
}
