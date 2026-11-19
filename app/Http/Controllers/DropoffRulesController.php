<?php

namespace App\Http\Controllers;

use App\Models\AlightingPoint;
use App\Models\CustomDropoffRestriction;
use App\Services\AlightingPointService;
use App\Services\AuditLogger;
use App\Services\DropoffRestrictionService;
use App\Services\OperatorRouteAccess;
use App\Traits\ApiResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Batch 28 Stage 3, item 10: the operator/admin screen's endpoints for designated alighting points and restricted
 * areas (where custom drop-off is NOT available). Admin manages any route; an Operator only routes their own buses
 * run. Every write is validated and measured on the server (the point's distances along the route, the area's end-stop
 * distances) and audited. Nothing here names a place: all names are typed by staff.
 */
class DropoffRulesController extends Controller
{
    use ApiResponse;

    public function __construct(
        private AlightingPointService $points,
        private DropoffRestrictionService $restrictions,
        private OperatorRouteAccess $access,
    ) {}

    // ---------------------------------------------------------------- alighting points

    public function points(Request $request)
    {
        $data = $request->validate([
            'route_id' => 'nullable|integer',
            'municipality' => 'nullable|string|max:100',
            'is_active' => 'nullable|boolean',
        ]);
        $scope = $this->access->scope($request->user());
        if ($scope === false) {
            return $this->error('Staff profile not found.', 404);
        }

        $rows = $this->points->list($data, $scope === null ? null : $this->access->routeIds($scope))
            ->map(fn (AlightingPoint $p) => $this->presentPoint($p))
            ->values();

        return $this->success($rows, 'Drop-off points retrieved successfully');
    }

    public function storePoint(Request $request)
    {
        $data = $this->validatePoint($request);
        if ($denied = $this->assertRoute($request, (int) $data['route_id'])) {
            return $denied;
        }

        $point = $this->points->create($data, (int) $request->user()->user_id);
        AuditLogger::log('dropoff_point.create', 'AlightingPoint', (int) $point->id, ['route_id' => (int) $point->route_id, 'name' => $point->name, 'kind' => $point->kind]);

        return $this->success($this->presentPoint($point->load('route:route_id,route_name')), 'Drop-off point saved', 201);
    }

    public function updatePoint(Request $request, int $id)
    {
        $point = $this->points->find($id);
        if (! $point) {
            return $this->error('Drop-off point not found.', 404);
        }
        if ($denied = $this->assertRoute($request, (int) $point->route_id)) {
            return $denied;
        }

        $point = $this->points->update($point, $this->validatePoint($request));
        AuditLogger::log('dropoff_point.update', 'AlightingPoint', (int) $point->id, ['route_id' => (int) $point->route_id, 'is_active' => (bool) $point->is_active]);

        return $this->success($this->presentPoint($point->load('route:route_id,route_name')), 'Drop-off point updated');
    }

    // ---------------------------------------------------------------- restricted areas

    public function restrictions(Request $request)
    {
        $data = $request->validate(['route_id' => 'nullable|integer']);
        $scope = $this->access->scope($request->user());
        if ($scope === false) {
            return $this->error('Staff profile not found.', 404);
        }

        $routeIds = isset($data['route_id']) ? [(int) $data['route_id']] : $this->restrictions->routesWithRestrictions();
        if ($scope !== null) {
            $routeIds = array_values(array_intersect($routeIds, $this->access->routeIds($scope)));
        }

        $rows = collect($routeIds)->flatMap(fn (int $routeId) => $this->restrictions->forRoute($routeId))
            ->map(fn (array $row) => $this->presentRestriction($row['restriction'], $row['resolved']))
            ->values();

        return $this->success($rows, 'Restricted areas retrieved successfully');
    }

    public function storeRestriction(Request $request)
    {
        $data = $this->validateRestriction($request);
        if ($denied = $this->assertRoute($request, (int) $data['route_id'])) {
            return $denied;
        }

        $row = $this->restrictions->save($data, null, (int) $request->user()->user_id);
        AuditLogger::log('dropoff_restriction.create', 'CustomDropoffRestriction', (int) $row->id, ['route_id' => (int) $row->route_id, 'name' => $row->name]);

        return $this->success($this->presentRestriction($row, $this->restrictions->resolve($row)), 'Restricted area saved', 201);
    }

