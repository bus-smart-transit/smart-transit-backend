<?php

namespace App\Services;

use App\Repositories\TicketRepository;
use App\Repositories\TripRepository;
use Illuminate\Validation\ValidationException;
use Illuminate\Support\Facades\DB;

class TicketService
{
    private TicketRepository $ticketRepository;
    private TripRepository $tripRepository;
    private FareRuleService $fareService;
    private TripService $tripService;
    private RewardService $rewardService;
    public function __construct(
        TicketRepository $ticketRepository,
        TripRepository $tripRepository,
        FareRuleService $fareRuleService,
        TripService $tripService,
        RewardService $rewardService,
    ) {
        $this->ticketRepository = $ticketRepository;
        $this->tripRepository = $tripRepository;
        $this->tripService = $tripService;
        $this->fareService = $fareRuleService;
        $this->rewardService = $rewardService;
    }

    /**
     * Issues one ticket against an existing payment. For a multi-ticket
     * purchase, call this once per ticket, all sharing the same $paymentId
     * (see PaymentService::checkout()).
     */
    public function issueTicket(array $payload)
    {
        $trip = $this->tripRepository->findById($payload['trip_id']);

        if (!$trip || !in_array($trip->status, ['scheduled', 'boarding'])) {
            throw ValidationException::withMessages(['trip' => ['This trip is not accepting tickets.']]);
        }

        $fare = $this->fareService->getFareRecordForSegment($payload['origin_stop_id'], $payload['destination_stop_id'], $payload['seat_type']);

        return DB::transaction(function () use ($trip, $payload, $fare) {
            // Reserve capacity before issuing — throws if full.
            $this->tripService->recordBoarding($trip->trip_id, $payload['seat_type']);

            $ticket = $this->ticketRepository->create([
                'fleet_route_id' => $trip->fleet_route_id,
                'trip_id' => $trip->trip_id,
                'fare_id' => $fare->fare_id,
                'payment_id' => $payload['payment_id'],
                'passenger_id' => $payload['passenger_id'],
                'status' => 'issued',
                'amount' => $fare->amount,
            ]);

            if ($payload['passenger_id']) {
                $this->rewardService->awardPoints($payload['passenger_id'], $fare->amount);
            }

            return $ticket;
        });
    }

    public function validateScan(string $ticketUuid)
    {
        $ticket = $this->ticketRepository->findByUuid($ticketUuid);

        if (!$ticket) {
            throw ValidationException::withMessages(['ticket' => ['Ticket not found.']]);
        }

        if ($ticket->status !== 'issued') {
            throw ValidationException::withMessages(['ticket' => ["Ticket already {$ticket->status}."]]);
        }

        $this->ticketRepository->markBoarded($ticket->ticket_id);
        return $this->ticketRepository->findByUuid($ticketUuid);
    }

    public function getPassengerTickets(int $passengerId)
    {
        return $this->ticketRepository->findByPassenger($passengerId);
    }
}
