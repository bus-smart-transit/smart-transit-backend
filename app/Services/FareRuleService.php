<?php
namespace App\Services;
use App\Repositories\FareRuleRepository;
use App\Repositories\StopRepository;
use App\Repositories\RouteStopRepository;
use App\Repositories\FleetRouteRepository;
use App\Repositories\RouteRepository;

class FareRuleService
{
    public function __construct(
        private FareRuleRepository $fareRuleRepository,
        private StopRepository $stopRepository,
        private RouteStopRepository $routeStopRepository,
        private RouteGeometryService $routeGeometryService,
        private FleetRouteRepository $fleetRouteRepository,
        private RouteRepository $routeRepository,
        private FareCalculationService $fareCalculationService,
    ) {
    }

    /**
     * Stop-based quote for a KNOWN route/fleet — no matrix lookup, computed
     * directly from the exact distance between two known stops.
     * $payload = ['route_id','origin_stop_id','destination_stop_id','seat_type','fleet_id']
     */
    public function getQuote(array $payload): float
    {
        $originRouteStop = $this->routeStopRepository->findByRouteAndStop($payload['route_id'], $payload['origin_stop_id']);
        $destinationRouteStop = $this->routeStopRepository->findByRouteAndStop($payload['route_id'], $payload['destination_stop_id']);

        if (!$originRouteStop || !$destinationRouteStop) {
            throw new \RuntimeException('One or both stops are not on this route.');
        }

        $distanceKm = abs($destinationRouteStop->distance_from_origin_km - $originRouteStop->distance_from_origin_km);

        $rule = $this->fareRuleRepository->getActiveRule($payload['fleet_id'], $payload['seat_type']);

        if (!$rule) {
            throw new \RuntimeException('No fare configured for this fleet and seat type.');
        }

        return $this->fareCalculationService->computeFare($rule->base_fare, $rule->fare_per_km, $distanceKm);
    }

    // Snap-to-stop GPS quote for a known route/fleet.
    public function getQuoteFromCoordinates(array $payload): float
    {
        $originStop = $this->stopRepository->findNearestStop(
            $payload['origin_lat'],
            $payload['origin_lng'],
            $payload['route_id'] ?? null,
        );
        $destinationStop = $this->stopRepository->findNearestStop(
            $payload['destination_lat'],
            $payload['destination_lng'],
            $payload['route_id'] ?? null,
        );

        if (!$originStop) {
            throw new \RuntimeException('Could not match your pickup location to a known stop on this route.');
        }
        if (!$destinationStop) {
            throw new \RuntimeException('Could not match your drop-off location to a known stop on this route.');
        }
        if ($originStop->stop_id === $destinationStop->stop_id) {
            throw new \RuntimeException('Pickup and drop-off resolved to the same stop.');
        }

        return $this->getQuote([
            'route_id' => $payload['route_id'],
            'origin_stop_id' => $originStop->stop_id,
            'destination_stop_id' => $destinationStop->stop_id,
            'seat_type' => $payload['seat_type'],
            'fleet_id' => $payload['fleet_id'],
        ]);
    }

