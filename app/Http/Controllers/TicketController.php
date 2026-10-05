<?php
namespace App\Http\Controllers;
use App\Services\TicketService;
use App\Services\OccupancyService;
use App\Services\QRCodeService;
use App\Services\TripService;
use App\Services\ConductorShiftService;
use App\Http\Requests\ScanTicketRequest;
use App\Http\Requests\GuestTicketLookupRequest;
use App\Http\Requests\ScanGroupRequest;
use App\Traits\ApiResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

class TicketController extends Controller
{
    use ApiResponse;
    public function __construct(
        private TicketService $ticketService,
        private OccupancyService $occupancyService,
        private QRCodeService $qrCodeService,
        private TripService $tripService,
    )
    {
    }

    // Passenger: view their own ticket history
    public function myTickets(Request $request)
    {
        $passenger = $request->user()->passengerProfile;

        if (!$passenger) {
            return $this->error('Passenger profile not found.', 404);
        }

        return $this->success(
            $this->ticketService->getPassengerTickets($passenger->passenger_id),
            'Tickets retrieved successfully'
        );
    }

    // Conductor: scan a passenger QR code at boarding
    public function scan(ScanTicketRequest $request)
    {
        $companyUser = $request->user()?->companyProfile;
        if (!$companyUser) return $this->error('Staff profile not found.', 404);

        $guard = $this->assertConductorShiftForCurrentTrip($request);
        if ($guard !== null) {
            return $guard;
        }

        $ticket = $this->ticketService->validateScan(
            $request->validated(),
            $companyUser->company_user_id
        );
        return $this->success($ticket, 'Ticket validated and boarded successfully');
    }

    // Conductor: scan a group QR to board all tickets under one transaction at once
    public function scanGroup(ScanGroupRequest $request)
    {
        $companyUser = $request->user()?->companyProfile;
        if (!$companyUser) return $this->error('Staff profile not found.', 404);

        $guard = $this->assertConductorShiftForCurrentTrip($request);
        if ($guard !== null) {
            return $guard;
        }

        $result = $this->ticketService->scanGroup(
            $request->validated(),
            $companyUser->company_user_id
        );

        $boardedCount = (int) ($result['boarded_count'] ?? 0);
        $totalCount = (int) ($result['total_tickets'] ?? 0);
        $tickets = is_array($result['tickets'] ?? null) ? $result['tickets'] : [];

        if ($boardedCount === 0 && $totalCount > 0) {
            $allUsedOrRescanned = count($tickets) > 0 && collect($tickets)->every(function ($ticket) {
                $reason = strtolower((string) ($ticket['skip_reason'] ?? ''));
                return str_contains($reason, 'invalid/used');
            });

            $allFutureDated = count($tickets) > 0 && collect($tickets)->every(function ($ticket) {
                $reason = strtolower((string) ($ticket['skip_reason'] ?? ''));
                return str_contains($reason, 'not yet active') || str_contains($reason, 'scheduled for');
            });

            $allInvalid = count($tickets) > 0 && collect($tickets)->every(function ($ticket) {
                $reason = strtolower((string) ($ticket['skip_reason'] ?? ''));
                return str_contains($reason, 'this ticket is invalid.');
            });

            if ($allUsedOrRescanned) {
                return $this->error('This ticket is invalid/used.', 422, [
                    'tickets' => $tickets,
                ]);
            }

            if ($allFutureDated) {
                $firstReason = (string) (collect($tickets)->pluck('skip_reason')->filter()->first() ?? '');
                $scheduledDate = null;
                if (preg_match('/scheduled for\s+([0-9]{4}-[0-9]{2}-[0-9]{2})/i', $firstReason, $matches)) {
                    $scheduledDate = $matches[1];
                }

                $displayDate = $scheduledDate ? Carbon::parse($scheduledDate)->format('m/d/Y') : 'a future date';
                $result['scan_outcome'] = 'info_future';

                return $this->success($result, "This ticket is scheduled for {$displayDate}. It cannot be boarded yet.");
            }

            if ($allInvalid) {
                return $this->error('This ticket is invalid.', 422, [
                    'tickets' => $tickets,
                ]);
            }

            return $this->error('Invalid ticket. This ticket cannot be boarded.', 422, [
                'tickets' => $tickets,
            ]);
        }

        $result['scan_outcome'] = 'success';
        $msg = "{$boardedCount} of {$totalCount} ticket(s) boarded successfully.";
        return $this->success($result, $msg);
    }

    // Public route — lets a guest (no account) recover their ticket(s)
    // using the transaction reference + email they provided at checkout.
    public function guestLookup(GuestTicketLookupRequest $request)
    {
        $validated = $request->validated();

        $tickets = $this->ticketService->findByTransactionAndEmail(
            $validated['transaction_reference'],
            $validated['email'] ?? null,
            $validated['payment_id'] ?? null,
        );

        if ($tickets->isEmpty()) {
            return $this->error('No tickets found for that transaction reference.', 404);
        }

        return $this->success($tickets, 'Tickets retrieved successfully');
    }

