<?php

namespace App\Http\Controllers;

use App\Models\FleetRoute;
use App\Models\Route;
use App\Models\RouteDurationFactor;
use App\Services\AuditLogger;
use App\Services\RouteDurationFactorService;
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

    public function __construct(private RouteDurationFactorService $factors) {}

    public function index(Request $request)
    {
        $data = $request->validate(['route_id' => 'nullable|integer']);
        $scope = $this->scope($request);
        if ($scope === false) {
            return $this->error('Staff profile not found.', 404);
        }

        $windows = RouteDurationFactor::with('route:route_id,route_name')
            ->when(isset($data['route_id']), fn ($q) => $q->where('route_id', (int) $data['route_id']))
            ->when($scope !== null, fn ($q) => $q->whereIn('route_id', $this->routeIds($scope)))
            ->orderBy('route_id')->orderBy('start_time')
            ->get()
            ->map(fn (RouteDurationFactor $w) => $this->present($w))
            ->values();

        return $this->success($windows, 'Duration factors retrieved successfully');
    }

    public function store(Request $request)
    {
        $data = $this->validated($request);
        if ($denied = $this->assertRoute($request, (int) $data['route_id'])) {
            return $denied;
        }

        $clean = $this->factors->validateWindow($data);
        $window = RouteDurationFactor::create($clean + ['created_by_user_id' => $request->user()->user_id]);
        AuditLogger::log('duration_factor.create', 'RouteDurationFactor', (int) $window->id, $clean);

        return $this->success($this->present($window->load('route:route_id,route_name')), 'Duration factor saved', 201);
    }

    public function update(Request $request, int $id)
    {
        $window = RouteDurationFactor::find($id);
        if (! $window) {
            return $this->error('Duration factor not found.', 404);
        }
        if ($denied = $this->assertRoute($request, (int) $window->route_id)) {
            return $denied;
        }

        $data = $this->validated($request) + [];
        $data['route_id'] = $window->route_id; // a window never moves to another route
        $clean = $this->factors->validateWindow($data, (int) $window->id);
        $window->update($clean);
        AuditLogger::log('duration_factor.update', 'RouteDurationFactor', (int) $window->id, $clean);

        return $this->success($this->present($window->fresh()->load('route:route_id,route_name')), 'Duration factor updated');
    }

    public function destroy(Request $request, int $id)
    {
        $window = RouteDurationFactor::find($id);
        if (! $window) {
            return $this->error('Duration factor not found.', 404);
        }
        if ($denied = $this->assertRoute($request, (int) $window->route_id)) {
            return $denied;
        }

        $window->delete();
        AuditLogger::log('duration_factor.delete', 'RouteDurationFactor', $id, ['route_id' => (int) $window->route_id]);

        return $this->success(null, 'Duration factor removed');
    }

    /** Read-only: median actual leg duration per route, direction and hour, and whether there is enough data to propose a factor. */
    public function report(Request $request)
    {
        $data = $request->validate(['route_id' => 'nullable|integer']);
        $scope = $this->scope($request);
        if ($scope === false) {
            return $this->error('Staff profile not found.', 404);
        }

        return $this->success($this->factors->durationReport(isset($data['route_id']) ? (int) $data['route_id'] : null, $scope), 'Duration report retrieved successfully');
    }

    /** Suggested start times for the legs of a shift block (a suggestion the operator can override). */
    public function planLegs(Request $request)
    {
        $data = $request->validate([
            'fleet_route_id' => 'required|integer|exists:fleets_routes,fleet_route_id',
            'scheduled_date' => 'required|date_format:Y-m-d',
            'first_departure_time' => ['required', 'regex:/^([01]\d|2[0-3]):[0-5]\d$/'],
            'service_type' => 'nullable|string|in:'.implode(',', \App\Support\ServiceType::ALL),
        ]);

        $fleetRoute = FleetRoute::with('fleet')->findOrFail($data['fleet_route_id']);
        $user = $request->user();
        if ($user->role === 'operator' && (int) $fleetRoute->fleet?->company_user_id !== (int) $user->companyProfile?->company_user_id) {
            return $this->error('You do not have permission to plan for that bus.', 403);
        }

        $plan = $this->factors->suggestLegs(
            (int) $fleetRoute->route_id,
            $data['service_type'] ?? null,
            $data['scheduled_date'],
            $data['first_departure_time'],
            (int) config('shift_blocks.legs_per_block'),
        );

        return $this->success($plan + ['note' => 'A suggestion only. These times come from your settings, not from measurements.'], 'Leg times suggested');
    }

    // ----------------------------------------------------------------

    /** @return array<string, mixed> */
    private function validated(Request $request): array
    {
        return $request->validate([
            'route_id' => 'required|integer|exists:routes,route_id',
            'direction' => 'nullable|string|in:outbound,reverse',
            'day_type' => 'nullable|string|in:'.implode(',', RouteDurationFactorService::DAY_TYPES),
            'start_time' => 'required|string',
            'end_time' => 'required|string',
            'factor' => 'required|numeric',
            'source' => 'nullable|string|in:manual,measured',
            'note' => 'nullable|string|max:255',
        ]);
    }

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

    /** null = admin (all), int = an operator's company, false = no staff profile. */
    private function scope(Request $request): int|false|null
    {
        if (($request->user()->role ?? null) === 'admin') {
            return null;
        }
        $companyUserId = $request->user()?->companyProfile?->company_user_id;

        return $companyUserId ? (int) $companyUserId : false;
    }

    /** @return array<int, int> the routes an operator's buses are assigned to */
    private function routeIds(int $companyUserId): array
    {
        return FleetRoute::whereHas('fleet', fn ($q) => $q->where('company_user_id', $companyUserId))->pluck('route_id')->unique()->values()->all();
    }

    private function assertRoute(Request $request, int $routeId)
    {
        $scope = $this->scope($request);
        if ($scope === false) {
            return $this->error('Staff profile not found.', 404);
        }
        if ($scope !== null && ! in_array($routeId, $this->routeIds($scope), true)) {
            return $this->error('You can only manage duration factors for routes your buses run.', 403);
        }
        if (! Route::whereKey($routeId)->exists()) {
            return $this->error('Route not found.', 404);
        }

        return null;
    }
}
