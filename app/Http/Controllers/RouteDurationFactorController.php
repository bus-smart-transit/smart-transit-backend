<?php

namespace App\Http\Controllers;

use App\Http\Requests\OptionalRouteFilterRequest;
use App\Http\Requests\RouteDurationFactorPlanLegsRequest;
use App\Http\Requests\RouteDurationFactorRequest;
use App\Models\RouteDurationFactor;
use App\Repositories\FleetRouteRepository;
use App\Services\AuditLogger;
use App\Services\FleetService;
use App\Services\LegPlanningService;
use App\Services\OperatorRouteAccess;
use App\Services\RouteDurationFactorService;
use App\Services\RouteService;
use App\Traits\ApiResponse;
use Illuminate\Http\Request;

/**
 * Batch 28 Stage 3, item 7: peak-hour duration windows (Operator for the routes their buses run, Admin for all),
 * the leg-time suggestion for a new shift block, and the read-only duration report. Factors are labelled
 * "configured": a person entered them; nothing here measures or applies anything on its own.
 */
class RouteDurationFactorController extends Controller
{
    use ApiResponse;

    public function __construct(
        private RouteDurationFactorService $factors,
        private LegPlanningService $legPlanning,
        private OperatorRouteAccess $access,
        private FleetRouteRepository $fleetRoutes,
    ) {}

    public function index(OptionalRouteFilterRequest $request)
    {
        $data = $request->validated();
        $scope = $this->access->scope($request->user());
        if ($scope === false) {
            return $this->error('Staff profile not found.', 404);
        }

        $windows = $this->factors->listWindows(
            isset($data['route_id']) ? (int) $data['route_id'] : null,
            $scope === null ? null : $this->access->routeIds($scope),
        )
            ->map(fn (RouteDurationFactor $w) => $this->present($w))
            ->values();

        return $this->success($windows, 'Duration factors retrieved successfully');
    }

    public function store(RouteDurationFactorRequest $request)
    {
        $data = $request->validated();
        if ($denied = $this->assertRoute($request, (int) $data['route_id'])) {
            return $denied;
        }

        $clean = $this->factors->validateWindow($data);
        $window = $this->factors->createWindow($clean, (int) $request->user()->user_id);
        AuditLogger::log('duration_factor.create', 'RouteDurationFactor', (int) $window->id, $clean);

        return $this->success($this->present($window->load('route:route_id,route_name')), 'Duration factor saved', 201);
    }

    public function update(RouteDurationFactorRequest $request, int $id)
    {
        $window = $this->factors->findWindow($id);
        if (! $window) {
            return $this->error('Duration factor not found.', 404);
        }
        if ($denied = $this->assertRoute($request, (int) $window->route_id)) {
            return $denied;
        }

        $data = $request->validated() + [];
        $data['route_id'] = $window->route_id; // a window never moves to another route
        $clean = $this->factors->validateWindow($data, (int) $window->id);
        $window = $this->factors->updateWindow($window, $clean);
        AuditLogger::log('duration_factor.update', 'RouteDurationFactor', (int) $window->id, $clean);

        return $this->success($this->present($window->load('route:route_id,route_name')), 'Duration factor updated');
    }

    public function destroy(Request $request, int $id)
    {
        $window = $this->factors->findWindow($id);
        if (! $window) {
            return $this->error('Duration factor not found.', 404);
        }
        if ($denied = $this->assertRoute($request, (int) $window->route_id)) {
            return $denied;
        }

        $this->factors->deleteWindow($window);
        AuditLogger::log('duration_factor.delete', 'RouteDurationFactor', $id, ['route_id' => (int) $window->route_id]);

        return $this->success(null, 'Duration factor removed');
    }

    /** Read-only: median actual leg duration per route, direction and hour, and whether there is enough data to propose a factor. */
    public function report(OptionalRouteFilterRequest $request)
    {
        $data = $request->validated();
        $scope = $this->access->scope($request->user());
        if ($scope === false) {
            return $this->error('Staff profile not found.', 404);
        }

        return $this->success($this->factors->durationReport(isset($data['route_id']) ? (int) $data['route_id'] : null, $scope), 'Duration report retrieved successfully');
    }

    /** Suggested start times for the legs of a shift block (a suggestion the operator can override). */
    public function planLegs(RouteDurationFactorPlanLegsRequest $request)
    {
        $data = $request->validated();
        $user = $request->user();

        // 2026-11-21 (user): the route alone is enough (the bus is chosen with it when scheduling).
        if (! empty($data['route_id'])) {
            $routeId = (int) $data['route_id'];
            if (! app(RouteService::class)->userOwnsRoute($routeId, $user)) {
                return $this->error('Route not found.', 404);
            }
        } else {
            $fleetRoute = $this->fleetRoutes->findWithRoute((int) $data['fleet_route_id']);
            if (! $fleetRoute) {
                return $this->error('Bus route not found.', 404);
            }
            // Batch 46 step 2: a bus of the Operator's company.
            if ($user->role === 'operator' && ! app(FleetService::class)->isOwnedByOperator((int) $fleetRoute->fleet_id, (int) $user->companyProfile?->company_user_id)) {
                return $this->error('You do not have permission to plan for that bus.', 403);
            }
            $routeId = (int) $fleetRoute->route_id;
        }

        $plan = $this->legPlanning->plan(
            $routeId,
            $data['service_type'] ?? null,
            $data['scheduled_date'],
            $data['first_departure_time'],
            isset($data['leg_count']) ? (int) $data['leg_count'] : null,
        );

        return $this->success($plan, 'Leg times suggested');
    }

    // ----------------------------------------------------------------

    /** @return array<string, mixed> */
    private function present(RouteDurationFactor $window): array
    {
        return [
            'id' => (int) $window->id,
            'route_id' => (int) $window->route_id,
            'route_name' => $window->route?->route_name,
            'direction' => $window->direction,
            'day_type' => $window->day_type,
            'start_time' => substr((string) $window->start_time, 0, 5),
            'end_time' => substr((string) $window->end_time, 0, 5),
            'factor' => $window->factor,
            'source' => $window->source,
            // A person entered it, whether typed or copied from the report: never "measured" as a claim.
            'label' => $window->source === RouteDurationFactor::SOURCE_MEASURED ? 'configured (copied from the duration report)' : 'configured',
            'note' => $window->note,
        ];
    }

    private function assertRoute(Request $request, int $routeId)
    {
        $denied = $this->access->denial($request->user(), $routeId, 'duration factors');

        return $denied ? $this->error(...$denied) : null;
    }
}