    public function updateRestriction(Request $request, int $id)
    {
        $row = $this->restrictions->find($id);
        if (! $row) {
            return $this->error('Restricted area not found.', 404);
        }
        if ($denied = $this->assertRoute($request, (int) $row->route_id)) {
            return $denied;
        }

        $data = $this->validateRestriction($request, false);
        $data['route_id'] = $row->route_id; // an area never moves to another route
        $data['from_stop_id'] ??= $row->fromRouteStop?->stop_id;
        $data['to_stop_id'] ??= $row->toRouteStop?->stop_id;
        $data['name'] ??= $row->name;
        $data['is_active'] ??= $row->is_active;
        $data['note'] = array_key_exists('note', $data) ? $data['note'] : $row->note;
        $data['source'] = array_key_exists('source', $data) ? $data['source'] : $row->source;

        $updated = $this->restrictions->save($data, $row);
        AuditLogger::log('dropoff_restriction.update', 'CustomDropoffRestriction', (int) $updated->id, ['route_id' => (int) $updated->route_id, 'is_active' => (bool) $updated->is_active]);

        return $this->success($this->presentRestriction($updated, $this->restrictions->resolve($updated)), 'Restricted area updated');
    }

    /** The team has looked at an area flagged "needs review" and confirms it still covers the right stretch. */
    public function reviewRestriction(Request $request, int $id)
    {
        $row = $this->restrictions->find($id);
        if (! $row) {
            return $this->error('Restricted area not found.', 404);
        }
        if ($denied = $this->assertRoute($request, (int) $row->route_id)) {
            return $denied;
        }

        $resolved = $this->restrictions->markReviewed($row);
        if (! $resolved['valid']) {
            return $this->error($resolved['reason'].' Edit the area and pick its two end stops again.', 422);
        }
        AuditLogger::log('dropoff_restriction.review', 'CustomDropoffRestriction', (int) $row->id, ['route_id' => (int) $row->route_id]);

        return $this->success($this->presentRestriction($row->fresh(), $resolved), 'Restricted area confirmed');
    }

    // ----------------------------------------------------------------

    private function validatePoint(Request $request): array
    {
        return $request->validate([
            'route_id' => 'required|integer|exists:routes,route_id',
            'name' => 'required|string|max:120',
            'kind' => ['required', 'string', Rule::in(AlightingPoint::KINDS)],
            'municipality' => 'nullable|string|max:100',
            'latitude' => 'required|numeric|between:-90,90',
            'longitude' => 'required|numeric|between:-180,180',
            'source' => 'nullable|string|max:120',
            'note' => 'nullable|string|max:1000',
            'is_active' => 'nullable|boolean',
        ]);
    }

    private function validateRestriction(Request $request, bool $creating = true): array
    {
        $required = $creating ? 'required' : 'sometimes';

        return $request->validate([
            'route_id' => ($creating ? 'required' : 'sometimes').'|integer|exists:routes,route_id',
            'name' => $required.'|string|max:120',
            'from_stop_id' => $required.'|integer|exists:stops,stop_id',
            'to_stop_id' => $required.'|integer|exists:stops,stop_id',
            'note' => 'nullable|string|max:1000',
            'source' => 'nullable|string|max:120',
            'is_active' => 'nullable|boolean',
        ]);
    }

    private function presentPoint(AlightingPoint $p): array
    {
        return [
            'id' => (int) $p->id,
            'route_id' => (int) $p->route_id,
            'route_name' => $p->route?->route_name,
            'name' => $p->name,
            'kind' => $p->kind,
            'municipality' => $p->municipality,
            'latitude' => (float) $p->latitude,
            'longitude' => (float) $p->longitude,
            'distance_along_outbound_m' => $p->distance_along_outbound_m,
            'distance_along_reverse_m' => $p->distance_along_reverse_m,
            'is_active' => (bool) $p->is_active,
            'source' => $p->source,
            'note' => $p->note,
            'is_sample' => $p->source !== null && stripos($p->source, 'SAMPLE') !== false,
        ];
    }

    private function presentRestriction(CustomDropoffRestriction $r, array $resolved): array
    {
        $r->loadMissing('route:route_id,route_name', 'fromRouteStop.stop:stop_id,stop_name', 'toRouteStop.stop:stop_id,stop_name');

        return [
            'id' => (int) $r->id,
            'route_id' => (int) $r->route_id,
            'route_name' => $r->route?->route_name,
            'name' => $r->name,
            'area_type' => $r->area_type,
            'from_stop_id' => $r->fromRouteStop?->stop_id,
            'from_stop_name' => $r->fromRouteStop?->stop?->stop_name,
            'to_stop_id' => $r->toRouteStop?->stop_id,
            'to_stop_name' => $r->toRouteStop?->stop?->stop_name,
            'from_distance_m' => $resolved['from_m'],
            'to_distance_m' => $resolved['to_m'],
            'is_active' => (bool) $r->is_active,
            'needs_review' => ! $resolved['valid'],
            'review_reason' => $resolved['reason'],
            'source' => $r->source,
            'note' => $r->note,
            'applies_to' => $r->applies_to,
            'pending_confirmation' => $r->source !== null && stripos($r->source, 'pending') !== false,
        ];
    }

    private function assertRoute(Request $request, int $routeId)
    {
        $denied = $this->access->denial($request->user(), $routeId, 'drop-off rules');

        return $denied ? $this->error(...$denied) : null;
    }
}
