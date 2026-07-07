<?php
namespace App\Http\Controllers;
use App\Services\TripService;
use App\Http\Requests\StoreTripRequest;
use App\Http\Requests\AssignDriverRequest;
use App\Http\Requests\AssignConductorRequest;
use App\Traits\ApiResponse;
use Illuminate\Http\Request;

class TripController extends Controller
{
    use ApiResponse;
    public function __construct(private TripService $tripService)
    {
    }

    // Operator / Admin: schedule a new trip
    public function store(StoreTripRequest $request)
    {
        $companyUser = $request->user()->companyProfile;
        $trip = $this->tripService->scheduleTrip(array_merge(
            $request->validated(),
            ['company_user_id' => $companyUser->company_user_id]
        ));
        return $this->success($trip, 'Trip scheduled successfully');
    }

    // Operator / Admin: assign a driver
    public function assignDriver(AssignDriverRequest $request, int $tripId)
    {
        $this->tripService->assignDriver($tripId, $request->validated());
        return $this->success('test', 'Driver assigned successfully');
    }

    // Operator / Admin: assign a conductor
    public function assignConductor(AssignConductorRequest $request, int $tripId)
    {
        $this->tripService->assignConductor($tripId, $request->validated());
        return $this->success('test', 'Conductor assigned successfully');
    }

    // Operator / Admin: open boarding
    public function startBoarding(int $tripId)
    {
        return $this->success($this->tripService->startBoarding($tripId), 'Boarding started');
    }

    // Driver / Operator / Admin: mark departed
    public function depart(int $tripId)
    {
        return $this->success($this->tripService->departTrip($tripId), 'Trip departed');
    }

    // Driver / Operator / Admin: mark completed
    public function complete(int $tripId)
    {
        return $this->success($this->tripService->completeTrip($tripId), 'Trip completed');
    }

    // Driver: see today's assigned trips
    public function myTrips(Request $request)
    {
        $companyUser = $request->user()->companyProfile;
        return $this->success(
            $this->tripService->getDriverTrips($companyUser->company_user_id),
            'Assigned trips retrieved successfully'
        );
    }

    // Driver: current active trip
    public function currentTripDriver(Request $request)
    {
        $companyUser = $request->user()->companyProfile;
        $trip = $this->tripService->getCurrentTripForDriver($companyUser->company_user_id);
        if (!$trip)
            return $this->error('No active trip found.', 404);
        return $this->success($trip, 'Current trip retrieved successfully');
    }

    // Conductor: current active trip
    public function currentTripConductor(Request $request)
    {
        $companyUser = $request->user()->companyProfile;
        $trip = $this->tripService->getCurrentTripForConductor($companyUser->company_user_id);
        if (!$trip)
            return $this->error('No active trip found.', 404);
        return $this->success($trip, 'Current trip retrieved successfully');
    }
}
