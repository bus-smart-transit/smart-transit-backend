<?php

namespace App\Services;

use App\Models\FleetRoute;
use App\Repositories\CompanyRepository;
use App\Repositories\FleetRepository;
use App\Repositories\FleetRouteRepository;
use App\Repositories\StaffRepository;
use App\Repositories\TripRepository;
use App\Support\CompanyScope;
use App\Support\VehicleClass;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class FleetService
{
    public function __construct(
        private FleetRepository $fleetRepository,
        private FleetRouteRepository $fleetRouteRepository,
        private CompanyRepository $companies,
        private StaffRepository $staff,
        private TripRepository $trips,
    ) {}

    // $payload = ['plate_number','seated_capacity','standing_capacity','fleet_type', 'routes' => [['route_id','start_time','end_time'], ...]]
    public function registerFleet(array $payload, int $staffId): object
    {
        // Auto-calculate capacity from seated + standing
        $capacity = (intval($payload['seated_capacity'] ?? 0) + intval($payload['standing_capacity'] ?? 0));
        $routes = $payload['routes'] ?? [];
        unset($payload['routes']);
        $payload['vehicle_class'] = VehicleClass::normalize($payload['vehicle_class'] ?? null);

        // B3: the fleet row and its fleets_routes rows are written atomically so
        // a fleet can never be saved while its selected routes are silently lost.
        // Batch 46 step 2: the bus belongs to the registering Operator's company, and its passenger label starts with the
        // company name (so the label and the owner cannot disagree). An Operator without a company keeps what was typed.
        $company = $this->companies->find((int) CompanyScope::companyIdOf($staffId));
        if ($company) {
            $payload['company_id'] = $company->company_id;
            $payload['bus_company_name'] = $company->company_name;
        }

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

            // 2026-11-06: never fewer places than are already sold on a trip of this bus that has not finished.
            $sold = $this->trips->mostSoldOnUnfinishedTrips($fleetId);
            if ($seated < $sold['seated']) {
                throw ValidationException::withMessages(['seated_capacity' => ["{$sold['seated']} seats are already sold on a trip of this bus that has not finished. Choose at least {$sold['seated']}."]]);
            }
            if ($standing < $sold['standing']) {
                throw ValidationException::withMessages(['standing_capacity' => ["{$sold['standing']} standing places are already sold on a trip of this bus that has not finished. Choose at least {$sold['standing']}."]]);
            }
        }

        // The class is stored in its canonical form (an alias such as "deluxe" becomes "aircon"); empty clears it.
        if (array_key_exists('vehicle_class', $payload)) {
            $payload['vehicle_class'] = VehicleClass::normalize($payload['vehicle_class']);
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

    /** Batch 46 step 2: an Operator lists their company's buses; null (the Admin) lists every active bus. */
    public function listFleets(?int $operatorCompanyUserId = null): object
    {
        return $operatorCompanyUserId === null
            ? $this->fleetRepository->all()
            : $this->fleetRepository->forCompanyOf($operatorCompanyUserId);
    }

    /**
     * Batch 46 step 2: a trip or shift block may only run on a bus of the owner's company. The same answer for an
     * unknown and another company's bus, so nothing about other companies is revealed. An Admin owner is not limited.
     *
     * @throws ValidationException
     */
    public function assertFleetRouteOfCompany(int $ownerCompanyUserId, mixed $fleetRouteId): void
    {
        if ($this->staff->roleOf($ownerCompanyUserId) === 'admin') {
            return;
        }
        if (! $fleetRouteId || ! $this->fleetRepository->fleetRouteIsOwnedByOperator((int) $fleetRouteId, $ownerCompanyUserId)) {
            throw ValidationException::withMessages(['fleet_route_id' => ['Choose one of your own buses.']]);
        }
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

    /** All active fleet routes of every company: the Admin only (Batch 46). */
    public function listAllFleetRoutes(): object
    {
        return $this->fleetRouteRepository->listAll();
    }

    // $payload = ['route_id','start_time','end_time']
    public function assignRouteToFleet(int $fleetId, array $payload): object
    {
        // The same route cannot be assigned to a bus twice: two rows would double its trips and its list entries.
        // To change the hours, the Operator removes or edits the existing assignment.
        $alreadyAssigned = FleetRoute::where('fleet_id', $fleetId)
            ->where('route_id', $payload['route_id'] ?? null)
            ->where('status', 'active')
            ->exists();
        if ($alreadyAssigned) {
            throw ValidationException::withMessages([
                'route_id' => ['This bus already has this route assigned.'],
            ]);
        }

        return $this->fleetRouteRepository->create(array_merge(
            $payload,
            ['fleet_id' => $fleetId, 'status' => 'active']
        ));
    }
}
