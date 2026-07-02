<?php

namespace App\Http\Controllers;

use App\Services\TicketService;
use Illuminate\Http\Request;
use App\Traits\ApiResponse;

class TicketController extends Controller
{
    use ApiResponse;
    private TicketService $ticketService;

    public function __construct(TicketService $ticketService)
    {
        $this->ticketService = $ticketService;
    }

    // Passenger's own ticket history.
    public function myTickets(Request $request)
    {
        $passenger = $request->user()->passengerProfile; // adjust to your actual User->PassengerUser relation
        return $this->success($this->ticketService->getPassengerTickets($passenger->passenger_id), 'Tickets retrieved successfully');
    }

    // Conductor scans a QR code at boarding.
    public function scan(Request $request)
    {
        $validated = $request->validate(['ticket_uuid' => 'required|string']);
        $ticket = $this->ticketService->validateScan($validated['ticket_uuid']);
        return $this->success($ticket, 'Ticket validated and boarded successfully');
    }
}
