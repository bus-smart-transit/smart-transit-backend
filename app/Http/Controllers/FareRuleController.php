<?php
namespace App\Http\Controllers;
use App\Services\FareCalculationService;
use App\Services\FareRuleService;
use App\Http\Requests\StoreFareRuleRequest;
use App\Http\Requests\FareQuoteRequest;
use App\Http\Requests\FareQuoteFromCoordinatesRequest;
use App\Traits\ApiResponse;

class FareRuleController extends Controller
{
    use ApiResponse;
    public function __construct(
        private FareCalculationService $fareCalculationService,
        private FareRuleService $fareRuleService,
    ) {
    }

    // Admin — create a fare rule for a fleet + seat type
    public function storeRule(StoreFareRuleRequest $request)
    {
        return $this->success($this->fareRuleService->createFareRule($request->validated()), 'Fare rule created successfully');
    }

    // Admin — manually trigger fare recalculation after rules change
    public function recalculate(int $fleetRouteId)
    {
        $this->fareCalculationService->recalculateForFleetRoute($fleetRouteId);
        return $this->success(null, 'Fares recalculated successfully');
    }

    // Passenger — get fare quote before buying
    public function quote(FareQuoteRequest $request)
    {
        $amount = $this->fareRuleService->getQuote($request->validated());
        return $this->success(['amount' => $amount], 'Fare retrieved successfully');
    }

    public function quoteByLocation(FareQuoteFromCoordinatesRequest $request)
    {
        try {
            $amount = $this->fareRuleService->getQuoteFromCoordinates($request->validated());
            return $this->success(['amount' => $amount], 'Fare retrieved successfully');
        } catch (\RuntimeException $e) {
            return $this->error($e->getMessage(), 422);
        }
    }
}
