<?php
namespace App\Http\Controllers;
use App\Services\FleetLocationService;
use App\Http\Requests\UpdateLocationRequest;
use App\Http\Requests\NearestFleetRequest;
use App\Traits\ApiResponse;

class FleetLocationController extends Controller
{
    use ApiResponse;
    public function __construct(private FleetLocationService $fleetLocationService) {}

    // Driver: push GPS position every 5-10 seconds
    // fleet_id is derived server-side from the driver's active trip — not trusted from client
    public function updateLocation(UpdateLocationRequest $request)
    {
        $companyUser = $request->user()?->companyProfile;
        if (!$companyUser) return $this->error('Staff profile not found.', 404);

        $this->fleetLocationService->updateLocation(
            $companyUser->company_user_id,
            $request->validated()
        );
        return $this->success(null, 'Location updated');
    }

    // Passenger / Public: all active bus positions for the live map
    public function activeLocations()
    {
        return $this->success(
            $this->fleetLocationService->getAllActiveLocations(),
            'Active fleet locations retrieved'
        );
    }

    // Passenger: find the nearest active bus
    public function nearest(NearestFleetRequest $request)
    {
        $nearest = $this->fleetLocationService->findNearestFleet($request->validated());
        return $this->success($nearest, 'Nearest fleet retrieved');
    }

    // Operator / Admin: GPS breadcrumb trail for a trip (for route adherence + replay)
    public function tripHistory(int $tripId)
    {
        $limit = min((int) request()->query('limit', 500), 1000);
        return $this->success(
            $this->fleetLocationService->getTripHistory($tripId, $limit),
            'GPS history retrieved'
        );
    }
}