    /**
     * Passenger "search" flow — coordinates only, no route_id/fleet_id
     * required. Infers the route automatically and returns every active
     * fleet on it with its price for the requested seat type.
     */
    public function getFleetQuotesFromCoordinates(array $payload): array
    {
        $match = $this->routeGeometryService->matchRouteForTrip(
            $payload['origin_lat'],
            $payload['origin_lng'],
            $payload['destination_lat'],
            $payload['destination_lng'],
        );

        $route = $match['route'];
        $tripDistanceKm = abs($match['destination']['distance_from_origin_km'] - $match['origin']['distance_from_origin_km']);

        if ($tripDistanceKm <= 0) {
            throw new \RuntimeException('Pickup and drop-off resolved to the same point on the route.');
        }

        $fleets = $this->fleetRouteRepository->getActiveFleetsForRoute($route->route_id);
        $quotes = [];

        foreach ($fleets as $fleet) {
            $rule = $this->fareRuleRepository->getActiveRule($fleet->fleet_id, $payload['seat_type']);
            if (!$rule) {
                continue; // this fleet hasn't configured pricing for this seat type
            }

            $quotes[] = [
                'fleet_id' => $fleet->fleet_id,
                'plate_number' => $fleet->plate_number,
                'fleet_type' => $fleet->fleet_type,
                'seat_type' => $payload['seat_type'],
                'amount' => $this->fareCalculationService->computeFare($rule->base_fare, $rule->fare_per_km, $tripDistanceKm),
            ];
        }

        if (empty($quotes)) {
            throw new \RuntimeException('No fleets currently offer this seat type on this route.');
        }

        return [
            'route_id' => $route->route_id,
            'route_name' => $route->route_name,
            'distance_km' => round($tripDistanceKm, 2),
            'fleets' => $quotes,
        ];
    }

    /**
     * Custom-point quote for a SPECIFIC trip's fleet+route — used at
     * purchase time (TicketService::reserveAndPrice). Re-derives distance
     * and price from scratch server-side.
     */
    public function getQuoteForTripFromCoordinates(int $fleetId, int $routeId, array $payload): array
    {
        $match = $this->routeGeometryService->interpolateForKnownRoute(
            $routeId,
            $payload['origin_lat'],
            $payload['origin_lng'],
            $payload['destination_lat'],
            $payload['destination_lng'],
        );

        $tripDistanceKm = abs($match['destination']['distance_from_origin_km'] - $match['origin']['distance_from_origin_km']);

        if ($tripDistanceKm <= 0) {
            throw new \RuntimeException('Pickup and drop-off resolved to the same point on the route.');
        }

        $rule = $this->fareRuleRepository->getActiveRule($fleetId, $payload['seat_type']);
        if (!$rule) {
            throw new \RuntimeException('No fare configured for this fleet and seat type.');
        }

        return [
            'amount' => $this->fareCalculationService->computeFare($rule->base_fare, $rule->fare_per_km, $tripDistanceKm),
            'distance_km' => round($tripDistanceKm, 2),
            'fare_rule_id' => $rule->fare_rule_id,
        ];
    }

    /**
     * Browsable replacement for the old fare_matrix table. Computes every
     * stop-pair fare for one fleet+seat_type on demand — nothing stored,
     * nothing to keep in sync.
     */
    public function listComputedFaresForRoute(int $routeId, int $fleetId, string $seatType): array
    {
        $route = $this->routeRepository->findById($routeId);

        if (!$route) {
            throw new \RuntimeException('Route not found.');
        }

        $rule = $this->fareRuleRepository->getActiveRule($fleetId, $seatType);

        if (!$rule) {
            throw new \RuntimeException('No fare configured for this fleet and seat type.');
        }

        $stops = $route->routeStops;
        $fares = [];

        foreach ($stops as $origin) {
            foreach ($stops as $destination) {
                if ($origin->stop_id === $destination->stop_id) {
                    continue;
                }

                $distanceKm = abs($destination->distance_from_origin_km - $origin->distance_from_origin_km);

                $fares[] = [
                    'origin_stop_id' => $origin->stop_id,
                    'origin_stop_name' => $origin->stop->stop_name,
                    'destination_stop_id' => $destination->stop_id,
                    'destination_stop_name' => $destination->stop->stop_name,
                    'distance_km' => round($distanceKm, 2),
                    'amount' => $this->fareCalculationService->computeFare($rule->base_fare, $rule->fare_per_km, $distanceKm),
                ];
            }
        }

        return [
            'route_id' => $route->route_id,
            'route_name' => $route->route_name,
            'fleet_id' => $fleetId,
            'seat_type' => $seatType,
            'fares' => $fares,
        ];
    }

    public function createFareRule(array $payload): object
    {
        return $this->fareRuleRepository->create(array_merge($payload, ['status' => 'active']));
    }
}