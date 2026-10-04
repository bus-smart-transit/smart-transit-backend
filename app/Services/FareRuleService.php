<?php
namespace App\Services;
use App\Repositories\FareRuleRepository;
use App\Support\BusLabel;
use App\Repositories\StopRepository;
use App\Repositories\RouteStopRepository;
use App\Repositories\FleetRouteRepository;
use App\Repositories\RouteRepository;
use App\Exceptions\FareUnavailableException;
use App\Support\LegDistance;
use App\Support\ServiceType;
use App\Support\VehicleClass;
use Illuminate\Validation\ValidationException;

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
        private FareRuleResolver $fareRuleResolver,
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
        if (isset($payload['leg_direction'])) {
            // Batch 27 Part 2: along the leg, and refuse a destination that is not ahead.
            $distanceKm = LegDistance::alongLegKm(
                (float) $originRouteStop->distance_from_origin_km,
                (float) $destinationRouteStop->distance_from_origin_km,
                $payload['leg_direction'],
            );
            if ($distanceKm <= 0) {
                throw new \RuntimeException('The destination is not ahead of the boarding stop for this direction.');
            }
        }

        $rule = $this->fareRuleResolver->resolve(
            (int) $payload['fleet_id'],
            (int) $payload['route_id'],
            $payload['service_type'] ?? null,
            $payload['seat_type'],
            $payload['on'] ?? null,
        );

        if (!$rule) {
            throw new FareUnavailableException();
        }

        return $this->fareCalculationService->priceWithRule($rule, $distanceKm);
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
            $rule = $this->fareRuleResolver->resolve((int) $fleet->fleet_id, (int) $route->route_id, $payload['service_type'] ?? null, $payload['seat_type']);
            if (!$rule) {
                continue; // no fare for this bus (no override and no rule for its class): "Fare unavailable", never guessed
            }

            $quotes[] = [
                'fleet_id' => $fleet->fleet_id,
                'bus_label' => BusLabel::for($fleet),
                'fleet_type' => $fleet->fleet_type,
                'vehicle_class' => $fleet->vehicle_class,
                'seat_type' => $payload['seat_type'],
                'amount' => $this->fareCalculationService->priceWithRule($rule, $tripDistanceKm),
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

        $rule = $this->fareRuleResolver->resolve($fleetId, $routeId, $payload['service_type'] ?? null, $payload['seat_type'], $payload['on'] ?? null);
        if (!$rule) {
            throw new FareUnavailableException();
        }

        return [
            'amount' => $this->fareCalculationService->priceWithRule($rule, $tripDistanceKm),
            'distance_km' => round($tripDistanceKm, 2),
            'fare_rule_id' => $rule->fare_rule_id,
            'rounding_mode' => $rule->rounding_mode,
            'boarding_distance_m' => LegDistance::toMeters((float) $match['origin']['distance_from_origin_km']),
            'alighting_distance_m' => LegDistance::toMeters((float) $match['destination']['distance_from_origin_km']),
        ];
    }

    /**
     * Browsable replacement for the old fare_matrix table. Computes every
     * stop-pair fare for one fleet+seat_type on demand — nothing stored,
     * nothing to keep in sync.
     */
    public function listComputedFaresForRoute(int $routeId, int $fleetId, string $seatType, ?string $serviceType = null): array
    {
        $route = $this->routeRepository->findById($routeId);

        if (!$route) {
            throw new \RuntimeException('Route not found.');
        }

        $rule = $this->fareRuleResolver->resolve($fleetId, $routeId, $serviceType, $seatType);

        if (!$rule) {
            throw new FareUnavailableException();
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
                    'amount' => $this->fareCalculationService->priceWithRule($rule, $distanceKm),
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

    /** @return \Illuminate\Support\Collection<int, \App\Models\FareRule> */
    public function listRules(array $filters = []): \Illuminate\Support\Collection
    {
        return $this->fareRuleRepository->list($filters)->map(function ($rule) {
            $rule->setAttribute('vehicle_class_label', VehicleClass::label($rule->vehicle_class));

            return $rule;
        });
    }

    public function createFareRule(array $payload): object
    {
        // A rule is an override (it names a fleet) or a general rule (it names a vehicle class). Either way the
        // class is stored in its canonical form ("deluxe" is part of aircon).
        $payload['vehicle_class'] = VehicleClass::normalize($payload['vehicle_class'] ?? null);
        $payload['service_type'] = ServiceType::normalize($payload['service_type'] ?? null);
        $payload['rounding_mode'] = $payload['rounding_mode'] ?? config('fares.default_rounding_mode');

        if (empty($payload['fleet_id']) && $payload['vehicle_class'] === null) {
            throw ValidationException::withMessages(['vehicle_class' => ['A general fare rule needs a vehicle class.']]);
        }

        // Two rules with the same key (fleet, route, class, service type, seat type) may not be in force on the same day.
        if ($this->fareRuleRepository->overlappingRuleExists(
            [
                'fleet_id' => $payload['fleet_id'] ?? null,
                'route_id' => $payload['route_id'] ?? null,
                'vehicle_class' => $payload['vehicle_class'],
                'service_type' => $payload['service_type'],
                'seat_type' => (string) $payload['seat_type'],
            ],
            $payload['effective_from'] ?? null,
            $payload['effective_to'] ?? null,
        )) {
            throw ValidationException::withMessages([
                'effective_from' => ['Another active fare rule with the same fleet, route, class, service type and seat type is in force on those dates. Set its end date first.'],
            ]);
        }

        return $this->fareRuleRepository->create(array_merge($payload, ['status' => 'active']));
    }
}