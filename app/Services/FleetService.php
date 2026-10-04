<?php
namespace App\Services;
use App\Repositories\FleetRepository;
use App\Repositories\FleetRouteRepository;
use Illuminate\Support\Facades\DB;

class FleetService
{
    public function __construct(
        private FleetRepository $fleetRepository,
        private FleetRouteRepository $fleetRouteRepository,
    ) {
    }

    // $payload = ['plate_number','seated_capacity','standing_capacity','fleet_type', 'routes' => [['route_id','start_time','end_time'], ...]]
    public function registerFleet(array $payload, int $staffId): object
    {
        // Auto-calculate capacity from seated + standing
        $capacity = (intval($payload['seated_capacity'] ?? 0) + intval($payload['standing_capacity'] ?? 0));
        $routes = $payload['routes'] ?? [];
        unset($payload['routes']);
        $payload['vehicle_class'] = \App\Support\VehicleClass::normalize($payload['vehicle_class'] ?? null);

        // B3: the fleet row and its fleets_routes rows are written atomically so
        // a fleet can never be saved while its selected routes are silently lost.
        return DB::transaction(function () use ($payload, $staffId, $capacity, $routes) {
            $fleet = $this->fleetRepository->create(array_merge(
                $payload,
                ['company_user_id' => $staffId],
                ['capacity' => $capacity],
                ['status' => 'active']
            ));

            foreach ($routes as $route) {
                $this->fleetRouteRepository->create([
                    'fleet_id' => $fleet->fleet_id,
                    'route_id' => $route['route_id'],
                    'start_time' => $route['start_time'],
                    'end_time' => $route['end_time'],
                    'status' => 'active',
                ]);
            }

            return $fleet->load('fleetRoutes.route');
        });
    }

    /** Editable fleet details. Capacity is recomputed whenever a seat count changes. */
    public function updateFleet(int $fleetId, array $payload): ?object
    {
        $fleet = $this->fleetRepository->findById($fleetId);
        if (! $fleet) {
            return null;
        }

        if (array_key_exists('seated_capacity', $payload) || array_key_exists('standing_capacity', $payload)) {
            $seated = (int) ($payload['seated_capacity'] ?? $fleet->seated_capacity);
            $standing = (int) ($payload['standing_capacity'] ?? $fleet->standing_capacity);
            $payload['capacity'] = $seated + $standing;
        }

        // The class is stored in its canonical form (an alias such as "deluxe" becomes "aircon"); empty clears it.
        if (array_key_exists('vehicle_class', $payload)) {
            $payload['vehicle_class'] = \App\Support\VehicleClass::normalize($payload['vehicle_class']);
        }

        // An empty string clears an optional label field.
        foreach (['bus_company_name', 'body_no'] as $key) {
            if (array_key_exists($key, $payload) && trim((string) $payload[$key]) === '') {
                $payload[$key] = null;
            }
        }

        $fleet->fill($payload)->save();

        return $fleet->fresh();
    }

    public function listFleets(): object
    {
        return $this->fleetRepository->all();
    }

    /**
     * IDOR guard used by controllers before allowing an operator to act on
     * or view data scoped to a fleet.
     */
    public function isOwnedByOperator(int $fleetId, int $companyUserId): bool
    {
        return $this->fleetRepository->isOwnedByOperator($fleetId, $companyUserId);
    }

    public function listFleetRoutesByOperator(int $companyUserId): object
    {
        return $this->fleetRouteRepository->listActiveByOperator($companyUserId);
    }

    /**
     * All active fleet routes — used by the trip-scheduling form so operators
     * can schedule trips on fleets they did not personally create.
     */
    public function listAllFleetRoutes(): object
    {
        return $this->fleetRouteRepository->listAll();
    }

    // $payload = ['route_id','start_time','end_time']
    public function assignRouteToFleet(int $fleetId, array $payload): object
    {
        return $this->fleetRouteRepository->create(array_merge(
            $payload,
            ['fleet_id' => $fleetId, 'status' => 'active']
        ));
    }
}
