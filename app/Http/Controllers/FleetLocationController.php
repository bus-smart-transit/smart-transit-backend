<?php

namespace App\Http\Controllers;

use App\Http\Requests\NearestFleetRequest;
use App\Http\Requests\UpdateLocationRequest;
use App\Services\FleetLocationService;
use App\Services\TripService;
use App\Support\BusLabel;
use App\Traits\ApiResponse;
use Illuminate\Http\Request;

class FleetLocationController extends Controller
{
    use ApiResponse;

    public function __construct(
        private FleetLocationService $fleetLocationService,
        private TripService $tripService,
    ) {}

    // Driver: push GPS position every 5-10 seconds
    // fleet_id is derived server-side from the driver's active trip — not trusted from client
    public function updateLocation(UpdateLocationRequest $request)
    {
        $companyUser = $request->user()?->companyProfile;
        if (! $companyUser) {
            return $this->error('Staff profile not found.', 404);
        }

        $accepted = $this->fleetLocationService->updateLocation(
            $companyUser->company_user_id,
            $request->validated()
        );

        return $this->success(['accepted' => $accepted], $accepted ? 'Location updated' : 'Location ignored: GPS accuracy too low');
    }

    // Passenger / Public: all active bus positions for the live map.
    // Guests and passengers see the bus label only; staff keep the plate number.
    public function activeLocations(Request $request)
    {
        $rows = $this->fleetLocationService->getAllActiveLocations()
            ->map(fn ($row) => $this->presentBus($row, $request));

        return $this->success($rows, 'Active fleet locations retrieved');
    }

    // Passenger: find the nearest active bus
    public function nearest(NearestFleetRequest $request)
    {
        $nearest = $this->fleetLocationService->findNearestFleet($request->validated());

        return $this->success($nearest ? $this->presentBus($nearest, $request) : null, 'Nearest fleet retrieved');
    }

    private function presentBus(object $row, Request $request): object
    {
        $row->bus_label = BusLabel::compose($row->bus_company_name ?? null, $row->body_no ?? null);
        unset($row->bus_company_name, $row->body_no);

        $role = strtolower((string) ($request->user()?->role ?? ''));
        if (! in_array($role, ['driver', 'conductor', 'operator', 'admin'], true)) {
            unset($row->plate_number);
        }

        return $row;
    }

    // Operator / Admin: GPS breadcrumb trail for a trip (for route adherence + replay)
    public function tripHistory(int $tripId)
    {
        $user = request()->user();
        if ($user?->role === 'operator') {
            $companyUserId = $user->companyProfile?->company_user_id;
            if (! $companyUserId) {
                return $this->error('Staff profile not found.', 404);
            }

            $ownsTrip = $this->tripService->isOwnedByOperator($tripId, $companyUserId);

            if (! $ownsTrip) {
                return $this->error('You do not have permission to access GPS history for this trip.', 403);
            }
        }

        $limit = min((int) request()->query('limit', 500), 1000);

        return $this->success(
            $this->fleetLocationService->getTripHistory($tripId, $limit),
            'GPS history retrieved'
        );
    }
}
