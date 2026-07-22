<?php
namespace App\Http\Controllers;
use App\Services\TripService;
use App\Services\DriverNavigationService;
use App\Http\Requests\StoreTripRequest;
use App\Http\Requests\AssignDriverRequest;
use App\Http\Requests\AssignConductorRequest;
use App\Traits\ApiResponse;
use Illuminate\Http\Request;

class TripController extends Controller
{
    use ApiResponse;
    public function __construct(
        private TripService $tripService,
        private DriverNavigationService $navigationService,
    )
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

    // Conductor: see assigned trips (scheduled/current)
    public function myConductorTrips(Request $request)
    {
        $companyUser = $request->user()->companyProfile;
        return $this->success(
            $this->tripService->getConductorTrips($companyUser->company_user_id),
            'Assigned trips retrieved successfully'
        );
    }

    // Operator: see all upcoming trips scheduled under this operator
    public function operatorTrips(Request $request)
    {
        $companyUser = $request->user()->companyProfile;
        return $this->success(
            $this->tripService->getOperatorUpcomingTrips($companyUser->company_user_id),
            'Upcoming trips retrieved successfully'
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

    /**
     * Driver: Get current trip with all stops and passengers alighting at each stop
     * GET /driver/trips/current/stops
     */
    public function currentTripStops(Request $request)
    {
        $companyUser = $request->user()->companyProfile;
        $tripWithStops = $this->navigationService->getCurrentTripWithStops($companyUser->company_user_id);
        return $this->success($tripWithStops, 'Current trip stops and passengers retrieved successfully');
    }

    /**
     * Driver: Get details for a specific stop on current trip
     * GET /driver/trips/current/stops/{stopId}
     */
    public function currentTripStopDetail(Request $request, int $stopId)
    {
        $companyUser = $request->user()->companyProfile;
        $stopDetails = $this->navigationService->getStopDetails($companyUser->company_user_id, $stopId);
        return $this->success($stopDetails, 'Stop details retrieved successfully');
    }

    /**
     * Driver: Acknowledge reaching a stop (mark as passed)
     * POST /driver/trips/current/stops/{stopId}/acknowledge
     */
    public function acknowledgeStop(Request $request, int $stopId)
    {
        $companyUser = $request->user()->companyProfile;
        $this->navigationService->acknowledgeStop($companyUser->company_user_id, $stopId);
        return $this->success(null, 'Stop acknowledged successfully');
    }

    /**
     * Public: Get available trips for passengers to book
     * GET /trips
     */
    public function availableTrips()
    {
        $trips = \App\Models\Trip::query()
            ->whereIn('status', ['scheduled', 'boarding'])
            ->where('trip_date', '>=', now()->toDateString())
            ->with([
                'fleetRoute.route.routeStops.stop',
                'fleetRoute.fleet',
            ])
            ->orderBy('trip_date', 'asc')
            ->get();

        return $this->success($trips, 'Available trips retrieved successfully');
    }
}