    /**
     * Conductor: Get current trip occupancy dashboard
     * GET /conductor/trips/current/occupancy
     */
    public function currentTripOccupancy(Request $request)
    {
        $companyUser = $request->user()?->companyProfile;
        if (!$companyUser) return $this->error('Staff profile not found.', 404);
        $trip = $this->tripService->getCurrentTripForConductor($companyUser->company_user_id);

        if (!$trip) {
            return $this->error('No active trip found.', 404);
        }

        $occupancy = $this->occupancyService->getTripOccupancy($trip->trip_id);
        return $this->success($occupancy, 'Trip occupancy retrieved successfully');
    }

    /**
     * Conductor: Get occupancy breakdown by stop
     * GET /conductor/trips/current/occupancy/by-stop
     */
    public function occupancyByStop(Request $request)
    {
        $companyUser = $request->user()?->companyProfile;
        if (!$companyUser) return $this->error('Staff profile not found.', 404);
        $trip = $this->tripService->getCurrentTripForConductor($companyUser->company_user_id);

        if (!$trip) {
            return $this->error('No active trip found.', 404);
        }

        $breakdown = $this->occupancyService->getOccupancyByStop($trip->trip_id);
        return $this->success($breakdown, 'Occupancy by stop retrieved successfully');
    }

    /**
     * Conductor: Get current passengers on bus
     * GET /conductor/trips/current/passengers
     */
    public function currentPassengers(Request $request)
    {
        $companyUser = $request->user()?->companyProfile;
        if (!$companyUser) return $this->error('Staff profile not found.', 404);
        $trip = $this->tripService->getCurrentTripForConductor($companyUser->company_user_id);

        if (!$trip) {
            return $this->error('No active trip found.', 404);
        }

        $passengers = $this->occupancyService->getCurrentPassengers($trip->trip_id);
        return $this->success($passengers, 'Current passengers retrieved successfully');
    }

    /**
     * Conductor: Record passenger alighting
     * POST /conductor/tickets/{ticketId}/alight
     */
    public function recordAlighting(Request $request, int $ticketId)
    {
        // Only tickets of the conductor's own current trip can be alighted (no cross-trip ids).
        $companyUser = $request->user()?->companyProfile;
        if (!$companyUser) return $this->error('Staff profile not found.', 404);
        $trip = $this->tripService->getCurrentTripForConductor($companyUser->company_user_id);
        if (!$trip) return $this->error('No active trip found.', 404);

        $this->occupancyService->recordAlighting($ticketId, (int) $trip->trip_id);
        return $this->success(null, 'Passenger alighting recorded successfully');
    }

    /**
     * Chauffeur: where is the bus right now, as a boarding point on this trip's route?
     * GET /conductor/trips/current/boarding-point
     *
     * Auth + conductor role + completed pairing (route group), the caller's own open shift and
     * current trip (never an id from the request), throttled, and audited. Takes no coordinates:
     * the position is the bus's own latest accepted fix. When it is not reliable the state says
     * why and the Chauffeur picks the boarding stop instead; no destinations are offered then.
     */
    public function boardingPoint(Request $request)
    {
        $companyUser = $request->user()?->companyProfile;
        if (! $companyUser) {
            return $this->error('Staff profile not found.', 404);
        }

        $guard = $this->assertConductorShiftForCurrentTrip($request);
        if ($guard !== null) {
            return $guard;
        }

        $trip = $this->tripService->getCurrentTripForConductor($companyUser->company_user_id);
        if (! $trip || ! in_array($trip->status, ['boarding', 'departed', 'in-progress'], true)) {
            return $this->error('Boarding between stops is available once the trip is boarding or under way.', 422);
        }

        $resolved = app(\App\Services\MidTripBoardingService::class)->resolve($trip);

        $direction = $trip->leg_direction === 'reverse' ? 'reverse' : 'outbound';
        $routeId = (int) $trip->fleetRoute->route_id;
        $destinations = [];
        $dropoff = ['available' => false, 'reason' => null];

        if ($resolved['state'] === \App\Services\MidTripBoardingService::STATE_GOOD) {
            $boardingKm = (float) $resolved['boarding_point']['distance_from_origin_km'];
            // Stored (outbound) distances, travelled in the leg's order: the same frame the sale prices in.
            $stops = app(\App\Repositories\RouteStopRepository::class)->getOrderedStops($routeId);
            if ($direction === 'reverse') {
                $stops = $stops->reverse()->values();
            }
            foreach ($stops as $routeStop) {
                if (! \App\Support\ServiceType::isServed($trip->service_type, $routeStop)) {
                    continue;
                }
                $ahead = \App\Support\LegDistance::alongLegKm($boardingKm, (float) $routeStop->distance_from_origin_km, $direction);
                if ($ahead <= 0) {
                    continue;
                }
                $destinations[] = [
                    'stop_id' => (int) $routeStop->stop_id,
                    'stop_name' => $routeStop->stop?->stop_name,
                    'distance_km' => round($ahead, 2),
                ];
            }

            $route = $trip->fleetRoute->route;
            $dropoff = ($route?->allow_custom_dropoff && ! \App\Support\ServiceType::isExpress($trip->service_type))
                ? ['available' => true, 'reason' => null]
                : ['available' => false, 'reason' => null];
        }

        \App\Services\AuditLogger::log('boarding_point.resolved', 'Trip', (int) $trip->trip_id, [
            'state' => $resolved['state'],
            'age_seconds' => $resolved['age_seconds'],
            'accuracy_m' => $resolved['accuracy_m'],
        ]);

        return $this->success(array_merge($resolved, [
            'trip_id' => (int) $trip->trip_id,
            'leg_direction' => $direction,
            'service_type' => $trip->service_type,
            'destinations' => $destinations,
            'custom_dropoff' => $dropoff,
            // Tells the client what to do when the state is not good.
            'fallback' => $resolved['state'] === \App\Services\MidTripBoardingService::STATE_GOOD ? null : 'manual_boarding_stop',
        ]), 'Boarding point resolved.');
    }

