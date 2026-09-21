<?php

namespace App\Http\Controllers;

use App\Http\Requests\FareFleetQuoteRequest;
use App\Http\Requests\FareListRequest;
use App\Http\Requests\FareQuoteFromCoordinatesRequest;
use App\Http\Requests\FareQuoteRequest;
use App\Http\Requests\StoreFareRuleRequest;
use App\Services\AuditLogger;
use App\Services\FareRuleService;
use App\Services\FleetService;
use App\Traits\ApiResponse;

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
            $owned = $this->fleetService->isOwnedByOperator($fleetId, $companyUserId);

            if (! $owned) {
                return $this->error('You do not have permission to manage fare rules for this fleet.', 403);
            }
        }

        $rule = $this->fareRuleService->createFareRule($request->validated());
        AuditLogger::log('fare_rule.create', 'FareRule', $rule->fare_rule_id ?? null, $request->validated());

        return $this->success($rule, 'Fare rule created successfully');
    }

    // Passenger — get fare quote before buying, for a known route/fleet/stop pair
    public function quote(FareQuoteRequest $request)
    {
        try {
            $amount = $this->fareRuleService->getQuote($request->validated());

            return $this->success(['amount' => $amount], 'Fare retrieved successfully');
        } catch (\RuntimeException $e) {
            return $this->error($e->getMessage(), 422);
        }
    }

    // Passenger — snap-to-stop GPS quote for a known route/fleet
    public function quoteByLocation(FareQuoteFromCoordinatesRequest $request)
    {
        try {
            $amount = $this->fareRuleService->getQuoteFromCoordinates($request->validated());

            return $this->success(['amount' => $amount], 'Fare retrieved successfully');
        } catch (\RuntimeException $e) {
            return $this->error($e->getMessage(), 422);
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
            return $this->error($e->getMessage(), 422);
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
            return $this->error($e->getMessage(), 422);
        }
    }
}
