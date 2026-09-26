<?php

namespace App\Http\Controllers;

use App\Http\Requests\AssignConductorRequest;
use App\Http\Requests\AssignDriverRequest;
use App\Http\Requests\StoreTripRequest;
use App\Http\Requests\UpdateTripStatusRequest;
use App\Services\AuditLogger;
use App\Services\DriverNavigationService;
use App\Services\DriverShiftService;
use App\Services\FleetPairingService;
use App\Services\TripService;
use App\Traits\ApiResponse;
use Illuminate\Http\Request;

class TripController extends Controller
{
    use ApiResponse;

    public function __construct(
        private TripService $tripService,
        private DriverNavigationService $navigationService,
    ) {}

    // Operator / Admin: schedule a new trip
    public function store(StoreTripRequest $request)
    {
        $companyUser = $request->user()?->companyProfile;
        if (! $companyUser) {
            return $this->error('Staff profile not found.', 404);
        }
        $trip = $this->tripService->scheduleTrip(array_merge(
            $request->validated(),
            ['company_user_id' => $companyUser->company_user_id]
        ));
        AuditLogger::log('trip.schedule', 'Trip', $trip->trip_id ?? null, ['fleet_route_id' => $request->fleet_route_id ?? null]);

        return $this->success($trip, 'Trip scheduled successfully');
    }

    // Operator / Admin: assign a driver
    public function assignDriver(AssignDriverRequest $request, int $tripId)
    {
        if ($denied = $this->assertOperatorOwnsTrip($request, $tripId)) {
            return $denied;
        }

        $this->tripService->assignDriver($tripId, $request->validated());
        AuditLogger::log('trip.assign_driver', 'Trip', $tripId, $request->validated());

        return $this->success(null, 'Driver assigned successfully');
    }

    // Operator / Admin: assign a conductor
    public function assignConductor(AssignConductorRequest $request, int $tripId)
    {
        if ($denied = $this->assertOperatorOwnsTrip($request, $tripId)) {
            return $denied;
        }

        $this->tripService->assignConductor($tripId, $request->validated());
        AuditLogger::log('trip.assign_conductor', 'Trip', $tripId, $request->validated());

        return $this->success(null, 'Conductor assigned successfully');
    }

    // Operator / Admin: save reroute decision
    public function saveDispatchDecision(Request $request, int $tripId)
    {
        if ($denied = $this->assertOperatorOwnsTrip($request, $tripId)) {
            return $denied;
        }

        $validated = $request->validate([
            'decision' => ['required', 'string', 'in:accept,keep'],
            'route' => ['nullable', 'string', 'max:255'],
            'reason' => ['nullable', 'string', 'max:1000'],
        ]);

        $result = $this->tripService->saveDispatchDecision($tripId, $validated);
        AuditLogger::log('trip.dispatch_decision', 'Trip', $tripId, $validated);

        return $this->success($result, 'Dispatch decision saved successfully');
    }

    // Operator / Admin: open boarding
    public function startBoarding(Request $request, int $tripId)
    {
        if ($denied = $this->assertOperatorOwnsTrip($request, $tripId)) {
            return $denied;
        }

        $guard = $this->assertDriverShiftForTrip($request, $tripId);
        if ($guard !== null) {
            return $guard;
        }

        $result = $this->tripService->startBoarding($tripId);
        AuditLogger::log('trip.boarding', 'Trip', $tripId);

        return $this->success($result, 'Boarding started');
    }

    // Driver / Operator / Admin: mark departed
    public function depart(Request $request, int $tripId)
    {
        if ($denied = $this->assertOperatorOwnsTrip($request, $tripId)) {
            return $denied;
        }

        $pairingStatus = app(FleetPairingService::class)->getPairingStatus($tripId);
        if (! ($pairingStatus['paired'] ?? false)) {
            return response()->json([
                'status' => 'error',
                'message' => $pairingStatus['reason'] ?? 'Pairing required before departing the trip.',
            ], 423);
        }

        $guard = $this->assertDriverShiftForTrip($request, $tripId);
        if ($guard !== null) {
            return $guard;
        }

        $result = $this->tripService->departTrip($tripId);
        AuditLogger::log('trip.depart', 'Trip', $tripId);

        return $this->success($result, 'Trip departed');
    }

    // Driver / Operator / Admin: mark completed
    public function complete(Request $request, int $tripId)
    {
        if ($denied = $this->assertOperatorOwnsTrip($request, $tripId)) {
            return $denied;
        }

        $result = $this->tripService->completeTrip($tripId);
        AuditLogger::log('trip.complete', 'Trip', $tripId);

        return $this->success($result, 'Trip completed');
    }

