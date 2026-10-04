<?php

namespace App\Http\Controllers;

use App\Models\Ticket;
use App\Models\TicketResolution;
use App\Services\AuditLogger;
use App\Services\TicketResolutionService;
use App\Services\TicketService;
use App\Services\TripCancellationService;
use App\Traits\ApiResponse;
use Illuminate\Http\Request;

/**
 * Batch 28 Stage 3, item 6: rebook or refund for a ticket of a trip the OPERATOR cancelled.
 *
 * The passenger side acts only on the signed-in passenger's own tickets (anyone else's is a 404). The staff side
 * (Operator for the tickets of their own trips, Admin for all) acts for a passenger at the terminal and records who
 * did it; a staff action never pays out cash for a digital ticket. The amount is always worked out on the server.
 */
class TicketResolutionController extends Controller
{
    use ApiResponse;

    public function __construct(
        private TicketResolutionService $resolutions,
        private TicketService $ticketService,
    ) {}

    // ---------------------------------------------------------------- passenger

    public function show(Request $request, string $ticketUuid)
    {
        $ticket = $this->passengerTicket($request, $ticketUuid);
        if (! $ticket) {
            return $this->error('Ticket not found.', 404);
        }

        return $this->success($this->resolutions->view($ticket), 'Ticket options retrieved successfully');
    }

    public function choose(Request $request, string $ticketUuid)
    {
        $ticket = $this->passengerTicket($request, $ticketUuid);
        if (! $ticket) {
            return $this->error('Ticket not found.', 404);
        }

        $data = $request->validate([
            'choice' => 'required|string|in:rebook,refund',
            'trip_id' => 'nullable|integer',
        ]);

        $resolution = $this->resolutions->choose($ticket, $data, ['user' => $request->user(), 'role' => 'passenger', 'on_behalf' => false]);
        AuditLogger::log('ticket.passenger_choice', 'Ticket', (int) $ticket->ticket_id, ['choice' => $data['choice'], 'resolution_id' => $resolution->id]);

        return $this->success($this->resolutions->view($ticket->fresh()), 'Your choice was recorded.');
    }

    // ---------------------------------------------------------------- staff (operator for own trips, admin for all)

    /** The tickets that need staff attention: awaiting a choice, a refund waiting on cash or review, or a failed refund. */
    public function queue(Request $request)
    {
        $companyUserId = $this->operatorScope($request);
        if ($companyUserId === false) {
            return $this->error('Staff profile not found.', 404);
        }

        $tickets = Ticket::with(['trip.fleetRoute.route', 'passenger.user', 'payment'])
            ->whereIn('status', [TripCancellationService::AWAITING_CHOICE, TicketResolutionService::PENDING, TicketResolutionService::REVIEW])
            ->when($companyUserId !== null, fn ($q) => $q->whereHas('trip', fn ($t) => $t->where('company_user_id', $companyUserId)))
            ->orderByDesc('ticket_id')
            ->limit(200)
            ->get();

        $resolutions = TicketResolution::whereIn('ticket_id', $tickets->pluck('ticket_id'))->get()->keyBy('ticket_id');

        return $this->success($tickets->map(function (Ticket $ticket) use ($resolutions) {
            $resolution = $resolutions->get($ticket->ticket_id);

            return [
                'ticket_uuid' => $ticket->ticket_uuid,
                'status' => $ticket->status,
                'seat_type' => $ticket->seat_type,
                'journey_leg' => $ticket->journey_leg,
                'trip_id' => (int) $ticket->trip_id,
                'trip_date' => $ticket->trip?->trip_date?->toDateString(),
                'route_name' => $ticket->trip?->fleetRoute?->route?->route_name,
                'passenger_name' => $ticket->passenger?->user?->name,
                'guest_email' => $ticket->payment?->guest_email,
                'fare_paid' => $this->resolutions->farePaid($ticket),
                'method' => $this->resolutions->methodFor($ticket),
                'resolution' => $resolution ? $this->resolutions->presentResolution($resolution) : null,
            ];
        })->values(), 'Tickets needing attention retrieved successfully');
    }

