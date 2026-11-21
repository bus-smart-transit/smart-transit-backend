<?php

namespace App\Services;

use App\Models\FleetRoute;
use App\Repositories\FleetRouteRepository;
use App\Repositories\RouteStopRepository;
use App\Support\BookingWindow;
use App\Support\BusLabel;
use App\Support\ServiceType;
use App\Support\VehicleClass;
use Illuminate\Validation\ValidationException;

/**
 * Batch 75 (panel, 2026-11-15): the fares a route's stops produce, shown to the Operator the way passengers see them
 * ("{from stop} -> {to stop}: PHP {fare}"). Every amount comes from FareRuleService::getQuote, the same
 * quote the passenger is charged, for today's date; a pair with no fare rule is null ("Fare unavailable").
 */
class RouteFareTableService
{
    public function __construct(
        private RouteStopRepository $routeStops,
        private FareRuleService $fares,
        private FleetService $fleets,
        private FleetRouteRepository $fleetRoutes,
    ) {}

    /**
     * The bus whose fares are shown on this route. 2026-11-21 (user, Dynamic Route Scheduling): any bus of the company
     * (fleet_id) can run any route, so a bus that never ran it is priced on an unsaved bus-and-route object (nothing is
     * written); an existing row (fleet_route_id) must be the company's and on this route. The Admin is not limited.
     *
     * @throws ValidationException
     */
    public function busOnRoute(int $ownerCompanyUserId, bool $isAdmin, int $routeId, ?int $fleetId, ?int $fleetRouteId): FleetRoute
    {
        if ($fleetId) {
            if (! $isAdmin && ! $this->fleets->isOwnedByOperator($fleetId, $ownerCompanyUserId)) {
                throw ValidationException::withMessages(['fleet_id' => ['Choose one of your own buses.']]);
            }

            return $this->fleetRoutes->findLink($fleetId, $routeId) ?? new FleetRoute(['fleet_id' => $fleetId, 'route_id' => $routeId]);
        }

        $this->fleets->assertFleetRouteOfCompany($ownerCompanyUserId, $fleetRouteId);
        $fleetRoute = $this->fleetRoutes->findWithRoute((int) $fleetRouteId);
        if (! $fleetRoute || (int) $fleetRoute->route_id !== $routeId) {
            throw ValidationException::withMessages(['fleet_route_id' => ['That bus does not run this route.']]);
        }

        return $fleetRoute;
    }

    /**
     * @return array{route_id: int, bus_label: string|null, vehicle_class: string|null, direction: string, service_type: string, origins: array<int, array{stop_id: int, name: string|null}>, origin: array{stop_id: int, name: string|null}|null, rows: array<int, array{stop_id: int, name: string|null, fare: float|null}>}
     */
    public function forFleetRoute(FleetRoute $fleetRoute, ?string $direction, ?string $serviceType, ?int $originStopId): array
    {
        $direction = $direction === 'reverse' ? 'reverse' : 'outbound';
        $service = ServiceType::normalize($serviceType);
        $routeId = (int) $fleetRoute->route_id;
        $stops = $this->routeStops->getOrderedStopsForDirection($routeId, $direction)
            ->filter(fn ($rs) => ServiceType::isServed($service, $rs))
            ->values();

        // Any stop but the last can be a starting point; the first one is the default.
        $origins = $stops->slice(0, max(0, $stops->count() - 1))->values();
        $originIndex = $originStopId === null ? 0 : $origins->search(fn ($rs) => (int) $rs->stop_id === $originStopId);
        $origin = $originIndex === false ? null : $origins->get($originIndex);

        $rows = [];
        if ($origin) {
            foreach ($stops->slice($originIndex + 1) as $destination) {
                $rows[] = [
                    'stop_id' => (int) $destination->stop_id,
                    'name' => $destination->stop?->stop_name,
                    'fare' => $this->quote($fleetRoute, $service, $direction, (int) $origin->stop_id, (int) $destination->stop_id),
                ];
            }
        }

        $fleetRoute->loadMissing('fleet');
        $class = VehicleClass::normalize($fleetRoute->fleet?->vehicle_class);

        return [
            'route_id' => $routeId,
            'bus_label' => BusLabel::for($fleetRoute->fleet),
            'vehicle_class' => VehicleClass::label($class),
            'direction' => $direction,
            'service_type' => $service,
            'origins' => $origins->map(fn ($rs) => ['stop_id' => (int) $rs->stop_id, 'name' => $rs->stop?->stop_name])->all(),
            'origin' => $origin ? ['stop_id' => (int) $origin->stop_id, 'name' => $origin->stop?->stop_name] : null,
            'rows' => $rows,
        ];
    }

    private function quote(FleetRoute $fleetRoute, string $service, string $direction, int $originId, int $destinationId): ?float
    {
        try {
            return round((float) $this->fares->getQuote([
                'route_id' => (int) $fleetRoute->route_id,
                'fleet_id' => (int) $fleetRoute->fleet_id,
                'service_type' => $service,
                'origin_stop_id' => $originId,
                'destination_stop_id' => $destinationId,
                'seat_type' => 'seated',
                'leg_direction' => $direction,
                'on' => BookingWindow::today()->toDateString(),
            ]), 2);
        } catch (\Throwable) {
            return null; // shown as "Fare unavailable"; never guessed
        }
    }
}
