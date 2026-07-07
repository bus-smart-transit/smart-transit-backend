<?php
namespace App\Http\Controllers;
use App\Services\TicketService;
use App\Http\Requests\ScanTicketRequest;
use App\Traits\ApiResponse;
use Illuminate\Http\Request;

class TicketController extends Controller
{
    use ApiResponse;
    public function __construct(private TicketService $ticketService)
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
}