    public function staffShow(Request $request, string $ticketUuid)
    {
        $ticket = $this->staffTicket($request, $ticketUuid);
        if ($ticket instanceof \Illuminate\Http\JsonResponse) {
            return $ticket;
        }

        return $this->success($this->resolutions->view($ticket), 'Ticket options retrieved successfully');
    }

    public function staffChoose(Request $request, string $ticketUuid)
    {
        $ticket = $this->staffTicket($request, $ticketUuid);
        if ($ticket instanceof \Illuminate\Http\JsonResponse) {
            return $ticket;
        }

        $data = $request->validate([
            'choice' => 'required|string|in:rebook,refund',
            'trip_id' => 'nullable|integer',
            'note' => 'nullable|string|max:255',
            'method' => 'nullable|string|in:gateway,cash',
        ]);

        $resolution = $this->resolutions->choose($ticket, $data, ['user' => $request->user(), 'role' => (string) $request->user()->role, 'on_behalf' => true]);
        AuditLogger::log('ticket.staff_choice', 'Ticket', (int) $ticket->ticket_id, ['choice' => $data['choice'], 'resolution_id' => $resolution->id, 'role' => $request->user()->role]);

        return $this->success($this->resolutions->view($ticket->fresh()), 'The choice was recorded for the passenger.');
    }

    /** Record the cash refund of a cash ticket (who, when, how much). */
    public function recordCash(Request $request, int $resolutionId)
    {
        $resolution = $this->staffResolution($request, $resolutionId);
        if ($resolution instanceof \Illuminate\Http\JsonResponse) {
            return $resolution;
        }

        $data = $request->validate(['note' => 'nullable|string|max:255']);
        $done = $this->resolutions->recordCashRefund($resolution, $request->user(), (string) $request->user()->role, $data['note'] ?? null);

        return $this->success($this->resolutions->presentResolution($done), 'Cash refund recorded.');
    }

    /** Set the refund of a ticket whose travelled part could not be priced (the reason is required and recorded). */
    public function review(Request $request, int $resolutionId)
    {
        $resolution = $this->staffResolution($request, $resolutionId);
        if ($resolution instanceof \Illuminate\Http\JsonResponse) {
            return $resolution;
        }

        $data = $request->validate([
            'amount' => 'required|numeric|min:0',
            'note' => 'required|string|max:255',
        ]);
        $done = $this->resolutions->setReviewedAmount($resolution, (float) $data['amount'], $request->user(), (string) $request->user()->role, $data['note']);

        return $this->success($this->resolutions->presentResolution($done), 'Refund amount set.');
    }

    // ----------------------------------------------------------------

    private function passengerTicket(Request $request, string $ticketUuid): ?Ticket
    {
        $ticket = $this->ticketService->getTicketByUuidOrFail($ticketUuid);
        $passenger = $request->user()?->passengerProfile;

        return $passenger && $ticket->passenger_id !== null && (int) $ticket->passenger_id === (int) $passenger->passenger_id ? $ticket : null;
    }

    /** null = admin (all), int = an operator's company, false = no staff profile. */
    private function operatorScope(Request $request): int|false|null
    {
        $user = $request->user();
        if (($user->role ?? null) === 'admin') {
            return null;
        }
        $companyUserId = $user?->companyProfile?->company_user_id;

        return $companyUserId ? (int) $companyUserId : false;
    }

    private function staffTicket(Request $request, string $ticketUuid): Ticket|\Illuminate\Http\JsonResponse
    {
        $scope = $this->operatorScope($request);
        if ($scope === false) {
            return $this->error('Staff profile not found.', 404);
        }

        $ticket = $this->ticketService->getTicketByUuidOrFail($ticketUuid);
        if ($scope !== null && (int) $ticket->trip?->company_user_id !== $scope) {
            return $this->error('Ticket not found.', 404);
        }

        return $ticket;
    }

    private function staffResolution(Request $request, int $resolutionId): TicketResolution|\Illuminate\Http\JsonResponse
    {
        $resolution = TicketResolution::with('ticket.trip')->find($resolutionId);
        $scope = $this->operatorScope($request);
        if ($scope === false) {
            return $this->error('Staff profile not found.', 404);
        }
        if (! $resolution || ($scope !== null && (int) $resolution->ticket?->trip?->company_user_id !== $scope)) {
            return $this->error('Refund not found.', 404);
        }

        return $resolution;
    }
}