    // Operator / Admin: manually override a trip's status (e.g. driver
    // forgot to mark it completed, or an admin resolving a no-show trip
    // that never departed). Operators are restricted to completing an
    // already-departed trip; admins can force any status.
    public function overrideStatus(UpdateTripStatusRequest $request, int $tripId)
    {
        if ($denied = $this->assertOperatorOwnsTrip($request, $tripId)) {
            return $denied;
        }

        $role = (string) ($request->user()->role ?? '');

        [$trip, $previousStatus] = $this->tripService->overrideStatus(
            $tripId,
            $request->validated('status'),
            $role,
        );

        AuditLogger::log('trip.status_override', 'Trip', $tripId, [
            'from' => $previousStatus,
            'to' => $trip->status,
            'role' => $role,
        ]);

        return $this->success($trip, 'Trip status updated');
    }

    // Driver: decline their assigned trip — returns to the unassigned pool
    // for the Operator to manually reassign. Reason is optional.
    public function declineAsDriver(Request $request, int $tripId)
    {
        $companyUser = $request->user()?->companyProfile;
        if (! $companyUser) {
            return $this->error('Staff profile not found.', 404);
        }

        $validated = $request->validate([
            'reason_code' => ['nullable', 'string', 'in:sick,vehicle_issue,schedule_conflict,other'],
            'reason_text' => ['nullable', 'string', 'max:500'],
        ]);

        $trip = $this->tripService->declineTrip(
            $tripId,
            'driver',
            $companyUser->company_user_id,
            $validated['reason_code'] ?? null,
            $validated['reason_text'] ?? null,
        );

        AuditLogger::log('trip.decline', 'Trip', $tripId, array_merge(['role' => 'driver'], $validated));

        return $this->success($trip, 'Trip declined. It has been returned to the unassigned pool.');
    }

    // Conductor: decline their assigned trip — same rules as the driver path.
    public function declineAsConductor(Request $request, int $tripId)
    {
        $companyUser = $request->user()?->companyProfile;
        if (! $companyUser) {
            return $this->error('Staff profile not found.', 404);
        }

        $validated = $request->validate([
            'reason_code' => ['nullable', 'string', 'in:sick,vehicle_issue,schedule_conflict,other'],
            'reason_text' => ['nullable', 'string', 'max:500'],
        ]);

        $trip = $this->tripService->declineTrip(
            $tripId,
            'conductor',
            $companyUser->company_user_id,
            $validated['reason_code'] ?? null,
            $validated['reason_text'] ?? null,
        );

        AuditLogger::log('trip.decline', 'Trip', $tripId, array_merge(['role' => 'conductor'], $validated));

        return $this->success($trip, 'Trip declined. It has been returned to the unassigned pool.');
    }

    // Batch 15, Item 1: Driver accepts their assigned trip.
    public function acceptAsDriver(Request $request, int $tripId)
    {
        $companyUser = $request->user()?->companyProfile;
        if (! $companyUser) {
            return $this->error('Staff profile not found.', 404);
        }

        $trip = $this->tripService->acceptTrip($tripId, 'driver', $companyUser->company_user_id);

        AuditLogger::log('trip.accept', 'Trip', $tripId, ['role' => 'driver']);

        return $this->success($trip, 'Trip accepted.');
    }

    // Batch 15, Item 1 (extended per Item 7 to the conductor role too): Conductor accepts their assigned trip.
    public function acceptAsConductor(Request $request, int $tripId)
    {
        $companyUser = $request->user()?->companyProfile;
        if (! $companyUser) {
            return $this->error('Staff profile not found.', 404);
        }

        $trip = $this->tripService->acceptTrip($tripId, 'conductor', $companyUser->company_user_id);

        AuditLogger::log('trip.accept', 'Trip', $tripId, ['role' => 'conductor']);

        return $this->success($trip, 'Trip accepted.');
    }

    // Driver: see today's assigned trips
    public function myTrips(Request $request)
    {
        $companyUser = $request->user()?->companyProfile;
        if (! $companyUser) {
            return $this->error('Staff profile not found.', 404);
        }

        return $this->success(
            $this->tripService->getDriverTrips($companyUser->company_user_id, (string) $request->query('status_filter', 'all')),
            'Assigned trips retrieved successfully'
        );
    }

    // Conductor: see assigned trips (scheduled/current)
    public function myConductorTrips(Request $request)
    {
        $companyUser = $request->user()?->companyProfile;
        if (! $companyUser) {
            return $this->error('Staff profile not found.', 404);
        }

        return $this->success(
            $this->tripService->getConductorTrips($companyUser->company_user_id, (string) $request->query('status_filter', 'all')),
            'Assigned trips retrieved successfully'
        );
    }

