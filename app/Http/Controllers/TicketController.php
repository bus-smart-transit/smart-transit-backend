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

        if ((int) ($openShift->trip_id ?? 0) !== (int) ($activeTrip->trip_id ?? 0)) {
            return $this->error('Your active shift is assigned to a different trip.', 422);
        }

        return null;
    }
}
