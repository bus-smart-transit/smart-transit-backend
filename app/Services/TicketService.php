<?php
namespace App\Services;
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
     * Issues one ticket. Called once per item inside PaymentService::checkout*().
     *
     * $item = ['trip_id','seat_type','origin_stop_id','destination_stop_id']
     * $passengerId nullable (walk-up onsite passengers may have no account)
     * $paymentId   the payment record already created by PaymentService
     */
    public function issueTicket(array $item, ?int $passengerId, int $paymentId): object
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

        return DB::transaction(function () use ($trip, $passengerId, $item, $fare, $paymentId) {
            // Reserve seat/standing capacity — throws if full
            $this->tripService->recordBoarding($trip->trip_id, $item['seat_type']);

            $ticket = $this->ticketRepository->create([
                'fleet_route_id' => $trip->fleet_route_id,
                'trip_id' => $trip->trip_id,
                'fare_id' => $fare->fare_id,
                'payment_id' => $paymentId,
                'passenger_id' => $passengerId,
                'status' => 'issued',
                'amount' => $fare->amount,
            ]);

            if ($passengerId) {
                $this->rewardService->awardPoints($passengerId, $fare->amount);
            }

            return $ticket;
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

        $this->ticketRepository->markBoarded($ticket->ticket_id);
        return $this->ticketRepository->findByUuid($payload['ticket_uuid']);
    }

    public function getPassengerTickets(int $passengerId): object
    {
        return $this->ticketRepository->findByPassenger($passengerId);
    }
}
