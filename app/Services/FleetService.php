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
