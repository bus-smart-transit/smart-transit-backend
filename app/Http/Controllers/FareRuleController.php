<?php

namespace App\Http\Controllers;

use App\Http\Requests\CopyFareRuleRequest;
use App\Http\Requests\FareFleetQuoteRequest;
use App\Http\Requests\FareListRequest;
use App\Http\Requests\FareQuoteFromCoordinatesRequest;
use App\Http\Requests\FareQuoteRequest;
use App\Http\Requests\StoreFareRuleRequest;
use App\Http\Requests\UpdateFareRuleRequest;
use App\Models\FareRule;
use App\Models\Fleet;
use App\Services\AuditLogger;
use App\Services\FareRuleService;
use App\Services\FleetService;
use App\Support\CompanyScope;
use App\Support\ServiceType;
use App\Support\VehicleClass;
use App\Traits\ApiResponse;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class FareRuleController extends Controller
{
    use ApiResponse;

    public function __construct(
        private FareRuleService $fareRuleService,
        private FleetService $fleetService,
    ) {}

    // Admin — create a fare rule for a fleet + seat type
    public function storeRule(StoreFareRuleRequest $request)
    {
        $user = $request->user();
        if ($user?->role === 'operator') {
            $companyUserId = $user->companyProfile?->company_user_id;
            if (! $companyUserId) {
                return $this->error('Staff profile not found.', 404);
            }

            $fleetId = (int) $request->validated('fleet_id');
            // A general rule (no fleet) prices every bus of a class, so only an Admin writes one; an Operator
            // writes overrides for their own fleets.
            if (! $fleetId) {
                return $this->error('Only an Admin can create a general fare rule. Choose one of your fleets for an override.', 403);
            }
            $owned = $this->fleetService->isOwnedByOperator($fleetId, $companyUserId);

            if (! $owned) {
                return $this->error('You do not have permission to manage fare rules for this fleet.', 403);
            }
        }

        $rule = $this->fareRuleService->createFareRule(array_filter(
            $request->validated(),
            fn ($value) => $value !== null && $value !== '',
        ));
        AuditLogger::log('fare_rule.create', 'FareRule', $rule->fare_rule_id ?? null, $request->validated());

        return $this->success($rule, 'Fare rule created successfully');
    }

    /** 2026-11-06: PATCH fare-rules/{id}. End, stop or annotate a rule in use; change a rule that has not started. */
    public function updateRule(UpdateFareRuleRequest $request, int $fareRuleId)
    {
        $rule = $this->ownRuleOr404($request, $fareRuleId);
        if (! $rule instanceof FareRule) {
            return $rule;
        }

        $updated = $this->fareRuleService->updateRule($rule, $request->validated());
        AuditLogger::log('fare_rule.update', 'FareRule', $fareRuleId, ['changes' => $request->validated()]);

        return $this->success($updated, 'Fare rule updated');
    }

    /** 2026-11-06: POST fare-rules/{id}/copy. A copy from a later date; the original ends the day before. */
    public function copyRule(CopyFareRuleRequest $request, int $fareRuleId)
    {
        $rule = $this->ownRuleOr404($request, $fareRuleId);
        if (! $rule instanceof FareRule) {
            return $rule;
        }

        $copy = $this->fareRuleService->copyRule($rule, $request->validated());
        AuditLogger::log('fare_rule.copy', 'FareRule', (int) $copy->fare_rule_id, ['from_rule' => $fareRuleId] + $request->validated());

        return $this->success($copy, 'Fare rule copied');
    }

    /**
     * A rule the caller may change: the Admin any rule; an Operator only a rule of a bus of their own company (a
     * general rule or another company's reads as not found).
     */
    private function ownRuleOr404(Request $request, int $fareRuleId): FareRule|JsonResponse
    {
        $rule = $this->fareRuleService->findRule($fareRuleId);
        if (! $rule) {
            return $this->error('Fare rule not found.', 404);
        }
        if ($request->user()?->role === 'operator') {
            $companyUserId = (int) ($request->user()->companyProfile?->company_user_id ?? 0);
            if (! $rule->fleet_id || ! $this->fleetService->isOwnedByOperator((int) $rule->fleet_id, $companyUserId)) {
                return $this->error('Fare rule not found.', 404);
            }
        }

        return $rule;
    }

    // Admin and Operator: the fare screen's list. An Operator sees general rules (read only) and the rules of their own fleets.
    public function listRules(Request $request)
    {
        $user = $request->user();
        $filters = $request->validate([
            'vehicle_class' => 'nullable|string|max:32',
            'service_type' => 'nullable|string|in:'.implode(',', ServiceType::ALL),
            'seat_type' => 'nullable|string|in:seated,standing',
            'route_id' => 'nullable|integer',
            'fleet_id' => 'nullable|integer',
            'scope' => 'nullable|string|in:general,fleet',
            'status' => 'nullable|string|in:active,inactive',
        ]);
        if (! empty($filters['vehicle_class'])) {
            $filters['vehicle_class'] = VehicleClass::normalize($filters['vehicle_class']) ?? $filters['vehicle_class'];
        }

        if ($user?->role === 'operator') {
            $companyUserId = $user->companyProfile?->company_user_id;
            if (! $companyUserId) {
                return $this->error('Staff profile not found.', 404);
            }
            // Batch 46 step 2: the fares of every bus of the Operator's company.
            $filters['fleet_ids'] = CompanyScope::whereFleetOfCompany(Fleet::query(), (int) $companyUserId)->pluck('fleet_id')->all();
        }

        return $this->success($this->fareRuleService->listRules($filters), 'Fare rules retrieved successfully');
    }

    // Public: the lists the fare and fleet forms are built from (no fare values, no secrets).
    public function options()
    {
        return $this->success([
            'vehicle_classes' => collect(VehicleClass::all())->map(fn ($label, $key) => ['key' => $key, 'label' => $label])->values(),
            'service_types' => ServiceType::ALL,
            'rounding_modes' => config('fares.rounding_modes'),
            'rounding_increment' => (string) config('fares.rounding_increment'),
            'seat_types' => ['seated', 'standing'],
        ], 'Fare options retrieved successfully');
    }

    // Passenger — get fare quote before buying, for a known route/fleet/stop pair
    public function quote(FareQuoteRequest $request)
    {
        try {
            $amount = $this->fareRuleService->getQuote($request->validated());

            return $this->success(['amount' => $amount], 'Fare retrieved successfully');
        } catch (\RuntimeException $e) {
            return $this->fareError($e);
        }
    }

    // Passenger — snap-to-stop GPS quote for a known route/fleet
    public function quoteByLocation(FareQuoteFromCoordinatesRequest $request)
    {
        try {
            $amount = $this->fareRuleService->getQuoteFromCoordinates($request->validated());

            return $this->success(['amount' => $amount], 'Fare retrieved successfully');
        } catch (\RuntimeException $e) {
            return $this->fareError($e);
        }
    }

    // Passenger "search" step — coordinates only, no route_id/fleet_id required.
    // Infers the route automatically and returns every active fleet's price.
    public function quoteFleetsByLocation(FareFleetQuoteRequest $request)
    {
        try {
            $result = $this->fareRuleService->getFleetQuotesFromCoordinates($request->validated());

            return $this->success($result, 'Fleet fares retrieved successfully');
        } catch (\RuntimeException $e) {
            return $this->fareError($e);
        }
    }

    // Browsable replacement for the old fare_matrix table — computes every
    // stop-pair fare for one fleet + seat type on a route, live, on demand.
    // GET /api/routes/{routeId}/fares?fleet_id=1&seat_type=seated
    public function listFares(int $routeId, FareListRequest $request)
    {
        try {
            $result = $this->fareRuleService->listComputedFaresForRoute(
                $routeId,
                $request->validated('fleet_id'),
                $request->validated('seat_type'),
            );

            return $this->success($result, 'Computed fares retrieved successfully');
        } catch (\RuntimeException $e) {
            return $this->fareError($e);
        }
    }

    /**
     * 2026-11-04 (Todo 3 finding 6): the fare rules' own refusals (RuntimeException) are shown as they are, but a
     * database error is also a RuntimeException (PDOException) and its text holds SQL, so it is logged and the
     * visitor gets a plain message instead.
     */
    private function fareError(\RuntimeException $e)
    {
        if ($e instanceof \PDOException || $e instanceof QueryException) {
            Log::error('Fare lookup failed on a database error', ['message' => $e->getMessage()]);

            return $this->error('Fares could not be loaded right now. Please try again.', 500);
        }

        return $this->error($e->getMessage(), 422);
    }
}
