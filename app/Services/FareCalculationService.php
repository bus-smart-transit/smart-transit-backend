<?php
namespace App\Services;
use App\Repositories\FleetRouteRepository;
use App\Repositories\RouteStopRepository;
use App\Repositories\FareRuleRepository;
use App\Repositories\FareMatrixRepository;

class FareCalculationService
{
    public function __construct(
        private FleetRouteRepository $fleetRouteRepository,
        private RouteStopRepository $routeStopRepository,
        private FareRuleRepository $fareRuleRepository,
        private FareMatrixRepository $fareMatrixRepository,
    ) {
    }

    /**
     * Precomputes every stop-pair fare for one fleet-route.
     * Called by FleetController::assignRoute() automatically after assignment,
     * and manually by FareController::recalculate().
     * NEVER call this at booking time.
     */
    public function recalculateForFleetRoute(int $fleetRouteId): void
    {
        $fleetRoute = $this->fleetRouteRepository->findWithRoute($fleetRouteId);
        $stops = $this->routeStopRepository->getOrderedStops($fleetRoute->route_id);
        $rules = $this->fareRuleRepository->getActiveRulesForFleet($fleetRoute->fleet_id);

        foreach ($rules as $rule) {
            foreach ($stops as $origin) {
                foreach ($stops as $destination) {
                    if ($origin->stop_id === $destination->stop_id)
                        continue;

                    $distanceKm = abs(
                        $destination->distance_from_origin_km -
                        $origin->distance_from_origin_km
                    );

                    $this->fareMatrixRepository->upsert([
                        'origin_stop_id' => $origin->stop_id,
                        'destination_stop_id' => $destination->stop_id,
                        'seat_type' => $rule->seat_type,
                        'fleet_id' => $fleetRoute->fleet_id,
                        'fare_rule_id' => $rule->fare_rule_id,
                        'amount' => $this->computeFare($rule->base_fare, $rule->fare_per_km, $distanceKm),
                        'status' => 'active',
                    ]);
                }
            }
        }
    }

    // Continuous per-km model. To use stepped 5km blocks instead:
    // return round($baseFare + (ceil($distanceKm / 5) * $farePerKm), 2);
    private function computeFare(float $baseFare, float $farePerKm, float $distanceKm): float
    {
        return round($baseFare + ($distanceKm * $farePerKm), 2);
    }
}
