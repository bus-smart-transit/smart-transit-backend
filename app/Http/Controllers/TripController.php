<?php

namespace App\Http\Controllers;

use App\Http\Requests\AssignConductorRequest;
use App\Http\Requests\AssignDriverRequest;
use App\Http\Requests\StoreTripRequest;
use App\Http\Requests\UpdateTripStatusRequest;
use App\Models\FleetRoute;
use App\Repositories\TicketRepository;
use App\Services\AuditLogger;
use App\Services\DriverNavigationService;
use App\Services\FleetService;
use App\Services\TripActionGuard;
use App\Services\TripAssignmentRequestService;
use App\Services\TripService;
use App\Support\TripRunInfo;
use App\Support\TripTiming;
use App\Traits\ApiResponse;
use Illuminate\Http\Request;

class TripController extends Controller
{
    use ApiResponse;

    public function __construct(
        private TripService $tripService,
        private DriverNavigationService $navigationService,
        private TripActionGuard $tripActionGuard,
        private TripAssignmentRequestService $assignmentRequestService,
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

    // Operator / Admin: move a trip that has not started onto another bus of the same route (passengers are told)
    public function changeFleet(Request $request, int $tripId)
    {
        if ($denied = $this->assertOperatorOwnsTrip($request, $tripId)) {
            return $denied;
        }

        $data = $request->validate(['fleet_route_id' => 'required|integer|exists:fleets_routes,fleet_route_id']);
        $user = $request->user();
        if ($user?->role === 'operator') {
            $fleetId = FleetRoute::where('fleet_route_id', $data['fleet_route_id'])->value('fleet_id');
            if (! $fleetId || ! app(FleetService::class)->isOwnedByOperator((int) $fleetId, (int) $user->companyProfile?->company_user_id)) {
                return $this->error('You do not have permission to use that bus.', 403);
            }
        }

        $trip = $this->tripService->changeFleet($tripId, (int) $data['fleet_route_id']);
        AuditLogger::log('trip.change_fleet', 'Trip', $tripId, $data);

        return $this->success($trip, 'Bus changed. Passengers with tickets were notified.');
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

    // Driver / Operator / Admin: open boarding (idempotent)
    public function startBoarding(Request $request, int $tripId)
    {
        [$denied, $alreadyApplied] = $this->tripActionGuard->evaluate($request, $tripId, 'boarding');
        if ($denied) {
            return $denied;
        }

        if (! $alreadyApplied) {
            $result = $this->tripService->startBoarding($tripId);
            AuditLogger::log('trip.boarding', 'Trip', $tripId);
        }

        return $this->success($result ?? $this->tripService->getTrip($tripId), 'Boarding started');
    }

    // Driver / Operator / Admin: mark departed (idempotent)
    public function depart(Request $request, int $tripId)
    {
        [$denied, $alreadyApplied] = $this->tripActionGuard->evaluate($request, $tripId, 'depart');
        if ($denied) {
            return $denied;
        }

        if (! $alreadyApplied) {
            $result = $this->tripService->departTrip($tripId);
            AuditLogger::log('trip.depart', 'Trip', $tripId);
        }

        return $this->success($result ?? $this->tripService->getTrip($tripId), 'Trip departed');
    }

    // Driver / Operator / Admin: End Trip (idempotent)
    public function complete(Request $request, int $tripId)
    {
        [$denied, $alreadyApplied] = $this->tripActionGuard->evaluate($request, $tripId, 'complete');
        if ($denied) {
            return $denied;
        }

        if (! $alreadyApplied) {
            $result = $this->tripService->completeTrip($tripId);
            AuditLogger::log('trip.complete', 'Trip', $tripId);
        }

        return $this->success($result ?? $this->tripService->getTrip($tripId), 'Trip completed');
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
            $request->user(),
            $request->input('reason'),
        );

        AuditLogger::log('trip.status_override', 'Trip', $tripId, [
            'from' => $previousStatus,
            'to' => $trip->status,
            'role' => $role,
        ]);

        return $this->success($trip, 'Trip status updated');
    }

    // Operator / Admin (Batch 28 Stage 3, item 6): cancel one trip, before or during it. Every paid, unused ticket becomes
    // "trip cancelled, awaiting choice" and its passenger is told; nothing is refunded automatically.
    public function cancel(Request $request, int $tripId)
    {
        if ($denied = $this->assertOperatorOwnsTrip($request, $tripId)) {
            return $denied;
        }

        $data = $request->validate(['reason' => 'nullable|string|max:255']);
        [$trip, $previousStatus] = $this->tripService->cancelTrip($tripId, $request->user(), $data['reason'] ?? null);

        AuditLogger::log('trip.cancel', 'Trip', $tripId, ['from' => $previousStatus, 'reason' => $data['reason'] ?? null, 'role' => $request->user()->role ?? null]);

        return $this->success($trip, 'Trip cancelled. Passengers with paid tickets were told and can rebook or ask for a refund.');
    }

    // Driver/Chauffeur: ask to be released from an assigned trip. The request
    // goes to the Operator as "For Approval" (assignment-level status, D6); the
    // assignment itself only changes when the Operator approves.
    public function declineAsDriver(Request $request, int $tripId)
    {
        return $this->requestDecline($request, $tripId, 'driver');
    }

    public function declineAsConductor(Request $request, int $tripId)
    {
        return $this->requestDecline($request, $tripId, 'conductor');
    }

    private function requestDecline(Request $request, int $tripId, string $role)
    {
        $companyUser = $request->user()?->companyProfile;
        if (! $companyUser) {
            return $this->error('Staff profile not found.', 404);
        }

        $validated = $request->validate([
            'reason_code' => ['nullable', 'string', 'in:'.implode(',', TripAssignmentRequestService::REASON_CODES)],
            'reason_text' => ['nullable', 'string', 'max:'.TripAssignmentRequestService::REASON_TEXT_MAX, 'required_if:reason_code,other'],
        ]);

        $trip = $this->assignmentRequestService->requestDecline(
            $tripId,
            $role,
            $companyUser->company_user_id,
            $validated['reason_code'] ?? null,
            $validated['reason_text'] ?? null,
        );

        AuditLogger::log('trip.decline_requested', 'Trip', $tripId, ['role' => $role, 'reason_code' => $validated['reason_code'] ?? null]);

        return $this->success($trip, 'Request sent. It is now waiting for Operator approval.');
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

        $pagination = $request->validate([
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:200'],
        ]);

        return $this->paginated(
            $this->tripService->getDriverTrips($companyUser->company_user_id, (string) $request->query('status_filter', 'all'), (int) ($pagination['page'] ?? 1), (int) ($pagination['per_page'] ?? 100)),
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

        $pagination = $request->validate([
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:200'],
        ]);

        return $this->paginated(
            $this->tripService->getConductorTrips($companyUser->company_user_id, (string) $request->query('status_filter', 'all'), (int) ($pagination['page'] ?? 1), (int) ($pagination['per_page'] ?? 100)),
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
        $pagination = $request->validate([
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);

        $page = $this->tripService->getOperatorUpcomingTrips(
            $companyUser->company_user_id,
            $resolvedFilter,
            (int) ($pagination['page'] ?? 1),
            (int) ($pagination['per_page'] ?? 100),
        );
        $trips = $page->getCollection();
        TripRunInfo::attach($trips);
        // §4.7: how many passengers are on board now, for the Operator's Track fleet view (tickets sold stays separate).
        $onboard = app(TicketRepository::class)->countBoardedByTrips($trips->pluck('trip_id')->map(fn ($id) => (int) $id)->all());
        $trips->each(fn ($trip) => $trip->setAttribute('onboard_count', $onboard[(int) $trip->trip_id] ?? 0));

        return response()->json([
            'status' => 'success',
            'message' => 'Upcoming trips retrieved successfully',
            'data' => $trips->values(),
            'meta' => [
                'current_page' => $page->currentPage(),
                'last_page' => $page->lastPage(),
                'per_page' => $page->perPage(),
                'total' => $page->total(),
            ],
        ]);
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
        TripRunInfo::attach([$trip]);
        // Phase A: when Start Boarding and Depart open, for the buttons (the server still decides).
        $trip->setAttribute('action_timing', TripTiming::forTrip($trip));

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
        TripRunInfo::attach([$trip]);
        $trip->setAttribute('action_timing', TripTiming::forTrip($trip));

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
        TripRunInfo::attach($trips);

        return $this->success($trips, 'Available trips retrieved successfully');
    }

    // Ownership rule lives in TripActionGuard (single implementation).
    private function assertOperatorOwnsTrip(Request $request, int $tripId)
    {
        return $this->tripActionGuard->assertOperatorOwnsTrip($request, $tripId);
    }
}
