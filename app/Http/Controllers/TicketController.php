<?php

namespace App\Http\Controllers;

use App\Services\TicketService;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;

class TicketController extends Controller
{
    private TicketService $ticketService;

    public function __construct(TicketService $ticketService)
    {
        $this->ticketService = $ticketService;
    }

    public function index(Request $request)
    {
        return $this->ticketService->listTicket($request->input('per_page', 15));
    }

    public function store(Request $request)
    {
        return $this->ticketService->createTicket($request->all());
    }

    public function show(string $uuid)
    {
        return $this->ticketService->getTicket($uuid);
    }

    public function update(Request $request, string $uuid)
    {
        return $this->ticketService->updateTicket($uuid, $request->all());
    }

    public function destroy(string $uuid)
    {
        $this->ticketService->deleteTicket($uuid);
        return response()->json(['message' => 'Deleted successfully'], 200);
    }
    
    public function restore(string $uuid)
    {
        return $this->ticketService->restoreTicket($uuid);
    }
}