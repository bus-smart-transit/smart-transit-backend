<?php

namespace App\Services;

use App\Repositories\FleetRouteRepository;
use App\Repositories\RouteStopRepository;
use App\Repositories\FareRuleRepository;
use App\Repositories\FareMatrixRepository;

class FareCalculationService
{
    private FleetRouteRepository $fleetRouteRepository;
    private RouteStopRepository $routeStopRepository;
    private FareRuleRepository $fareRuleRepository;
    private FareMatrixRepository $fareMatrixRepository;
    public function __construct(
        FleetRouteRepository $fleetRouteRepository,
        RouteStopRepository $routeStopRepository,
        FareRuleRepository $fareRuleRepository,
        FareMatrixRepository $fareMatrixRepository,
    ) {
        $this->fleetRouteRepository = $fleetRouteRepository;
        $this->routeStopRepository = $routeStopRepository;
        $this->fareRuleRepository = $fareRuleRepository;
        $this->fareMatrixRepository = $fareMatrixRepository;
    }

    /**
     * Recomputes every stop-pair fare for one fleet's assignment to one
     * route. Call when a fleet-route is created, or when that fleet's
     * fare_rules change — never at booking time.
     */
    public function recalculateForFleetRoute(int $fleetRouteId): void
    {
        $fleetRoute = $this->fleetRouteRepository->findWithRoute($fleetRouteId);
        $stops = $this->routeStopRepository->getOrderedStops($fleetRoute->route_id);
        $rules = $this->fareRuleRepository->getActiveRulesForFleet($fleetRoute->fleet_id);

        foreach ($rules as $rule) {
            foreach ($stops as $origin) {
                foreach ($stops as $destination) {
                    if ($origin->stop_id === $destination->stop_id) {
                        continue;
                    }

                    $distanceKm = abs($destination->distance_from_origin_km - $origin->distance_from_origin_km);
                    $amount = $this->computeFare($rule->base_fare, $rule->fare_per_km, $distanceKm);

                    $this->fareMatrixRepository->upsert([
                        'origin_stop_id' => $origin->stop_id,
                        'destination_stop_id' => $destination->stop_id,
                        'seat_type' => $rule->seat_type,
                        'fleet_id' => $fleetRoute->fleet_id,
                        'fare_rule_id' => $rule->fare_rule_id,
                        'amount' => $amount,
                        'status' => 'active',
                    ]);
                }
            }
        }
    }

    private function computeFare(float $baseFare, float $farePerKm, float $distanceKm): float
    {
        return round($baseFare + ($distanceKm * $farePerKm), 2);
    }
}
