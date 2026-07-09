<?php
namespace App\Services;
use App\Models\Ticket;
use App\Repositories\TicketRepository;
use App\Repositories\TripRepository;
use Illuminate\Validation\ValidationException;
use Illuminate\Support\Facades\DB;

class TicketService
{
    public function __construct(
        private TicketRepository $ticketRepository,
        private TripRepository $tripRepository,
        private FareRuleService $fareRuleService,
        private TripService $tripService,
        private RewardService $rewardService,
    ) {
    }

    /**
     * Validates the trip, prices the fare, and RESERVES capacity for it —
     * but does NOT create a ticket row. Used by the online checkout flow so
     * a seat is held the moment checkout starts, before payment completes.
     *
     * $item = ['trip_id','seat_type','origin_stop_id','destination_stop_id']
     *
     * Returns a plain array (not a model) so it can be JSON-encoded straight
     * onto payments.items_payload and replayed later in finalizeTicket().
     */
    public function reserveAndPrice(array $item): array
    {
        $trip = $this->tripRepository->findById($item['trip_id']);

        if (!$trip || !in_array($trip->status, ['scheduled', 'boarding'])) {
            throw ValidationException::withMessages([
                'trip' => ['This trip is not accepting tickets.'],
            ]);
        }

        $farePayload = [
            'origin_stop_id' => $item['origin_stop_id'],
            'destination_stop_id' => $item['destination_stop_id'],
            'seat_type' => $item['seat_type'],
        ];

        $fare = $this->fareRuleService->getFareRecord($farePayload);

        // Reserve seat/standing capacity now — throws if full. This is the
        // seat "hold": capacity is committed here, before payment is
        // confirmed, so a second buyer can't take the same seat while this
        // checkout session is still pending.
        $this->tripService->recordBoarding($trip->trip_id, $item['seat_type']);

        return [
            'fleet_route_id' => $trip->fleet_route_id,
            'trip_id' => $trip->trip_id,
            'seat_type' => $item['seat_type'],
            'fare_id' => $fare->fare_id,
            'amount' => $fare->amount,
        ];
    }

    /**
     * Creates the actual ticket row for a previously reserved item. Called
     * only once payment is confirmed:
     * - online: PayMongoController::handleCheckoutPaid, after reserveAndPrice()
     *   already ran at checkout time
     * - onsite: immediately, via issueTicket() below, since cash is confirmed
     *   instantly
     *
     * Does NOT call recordBoarding() again — capacity was already reserved.
     */
    public function finalizeTicket(array $reserved, ?int $passengerId, int $paymentId): Ticket
    {
        $ticket = $this->ticketRepository->create([
            'fleet_route_id' => $reserved['fleet_route_id'],
            'trip_id' => $reserved['trip_id'],
            'fare_id' => $reserved['fare_id'],
            'payment_id' => $paymentId,
            'passenger_id' => $passengerId,
            'status' => 'issued',
            'amount' => $reserved['amount'],
            'seat_type' => $reserved['seat_type'],
        ]);

        if ($passengerId) {
            $this->rewardService->awardPoints($passengerId, $reserved['amount']);
        }

        return $ticket;
    }

    /**
     * Releases previously held capacity for an item that never became a
     * ticket (payment failed, or its hold expired). Called from
     * PayMongoController::handlePaymentFailed and the expired-holds command.
     */
    public function releaseTicketHold(array $reserved): void
    {
        $this->tripService->releaseBoarding($reserved['trip_id'], $reserved['seat_type']);
    }

    /**
     * Combines reserveAndPrice() + finalizeTicket() into a single call.
     * Used by the onsite flow, where cash payment is confirmed instantly —
     * there's no pending window, so there's no benefit to splitting
     * reservation from issuance.
     */
    public function issueTicket(array $item, ?int $passengerId, int $paymentId): Ticket
    {
        return DB::transaction(function () use ($item, $passengerId, $paymentId) {
            $reserved = $this->reserveAndPrice($item);
            return $this->finalizeTicket($reserved, $passengerId, $paymentId);
        });
    }

    // $payload = ['ticket_uuid']
    public function validateScan(array $payload): object
    {
        $ticket = $this->ticketRepository->findByUuid($payload['ticket_uuid']);

        if (!$ticket) {
            throw ValidationException::withMessages(['ticket' => ['Ticket not found.']]);
        }

        if ($ticket->status !== 'issued') {
            throw ValidationException::withMessages([
                'ticket' => ["Ticket is already {$ticket->status}."],
            ]);
        }

        if (!$ticket->payment || $ticket->payment->status !== 'paid' || !$ticket->payment->is_valid) {
            throw ValidationException::withMessages([
                'ticket' => ['Payment for this ticket has not been completed yet.'],
            ]);
        }

        $this->ticketRepository->markBoarded($ticket->ticket_id);
        return $this->ticketRepository->findByUuid($payload['ticket_uuid']);
    }

    public function getPassengerTickets(int $passengerId): object
    {
        return $this->ticketRepository->findByPassenger($passengerId);
    }

    public function findByTransactionAndEmail(string $transactionReference, string $email): object
    {
        return $this->ticketRepository->findByTransactionAndEmail($transactionReference, $email);
    }
}