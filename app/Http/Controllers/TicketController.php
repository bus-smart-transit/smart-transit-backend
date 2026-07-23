<?php
namespace App\Http\Controllers;
use App\Services\TicketService;
use App\Services\OccupancyService;
use App\Services\QRCodeService;
use App\Http\Requests\ScanTicketRequest;
use App\Http\Requests\GuestTicketLookupRequest;
use App\Traits\ApiResponse;
use Illuminate\Http\Request;

class TicketController extends Controller
{
    use ApiResponse;
    public function __construct(
        private TicketService $ticketService,
        private OccupancyService $occupancyService,
        private QRCodeService $qrCodeService,
    )
    {
    }

    // Passenger: view their own ticket history
    public function myTickets(Request $request)
    {
        $passenger = $request->user()->passengerProfile;
        return $this->success(
            $this->ticketService->getPassengerTickets($passenger->passenger_id),
            'Tickets retrieved successfully'
        );
    }

    // Conductor: scan a passenger QR code at boarding
    public function scan(ScanTicketRequest $request)
    {
        $ticket = $this->ticketService->validateScan($request->validated());
        return $this->success($ticket, 'Ticket validated and boarded successfully');
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
        $companyUser = $request->user()->companyProfile;
        $trip = app(\App\Services\TripService::class)->getCurrentTripForConductor($companyUser->company_user_id);

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
        $companyUser = $request->user()->companyProfile;
        $trip = app(\App\Services\TripService::class)->getCurrentTripForConductor($companyUser->company_user_id);

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
        $companyUser = $request->user()->companyProfile;
        $trip = app(\App\Services\TripService::class)->getCurrentTripForConductor($companyUser->company_user_id);

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
        $this->occupancyService->recordAlighting($ticketId);
        return $this->success(null, 'Passenger alighting recorded successfully');
    }

    /**
     * Passenger: Get QR code for their ticket
     * GET /passengers/tickets/{ticketUuid}/qr
     */
    public function getTicketQR(Request $request, string $ticketUuid)
    {
        $ticket = \App\Models\Ticket::where('ticket_uuid', $ticketUuid)->firstOrFail();
        
        // Verify ownership
        if ($ticket->passenger_id && $ticket->passenger_id !== $request->user()->passengerProfile->passenger_id) {
            return $this->error('Unauthorized', 403);
        }

        $qrData = $this->qrCodeService->generateQRData($ticket);
        return $this->success($qrData, 'QR code generated successfully');
    }
}