    // Operator: see all upcoming trips scheduled under this operator
    public function operatorTrips(Request $request)
    {
        $companyUser = $request->user()?->companyProfile;
        if (! $companyUser) {
            return $this->error('Staff profile not found.', 404);
        }

        $statusFilter = $request->query('status_filter');
        $resolvedFilter = $statusFilter !== null ? (string) $statusFilter : 'upcoming';

        return $this->success(
            $this->tripService->getOperatorUpcomingTrips($companyUser->company_user_id, $resolvedFilter),
            'Upcoming trips retrieved successfully'
        );
    }

    // Driver: current active trip
    public function currentTripDriver(Request $request)
    {
        $companyUser = $request->user()?->companyProfile;
        if (! $companyUser) {
            return $this->error('Staff profile not found.', 404);
        }
        $trip = $this->tripService->getCurrentTripForDriver($companyUser->company_user_id);
        if (! $trip) {
            return $this->error('No active trip found.', 404);
        }

        return $this->success($trip, 'Current trip retrieved successfully');
    }

    // Conductor: current active trip
    public function currentTripConductor(Request $request)
    {
        $companyUser = $request->user()?->companyProfile;
        if (! $companyUser) {
            return $this->error('Staff profile not found.', 404);
        }
        $trip = $this->tripService->getCurrentTripForConductor($companyUser->company_user_id);
        if (! $trip) {
            return $this->error('No active trip found.', 404);
        }

        return $this->success($trip, 'Current trip retrieved successfully');
    }

    /**
     * Driver: Get current trip with all stops and passengers alighting at each stop
     * GET /driver/trips/current/stops
     */
    public function currentTripStops(Request $request)
    {
        $companyUser = $request->user()?->companyProfile;
        if (! $companyUser) {
            return $this->error('Staff profile not found.', 404);
        }
        $tripWithStops = $this->navigationService->getCurrentTripWithStops($companyUser->company_user_id);

        return $this->success($tripWithStops, 'Current trip stops and passengers retrieved successfully');
    }

    /**
     * Driver: Get details for a specific stop on current trip
     * GET /driver/trips/current/stops/{stopId}
     */
    public function currentTripStopDetail(Request $request, int $stopId)
    {
        $companyUser = $request->user()?->companyProfile;
        if (! $companyUser) {
            return $this->error('Staff profile not found.', 404);
        }
        $stopDetails = $this->navigationService->getStopDetails($companyUser->company_user_id, $stopId);

        return $this->success($stopDetails, 'Stop details retrieved successfully');
    }

    /**
     * Driver: Acknowledge reaching a stop (mark as passed)
     * POST /driver/trips/current/stops/{stopId}/acknowledge
     */
    public function acknowledgeStop(Request $request, int $stopId)
    {
        $companyUser = $request->user()?->companyProfile;
        if (! $companyUser) {
            return $this->error('Staff profile not found.', 404);
        }
        $this->navigationService->acknowledgeStop($companyUser->company_user_id, $stopId);

        return $this->success(null, 'Stop acknowledged successfully');
    }

    /**
     * Public: Get available trips for passengers to book
     * GET /trips
     */
    public function availableTrips(Request $request)
    {
        $includeStops = $request->boolean('include_stops', true);
        $tripDate = $request->query('trip_date');

        $trips = $this->tripService->getAvailableTripsForPassengers($includeStops, is_string($tripDate) ? $tripDate : null);

        return $this->success($trips, 'Available trips retrieved successfully');
    }

    private function assertDriverShiftForTrip(Request $request, int $tripId)
    {
        $user = $request->user();
        $role = strtolower((string) data_get($user, 'role', data_get($user, 'role_name', '')));
        if (! $user || $role === '' || $role !== 'driver') {
            return null;
        }

        $companyUser = $user->companyProfile;
        if (! $companyUser) {
            return $this->error('Staff profile not found.', 404);
        }

        $shiftStatus = app(DriverShiftService::class)->getStatusForDriver($companyUser->company_user_id);
        $openShift = $shiftStatus['open_shift'] ?? null;
        $hasOpenShift = (bool) ($shiftStatus['has_open_shift'] ?? false);

        if (! $hasOpenShift || ! $openShift) {
            return $this->error('Start your shift first.', 422);
        }

        if ((int) ($openShift->trip_id ?? 0) !== $tripId) {
            return $this->error('Your active shift is assigned to a different trip.', 422);
        }

        return null;
    }

    private function assertOperatorOwnsTrip(Request $request, int $tripId)
    {
        $user = $request->user();
        if (! $user || $user->role !== 'operator') {
            return null;
        }

        $companyUserId = $user->companyProfile?->company_user_id;
        if (! $companyUserId) {
            return $this->error('Staff profile not found.', 404);
        }

        $ownsTrip = $this->tripService->isOwnedByOperator($tripId, $companyUserId);

        if (! $ownsTrip) {
            return $this->error('You do not have permission to modify this trip.', 403);
        }

        return null;
    }
}
