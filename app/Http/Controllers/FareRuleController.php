<?php

namespace App\Http\Controllers;

use App\Services\FareCalculationService;
use App\Services\FareRuleService;
use Illuminate\Http\Request;
use App\Traits\ApiResponse;

class FareRuleController extends Controller
{
    use ApiResponse;
    private FareCalculationService $fareCalculationService;
    private FareRuleService $fareService;

    public function __construct(
        FareCalculationService $fareCalculationService,
        FareRuleService $fareService,
    ) {
        $this->fareCalculationService = $fareCalculationService;
        $this->fareService = $fareService;
    }

    public function storeRule(Request $request)
    {
        $validated = $request->validate([
            'fleet_id' => 'required|integer|exists:fleets,fleet_id',
            'base_fare' => 'required|numeric',
            'fare_per_km' => 'required|numeric',
            'seat_type' => 'required|string|in:seated,standing',
        ]);
        $validated['status'] = 'active';

        return $this->success($this->fareService->createFareRule($validated), 'Fare rule created successfully');
    }

    // Admin-facing: re-run pricing after a route or fare_rules change.
    public function recalculate(int $fleetRouteId)
    {
        $this->fareCalculationService->recalculateForFleetRoute($fleetRouteId);
        return $this->success(null, 'Fares recalculated successfully');
    }

    // Passenger-facing: what would this trip cost?
    public function quote(Request $request)
    {
        $validated = $request->validate([
            'origin_stop_id' => 'required|integer|exists:stops,stop_id',
            'destination_stop_id' => 'required|integer|exists:stops,stop_id',
            'seat_type' => 'required|string|in:seated,standing',
        ]);

        $amount = $this->fareService->getFareForSegment(
            $validated['origin_stop_id'],
            $validated['destination_stop_id'],
            $validated['seat_type'],
        );

        return $this->success(['amount' => $amount], 'Fare retrieved successfully');
    }
}
