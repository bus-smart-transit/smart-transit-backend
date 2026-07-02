<?php

namespace App\Http\Controllers;

use App\Services\TripService;
use Illuminate\Http\Request;
use App\Traits\ApiResponse;

class TripController extends Controller
{
    use ApiResponse;
    private TripService $tripService;

    public function __construct(TripService $tripService)
    {
        $this->tripService = $tripService;
    }

    public function index(Request $request)
    {
        return $this->tripService->listTrip($request->input('per_page', 15));
    }
    public function store(Request $request)
    {
        $validated = $request->validate([
            'fleet_route_id' => 'required|integer|exists:fleets_routes,fleet_route_id',
            'trip_date' => 'required|date',
        ]);

        // The operator who schedules the trip is the authenticated user
        $companyUser = $request->user()->companyProfile;

        $trip = $this->tripService->scheduleTrip([
            ...$validated,
            'company_user_id' => $companyUser->company_user_id,
        ]);

        return $this->success($trip, 'Trip scheduled successfully');
    }

    // Operator / Admin: assign a driver to a trip
    public function assignDriver(Request $request, int $tripId)
    {
        $validated = $request->validate([
            'driver_id' => 'required|integer|exists:company_users,company_user_id',
        ]);

        $this->tripService->assignDriver($tripId, $validated['driver_id']);

        return $this->success(null, 'Driver assigned successfully');
    }

    // Operator / Admin: assign a conductor to a trip
    public function assignConductor(Request $request, int $tripId)
    {
        $validated = $request->validate([
            'conductor_id' => 'required|integer|exists:company_users,company_user_id',
        ]);

        $this->tripService->assignConductor($tripId, $validated['conductor_id']);

        return $this->success(null, 'Conductor assigned successfully');
    }

    // Operator / Admin: open boarding for a trip
    public function startBoarding(int $tripId)
    {
        return $this->success(
            $this->tripService->startBoarding($tripId),
            'Boarding started'
        );
    }

    // Driver / Operator / Admin: mark a trip as departed
    public function depart(int $tripId)
    {
        return $this->success(
            $this->tripService->departTrip($tripId),
            'Trip departed'
        );
    }

    // Driver / Operator / Admin: mark a trip as completed
    public function complete(int $tripId)
    {
        return $this->success(
            $this->tripService->completeTrip($tripId),
            'Trip completed'
        );
    }

    // Driver: see trips assigned to them today
    public function myTrips(Request $request)
    {
        $companyUser = $request->user()->companyProfile;

        return $this->success(
            $this->tripService->getDriverTrips($companyUser->company_user_id),
            'Assigned trips retrieved successfully'
        );
    }

    // Driver: their current active trip
    public function currentTripDriver(Request $request)
    {
        $companyUser = $request->user()->companyProfile;
        $trip = $this->tripService->getCurrentTripForDriver($companyUser->company_user_id);

        if (!$trip) {
            return $this->error('No active trip found.', 404);
        }

        return $this->success($trip, 'Current trip retrieved successfully');
    }

    // Conductor: their current active trip
    public function currentTripConductor(Request $request)
    {
        $companyUser = $request->user()->companyProfile;
        $trip = $this->tripService->getCurrentTripForConductor($companyUser->company_user_id);

        if (!$trip) {
            return $this->error('No active trip found.', 404);
        }

        return $this->success($trip, 'Current trip retrieved successfully');
    }
}