    /**
     * Conductor & Driver: Trip earnings summary for current active trip.
     * GET /conductor/trips/current/earnings
     * GET /driver/trips/current/earnings
     */
    public function currentTripEarnings(Request $request)
    {
        $user = $request->user();
        if (!$user?->companyProfile) return $this->error('Staff profile not found.', 404);

        $trip = $this->tripService->getCurrentTripForUser($user);

        if (!$trip) {
            return $this->error('No active trip found.', 404);
        }

        $earnings = $this->ticketService->getTripEarnings($trip->trip_id);

        // Batch 29: a Chauffeur also sees the cash of their open shift (opening + cash sales - cash refunds). The shift's
        // cash is the Conductor's own, so it is never added for a Driver or an Operator.
        if (($user->role ?? null) === 'conductor') {
            $earnings['shift'] = app(\App\Services\ShiftCashService::class)->summaryForOpenShift((int) $user->companyProfile->company_user_id);
        }

        return $this->success($earnings, 'Trip earnings retrieved successfully');
    }

    /**
     * Passenger: Get QR code for their ticket
     * GET /passengers/tickets/{ticketUuid}/qr
     */
    public function getTicketQR(Request $request, string $ticketUuid)
    {
        $ticket = $this->ticketService->getTicketByUuidOrFail($ticketUuid);

        $passengerProfile = $request->user()->passengerProfile;
        if (!$passengerProfile) {
            return $this->error('Passenger profile not found.', 404);
        }

        // Verify ownership. A ticket with no passenger (a guest purchase) is not
        // anyone's account ticket, so it is refused here too.
        if ($ticket->passenger_id === null || (int) $ticket->passenger_id !== (int) $passengerProfile->passenger_id) {
            return $this->error('Unauthorized', 403);
        }

        $qrData = $this->qrCodeService->generateQRData($ticket);
        return $this->success($qrData, 'QR code generated successfully');
    }

    /**
     * A passenger cannot cancel or ask for a refund after paying (adviser decision, Batch 28). A refund exists only
     * when the OPERATOR cancels a trip (a later stage). The rule applies per ticket, so each ticket of a
     * roundtrip answers the same way. Another passenger's ticket is not found.
     */
    public function refuseCancellation(Request $request, string $ticketUuid)
    {
        $ticket = $this->ticketService->getTicketByUuidOrFail($ticketUuid);
        $passenger = $request->user()?->passengerProfile;

        if (! $passenger || $ticket->passenger_id === null || (int) $ticket->passenger_id !== (int) $passenger->passenger_id) {
            return $this->error('Ticket not found.', 404);
        }

        \App\Services\AuditLogger::log('ticket.passenger_cancel_refused', 'Ticket', (int) $ticket->ticket_id, [
            'journey_leg' => $ticket->journey_leg,
            'status' => $ticket->status,
        ]);

        return $this->error('A paid ticket cannot be cancelled or refunded by the passenger. A refund is possible only if the operator cancels the trip.', 422);
    }

    private function assertConductorShiftForCurrentTrip(Request $request)
    {
        $user = $request->user();
        $role = strtolower((string) data_get($user, 'role', data_get($user, 'role_name', '')));
        if (!$user || $role === '' || $role !== 'conductor') {
            return null;
        }

        $companyUser = $user->companyProfile;
        if (!$companyUser) {
            return $this->error('Staff profile not found.', 404);
        }

        $shiftStatus = app(ConductorShiftService::class)->getStatusForConductor($companyUser->company_user_id);
        $openShift = $shiftStatus['open_shift'] ?? null;
        $hasOpenShift = (bool) ($shiftStatus['has_open_shift'] ?? false);

        if (!$hasOpenShift || !$openShift) {
            return $this->error('Start your shift first.', 422);
        }

        $activeTrip = $this->tripService->getCurrentTripForConductor($companyUser->company_user_id);
        if (!$activeTrip) {
            return $this->error('No active trip found.', 404);
        }

        if (! app(\App\Services\LegProgressionService::class)->sameShift($openShift->trip ?? $openShift, $activeTrip)) {
            return $this->error('Your active shift is assigned to a different trip.', 422);
        }

        return null;
    }
}
